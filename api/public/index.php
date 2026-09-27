<?php

declare(strict_types=1);

use Keelwatch\Bootstrap;
use Keelwatch\ConfigException;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Support\Logger;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '0');
Bootstrap::convertErrorsToExceptions();

$logger = new Logger('api');

try {
    $config = Bootstrap::config();
} catch (ConfigException $e) {
    // Names of missing/invalid variables only; values are never logged.
    $logger->error('configuration invalid', ['errors' => $e->errors]);
    Response::error(500, 'config_invalid', 'The API is not configured correctly.')->send();
    return;
}

Bootstrap::app($config, $logger)->handle(Request::fromGlobals())->send();
