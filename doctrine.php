<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM;

$dbParams = [
    'driver'   => 'pdo_pgsql',
    'host'     => 'lolo-postgres',
    'user'     => 'lolo',
    'password' => 'lolo',
    'dbname'   => 'lolo',
];

$config = ORM\Tools\Setup::createAnnotationMetadataConfiguration([], true, null, null, false);
$connection = DriverManager::getConnection($dbParams, $config);

return new EntityManager($connection, $config);
