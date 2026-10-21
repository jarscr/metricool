<?php
// Run against the local Postman simulation; no production API or database writes.
require dirname(__DIR__) . '/bootstrap.php';
use Metricool\API\MetricoolAPI;
use Metricool\Services\DataProcessor;

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$api = new MetricoolAPI('fixture-token', 'fixture-user', '3680316', $argv[1] ?? 'http://127.0.0.1:4900/metricool');
foreach (['facebook', 'instagram', 'tiktok'] as $network) {
    $rows = DataProcessor::mergeTimelineMetrics(['impressions' => $api->getAnalytics($network, '2026-10-01', '2026-10-19', 'impressions')], 3680316, $network);
    check(array_sum(array_column($rows, 'impressions')) === 3000, "Total de cuenta incorrecto: {$network}");
}
$historical = DataProcessor::mergeTimelineMetrics([
    'impressions' => $api->getAnalytics('facebook', '2024-10-01', '2024-10-14', 'impressions'),
], 3680316, 'facebook');
check(array_sum(array_column($historical, 'impressions')) === 3000, 'Debe consultar la métrica histórica para fechas anteriores al cambio');
$posts = DataProcessor::unwrapList($api->getFacebookPosts('2026-10-01', '2026-10-19'));
$reels = DataProcessor::unwrapList($api->getFacebookReels('2026-10-01', '2026-10-19'));
check(count($posts) === 1 && count($reels) === 1, 'Debe recuperar posts y reels separados');
$post = DataProcessor::normalizeFacebookPost($posts[0], 3680316);
$reel = DataProcessor::normalizeFacebookPost($reels[0], 3680316);
check($post['published_date'] === '2026-10-15 10:12:00', 'Debe preservar fecha de publicación, no usar hoy');
check($reel['post_id'] === 'fb-reel' && $reel['published_date'] === '2026-10-12 10:40:00', 'Debe preservar ID y fecha de reel');
check($reel['impressions'] === 206 && $reel['message'] === 'Video octubre', 'Debe normalizar métricas y texto de reel');
$api->setBlogId('csv');
check(count($api->getFacebookReels('2026-10-01', '2026-10-19')) === 1, 'Debe leer CSV documentado');
$api->setBlogId('malformed');
try {
    $api->getFacebookReels('2026-10-01', '2026-10-19');
    throw new RuntimeException('No debe aceptar un CSV desconocido silenciosamente');
} catch (Exception $e) {
    check(str_contains($e->getMessage(), 'reelId'), 'Debe reportar estructura CSV desconocida');
}
echo "[ok] HTTP account timelines, Facebook posts/reels, original dates and CSV validation\n";

// Exercise real persistence code with an isolated SQLite database. Translate only
// MySQL UPSERT syntax; the production MySQL dialect still needs a staging run.
class SyncTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT DO UPDATE SET', $query);
        $query = preg_replace('/VALUES\((\w+)\)/', 'excluded.$1', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = new SyncTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE daily_analytics (
    brand_id INTEGER, network TEXT, date TEXT, followers INTEGER DEFAULT 0,
    following INTEGER, posts_count INTEGER, likes INTEGER, comments INTEGER,
    shares INTEGER, reach INTEGER, impressions INTEGER, engagement_rate REAL,
    profile_views INTEGER, website_clicks INTEGER, ad_spend REAL,
    UNIQUE(brand_id, network, date))');
foreach (['facebook_posts', 'instagram_posts', 'tiktok_posts'] as $table) {
    $pdo->exec("CREATE TABLE {$table} (
        post_id TEXT, brand_id INTEGER, message TEXT, likes INTEGER DEFAULT 0,
        comments INTEGER DEFAULT 0, shares INTEGER DEFAULT 0, reactions INTEGER,
        reach INTEGER DEFAULT 0, impressions INTEGER DEFAULT 0, views INTEGER DEFAULT 0,
        engagement_rate REAL DEFAULT 0, post_type TEXT, post_url TEXT,
        website_clicks INTEGER DEFAULT 0, ad_spend REAL DEFAULT 0, published_date TEXT,
        UNIQUE(post_id, brand_id))");
}
$api->setBlogId('3680316');
$sync = new Metricool\Services\SyncService($pdo, $api);
$syncPosts = new ReflectionMethod($sync, 'syncNetworkPosts');
$syncAnalytics = new ReflectionMethod($sync, 'syncNetworkAnalytics');
$rebuild = new ReflectionMethod($sync, 'rebuildDailyAnalyticsFromPosts');
$pdo->exec("INSERT INTO facebook_posts (post_id,brand_id,published_date) VALUES ('fb-photo',3680316,'2026-10-20 00:00:00')");
check($syncPosts->invoke($sync, 'facebook', 3680316, '2026-10-01', '2026-10-19') === 2, 'Debe guardar posts y reels');
check($pdo->query("SELECT published_date FROM facebook_posts WHERE post_id='fb-photo'")->fetchColumn() === '2026-10-15 10:12:00', 'Debe corregir fechas ya guardadas');
$rebuild->invoke($sync, 3680316, '2026-10-01', '2026-10-19');
$syncAnalytics->invoke($sync, 'facebook', 3680316, '2026-10-01', '2026-10-19');
check((int)$pdo->query('SELECT SUM(impressions) FROM daily_analytics')->fetchColumn() === 3000, 'Debe guardar total de cuenta, no 484 de publicaciones');
$rebuild->invoke($sync, 3680316, '2026-10-01', '2026-10-19');
$syncAnalytics->invoke($sync, 'facebook', 3680316, '2026-10-01', '2026-10-19');
check((int)$pdo->query('SELECT SUM(impressions) FROM daily_analytics')->fetchColumn() === 3000, 'Repetir sync no debe duplicar ni sobrescribir con posts');
check((int)$pdo->query('SELECT COUNT(*) FROM daily_analytics')->fetchColumn() === 2, 'No debe duplicar días');
echo "[ok] Sync persistence, existing date correction, account totals and repeated synchronization\n";
