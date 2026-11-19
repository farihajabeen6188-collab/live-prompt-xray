# Windows mirror of the Makefile (no `make` needed): ./dev.ps1 <target>
param(
    [Parameter(Position = 0)][string]$Target = 'help',
    [Parameter(Position = 1, ValueFromRemainingArguments = $true)][string[]]$Rest
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

switch ($Target) {
    'up'       { docker compose up --build -d }
    'down'     { docker compose down }
    'test'     { docker compose exec app sh -c 'cd /srv/app && vendor/bin/phpunit' }
    'stan'     { docker compose exec app sh -c 'cd /srv/app && vendor/bin/phpstan analyse -c /srv/phpstan.neon' }
    'cs'       { docker compose exec app sh -c 'cd /srv/app && vendor/bin/php-cs-fixer fix --dry-run --diff --config=/srv/.php-cs-fixer.dist.php' }
    'shell'    { docker compose exec app sh }
    'console'  { docker compose exec app php /srv/app/bin/console @Rest }
    'composer' { docker compose exec -w /srv/app app composer @Rest }
    'logs'     { docker compose logs -f app }
    default    { Write-Host 'Targets: up, down, test, stan, cs, shell, console <args>, composer <args>, logs' }
}
exit $LASTEXITCODE
