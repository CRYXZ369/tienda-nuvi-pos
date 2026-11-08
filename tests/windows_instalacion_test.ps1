$ErrorActionPreference = 'Stop'
if (!$env:GITHUB_ACTIONS -or $env:RUNNER_OS -ne 'Windows' -or (Test-Path 'C:\xampp')) {
    throw 'La prueba requiere un runner Windows aislado sin XAMPP existente.'
}

$instalador = Join-Path $env:RUNNER_TEMP 'xampp-prueba.exe'
$url = 'https://sourceforge.net/projects/xampp/files/XAMPP%20Windows/8.3.36/xampp-windows-x64-8.3.36-0-VC16-installer.exe/download'
$hashEsperado = 'dd54667c0c379cc137a92889857bd809c24a619f18eacb09c7376ef914391bbf'
Invoke-WebRequest -Uri $url -OutFile $instalador
if ((Get-FileHash -Algorithm SHA256 $instalador).Hash -ne $hashEsperado) {
    throw 'La descarga de XAMPP no coincide con la versión verificada.'
}
$setup = Start-Process -FilePath $instalador -ArgumentList '--mode', 'unattended', '--unattendedmodeui', 'none' -PassThru
if (!$setup.WaitForExit(360000)) {
    Stop-Process -Id $setup.Id -Force
    throw 'La instalación de XAMPP excedió seis minutos.'
}
if ($setup.ExitCode -ne 0 -or !(Test-Path 'C:\xampp\php\php.exe') -or !(Test-Path 'C:\xampp\mysql\bin\mysqld.exe')) {
    throw "No se instaló XAMPP correctamente (código $($setup.ExitCode))."
}

$mysql = Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' `
    -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini', '--console' -PassThru
try {
    $listo = $false
    for ($i = 0; $i -lt 30; $i++) {
        & 'C:\xampp\mysql\bin\mysqladmin.exe' '--host=127.0.0.1' '--user=root' ping 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $listo = $true; break }
        Start-Sleep -Seconds 1
    }
    if (!$listo) { throw 'La base de XAMPP no inició en el runner.' }

    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
    $info.Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$PWD\installer\windows\install_windows.ps1`" -AppRoot `"$PWD`""
    $info.UseShellExecute = $false
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $proceso = [System.Diagnostics.Process]::Start($info)
    foreach ($linea in @('Prueba', 'Ficticia', 'adminprueba', 'PruebaWindows123!', 'PruebaWindows123!', '')) {
        $proceso.StandardInput.WriteLine($linea)
    }
    $proceso.StandardInput.Close()
    $salida = $proceso.StandardOutput.ReadToEndAsync()
    $errores = $proceso.StandardError.ReadToEndAsync()
    if (!$proceso.WaitForExit(120000)) {
        $proceso.Kill()
        throw 'El script de configuración no terminó en dos minutos.'
    }
    Write-Host $salida.Result
    if ($proceso.ExitCode -ne 0) {
        Write-Host $errores.Result
        throw "Falló el script de configuración ($($proceso.ExitCode))."
    }
    if (!(Test-Path (Join-Path $PWD 'config\local.php'))) {
        throw 'La configuración de base de datos no fue creada.'
    }
    $resultado = & 'C:\xampp\mysql\bin\mysql.exe' '--host=127.0.0.1' '--user=root' '--batch' '--skip-column-names' `
        '--execute=SELECT COUNT(*) FROM tienda_nuvi.usuarios WHERE usuario = ''adminprueba'' AND rol = ''admin'''
    if ($LASTEXITCODE -ne 0 -or ($resultado | Out-String).Trim() -ne '1') {
        throw 'No se encontró el administrador ficticio en la base inicial.'
    }
    Write-Host 'OK: configuración Windows, esquema, migraciones y administrador ficticio.'
} finally {
    if (!$mysql.HasExited) { Stop-Process -Id $mysql.Id -Force }
}
