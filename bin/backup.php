#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\BackupService;
use App\Config;
use TomasKulhanek\CzechDataBox\Connector;
use TomasKulhanek\CzechDataBox\Provider\GuzzleClientProvider;
use TomasKulhanek\Serializer\SerializerFactory;

require __DIR__ . '/../vendor/autoload.php';

$verbose = in_array('-v', $argv, true);
$configPath = __DIR__ . '/../config.neon';
$dataDir = __DIR__ . '/../data';
$logFile = __DIR__ . '/../data/error.log';

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

$service = new BackupService($connector, $dataDir, $logFile, $verbose);

$exitCode = 0;
foreach ($config->getDataboxes() as $databox) {
    try {
        $service->backup($databox, $config->isProduction());
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("\n\033[1;31mFATAL ERROR for databox %s:\033[0m %s\n", $databox['id'], $e->getMessage()));
        $exitCode = 1;
    }
}

if ($verbose) {
    echo "\nBackup complete.\n";
}
exit($exitCode);
