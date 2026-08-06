<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Metricool\API\MetricoolAPI;
use Metricool\Config\Config;
use Metricool\Services\DataProcessor;
use Metricool\Services\DataService;

function assertSameStrict(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function assertTrueStrict(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectException(callable $callback, string $expectedClass, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException($message . "\nUnexpected exception: " . $throwable::class . ' ' . $throwable->getMessage());
    }

    throw new RuntimeException($message . "\nExpected exception {$expectedClass} was not thrown.");
}

function createDataServiceFixture(): DataService
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $schema = [
        'CREATE TABLE brands (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
        'CREATE TABLE instagram_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id TEXT NOT NULL,
            brand_id INTEGER NOT NULL,
            caption TEXT,
            likes INTEGER DEFAULT 0,
            comments INTEGER DEFAULT 0,
            shares INTEGER DEFAULT 0,
            saves INTEGER DEFAULT 0,
            reach INTEGER DEFAULT 0,
            impressions INTEGER DEFAULT 0,
            engagement_rate REAL DEFAULT 0,
            post_type TEXT,
            post_url TEXT,
            media_url TEXT,
            published_date TEXT
        )',
        'CREATE TABLE facebook_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id TEXT NOT NULL,
            brand_id INTEGER NOT NULL,
            message TEXT,
            likes INTEGER DEFAULT 0,
            comments INTEGER DEFAULT 0,
            shares INTEGER DEFAULT 0,
            reactions INTEGER DEFAULT 0,
            reach INTEGER DEFAULT 0,
            impressions INTEGER DEFAULT 0,
            engagement_rate REAL DEFAULT 0,
            post_type TEXT,
            post_url TEXT,
            website_clicks INTEGER DEFAULT 0,
            ad_spend REAL DEFAULT 0,
            published_date TEXT
        )',
        'CREATE TABLE tiktok_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id TEXT NOT NULL,
            brand_id INTEGER NOT NULL,
            description TEXT,
            likes INTEGER DEFAULT 0,
            comments INTEGER DEFAULT 0,
            shares INTEGER DEFAULT 0,
            views INTEGER DEFAULT 0,
            engagement_rate REAL DEFAULT 0,
            post_type TEXT,
            post_url TEXT,
            media_url TEXT,
            duration_seconds INTEGER DEFAULT 0,
            published_date TEXT
        )',
        'CREATE TABLE daily_analytics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_id INTEGER NOT NULL,
            network TEXT NOT NULL,
            date TEXT NOT NULL,
            followers INTEGER DEFAULT 0,
            following INTEGER DEFAULT 0,
            posts_count INTEGER DEFAULT 0,
            likes INTEGER DEFAULT 0,
            comments INTEGER DEFAULT 0,
            shares INTEGER DEFAULT 0,
            reach INTEGER DEFAULT 0,
            impressions INTEGER DEFAULT 0,
            engagement_rate REAL DEFAULT 0,
            profile_views INTEGER DEFAULT 0,
            website_clicks INTEGER DEFAULT 0,
            ad_spend REAL DEFAULT 0
        )',
        'CREATE TABLE scheduled_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id TEXT,
            brand_id INTEGER NOT NULL,
            network TEXT NOT NULL,
            content TEXT,
            media_url TEXT,
            scheduled_date TEXT NOT NULL,
            status TEXT DEFAULT \'pending\'
        )',
        'CREATE TABLE sync_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_id INTEGER NOT NULL,
            sync_type TEXT NOT NULL,
            start_date TEXT,
            end_date TEXT,
            records_processed INTEGER DEFAULT 0,
            status TEXT DEFAULT \'completed\',
            error_message TEXT,
            duration_seconds INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )',
    ];

    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO brands (id, name) VALUES (1, 'Marca Demo')");
    $pdo->exec("INSERT INTO instagram_posts (post_id, brand_id, caption, likes, comments, shares, saves, reach, impressions, engagement_rate, post_type, post_url, media_url, published_date)
        VALUES ('ig-1', 1, 'Hola IG', 10, 2, 1, 1, 100, 150, 12.5, 'post', 'https://ig.example/post', 'https://ig.example/media', '2026-08-01 12:00:00')");
    $pdo->exec("INSERT INTO facebook_posts (post_id, brand_id, message, likes, comments, shares, reactions, reach, impressions, engagement_rate, post_type, post_url, website_clicks, ad_spend, published_date)
        VALUES ('fb-1', 1, 'Hola FB', 8, 1, 2, 8, 80, 120, 10.2, 'post', 'https://fb.example/post', 4, 0, '2026-08-02 12:00:00')");
    $pdo->exec("INSERT INTO tiktok_posts (post_id, brand_id, description, likes, comments, shares, views, engagement_rate, post_type, post_url, media_url, duration_seconds, published_date)
        VALUES ('tt-1', 1, 'Hola TikTok', 12, 3, 2, 600, 2.83, 'VIDEO', 'https://tiktok.example/video', 'https://tiktok.example/cover', 18, '2026-08-03 12:00:00')");
    $pdo->exec("INSERT INTO daily_analytics (brand_id, network, date, followers, reach, impressions, website_clicks)
        VALUES
        (1, 'instagram', '2026-08-01', 1200, 100, 150, 0),
        (1, 'instagram', '2026-08-04', 1100, 90, 140, 0),
        (1, 'facebook', '2026-08-02', 2400, 80, 120, 4),
        (1, 'tiktok', '2026-08-02', 500, 0, 1000, 20),
        (1, 'facebookads', '2026-08-03', 0, 8000, 12000, 350),
        (1, 'googleads', '2026-08-03', 0, 4500, 9000, 210)");

    $pdo->exec("INSERT INTO brands (id, name) VALUES (2, 'Solo Facebook')");
    $pdo->exec("INSERT INTO facebook_posts (post_id, brand_id, message, likes, comments, shares, reactions, reach, impressions, engagement_rate, post_type, post_url, website_clicks, ad_spend, published_date)
        VALUES ('fb-only', 2, 'Solo Facebook', 3, 1, 0, 3, 30, 45, 8.0, 'post', 'https://fb.example/only', 0, 0, '2026-08-02 09:00:00')");
    $pdo->exec("INSERT INTO daily_analytics (brand_id, network, date, followers, impressions)
        VALUES (2, 'facebook', '2026-08-02', 700, 45)");
    $pdo->exec("INSERT INTO scheduled_posts (post_id, brand_id, network, content, media_url, scheduled_date, status)
        VALUES ('sched-1', 1, 'instagram', 'Pendiente', 'https://cdn.example/media', '2026-08-03 10:00:00', 'pending')");
    $pdo->exec("INSERT INTO sync_history (brand_id, sync_type, start_date, end_date, records_processed, status, created_at)
        VALUES (1, 'full_sync', '2026-08-01', '2026-08-02', 3, 'completed', '2026-08-03 11:00:00')");

    return new DataService($pdo);
}

function testStatsDateParams(): void
{
    assertSameStrict(
        ['start' => '20260801', 'end' => '20260805'],
        MetricoolAPI::statsDateParams('2026-08-01', '20260805'),
        'statsDateParams debe normalizar YYYY-MM-DD a YYYYMMDD'
    );

    expectException(
        static fn () => MetricoolAPI::statsDateParams('2026/08/01', '20260805'),
        Exception::class,
        'statsDateParams debe rechazar formatos inválidos'
    );

    expectException(
        static fn () => MetricoolAPI::statsDateParams('20260806', '20260805'),
        Exception::class,
        'statsDateParams debe rechazar rangos invertidos'
    );

    assertSameStrict(
        ['metric' => 'followers', 'subject' => 'account'],
        MetricoolAPI::resolveAnalyticsMetricSpec('instagram', 'followers'),
        'Instagram debe consultar seguidores de cuenta en Analytics v2'
    );
    assertSameStrict(
        ['metric' => 'pageFollows', 'subject' => 'account'],
        MetricoolAPI::resolveAnalyticsMetricSpec('facebook', 'followers'),
        'Facebook debe consultar pageFollows'
    );
    assertSameStrict(
        ['metric' => 'followers_count', 'subject' => 'account'],
        MetricoolAPI::resolveAnalyticsMetricSpec('tiktok', 'followers'),
        'TikTok debe consultar followers_count'
    );
}

function testSupportedAnalyticsNetworks(): void
{
    $brand = [
        'instagram' => ['id' => 111],
        'tiktok' => ['id' => 123],
        'facebookAds' => ['id' => 456],
        'adwords' => ['id' => 654],
        'linkedinCompany' => ['id' => 789],
    ];

    assertSameStrict(
        ['instagram', 'tiktok', 'facebookads', 'googleads'],
        MetricoolAPI::resolveSupportedSyncAnalyticsNetworks($brand),
        'Solo deben sincronizarse perfiles y cuentas publicitarias soportadas que estén conectadas'
    );
}

function testConnectedPostNetworks(): void
{
    assertSameStrict(
        ['instagram', 'facebook'],
        MetricoolAPI::resolveConnectedPostNetworks([
            'instagram' => ['id' => 1],
            'facebook' => 'connected',
            'tiktok' => null,
        ]),
        'Solo deben sincronizarse las redes conectadas en la marca'
    );

    assertSameStrict(
        ['tiktok'],
        MetricoolAPI::resolveConnectedPostNetworks([
            'instagram' => false,
            'facebook' => '0',
            'tiktok' => ['openId' => 'abc'],
        ]),
        'TikTok conectado debe incluirse sin consultar redes ausentes'
    );
}

function testDataProcessorNormalization(): void
{
    $longUrl = 'https://example.com/' . str_repeat('a', 600);
    $timezone = new DateTimeZone(Config::get('METRICOOL_TIMEZONE', 'America/Costa_Rica') ?? 'America/Costa_Rica');
    $expectedDate = (new DateTimeImmutable('2026-08-05T03:00:00Z'))
        ->setTimezone($timezone)
        ->format('Y-m-d H:i:s');

    $post = DataProcessor::normalizeInstagramPost([
        'id' => 'ig-post-1',
        'caption' => 'Texto',
        'type' => str_repeat('r', 80),
        'url' => $longUrl,
        'mediaUrl' => ' https://cdn.example/media.jpg ',
        'date' => '2026-08-05T03:00:00Z',
    ], 10);

    assertTrueStrict($post !== null, 'normalizeInstagramPost debe devolver un arreglo válido');
    assertSameStrict(500, strlen((string) $post['post_url']), 'post_url debe truncarse a 500 caracteres');
    assertSameStrict(50, strlen((string) $post['post_type']), 'post_type debe truncarse a 50 caracteres');
    assertSameStrict('https://cdn.example/media.jpg', $post['media_url'], 'media_url debe limpiarse sin romper contenido válido');
    assertSameStrict($expectedDate, $post['published_date'], 'published_date debe normalizarse a METRICOOL_TIMEZONE');

    $tiktok = DataProcessor::normalizeTikTokPost([
        'videoId' => 'video-1',
        'videoDescription' => 'Video TikTok',
        'likeCount' => 10,
        'commentCount' => 2,
        'shareCount' => 3,
        'viewCount' => 100,
        'shareUrl' => 'https://tiktok.example/video-1',
        'coverImageUrl' => 'https://tiktok.example/cover-1',
        'duration' => 20,
        'createTime' => '2026-08-05T03:00:00Z',
    ], 10);

    assertTrueStrict($tiktok !== null, 'normalizeTikTokPost debe aceptar videoId');
    assertSameStrict('video-1', $tiktok['post_id'], 'TikTok debe conservar videoId como post_id');
    assertSameStrict(100, $tiktok['views'], 'TikTok debe conservar viewCount');
    assertSameStrict(15.0, $tiktok['engagement_rate'], 'TikTok debe calcular interacción sobre vistas');

    $timeline = DataProcessor::mergeTimelineMetrics([
        'followers' => [
            'data' => [[
                'metric' => 'followers_count',
                'values' => [
                    ['dateTime' => '2026-08-04T00:00:00-06:00', 'value' => 490],
                    ['dateTime' => '2026-08-05T00:00:00-06:00', 'value' => 500],
                ],
            ]],
        ],
    ], 10, 'tiktok');

    assertSameStrict(2, count($timeline), 'Debe reconocer la respuesta envuelta de Analytics v2');
    assertSameStrict(500, $timeline[1]['followers'], 'Debe guardar el valor de seguidores de Analytics v2');

    $scheduled = DataProcessor::normalizeScheduledPost([
        'id' => 'sched-1',
        'provider' => str_repeat('n', 70),
        'mediaUrl' => $longUrl,
        'status' => str_repeat('p', 80),
        'scheduledDate' => '2026-08-05 10:15:00-04:00',
    ], 10);

    assertTrueStrict($scheduled !== null, 'normalizeScheduledPost debe devolver un arreglo válido');
    assertSameStrict(50, strlen((string) $scheduled['network']), 'network debe truncarse a 50 caracteres');
    assertSameStrict(500, strlen((string) $scheduled['media_url']), 'media_url programado debe truncarse a 500 caracteres');
    assertSameStrict(50, strlen((string) $scheduled['status']), 'status debe truncarse a 50 caracteres');
}

function testDataServiceSupportedNetworks(): void
{
    $service = createDataServiceFixture();

    $posts = $service->getPosts(1, null, '2026-08-01', '2026-08-05');
    assertSameStrict(3, $posts['total'], 'Instagram, Facebook y TikTok deben contarse como redes de posts');

    $tiktokPosts = $service->getPosts(1, 'tiktok', '2026-08-01', '2026-08-05');
    assertSameStrict(1, $tiktokPosts['total'], 'TikTok debe exponerse como red de publicaciones');
    assertSameStrict(600, $tiktokPosts['items'][0]['impressions'], 'Las vistas de TikTok deben exponerse como impresiones');

    expectException(
        static fn () => $service->getPosts(1, 'x', '2026-08-01', '2026-08-05'),
        InvalidArgumentException::class,
        'X no debe aceptarse como red de posts soportada'
    );

    $analytics = $service->getDailyAnalytics(1, 'tiktok', '2026-08-01', '2026-08-05');
    assertSameStrict(1, count($analytics), 'TikTok debe seguir disponible en analytics diarios reconstruidos desde videos');

    $adsAnalytics = $service->getDailyAnalytics(1, 'facebookads', '2026-08-01', '2026-08-05');
    assertSameStrict(1, count($adsAnalytics), 'Meta Ads debe exponerse cuando la marca tiene datos sincronizados');

    $overview = $service->getOverview(1, '2026-08-01', '2026-08-05');
    assertSameStrict(
        ['instagram', 'facebook', 'tiktok', 'facebookads', 'googleads'],
        array_keys($overview['networks']),
        'El overview debe incluir redes orgánicas y Ads que tengan datos'
    );
    assertSameStrict(1100, $overview['networks']['instagram']['followers_latest'], 'Instagram debe exponer el total más reciente, no el máximo histórico');
    assertSameStrict(2400, $overview['networks']['facebook']['followers_latest'], 'Facebook debe exponer su último total de seguidores');
    assertSameStrict(500, $overview['networks']['tiktok']['followers_latest'], 'TikTok debe exponer su último total de seguidores');
    assertSameStrict('ads', $overview['networks']['facebookads']['type'], 'Meta Ads debe identificarse como publicidad');
    assertSameStrict(12000, $overview['networks']['facebookads']['impressions_total'], 'Meta Ads debe sumar impresiones');
    assertTrueStrict(!array_key_exists('ad_spend_total', $overview['networks']['facebookads']), 'El gasto de Meta Ads no debe exponerse al dashboard');
    assertTrueStrict(!array_key_exists('ad_spend_total', $overview['networks']['googleads']), 'El gasto de Google Ads no debe exponerse al dashboard');

    $facebookOnly = $service->getOverview(2, '2026-08-01', '2026-08-05');
    assertSameStrict(
        ['facebook'],
        array_keys($facebookOnly['networks']),
        'Una marca con solo Facebook no debe mostrar redes ni cuentas Ads ausentes'
    );
}

$tests = [
    'statsDateParams' => 'testStatsDateParams',
    'supportedAnalyticsNetworks' => 'testSupportedAnalyticsNetworks',
    'connectedPostNetworks' => 'testConnectedPostNetworks',
    'dataProcessorNormalization' => 'testDataProcessorNormalization',
    'dataServiceSupportedNetworks' => 'testDataServiceSupportedNetworks',
];

foreach ($tests as $name => $test) {
    $test();
    echo "[ok] {$name}\n";
}

echo "All tests passed.\n";
