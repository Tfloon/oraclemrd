<?php
declare(strict_types=1);
date_default_timezone_set('America/Hermosillo');

require __DIR__ . '/vendor/autoload.php';

use Hitrov\Exception\ApiCallException;
use Hitrov\OciApi;
use Hitrov\OciConfig;

/* =========================
   🔐 CREDENCIALES
========================= */

$region = 'us-phoenix-1';

$userOcid = 'ocid1.user.oc1..aaaaaaaa3ictppiahy5kj42t2wlakb2smmivtbmza3hg2x5hoboz5l45vdka';

$tenancyOcid = 'ocid1.tenancy.oc1..aaaaaaaa5f7bf7uu4pqlzpfezxx2b4gn4d5ntpcfpvgexohel6ypp5uf6z3q';

$fingerprint = '7f:f5:5a:ef:b0:10:99:91:a1:fe:20:83:6c:de:0b:83';

$keyFilename = __DIR__ . '/key_rsa.pem';

/* =========================
   🌐 RECURSOS
========================= */

$availabilityDomain = 'Pjmv:PHX-AD-3';

$subnetId = 'ocid1.subnet.oc1.phx.aaaaaaaajkxwcvxqasnfaot7gvjlreaamhqkhbx2l2wtvrshcrqmsgvp24aa';

$imageId = 'ocid1.image.oc1.phx.aaaaaaaavfbkczxpy4zopkqswucpfx7tv7x5xyjvppsp4l5tjkwv5kctr3dq';

$shape = 'VM.Standard.A1.Flex';

/* =========================
   🔑 SSH KEY
========================= */

$sshPublicKey = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC8Cn8OAp/jHISxcNt+Sj2sz6RIos62++2oye/EdgxKpR1SYVYyaMrsBSYhvlM2H1WjBuYUNJ+qOCNpH+xRJqq+JPZnFRZdyTl+Rr6S37a49kzLxA/eCKB3ijiy9UMrB0MTgjSIw/VhtgYwKkzkEDZiBycsk7zzjtf1uMfijrhccWL7gvh8kSj/f/mi5wlxUWLXULzeDjktR0QUpOQFuvvVpRIq2HYDxqVIJWUVi4wAEkKO7NvgOPp8fKyzwzNTqwMNymEuCjJNJyTHvT221pnxQDSnOyFQ6CBrt06FU5l1ukV+eUOW3k5a8eDZW7Yw1qGuoXeiG5cb0hlygMuRFkkd ssh-key-2026-03-30';

/* =========================
   ⚙️ CONFIG VM
========================= */

$config = new OciConfig(
    $region,
    $userOcid,
    $tenancyOcid,
    $fingerprint,
    $keyFilename,
    $availabilityDomain,
    $subnetId,
    $imageId,
    4,
    24
);

$config->setBootVolumeSizeInGBs('150');

/* =========================
   🔁 LOOP CREACIÓN
========================= */

$api = new OciApi();

echo "Hora: " . date('H:i:s') . " | Iniciando loop...\n\n";

while (true) {

    echo "Hora: " . date('H:i:s') . " | Intento...\n";

    try {

        $instanceDetails = $api->createInstance(
            $config,
            $shape,
            $sshPublicKey,
            $availabilityDomain
        );

        echo "\n🔥 INSTANCIA CREADA 🔥\n";
        echo json_encode($instanceDetails, JSON_PRETTY_PRINT);
        break;

    } catch (ApiCallException $e) {

        $msg = $e->getMessage();

        if (
            strpos($msg, 'Out of host capacity') !== false ||
            strpos($msg, 'InternalError') !== false
        ) {
            echo "[OK] Sin capacidad todavía...\n\n";
        } else {
            echo "\n❌ ERROR REAL:\n$msg\n";
            break;
        }
    }

    sleep(30);
}