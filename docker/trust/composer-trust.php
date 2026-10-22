<?php

// Prints the CA file Composer selects, after refusing a Composer release
// below 2.10.3. Composer falls back to its own bundled store when no system
// file is usable, so the caller also requires the system path.

$version = (string) shell_exec('COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-ansi 2>/dev/null');
if (preg_match('/Composer version (\d+\.\d+\.\d+)/', $version, $match) !== 1
    || version_compare($match[1], '2.10.3', '<')) {
    fwrite(STDERR, 'Composer 2.10.3 or later is required; found '.trim($version).PHP_EOL);
    exit(1);
}
// The binary has no .phar extension; load it under its own alias.
Phar::loadPhar(trim((string) shell_exec('command -v composer')), 'composer.phar');
require 'phar://composer.phar/vendor/autoload.php';
echo Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(), PHP_EOL;
