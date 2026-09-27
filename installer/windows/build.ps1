$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot 'setup_POS_nuvi.iss'
$candidates = @(
    (Get-Command ISCC.exe -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -First 1),
    (Join-Path ${env:ProgramFiles(x86)} 'Inno Setup 6\ISCC.exe'),
    (Join-Path $env:ProgramFiles 'Inno Setup 6\ISCC.exe')
) | Where-Object { $_ -and (Test-Path $_) }

if (!$candidates) {
    throw 'Instala Inno Setup 6 para compilar setup_POS_nuvi.exe.'
}

& $candidates[0] $script
if ($LASTEXITCODE -ne 0) {
    throw 'Falló la compilación con Inno Setup.'
}