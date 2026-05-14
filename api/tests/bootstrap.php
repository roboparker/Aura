<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

if (true === ($_SERVER['APP_DEBUG'] ?? false) || '1' === ($_SERVER['APP_DEBUG'] ?? null)) {
    umask(0000);
}
