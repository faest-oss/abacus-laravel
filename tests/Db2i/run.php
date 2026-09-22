<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
$harness = __DIR__;
$harnessVendor = $root.'/.db2i/vendor';

require $root.'/vendor/autoload.php';

$environmentFile = $root.'/.env.db2i';

if (! is_file($environmentFile)) {
    fwrite(STDERR, "Missing .env.db2i. Copy .env.db2i.example and add local test credentials.\n");
    exit(1);
}

Dotenv::createUnsafeMutable($root, '.env.db2i')->load();

$required = [
    'DB2I_HOST',
    'DB2I_DATABASE',
    'DB2I_USERNAME',
    'DB2I_PASSWORD',
    'DB2I_SCHEMA',
];

$missing = array_values(array_filter(
    $required,
    static fn (string $key): bool => trim((string) getenv($key)) === '',
));

if ($missing !== []) {
    fwrite(STDERR, 'Missing required DB2 for i settings: '.implode(', ', $missing)."\n");
    exit(1);
}

if (filter_var(getenv('DB2I_ALLOW_DESTRUCTIVE_TESTS'), FILTER_VALIDATE_BOOL) !== true) {
    fwrite(
        STDERR,
        "DB2I_ALLOW_DESTRUCTIVE_TESTS must be true. The selected schema must be dedicated to testing.\n",
    );
    exit(1);
}

if (! extension_loaded('pdo_odbc') || ! in_array('odbc', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "The pdo_odbc PHP extension is required.\n");
    exit(1);
}

$driverManifest = realpath($harness.'/../../../db2-driver/composer.json');

if ($driverManifest === false) {
    fwrite(STDERR, "The DB2 driver checkout was not found at ../db2-driver.\n");
    exit(1);
}

$composerBinary = (string) ($_SERVER['COMPOSER_BINARY'] ?? getenv('COMPOSER_BINARY') ?: 'composer');
$composerCommand = str_ends_with($composerBinary, '.phar')
    ? [PHP_BINARY, $composerBinary]
    : [$composerBinary];

$install = new Process([
    ...$composerCommand,
    'install',
    '--working-dir='.$harness,
    '--no-interaction',
    '--no-progress',
    '--prefer-dist',
]);
$install->setTimeout(null);
$installExitCode = $install->run(static function (string $type, string $output): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
});

if ($installExitCode !== 0) {
    exit($installExitCode);
}

putenv('DB_CONNECTION=db2');
putenv('DB_CONN=db2');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'db2';
$_ENV['DB_CONN'] = $_SERVER['DB_CONN'] = 'db2';

$arguments = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $argument): bool => $argument !== '--',
));

$pest = new Process([
    PHP_BINARY,
    $harnessVendor.'/bin/pest',
    '--configuration='.$harness.'/phpunit.xml',
    '--test-directory=../tests',
    ...$arguments,
], $root);
$pest->setTimeout(null);

exit($pest->run(static function (string $type, string $output): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
}));
