<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__, 2) . '/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
}

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
