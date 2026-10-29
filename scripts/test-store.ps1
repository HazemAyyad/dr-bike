param(
    [string[]] $TestPaths = @('tests/Unit/OnlineStore', 'tests/Feature/OnlineStore'),
    [string] $Filter = '',
    [string] $ExcludeGroup = '',
    [switch] $ReuseSchema,
    [string] $Php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
)
$ErrorActionPreference = 'Stop'
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_DATABASE = 'doctor_bike_store_hardening_test'
$env:DB_USERNAME = 'root'
$env:DB_PASSWORD = ''
$env:ONLINE_STORE_DISPOSABLE_DB = 'true'
$env:ONLINE_STORE_DISPOSABLE_DB_NAME = 'doctor_bike_store_hardening_test'
$env:CACHE_DRIVER = 'array'
$env:SESSION_DRIVER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:ONLINE_STORE_PREMIGRATED_SCHEMA_READY = if ($ReuseSchema) { 'true' } else { 'false' }
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    $testOptions = @('--no-coverage')
    if ($Filter -ne '') { $testOptions += @('--filter', $Filter) }
    if ($ExcludeGroup -ne '') { $testOptions += @('--exclude-group', $ExcludeGroup) }
    & $Php vendor/phpunit/phpunit/phpunit @testOptions @TestPaths
    $testExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $testExitCode
