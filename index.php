<?php
declare(strict_types=1);

date_default_timezone_set('America/Hermosillo');

require __DIR__ . '/vendor/autoload.php';

use Hitrov\Exception\ApiCallException;
use Hitrov\OciApi;
use Hitrov\OciConfig;

/* ========================= HELPERS ========================= */

$scriptStartTime = time();

function logLine(string $message): void
{
    global $scriptStartTime;
    $elapsed = time() - $scriptStartTime;
    $h = intdiv($elapsed, 3600);
    $m = intdiv($elapsed % 3600, 60);
    $s = $elapsed % 60;
    $elapsedStr = sprintf('%02d:%02d:%02d', $h, $m, $s);
    echo '[' . date('H:i:s') . '] [+' . $elapsedStr . '] ' . $message . PHP_EOL;
    flush();
}

function containsInsensitive(string $haystack, string $needle): bool
{
    return strpos(strtolower($haystack), strtolower($needle)) !== false;
}

function isCapacityError(string $message): bool
{
    return containsInsensitive($message, 'Out of host capacity')
        || containsInsensitive($message, 'InternalError')
        || containsInsensitive($message, 'capacity');
}

function isThrottleError(string $message): bool
{
    return containsInsensitive($message, 'TooManyRequests')
        || containsInsensitive($message, '429')
        || containsInsensitive($message, 'throttl');
}

function isAuthOrNotFoundError(string $message): bool
{
    return containsInsensitive($message, 'NotAuthorizedOrNotFound')
        || containsInsensitive($message, 'Authorization failed')
        || containsInsensitive($message, 'requested resource not found')
        || containsInsensitive($message, 'LimitExceeded');
}

/**
 * Backoff exponencial basado en ciclos completados, con jitter.
 * base: segundos base, max: techo en segundos, cycle: número de ciclo completo
 */
function pickCycleDelay(int $base, int $max, int $cycle): int
{
    $exp   = min($max, $base * (int)pow(2, max(0, $cycle - 1)));
    $jitter = random_int(0, max(1, (int)floor($exp * 0.20)));
    return min($max, $exp + $jitter);
}

function sleepWithCountdown(int $seconds): void
{
    $end = time() + $seconds;
    while (($remaining = $end - time()) > 0) {
        $m = intdiv($remaining, 60);
        $s = $remaining % 60;
        echo "\r  Esperando " . sprintf('%02d:%02d', $m, $s) . ' restantes...   ';
        flush();
        sleep(min(15, $remaining));
    }
    echo "\r  Reanudando...                          \n";
}

/* ========================= CONFIGURACIÓN ========================= */

$region     = 'us-phoenix-1';
$userOcid   = 'ocid1.user.oc1..aaaaaaaa3ictppiahy5kj42t2wlakb2smmivtbmza3hg2x5hoboz5l45vdka';
$tenancyOcid = 'ocid1.tenancy.oc1..aaaaaaaa5f7bf7uu4pqlzpfezxx2b4gn4d5ntpcfpvgexohel6ypp5uf6z3q';
$fingerprint = '7f:f5:5a:ef:b0:10:99:91:a1:fe:20:83:6c:de:0b:83';
$keyFilename = __DIR__ . DIRECTORY_SEPARATOR . 'key_rsa.pem';

$subnetId = 'ocid1.subnet.oc1.phx.aaaaaaaajkxwcvxqasnfaot7gvjlreaamhqkhbx2l2wtvrshcrqmsgvp24aa';
$imageId  = 'ocid1.image.oc1.phx.aaaaaaaavfbkczxpy4zopkqswucpfx7tv7x5xyjvppsp4l5tjkwv5kctr3dq';
$shape    = 'VM.Standard.A1.Flex';

$availabilityDomains = [
    'Pjmv:PHX-AD-1',
    'Pjmv:PHX-AD-2',
    'Pjmv:PHX-AD-3',
];

$sshPublicKey = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC8Cn8OAp/jHISxcNt+Sj2sz6RIos62++2oye/EdgxKpR1SYVYyaMrsBSYhvlM2H1WjBuYUNJ+qOCNpH+xRJqq+JPZnFRZdyTl+Rr6S37a49kzLxA/eCKB3ijiy9UMrB0MTgjSIw/VhtgYwKkzkEDZiBycsk7zzjtf1uMfijrhccWL7gvh8kSj/f/mi5wlxUWLXULzeDjktR0QUpOQFuvvVpRIq2HYDxqVIJWUVi4wAEkKO7NvgOPp8fKyzwzNTqwMNymEuCjJNJyTHvT221pnxQDSnOyFQ6CBrt06FU5l1ukV+eUOW3k5a8eDZW7Yw1qGuoXeiG5cb0hlygMuRFkkd ssh-key-2026-03-30';

// Perfiles ordenados de menor a mayor. El loop SIEMPRE vuelve a 2 OCPU al empezar un ciclo nuevo.
$profiles = [
    ['ocpus' => 2, 'memory' => 12, 'label' => '2 OCPU / 12 GB'],
    ['ocpus' => 3, 'memory' => 18, 'label' => '3 OCPU / 18 GB'],
    ['ocpus' => 4, 'memory' => 24, 'label' => '4 OCPU / 24 GB'],
];

