# Tareas programadas de Windows para el worker y el respaldo diario.
# Ejecutar UNA VEZ, en PowerShell como Administrador, desde la carpeta del proyecto:
#     powershell -ExecutionPolicy Bypass -File deploy\windows-tareas.ps1 -Php "C:\php\php.exe" -Proyecto "C:\sitios\api-sunat" -ClaveRespaldo "una-frase-larga"
# Windows NO es la plataforma recomendada para produccion (ver docs/DESPLIEGUE.md); esto es para quien deba usarlo.
# Para quitar las tareas:   Unregister-ScheduledTask -TaskName "API SUNAT worker" -Confirm:$false  (y la de respaldo)
param(
    [Parameter(Mandatory = $true)][string]$Php,
    [Parameter(Mandatory = $true)][string]$Proyecto,
    [string]$ClaveRespaldo = ""
)
$ErrorActionPreference = "Stop"
if (-not (Test-Path $Php)) { throw "No existe $Php" }
if (-not (Test-Path "$Proyecto\bin\worker.php")) { throw "No se encontro bin\worker.php en $Proyecto" }

# 1) Worker: arranca con el equipo, sin sesion iniciada, y se reinicia solo si se cae.
$accion = New-ScheduledTaskAction -Execute $Php -Argument "bin\worker.php" -WorkingDirectory $Proyecto
$disparador = New-ScheduledTaskTrigger -AtStartup
$config = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName "API SUNAT worker" -Action $accion -Trigger $disparador -Settings $config -User "SYSTEM" -RunLevel Highest -Force | Out-Null
Start-ScheduledTask -TaskName "API SUNAT worker"
Write-Host "Worker instalado e iniciado. Compruebe en el panel del operador que diga 'Worker activo'."

# 2) Respaldo diario a las 02:30 (conserva los ultimos 14)
$arg = "bin\respaldo.php crear --retener=14"
if ($ClaveRespaldo -ne "") { $arg += " --clave=`"$ClaveRespaldo`"" }
$accion2 = New-ScheduledTaskAction -Execute $Php -Argument $arg -WorkingDirectory $Proyecto
$disparador2 = New-ScheduledTaskTrigger -Daily -At "02:30"
Register-ScheduledTask -TaskName "API SUNAT respaldo" -Action $accion2 -Trigger $disparador2 -User "SYSTEM" -RunLevel Highest -Force | Out-Null
Write-Host "Respaldo diario programado (02:30). Copie la carpeta de respaldos FUERA de este equipo."
