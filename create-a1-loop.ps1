# =============================================
# SCRIPT SUPREMO A1 Flex 2026 - Auto-diagnóstico + Rotación ADs
# =============================================

$env:SUPPRESS_LABEL_WARNING = "True"

$compartmentId = "ocid1.tenancy.oc1..aaaaaaaa5f7bf7uu4pqlzpfezxx2b4gn4d5ntpcfpvgexohel6ypp5uf6z3q"
$subnetId      = "ocid1.subnet.oc1.phx.aaaaaaaajkxwcvxqasnfaot7gvjlreaamhqkhbx2l2wtvrshcrqmsgvp24aa"
$imageId       = "ocid1.image.oc1.phx.aaaaaaaavfbkczxpy4zopkqswucpfx7tv7x5xyjvppsp4l5tjkwv5kctr3dq"
$sshKeyFile    = "C:\Users\Isra\oci-arm-host-capacity\ssh-public-key.txt"
$shapeConfigFile = "C:\Users\Isra\oci-arm-host-capacity\shape-config.json"

# Rotación automática de los 3 ADs
$ads = @("Pjmv:PHX-AD-1", "Pjmv:PHX-AD-2", "Pjmv:PHX-AD-3")
$adIndex = 0

Write-Host "🚀 SCRIPT SUPREMO A1 Flex iniciado" -ForegroundColor Green
Write-Host "Prueba los 3 ADs en rotación | Auto-diagnóstico activado" -ForegroundColor Yellow
Write-Host "NO se cerrará solo. Deja esta ventana abierta.`n" -ForegroundColor Cyan

$attempt = 0

while ($true) {
    $attempt++
    $currentAD = $ads[$adIndex]
    $timestamp = Get-Date -Format "HH:mm:ss"

    Write-Host "`n[$timestamp] Intento #$attempt | AD: $currentAD" -ForegroundColor Cyan

    try {
        $result = oci compute instance launch `
            --compartment-id $compartmentId `
            --availability-domain $currentAD `
            --shape "VM.Standard.A1.Flex" `
            --shape-config file://$shapeConfigFile `
            --image-id $imageId `
            --subnet-id $subnetId `
            --ssh-authorized-keys-file $sshKeyFile `
            --boot-volume-size-in-gbs 50 `
            --assign-public-ip false `
            --display-name "MinecraftA1" `
            --debug `
            2>&1 | Out-String

        Write-Host "Salida completa:" -ForegroundColor Yellow
        Write-Host $result

        if ($result -match "Out of host capacity" -or $result -match "Capacity") {
            Write-Host "⏳ Sin capacidad en este AD. Probando siguiente..." -ForegroundColor Gray
        }
        elseif ($result -match "ocid1.instance") {
            Write-Host "`n🎉 ¡ÉXITO! Instancia creada. Revisa la consola de Oracle." -ForegroundColor Green
            break
        }
        elseif ($result -match "NotAuthorizedOrNotFound") {
            Write-Host "`n❌ ERROR: NotAuthorizedOrNotFound (404)" -ForegroundColor Red
            Write-Host "Causa más común: Oracle-Tags en la subnet." -ForegroundColor Red
            Write-Host "Solución inmediata:" -ForegroundColor Red
            Write-Host "1. Ve a Identity & Security → Policies" -ForegroundColor Red
            Write-Host "2. Crea policy nueva:" -ForegroundColor Red
            Write-Host "   Name: OracleTagsPolicy" -ForegroundColor Red
            Write-Host "   Statement: Allow group Administrators to use tag-namespaces in tenancy where target.tag-namespace.name = 'oracle-tags'" -ForegroundColor Red
            Write-Host "3. Guarda y vuelve a ejecutar este script." -ForegroundColor Red
            Start-Sleep -Seconds 10
        }
        else {
            Write-Host "⚠️ Error desconocido. Reintentando..." -ForegroundColor Red
        }
    }
    catch {
        Write-Host "❌ Excepción en PowerShell: $($_.Exception.Message)" -ForegroundColor Red
    }

    # Rotar al siguiente AD
    $adIndex = ($adIndex + 1) % 3

    Start-Sleep -Seconds 30
}

Write-Host "`nScript finalizado." -ForegroundColor Green