$bootVolumeSizeInGBs = '150';

// Delay entre ciclos completos (todos los ADs × todos los perfiles fallados):
// Ciclo 1 fallido → espera 10 min
// Ciclo 2 fallido → espera 20 min
// Ciclo 3+ fallido → espera hasta 60 min
$baseCycleDelay = 600;   // 10 minutos
$maxCycleDelay  = 3600;  // 60 minutos máximo

// Delay fijo cuando Oracle devuelve TooManyRequests: 15 minutos
$throttleFixedDelay = 900; // 15 minutos

/* ========================= INIT ========================= */

$attempt      = 0;
$cycle        = 0;
$profileIndex = 0;

$api = new OciApi();

logLine('=========================================');
logLine('  ORACLE A1.Flex AUTO-REQUESTER v2.0     ');
logLine('=========================================');
logLine('Shape objetivo : ' . $shape);
logLine('Región         : ' . $region);
logLine('ADs            : ' . implode(', ', $availabilityDomains));
logLine('Perfiles       : ' . implode(' → ', array_column($profiles, 'label')));
logLine('Estrategia     : ciclos con backoff exponencial por ciclo');
logLine('=========================================');

/* ========================= LOOP PRINCIPAL ========================= */

while (true) {

    $cycle++;
    $profileIndex = 0; // <- CORRECCIÓN CLAVE: siempre volver al perfil mínimo en cada ciclo
    $cycleSuccess = false;

    logLine("--- CICLO #{$cycle} INICIANDO ---");

    foreach ($profiles as $profileIndex => $profile) {

        // Barajar ADs en cada perfil para distribuir carga
        $ads = $availabilityDomains;
        shuffle($ads);

        logLine("Perfil: {$profile['label']} | Orden ADs este turno: " . implode(' → ', $ads));

        foreach ($ads as $ad) {

            $attempt++;
            logLine("Intento #{$attempt} | Ciclo #{$cycle} | AD: {$ad} | {$profile['label']}");

            try {
                $config = new OciConfig(
                    $region,
                    $userOcid,
                    $tenancyOcid,
                    $fingerprint,
                    $keyFilename,
                    $ad,
                    $subnetId,
                    $imageId,
                    (int)$profile['ocpus'],
                    (int)$profile['memory']
                );

                $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);

                $details = $api->createInstance(
                    $config,
                    $shape,
                    $sshPublicKey,
                    $ad
                );

                // ✅ ÉXITO
                $save = [
                    'createdAt'          => date('c'),
                    'cycle'              => $cycle,
                    'attempt'            => $attempt,
                    'availabilityDomain' => $ad,
                    'shape'              => $shape,
                    'ocpus'              => $profile['ocpus'],
                    'memoryInGBs'        => $profile['memory'],
                    'details'            => $details,
                ];

                file_put_contents(
                    __DIR__ . DIRECTORY_SEPARATOR . 'last-success.json',
                    json_encode($save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                logLine('');
                logLine('╔══════════════════════════════════════╗');
                logLine('║   ✅  INSTANCIA CREADA CON ÉXITO     ║');
                logLine('╚══════════════════════════════════════╝');
                logLine("AD: {$ad} | {$profile['label']} | Intento #{$attempt} | Ciclo #{$cycle}");
                logLine('Detalles guardados en last-success.json');
                echo json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                exit(0);

            } catch (ApiCallException $e) {
                $msg = $e->getMessage();
                logLine('Respuesta Oracle: ' . substr($msg, 0, 200));

                // Error fatal: no reintentar
                if (isAuthOrNotFoundError($msg)) {
                    logLine('❌ Error de autorización o recurso no encontrado. Deteniendo. Revisa OCIDs y policy.');
                    exit(1);
                }

                // Throttle: espera fija larga y reinicia ciclo completo
                if (isThrottleError($msg)) {
                    logLine("⚠️  TooManyRequests. Pausando {$throttleFixedDelay} segundos (15 min) y reiniciando ciclo.");
                    sleepWithCountdown($throttleFixedDelay);
                    break 2; // sale de foreach $ads y foreach $profiles → vuelve al while(true)
                }

                // Sin capacidad: seguir con el siguiente AD
                if (isCapacityError($msg)) {
                    logLine('   Sin capacidad en este AD. Continuando...');
                    continue; // siguiente AD
                }

                // Error desconocido: loguear y seguir con el siguiente AD
                logLine('   Error no reconocido, continuando con siguiente AD.');
                continue;

            } catch (Throwable $e) {
                logLine('Excepción inesperada: ' . $e->getMessage());
                logLine('Continuando con siguiente AD por precaución.');
                continue;
            }
        } // foreach $ads
    } // foreach $profiles

    // Si llegamos aquí, el ciclo completo falló (todos ADs × todos perfiles)
    $delay = pickCycleDelay($baseCycleDelay, $maxCycleDelay, $cycle);

    logLine('');
    logLine("--- CICLO #{$cycle} COMPLETADO SIN ÉXITO ---");
    logLine("Todos los ADs y perfiles devolvieron Out of host capacity.");
    logLine("Esperando {$delay} segundos (~" . round($delay / 60) . " min) antes del ciclo " . ($cycle + 1) . '...');
    sleepWithCountdown($delay);

} // while(true)