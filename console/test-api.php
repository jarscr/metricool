<?php

require __DIR__ . '/../bootstrap.php';

use Metricool\API\MetricoolAPI;
use Metricool\Config\Config;

try {
    Config::validateMetricoolCredentials();

    $api = new MetricoolAPI();
    $brandsRaw = $api->getBrands();
    $brands = \Metricool\Services\DataProcessor::unwrapList($brandsRaw);

    echo "=== Test API Metricool (endpoints actuales) ===" . PHP_EOL;
    echo 'User ID: ' . Config::require('METRICOOL_USER_ID') . PHP_EOL;
    echo 'Timezone: ' . Config::get('METRICOOL_TIMEZONE', 'America/Costa_Rica') . PHP_EOL;
    echo 'Marcas encontradas: ' . count($brands) . PHP_EOL . PHP_EOL;

    if ($brands === []) {
        echo "No se encontraron marcas." . PHP_EOL;
        exit(0);
    }

    foreach ($brands as $brand) {
        if (!is_array($brand)) {
            continue;
        }
        $normalized = \Metricool\Services\DataProcessor::normalizeBrand($brand);
        if ($normalized === null) {
            continue;
        }
        echo "ID: {$normalized['id']} - Nombre: {$normalized['name']}" . PHP_EOL;
    }

    $first = \Metricool\Services\DataProcessor::normalizeBrand($brands[0]);
    if ($first === null) {
        exit(0);
    }

    $api->setBlogId($first['id']);
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-7 days'));

    echo PHP_EOL . "=== Marca de prueba: {$first['name']} ({$startDate} a {$endDate}) ===" . PHP_EOL;

    $instagram = \Metricool\Services\DataProcessor::unwrapList($api->getInstagramPosts($startDate, $endDate));
    echo 'Posts Instagram (/stats/instagram/posts): ' . count($instagram) . PHP_EOL;

    $connectedAnalytics = MetricoolAPI::resolveSupportedSyncAnalyticsNetworks($brands[0]);
    foreach (['instagram', 'facebook', 'tiktok'] as $network) {
        if (!in_array($network, $connectedAnalytics, true)) {
            echo "Seguidores {$network}: red no conectada" . PHP_EOL;
            continue;
        }

        try {
            $analytics = $api->getAnalytics($network, $startDate, $endDate, 'followers');
            $rows = \Metricool\Services\DataProcessor::mergeTimelineMetrics(
                ['followers' => $analytics],
                $first['id'],
                $network
            );
            $lastRow = $rows === [] ? null : end($rows);
            $latest = is_array($lastRow) ? (int) ($lastRow['followers'] ?? 0) : 0;

            if ($latest === 0) {
                $aggregation = $api->getAnalyticsAggregation(
                    $network,
                    $startDate,
                    $endDate,
                    'followers'
                );
                $latest = (int) ($aggregation['data'] ?? 0);
            }

            echo "Seguidores {$network} (Analytics v2): {$latest}" . PHP_EOL;
        } catch (Throwable $e) {
            echo "Seguidores {$network}: error - {$e->getMessage()}" . PHP_EOL;
        }
    }

    $scheduled = \Metricool\Services\DataProcessor::unwrapList($api->getScheduledPosts($startDate, $endDate));
    echo 'Programados (/v2/scheduler/posts): ' . count($scheduled) . PHP_EOL;

    $competitors = \Metricool\Services\DataProcessor::unwrapList(
        $api->getCompetitors('facebook', $startDate, $endDate, 5)
    );
    echo 'Competidores Facebook (/v2/analytics/competitors/facebook): ' . count($competitors) . PHP_EOL;

    echo PHP_EOL . '✓ API respondió correctamente' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
