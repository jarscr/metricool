<?php

require __DIR__ . '/../bootstrap.php';

use Metricool\API\MetricoolAPI;
use Metricool\Config\Config;
use Metricool\Config\Database;
use Metricool\Services\SyncService;
use Metricool\Utils\Logger;

function printUsage(): void
{
    echo "Uso: php console/sync.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]" . PHP_EOL;
    echo PHP_EOL;
    echo "Opciones:" . PHP_EOL;
    echo "  --start   Fecha inicial (ej. 2026-05-01)" . PHP_EOL;
    echo "  --end     Fecha final   (ej. 2026-05-31)" . PHP_EOL;
    echo PHP_EOL;
    echo "Sin fechas usa SYNC_DAYS del .env (default: últimos 365 días)." . PHP_EOL;
    echo "También puedes definir SYNC_START_DATE y SYNC_END_DATE en .env." . PHP_EOL;
}

try {
    $options = getopt('', ['start:', 'end:', 'help']);

    if (isset($options['help'])) {
        printUsage();
        exit(0);
    }

    $pdo = Database::getConnection();
    $api = new MetricoolAPI();
    $logger = new Logger();
    $sync = new SyncService($pdo, $api, $logger);

    $start = $options['start'] ?? Config::get('SYNC_START_DATE');
    $end = $options['end'] ?? Config::get('SYNC_END_DATE');

    if (($start && !$end) || (!$start && $end)) {
        fwrite(STDERR, "Error: debes indicar --start y --end juntos." . PHP_EOL);
        exit(1);
    }

    if ($start && $end) {
        $sync->setDateRange($start, $end);
    }

    $sync->syncAll();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
