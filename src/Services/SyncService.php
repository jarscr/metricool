<?php

namespace Metricool\Services;

use Metricool\API\MetricoolAPI;
use Metricool\Config\Config;
use Metricool\Utils\Logger;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Exception;

class SyncService
{
    /** @var list<string> */
    private const ACTIVE_SCHEDULED_NETWORKS = ['instagram', 'facebook', 'tiktok'];

    private PDO $pdo;
    private MetricoolAPI $api;
    private Logger $logger;
    private int $syncDays;
    private int $throttleMs;
    private DateTimeZone $metricoolTimezone;
    private ?string $startDateOverride = null;
    private ?string $endDateOverride = null;

    public function __construct(PDO $pdo, MetricoolAPI $api, ?Logger $logger = null)
    {
        $this->pdo = $pdo;
        $this->api = $api;
        $this->logger = $logger ?? new Logger(Config::get('SYNC_LOG_DIR'));
        $this->syncDays = Config::getInt('SYNC_DAYS', 365);
        $this->throttleMs = Config::getInt('SYNC_THROTTLE_MS', 300);
        $this->metricoolTimezone = $this->resolveMetricoolTimezone();
    }

    public function setDateRange(string $startDate, string $endDate): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new \InvalidArgumentException('Las fechas deben tener formato YYYY-MM-DD');
        }

        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('start_date no puede ser posterior a end_date');
        }

        $this->startDateOverride = $startDate;
        $this->endDateOverride = $endDate;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(): array
    {
        if ($this->startDateOverride !== null && $this->endDateOverride !== null) {
            return [$this->startDateOverride, $this->endDateOverride];
        }

        $envStart = Config::get('SYNC_START_DATE');
        $envEnd = Config::get('SYNC_END_DATE');
        if ($envStart && $envEnd) {
            return [$envStart, $envEnd];
        }

        return [
            (new DateTimeImmutable('now', $this->metricoolTimezone))
                ->modify('-' . $this->syncDays . ' days')
                ->format('Y-m-d'),
            (new DateTimeImmutable('now', $this->metricoolTimezone))->format('Y-m-d'),
        ];
    }

    public function syncAll(): void
    {
        $startTime = time();
        $failedBrands = 0;
        $this->logger->info('=== Iniciando sincronización completa ===');

        try {
            Config::validateMetricoolCredentials();

            [$rangeStart, $rangeEnd] = $this->resolveDateRange();
            $this->logger->info("Período de sync: {$rangeStart} a {$rangeEnd}");

            $brandsRaw = $this->api->getBrands();
            $brands = DataProcessor::unwrapList($brandsRaw);
            $this->logger->info('Encontradas ' . count($brands) . ' marcas');

            if ($brands === []) {
                $this->logger->warn('No se encontraron marcas para sincronizar');
            }

            foreach ($brands as $brandRaw) {
                if (!is_array($brandRaw)) {
                    continue;
                }

                $brand = DataProcessor::normalizeBrand($brandRaw);
                if ($brand === null) {
                    $this->logger->warn('Marca ignorada por datos incompletos');
                    continue;
                }

                if (!$this->syncBrand($brand, $brandRaw)) {
                    $failedBrands++;
                }
            }

            if ($failedBrands > 0) {
                throw new \RuntimeException("{$failedBrands} marca(s) finalizaron con errores parciales o totales");
            }

            $duration = time() - $startTime;
            $this->logger->success("=== Sincronización completada en {$duration} segundos ===");
        } catch (Exception $e) {
            $this->logger->error('Error en sincronización: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->logger->flush();
        }
    }

    /**
     * @param array{id: int, name: string} $brand
     * @param array<string, mixed> $brandRaw
     */
    private function syncBrand(array $brand, array $brandRaw): bool
    {
        $brandId = $brand['id'];
        $brandName = $brand['name'];
        $startTime = time();
        $totalRecords = 0;
        $syncErrors = [];

        $this->logger->info("--- Sincronizando marca: {$brandName} (ID: {$brandId}) ---");

        try {
            $this->saveBrand($brand);
            $this->api->setBlogId($brandId);

            [$startDate, $endDate] = $this->resolveDateRange();

            $connectedPostNetworks = MetricoolAPI::resolveConnectedPostNetworks($brandRaw);
            $networkList = $connectedPostNetworks === [] ? 'ninguna' : implode(', ', $connectedPostNetworks);
            $this->logger->info("  Redes conectadas: {$networkList}");
            foreach ($connectedPostNetworks as $network) {
                try {
                    $count = $this->syncNetworkPosts($network, $brandId, $startDate, $endDate);
                    $totalRecords += $count;
                    $this->logger->info("  ✓ {$network}: {$count} posts sincronizados");
                } catch (Exception $e) {
                    $this->logger->warn("  ✗ {$network}: " . $e->getMessage());
                    $syncErrors[] = "{$network}: {$e->getMessage()}";
                }
                $this->throttle();
            }

            try {
                $rollupCount = $this->rebuildDailyAnalyticsFromPosts($brandId, $startDate, $endDate);
                $totalRecords += $rollupCount;
                $this->logger->info("  ✓ analytics desde posts: {$rollupCount} días");
            } catch (Exception $e) {
                $this->logger->warn('  ✗ analytics desde posts: ' . $e->getMessage());
                $syncErrors[] = 'analytics desde posts: ' . $e->getMessage();
            }

            $analyticsNetworks = MetricoolAPI::resolveSupportedSyncAnalyticsNetworks($brandRaw);
            foreach ($analyticsNetworks as $network) {
                try {
                    $count = $this->syncNetworkAnalytics($network, $brandId, $startDate, $endDate);
                    $totalRecords += $count;
                    $this->logger->info("  ✓ analytics {$network}: {$count} registros");
                } catch (Exception $e) {
                    $this->logger->warn("  ✗ analytics {$network}: " . $e->getMessage());
                    $syncErrors[] = "analytics {$network}: {$e->getMessage()}";
                }
                $this->throttle();
            }

            try {
                $scheduledCount = $this->syncScheduledPosts($brandId, $startDate, $endDate);
                $totalRecords += $scheduledCount;
                $this->logger->info("  ✓ Posts programados: {$scheduledCount}");
            } catch (Exception $e) {
                $this->logger->warn('  ✗ Posts programados: ' . $e->getMessage());
                $syncErrors[] = 'posts programados: ' . $e->getMessage();
            }
            $this->throttle();

            $duration = time() - $startTime;
            $status = $syncErrors === [] ? 'completed' : 'partial';
            $errorMessage = $syncErrors === [] ? null : implode(' | ', $syncErrors);
            $this->saveSyncHistory($brandId, 'full_sync', $status, $errorMessage, $totalRecords, $duration, $startDate, $endDate);

            return $syncErrors === [];
        } catch (Exception $e) {
            $this->logger->error("Error sincronizando marca {$brandName}: " . $e->getMessage());
            [$errorStart, $errorEnd] = $this->resolveDateRange();
            $this->saveSyncHistory(
                $brandId,
                'full_sync',
                'error',
                $e->getMessage(),
                $totalRecords,
                time() - $startTime,
                $errorStart,
                $errorEnd
            );

            return false;
        }
    }

    /**
     * @param array{id: int, name: string} $brand
     */
    private function saveBrand(array $brand): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO brands (id, name) VALUES (:id, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        $stmt->execute([
            'id' => $brand['id'],
            'name' => $brand['name'],
        ]);
    }

    private function syncNetworkPosts(string $network, int $brandId, string $startDate, string $endDate): int
    {
        $posts = match ($network) {
            'instagram' => array_merge(
                DataProcessor::unwrapList($this->api->getInstagramPosts($startDate, $endDate)),
                DataProcessor::unwrapList($this->api->getInstagramReels($startDate, $endDate))
            ),
            'facebook' => DataProcessor::unwrapList($this->api->getFacebookPosts($startDate, $endDate)),
            'tiktok' => DataProcessor::unwrapList($this->api->getTikTokVideos($startDate, $endDate)),
            'x' => DataProcessor::unwrapList($this->api->getXPosts($startDate, $endDate)),
            'linkedin' => DataProcessor::unwrapList($this->api->getLinkedInPosts($startDate, $endDate)),
            default => [],
        };
        $saved = 0;
        $failed = 0;
        $firstError = null;

        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $normalized = null;
            try {
                $normalized = match ($network) {
                    'instagram' => DataProcessor::normalizeInstagramPost($post, $brandId),
                    'facebook' => DataProcessor::normalizeFacebookPost($post, $brandId),
                    'tiktok' => DataProcessor::normalizeTikTokPost($post, $brandId),
                    'x' => DataProcessor::normalizeXPost($post, $brandId),
                    'linkedin' => DataProcessor::normalizeLinkedInPost($post, $brandId),
                    default => null,
                };

                if ($normalized === null) {
                    $this->logger->warn("  Post {$network} sin ID, omitido");
                    continue;
                }

                $this->upsertNetworkPost($network, $normalized);
                $saved++;
            } catch (\Throwable $e) {
                $failed++;
                $firstError ??= $e->getMessage();
                $rawPostId = $normalized['post_id'] ?? $post['id'] ?? $post['postId'] ?? $post['videoId'] ?? null;
                $postId = is_scalar($rawPostId) ? (string) $rawPostId : 'sin-id';
                $this->logger->warn("  Post {$network} {$postId} omitido: " . $e->getMessage());
            }
        }

        if ($failed > 0) {
            throw new \RuntimeException(
                "{$failed} publicación(es) no pudieron guardarse. Primer error: {$firstError}"
            );
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertNetworkPost(string $network, array $row): void
    {
        switch ($network) {
            case 'instagram':
                $stmt = $this->pdo->prepare(
                    'INSERT INTO instagram_posts (
                        post_id, brand_id, caption, likes, comments, shares, saves,
                        reach, impressions, engagement_rate, post_type, post_url,
                        media_url, published_date
                    ) VALUES (
                        :post_id, :brand_id, :caption, :likes, :comments, :shares, :saves,
                        :reach, :impressions, :engagement_rate, :post_type, :post_url,
                        :media_url, :published_date
                    )
                    ON DUPLICATE KEY UPDATE
                        likes = VALUES(likes),
                        comments = VALUES(comments),
                        shares = VALUES(shares),
                        saves = VALUES(saves),
                        reach = VALUES(reach),
                        impressions = VALUES(impressions),
                        engagement_rate = VALUES(engagement_rate)'
                );
                break;

            case 'facebook':
                $stmt = $this->pdo->prepare(
                    'INSERT INTO facebook_posts (
                        post_id, brand_id, message, likes, comments, shares, reactions,
                        reach, impressions, engagement_rate, post_type, post_url,
                        website_clicks, ad_spend, published_date
                    ) VALUES (
                        :post_id, :brand_id, :message, :likes, :comments, :shares, :reactions,
                        :reach, :impressions, :engagement_rate, :post_type, :post_url,
                        :website_clicks, :ad_spend, :published_date
                    )
                    ON DUPLICATE KEY UPDATE
                        likes = VALUES(likes),
                        comments = VALUES(comments),
                        shares = VALUES(shares),
                        reactions = VALUES(reactions),
                        reach = VALUES(reach),
                        impressions = VALUES(impressions),
                        engagement_rate = VALUES(engagement_rate),
                        website_clicks = VALUES(website_clicks),
                        ad_spend = VALUES(ad_spend)'
                );
                break;

            case 'tiktok':
                $stmt = $this->pdo->prepare(
                    'INSERT INTO tiktok_posts (
                        post_id, brand_id, description, likes, comments, shares, views,
                        engagement_rate, post_type, post_url, media_url, duration_seconds,
                        published_date
                    ) VALUES (
                        :post_id, :brand_id, :description, :likes, :comments, :shares, :views,
                        :engagement_rate, :post_type, :post_url, :media_url, :duration_seconds,
                        :published_date
                    )
                    ON DUPLICATE KEY UPDATE
                        description = VALUES(description),
                        likes = VALUES(likes),
                        comments = VALUES(comments),
                        shares = VALUES(shares),
                        views = VALUES(views),
                        engagement_rate = VALUES(engagement_rate),
                        post_url = VALUES(post_url),
                        media_url = VALUES(media_url),
                        duration_seconds = VALUES(duration_seconds)'
                );
                break;

            case 'x':
                $stmt = $this->pdo->prepare(
                    'INSERT INTO x_posts (
                        post_id, brand_id, text, likes, retweets, replies, quotes,
                        impressions, engagement_rate, post_url, published_date
                    ) VALUES (
                        :post_id, :brand_id, :text, :likes, :retweets, :replies, :quotes,
                        :impressions, :engagement_rate, :post_url, :published_date
                    )
                    ON DUPLICATE KEY UPDATE
                        likes = VALUES(likes),
                        retweets = VALUES(retweets),
                        replies = VALUES(replies),
                        quotes = VALUES(quotes),
                        impressions = VALUES(impressions),
                        engagement_rate = VALUES(engagement_rate)'
                );
                break;

            case 'linkedin':
                $stmt = $this->pdo->prepare(
                    'INSERT INTO linkedin_posts (
                        post_id, brand_id, content, likes, comments, shares,
                        impressions, engagement_rate, post_url, published_date
                    ) VALUES (
                        :post_id, :brand_id, :content, :likes, :comments, :shares,
                        :impressions, :engagement_rate, :post_url, :published_date
                    )
                    ON DUPLICATE KEY UPDATE
                        likes = VALUES(likes),
                        comments = VALUES(comments),
                        shares = VALUES(shares),
                        impressions = VALUES(impressions),
                        engagement_rate = VALUES(engagement_rate)'
                );
                break;

            default:
                return;
        }

        $stmt->execute($row);
    }

    private function syncNetworkAnalytics(string $network, int $brandId, string $startDate, string $endDate): int
    {
        if (MetricoolAPI::isAdsAnalyticsNetwork($network)) {
            return $this->syncAdsAnalytics($network, $brandId, $startDate, $endDate);
        }

        $metrics = MetricoolAPI::ORGANIC_ANALYTICS_METRICS;

        $seriesByMetric = [];
        foreach ($metrics as $metric) {
            try {
                $seriesByMetric[$metric] = $this->api->getAnalytics($network, $startDate, $endDate, $metric);
            } catch (Exception $e) {
                $this->logger->warn("    métrica {$network}/{$metric}: " . $e->getMessage());
            }
        }

        $rows = DataProcessor::mergeTimelineMetrics($seriesByMetric, $brandId, $network);
        if ($rows === []) {
            try {
                $aggregation = $this->api->getAnalyticsAggregation(
                    $network,
                    $startDate,
                    $endDate,
                    'followers'
                );
                $followers = (int) ($aggregation['data'] ?? 0);
                if ($followers > 0) {
                    $rows[] = $this->emptyAnalyticsRow($brandId, $network, $endDate, [
                        'followers' => $followers,
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->warn("    agregación {$network}/followers: " . $e->getMessage());
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO daily_analytics (
                brand_id, network, date, followers, following, posts_count,
                likes, comments, shares, reach, impressions, engagement_rate,
                profile_views, website_clicks, ad_spend
            ) VALUES (
                :brand_id, :network, :date, :followers, :following, :posts_count,
                :likes, :comments, :shares, :reach, :impressions, :engagement_rate,
                :profile_views, :website_clicks, :ad_spend
            )
            ON DUPLICATE KEY UPDATE
                followers = CASE
                    WHEN VALUES(followers) > 0 THEN VALUES(followers)
                    ELSE followers
                END'
        );

        $saved = 0;
        foreach ($rows as $row) {
            try {
                $stmt->execute($row);
                $saved++;
            } catch (\Throwable $e) {
                $rowDate = is_scalar($row['date'] ?? null) ? (string) $row['date'] : 'sin-fecha';
                $this->logger->warn("    analytics {$network}/{$rowDate}: " . $e->getMessage());
                continue;
            }
        }

        return $saved;
    }

    private function syncAdsAnalytics(
        string $network,
        int $brandId,
        string $startDate,
        string $endDate
    ): int {
        $response = $this->api->getAdsCampaigns($network, $startDate, $endDate);
        $campaigns = DataProcessor::unwrapList($response);

        $impressions = 0;
        $clicks = 0;
        $reach = 0;
        foreach ($campaigns as $campaign) {
            if (!is_array($campaign)) {
                continue;
            }

            $impressions += (int) ($campaign['impressions'] ?? 0);
            $clicks += (int) ($campaign['clicks'] ?? 0);
            $reach += (int) ($campaign['reach'] ?? $campaign['impressionsReach'] ?? 0);
        }

        if ($impressions === 0 && $clicks === 0 && $reach === 0) {
            return 0;
        }

        $row = $this->emptyAnalyticsRow($brandId, $network, $endDate, [
            'impressions' => $impressions,
            'website_clicks' => $clicks,
            'reach' => $reach,
        ]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO daily_analytics (
                brand_id, network, date, followers, following, posts_count,
                likes, comments, shares, reach, impressions, engagement_rate,
                profile_views, website_clicks, ad_spend
            ) VALUES (
                :brand_id, :network, :date, :followers, :following, :posts_count,
                :likes, :comments, :shares, :reach, :impressions, :engagement_rate,
                :profile_views, :website_clicks, :ad_spend
            )
            ON DUPLICATE KEY UPDATE
                reach = VALUES(reach),
                impressions = VALUES(impressions),
                website_clicks = VALUES(website_clicks),
                ad_spend = 0'
        );
        $stmt->execute($row);

        return 1;
    }

    /**
     * @param array<string, int|float> $values
     * @return array<string, int|float|string>
     */
    private function emptyAnalyticsRow(int $brandId, string $network, string $date, array $values = []): array
    {
        return array_replace([
            'brand_id' => $brandId,
            'network' => $network,
            'date' => $date,
            'followers' => 0,
            'following' => 0,
            'posts_count' => 0,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'reach' => 0,
            'impressions' => 0,
            'engagement_rate' => 0.0,
            'profile_views' => 0,
            'website_clicks' => 0,
            'ad_spend' => 0.0,
        ], $values);
    }

    private function rebuildDailyAnalyticsFromPosts(int $brandId, string $startDate, string $endDate): int
    {
        $startDateTime = $startDate . ' 00:00:00';
        $endDateTime = $endDate . ' 23:59:59';
        $saved = 0;

        $definitions = [
            'instagram' => [
                'table' => 'instagram_posts',
                'comments' => 'comments',
                'shares' => 'COALESCE(SUM(shares), 0)',
                'reach' => 'COALESCE(SUM(reach), 0)',
                'impressions' => 'COALESCE(SUM(NULLIF(impressions, 0)), SUM(reach), 0)',
                'clicks' => '0',
                'spend' => '0',
            ],
            'facebook' => [
                'table' => 'facebook_posts',
                'comments' => 'comments',
                'shares' => 'COALESCE(SUM(shares), 0)',
                'reach' => 'COALESCE(SUM(reach), 0)',
                'impressions' => 'COALESCE(SUM(impressions), 0)',
                'clicks' => 'COALESCE(SUM(website_clicks), 0)',
                'spend' => 'COALESCE(SUM(ad_spend), 0)',
            ],
            'tiktok' => [
                'table' => 'tiktok_posts',
                'comments' => 'comments',
                'shares' => 'COALESCE(SUM(shares), 0)',
                'reach' => '0',
                'impressions' => 'COALESCE(SUM(views), 0)',
                'clicks' => '0',
                'spend' => '0',
            ],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO daily_analytics (
                brand_id, network, date, followers, following, posts_count,
                likes, comments, shares, reach, impressions, engagement_rate,
                profile_views, website_clicks, ad_spend
            ) VALUES (
                :brand_id, :network, :date, 0, 0, :posts_count,
                :likes, :comments, :shares, :reach, :impressions, :engagement_rate,
                0, :website_clicks, :ad_spend
            )
            ON DUPLICATE KEY UPDATE
                posts_count = VALUES(posts_count),
                likes = VALUES(likes),
                comments = VALUES(comments),
                shares = VALUES(shares),
                reach = VALUES(reach),
                impressions = VALUES(impressions),
                engagement_rate = VALUES(engagement_rate),
                website_clicks = VALUES(website_clicks),
                ad_spend = VALUES(ad_spend)'
        );

        foreach ($definitions as $network => $config) {
            $query = $this->pdo->prepare(
                "SELECT
                    DATE(published_date) AS day,
                    COUNT(*) AS posts_count,
                    COALESCE(SUM(likes), 0) AS likes,
                    COALESCE(SUM({$config['comments']}), 0) AS comments,
                    {$config['shares']} AS shares,
                    {$config['reach']} AS reach,
                    {$config['impressions']} AS impressions,
                    COALESCE(AVG(engagement_rate), 0) AS engagement_rate,
                    {$config['clicks']} AS website_clicks,
                    {$config['spend']} AS ad_spend
                 FROM {$config['table']}
                 WHERE brand_id = :brand_id
                 AND published_date BETWEEN :start AND :end
                 GROUP BY DATE(published_date)"
            );

            $query->execute([
                'brand_id' => $brandId,
                'start' => $startDateTime,
                'end' => $endDateTime,
            ]);

            foreach ($query->fetchAll() as $row) {
                $stmt->execute([
                    'brand_id' => $brandId,
                    'network' => $network,
                    'date' => $row['day'],
                    'posts_count' => (int) $row['posts_count'],
                    'likes' => (int) $row['likes'],
                    'comments' => (int) $row['comments'],
                    'shares' => (int) $row['shares'],
                    'reach' => (int) $row['reach'],
                    'impressions' => (int) $row['impressions'],
                    'engagement_rate' => round((float) $row['engagement_rate'], 2),
                    'website_clicks' => (int) $row['website_clicks'],
                    'ad_spend' => round((float) $row['ad_spend'], 2),
                ]);
                $saved++;
            }
        }

        return $saved;
    }

    private function syncScheduledPosts(int $brandId, string $startDate, string $endDate): int
    {
        $response = $this->api->getScheduledPosts($startDate, $endDate);
        $posts = DataProcessor::unwrapList($response);

        $this->pdo->prepare(
            "UPDATE scheduled_posts SET status = 'cancelled'
             WHERE brand_id = :brand_id AND status = 'pending'"
        )->execute(['brand_id' => $brandId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_posts (
                post_id, brand_id, network, content, media_url, scheduled_date, status
            ) VALUES (
                :post_id, :brand_id, :network, :content, :media_url, :scheduled_date, :status
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                content = VALUES(content),
                scheduled_date = VALUES(scheduled_date),
                network = VALUES(network)'
        );

        $saved = 0;
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $normalized = DataProcessor::normalizeScheduledPost($post, $brandId);
            if ($normalized === null) {
                continue;
            }

            if (!in_array($normalized['network'], self::ACTIVE_SCHEDULED_NETWORKS, true)) {
                continue;
            }

            if ($normalized['status'] !== 'pending') {
                $normalized['status'] = 'pending';
            }

            try {
                $stmt->execute($normalized);
                $saved++;
            } catch (\Throwable $e) {
                $postId = is_scalar($normalized['post_id'] ?? null) ? (string) $normalized['post_id'] : 'sin-id';
                $this->logger->warn("    programado {$postId}: " . $e->getMessage());
            }
        }

        return $saved;
    }

    private function syncCompetitors(int $brandId, string $startDate, string $endDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO competitors (
                brand_id, network, competitor_name, competitor_handle, competitor_url,
                followers, posts_count, last_synced
            ) VALUES (
                :brand_id, :network, :competitor_name, :competitor_handle, :competitor_url,
                :followers, :posts_count, :last_synced
            )
            ON DUPLICATE KEY UPDATE
                competitor_name = VALUES(competitor_name),
                competitor_url = VALUES(competitor_url),
                followers = VALUES(followers),
                posts_count = VALUES(posts_count),
                last_synced = VALUES(last_synced)'
        );

        $saved = 0;

        foreach (MetricoolAPI::COMPETITOR_NETWORKS as $network) {
            try {
                $response = $this->api->getCompetitors($network, $startDate, $endDate);
                $items = DataProcessor::unwrapList($response);

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $normalized = DataProcessor::normalizeCompetitor($item, $brandId, $network);
                    if ($normalized === null) {
                        continue;
                    }

                    if (empty($normalized['competitor_handle'])) {
                        $normalized['competitor_handle'] = 'id_' . ($item['id'] ?? md5($normalized['competitor_name']));
                    }

                    $stmt->execute($normalized);
                    $saved++;
                }

                if (count($items) > 0) {
                    $this->logger->info('    competidores ' . $network . ': ' . count($items));
                }
            } catch (Exception $e) {
                $this->logger->warn('    competidores ' . $network . ': ' . $e->getMessage());
            }

            $this->throttle();
        }

        return $saved;
    }

    private function saveSyncHistory(
        int $brandId,
        string $syncType,
        string $status,
        ?string $errorMsg,
        int $records,
        int $duration,
        string $startDate,
        string $endDate
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_history (
                brand_id, sync_type, start_date, end_date, records_processed,
                status, error_message, duration_seconds
            ) VALUES (
                :brand_id, :sync_type, :start_date, :end_date, :records_processed,
                :status, :error_message, :duration_seconds
            )'
        );

        $stmt->execute([
            'brand_id' => $brandId,
            'sync_type' => $syncType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'records_processed' => $records,
            'status' => $status,
            'error_message' => $errorMsg,
            'duration_seconds' => $duration,
        ]);
    }

    private function throttle(): void
    {
        if ($this->throttleMs > 0) {
            usleep($this->throttleMs * 1000);
        }
    }

    private function resolveMetricoolTimezone(): DateTimeZone
    {
        $timezoneName = Config::get('METRICOOL_TIMEZONE', 'America/Costa_Rica') ?? 'America/Costa_Rica';

        try {
            return new DateTimeZone($timezoneName);
        } catch (\Exception) {
            return new DateTimeZone('America/Costa_Rica');
        }
    }
}
