param(
    [Parameter(Mandatory = $true)]
    [string]$AppRoot
)

$ErrorActionPreference = 'Stop'
$xamppRoot = if ($env:NUVI_XAMPP_ROOT) { $env:NUVI_XAMPP_ROOT } else { 'C:\xampp' }
$php = Join-Path $xamppRoot 'php\php.exe'
$mysql = Join-Path $xamppRoot 'mysql\bin\mysql.exe'
$optionFile = Join-Path $env:TEMP ("nuvi-mysql-" + [guid]::NewGuid().ToString('N') + '.ini')
$rootPassword = ''

function ConvertFrom-SecureStringPlain([Security.SecureString]$Value) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

function Invoke-NuviPhp([string]$Script) {
    & $php (Join-Path $AppRoot "tools\$Script")
    if ($LASTEXITCODE -ne 0) {
        throw "Falló el paso $Script. Revisa el mensaje anterior."
    }
}

try {
    if (!(Test-Path $php) -or !(Test-Path $mysql)) {
        throw 'Instala XAMPP en C:\xampp (o define NUVI_XAMPP_ROOT) e inicia MySQL antes de continuar.'
    }
    if (Test-Path (Join-Path $AppRoot 'config\local.php')) {
        throw 'Ya existe config\local.php. No se modificó la instalación existente.'
    }

    $adminName = Read-Host 'Nombre del administrador'
    $adminLastName = Read-Host 'Apellidos del administrador'
    $adminUser = Read-Host 'Usuario administrador [admin]'
    if (!$adminUser) { $adminUser = 'admin' }
    $adminSecure = Read-Host 'Contraseña del administrador (mínimo 12 caracteres)' -AsSecureString
    $adminPassword = ConvertFrom-SecureStringPlain $adminSecure
    $confirmSecure = Read-Host 'Confirma la contraseña' -AsSecureString
    $adminConfirm = ConvertFrom-SecureStringPlain $confirmSecure
    $adminPasswordBytes = [Text.Encoding]::UTF8.GetByteCount($adminPassword)
    if (!$adminName -or !$adminLastName -or $adminName.Length -gt 50 -or $adminLastName.Length -gt 50 -or
        $adminUser -notmatch '^[A-Za-z0-9._-]{3,30}$' -or $adminPassword -ne $adminConfirm -or
        $adminPasswordBytes -lt 12 -or $adminPasswordBytes -gt 72) {
        throw 'Revisa los nombres, el usuario y la contraseña (12-72 bytes). No se creó la base.'
    }

    $secureRoot = Read-Host 'Contraseña root de MySQL en XAMPP (Enter si sigue vacía)' -AsSecureString
    $rootPassword = ConvertFrom-SecureStringPlain $secureRoot
    $optionLines = @('[client]', 'user=root', 'host=127.0.0.1', 'protocol=tcp')
    if ($rootPassword) {
        $escapedRoot = $rootPassword.Replace('\', '\\').Replace('"', '\"')
        $optionLines += "password=`"$escapedRoot`""
    }
    Set-Content -Path $optionFile -Value $optionLines -Encoding ASCII

    $database = 'tienda_nuvi'
    $suffix = [guid]::NewGuid().ToString('N').Substring(0, 8)
    $dbUser = "nuvi_$suffix"
    $randomBytes = New-Object byte[] 24
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($randomBytes)
    $dbPassword = [BitConverter]::ToString($randomBytes).Replace('-', '').ToLowerInvariant()

    $existing = & $mysql "--defaults-extra-file=$optionFile" --batch --skip-column-names --execute "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$database'"
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo conectar a MariaDB. Confirma que MySQL esté iniciado en XAMPP y revisa la contraseña root.'
    }
    if (($existing | Out-String).Trim() -ne '0') {
        throw "La base $database ya existe; no se modificó."
    }

    $sql = @(
        "CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        "CREATE USER '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPassword';"
        "GRANT ALL PRIVILEGES ON ``$database``.* TO '$dbUser'@'127.0.0.1';"
    ) -join "`n"
    $processInfo = New-Object System.Diagnostics.ProcessStartInfo
    $processInfo.FileName = $mysql
    $processInfo.Arguments = "--defaults-extra-file=`"$optionFile`""
    $processInfo.UseShellExecute = $false
    $processInfo.RedirectStandardInput = $true
    $processInfo.RedirectStandardOutput = $true
    $processInfo.RedirectStandardError = $true
    $process = [System.Diagnostics.Process]::Start($processInfo)
    $process.StandardInput.WriteLine($sql)
    $process.StandardInput.Close()
    $mysqlOutput = $process.StandardOutput.ReadToEnd()
    $mysqlError = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) {
        Write-Host $mysqlOutput
        Write-Host $mysqlError
        throw 'No se pudo preparar la base de datos de Nuvi.'
    }

    $env:NUVI_SETUP_DB_HOST = '127.0.0.1'
    $env:NUVI_SETUP_DB_USER = $dbUser
    $env:NUVI_SETUP_DB_PASSWORD = $dbPassword
    $env:NUVI_SETUP_DB_NAME = $database
    Invoke-NuviPhp 'crear_config_inicial.php'
    Invoke-NuviPhp 'importar_esquema_inicial.php'

    $env:NUVI_ADMIN_NOMBRE = $adminName
    $env:NUVI_ADMIN_APELLIDOS = $adminLastName
    $env:NUVI_ADMIN_USUARIO = $adminUser
    $env:NUVI_ADMIN_PASSWORD = $adminPassword
    Invoke-NuviPhp 'crear_admin_inicial.php'
    Invoke-NuviPhp 'ejecutar_migraciones_instalacion.php'

    Write-Host "Instalación terminada. Abre http://localhost/tienda-nuvi/login.php"
    Write-Host "Usuario administrador: $adminUser"
    Start-Process 'http://localhost/tienda-nuvi/login.php'
} catch {
    Write-Error $_
    exit 1
} finally {
    foreach ($name in @('NUVI_SETUP_DB_HOST', 'NUVI_SETUP_DB_USER', 'NUVI_SETUP_DB_PASSWORD', 'NUVI_SETUP_DB_NAME', 'NUVI_ADMIN_NOMBRE', 'NUVI_ADMIN_APELLIDOS', 'NUVI_ADMIN_USUARIO', 'NUVI_ADMIN_PASSWORD')) {
        Remove-Item "Env:$name" -ErrorAction SilentlyContinue
    }
    Remove-Item $optionFile -Force -ErrorAction SilentlyContinue
    $rootPassword = $null
    $dbPassword = $null
    $adminPassword = $null
    $adminConfirm = $null
}