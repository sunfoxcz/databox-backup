#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\BackupService;
use App\Config;
use TomasKulhanek\CzechDataBox\Connector;
use TomasKulhanek\CzechDataBox\Provider\GuzzleClientProvider;
use TomasKulhanek\Serializer\SerializerFactory;

require __DIR__ . '/../vendor/autoload.php';

$configPath = __DIR__ . '/../config.neon';
$dataDir = __DIR__ . '/../data';

try {
    $config = Config::load($configPath);
} catch (\RuntimeException $e) {
    echo "Configuration error: " . $e->getMessage() . "\n";
    echo "Copy config.neon.dist to config.neon and fill in your credentials.\n";
    exit(1);
}

$serializer = SerializerFactory::create();
$provider = GuzzleClientProvider::create();
$connector = new Connector($serializer, $provider);

$service = new BackupService($connector, $dataDir);

$exitCode = 0;
foreach ($config->getDataboxes() as $databox) {
    try {
        $service->backup($databox, $config->isProduction());
    } catch (\Throwable $e) {
        echo sprintf("\nFATAL ERROR for databox %s: %s\n", $databox['id'], $e->getMessage());
        $exitCode = 1;
    }
}

echo "\nBackup complete.\n";
exit($exitCode);
