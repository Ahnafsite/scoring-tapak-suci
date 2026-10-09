<?php

/*
 * Hard stop before PHPUnit boots anything.
 *
 * A cached bootstrap/cache/config.php makes phpunit.xml's <env> overrides
 * inert. The suite then runs against the real MySQL database and RefreshDatabase
 * drops every table in it. Refuse to start at all in that state.
 */

$configCache = __DIR__.'/../bootstrap/cache/config.php';

if (file_exists($configCache)) {
    fwrite(STDERR, PHP_EOL
        .'REFUSING TO RUN TESTS: bootstrap/cache/config.php exists.'.PHP_EOL.PHP_EOL
        .'A cached config overrides phpunit.xml, pointing the suite at the real'.PHP_EOL
        .'MySQL database, where RefreshDatabase would drop every table.'.PHP_EOL.PHP_EOL
        .'Fix: php artisan config:clear'.PHP_EOL.PHP_EOL);

    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
