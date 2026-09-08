<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

// This API does not accept form bodies. Avoid Symfony's eager PUT/PATCH form
// parsing before the bounded body middleware gets to inspect the request.
$request = new Request($_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER);
$response = $kernel->handle($request)->send();

$kernel->terminate($request, $response);
