# ============================================================
#  resize-a1.ps1 — Escala la instancia A1 a 4 OCPU / 24 GB
#  Usa archivo temporal para JSON (evita problemas de comillas
#  en Windows/PowerShell con OCI CLI)
# ============================================================

$env:SUPPRESS_LABEL_WARNING = "True"

$INSTANCE_ID   = "ocid1.instance.oc1.phx.anyhqljrnespdcacizkkqhuzoa6vje4zoqaobrefq2yxqxgntbmzo23o7wla"
$TARGET_OCPUS  = 4
$TARGET_MEMORY = 24
$MAX_RETRIES   = 20
$SLEEP_BASE    = 300
$SLEEP_MAX     = 1800
$TMP_JSON      = "$env:TEMP\oci_shape_config.json"

function Write-Log($msg) {
    Write-Host ("[{0}] {1}" -f (Get-Date -Format "HH:mm:ss"), $msg)
}

function Get-InstanceState {
    $out = & oci compute instance get --instance-id $INSTANCE_ID 2>$null
    if (-not $out) { return "UNKNOWN" }
    try { return ($out | ConvertFrom-Json).data.'lifecycle-state' }
    catch { return "UNKNOWN" }
}

function Get-CurrentShape {
    $out = & oci compute instance get --instance-id $INSTANCE_ID 2>$null
    if (-not $out) { return $null }
    try {
        $cfg = ($out | ConvertFrom-Json).data.'shape-config'
        return @{ ocpus = [int]$cfg.ocpus; memory = [int]$cfg.'memory-in-g-bs' }
    } catch { return $null }
}

# 1. Esperar estado usable
Write-Log "Verificando estado de la instancia..."
$state = Get-InstanceState
Write-Log "Estado: $state"

$waited = 0
while ($state -notin @("RUNNING","STOPPED") -and $waited -lt 600) {
    Write-Log "  Esperando (actual: $state)..."
    Start-Sleep -Seconds 20
    $waited += 20
    $state = Get-InstanceState
}

if ($state -notin @("RUNNING","STOPPED")) {
    Write-Log "ERROR: Estado '$state' no permite resize."
    exit 1
}

# 2. Verificar shape actual
$current = Get-CurrentShape
if ($current) {
    Write-Log ("Shape actual: {0} OCPU / {1} GB" -f $current.ocpus, $current.memory)
    if ($current.ocpus -eq $TARGET_OCPUS -and $current.memory -eq $TARGET_MEMORY) {
        Write-Log "Ya tiene $TARGET_OCPUS OCPU / $TARGET_MEMORY GB. Nada que hacer."
        exit 0
    }
}

# 3. Escribir JSON a archivo temporal (metodo confiable en Windows)
"{`"ocpus`":$TARGET_OCPUS,`"memoryInGBs`":$TARGET_MEMORY}" | `
    Out-File -FilePath $TMP_JSON -Encoding ascii -NoNewline

Write-Log "JSON: $(Get-Content $TMP_JSON -Raw)"
Write-Log "Archivo: $TMP_JSON"

# 4. Resize con reintentos
Write-Log "Iniciando resize a $TARGET_OCPUS OCPU / $TARGET_MEMORY GB..."

for ($attempt = 1; $attempt -le $MAX_RETRIES; $attempt++) {

    Write-Log "Intento #$attempt / $MAX_RETRIES"

    $stdout = & oci compute instance update `
        --instance-id $INSTANCE_ID `
        --shape-config "file://$TMP_JSON" `
        --force 2>&1

    $code      = $LASTEXITCODE
    $resultStr = $stdout | Out-String

    # Exito
    if ($code -eq 0 -and $resultStr -notmatch "Error" -and $resultStr -notmatch "error") {
        Write-Log ""
        Write-Log "╔══════════════════════════════════════╗"
        Write-Log "║   ✅  RESIZE APLICADO CON EXITO      ║"
        Write-Log "╚══════════════════════════════════════╝"
        Write-Log "La instancia se reiniciara para aplicar 4 OCPU / 24 GB."
        Write-Log "Verifica en https://cloud.oracle.com"
        $stdout | Out-File "resize-result.json" -Encoding utf8
        Remove-Item $TMP_JSON -ErrorAction SilentlyContinue
        exit 0
    }

    Write-Log "Respuesta: $resultStr"

    # Sin capacidad
    if ($resultStr -match "Out of host capacity" -or
        ($resultStr -match "InternalError" -and $resultStr -match "capacity")) {
        $exp   = [Math]::Min($SLEEP_MAX, $SLEEP_BASE * [Math]::Pow(2, $attempt - 1))
        $sleep = [int][Math]::Min($SLEEP_MAX, $exp + (Get-Random -Minimum 0 -Maximum 60))
        Write-Log "Sin capacidad. Reintentando en $sleep seg (~$([Math]::Round($sleep/60,1)) min)..."
        Start-Sleep -Seconds $sleep
        continue
    }

    # Limite excedido
    if ($resultStr -match "LimitExceeded") {
        Write-Log "ERROR: LimitExceeded. Ya tienes el maximo de recursos ARM."
        exit 1
    }

    # Instancia ocupada
    if ($resultStr -match "IncorrectState" -or $resultStr -match "Conflict") {
        Write-Log "Instancia en transicion. Esperando 30 seg..."
        Start-Sleep -Seconds 30
        continue
    }

    # Error desconocido
    Write-Log "Error no reconocido. Reintentando en 2 min..."
    Start-Sleep -Seconds 120
}

Write-Log "No fue posible aplicar el resize tras $MAX_RETRIES intentos."
Remove-Item $TMP_JSON -ErrorAction SilentlyContinue
exit 1