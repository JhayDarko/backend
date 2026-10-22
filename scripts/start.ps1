$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

function Step($message) { Write-Host "==> $message" -ForegroundColor Cyan }
function Require-Command($name, $message) {
    if (-not (Get-Command $name -ErrorAction SilentlyContinue)) { throw $message }
}
function Invoke-Checked($command, [string[]]$arguments, $failureMessage) {
    & $command @arguments
    if ($LASTEXITCODE -ne 0) { throw $failureMessage }
}

Require-Command docker 'Docker no esta instalado o no esta en el PATH.'
Require-Command php 'PHP no esta instalado o no esta en el PATH.'
Require-Command composer 'Composer no esta instalado o no esta en el PATH.'
Require-Command npm 'Node.js y npm son necesarios para iniciar Angular.'
if (-not (Test-Path frontend\package.json)) { throw 'No se encontro frontend\package.json. Verifica que el frontend Angular este instalado.' }

docker info *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Desktop no esta en ejecucion. Inicialo e intenta de nuevo.' }

$createdEnv = -not (Test-Path .env)
if ($createdEnv) {
    Step 'Creando .env'
    Copy-Item .env.example .env
}

if (-not (Test-Path vendor)) {
    Step 'Instalando dependencias PHP'
    Invoke-Checked composer @('install') 'Fallo la instalacion de dependencias PHP.'
}
if ($createdEnv) {
    Invoke-Checked php @('artisan', 'key:generate') 'No se pudo generar APP_KEY.'
}
if (-not (Test-Path frontend\node_modules)) {
    Step 'Instalando dependencias de Angular'
    Invoke-Checked npm @('--prefix', 'frontend', 'install', '--legacy-peer-deps') 'Fallo la instalacion de dependencias de Angular.'
}

# --wait bloquea hasta que PostgreSQL, Redis, BERT y PaddleOCR estan saludables.
Step 'Levantando Docker (PostgreSQL, Redis, BERT y PaddleOCR); la primera vez se descargan los modelos'
Invoke-Checked docker @('compose', 'up', '-d', '--wait') 'Los contenedores no quedaron saludables. Revisa: docker compose logs'

Step 'Aplicando migraciones'
Invoke-Checked php @('artisan', 'migrate', '--force') 'Fallaron las migraciones.'

Step 'Iniciando API, workers de cola y frontend Angular. Abre http://localhost:4200 (Ctrl+C para detener)'
php artisan dev
if ($LASTEXITCODE -ne 0) { throw 'No se pudo iniciar el entorno de desarrollo. Revisa los mensajes de Laravel, workers y Angular.' }
