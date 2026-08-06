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
    echo "Sin fechas ejecuta el modo cron incremental: ayer y hoy." . PHP_EOL;
    echo "El cálculo usa METRICOOL_TIMEZONE (default: America/Costa_Rica)." . PHP_EOL;
    echo "Para recuperar períodos anteriores debes indicar --start y --end." . PHP_EOL;
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

    $start = $options['start'] ?? null;
    $end = $options['end'] ?? null;

    if (($start && !$end) || (!$start && $end)) {
        fwrite(STDERR, "Error: debes indicar --start y --end juntos." . PHP_EOL);
        exit(1);
    }

    if (!$start && !$end) {
        $timezoneName = Config::get('METRICOOL_TIMEZONE', 'America/Costa_Rica') ?? 'America/Costa_Rica';
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone('America/Costa_Rica');
        }

        $today = new DateTimeImmutable('today', $timezone);
        $start = $today->modify('-1 day')->format('Y-m-d');
        $end = $today->format('Y-m-d');
    }

    $sync->setDateRange($start, $end);

    $sync->syncAll();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
