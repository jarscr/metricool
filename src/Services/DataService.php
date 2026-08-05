<?php

namespace Metricool\Services;

use PDO;

class DataService
{
    /** @var array<string, array{table: string, text: string, network: string}> */
    private const POST_NETWORKS = [
        'instagram' => ['table' => 'instagram_posts', 'text' => 'caption', 'network' => 'instagram'],
        'facebook' => ['table' => 'facebook_posts', 'text' => 'message', 'network' => 'facebook'],
        'tiktok' => ['table' => 'tiktok_posts', 'text' => 'description', 'network' => 'tiktok'],
    ];

    /** @var list<string> */
    private const ANALYTICS_ONLY_NETWORKS = [];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function getBrands(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM brands ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDashboardSummary(?int $brandId = null): array
    {
        if ($brandId !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM dashboard_summary WHERE brand_id = :brand_id');
            $stmt->execute(['brand_id' => $brandId]);
            $row = $stmt->fetch();
            return $row ? [$row] : [];
        }

        return $this->pdo->query('SELECT * FROM dashboard_summary ORDER BY brand_name ASC')->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(int $brandId, string $startDate, string $endDate): array
    {
        $brand = $this->getBrand($brandId);
        if ($brand === null) {
            throw new \InvalidArgumentException('Marca no encontrada');
        }

        $networks = [];
        $totalPosts = 0;
        $totalLikes = 0;

        foreach (self::POST_NETWORKS as $key => $config) {
            $stats = $this->getNetworkStats($key, $brandId, $startDate, $endDate);
            $stats['type'] = 'posts';
            $networks[$key] = $stats;
            $totalPosts += $stats['posts_count'];
            $totalLikes += $stats['likes_total'];
        }

        foreach ($this->getAnalyticsOnlyNetworkStats($brandId, $startDate, $endDate) as $key => $stats) {
            $networks[$key] = $stats;
        }

        $scheduled = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_posts
             WHERE brand_id = :brand_id AND status = 'pending'
             AND scheduled_date BETWEEN :start AND :end"
        );
        $scheduled->execute([
            'brand_id' => $brandId,
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59',
        ]);

        return [
            'brand' => $brand,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'totals' => [
                'posts' => $totalPosts,
                'likes' => $totalLikes,
                'scheduled_pending' => (int) $scheduled->fetchColumn(),
            ],
            'networks' => $networks,
            'last_sync' => $this->getLastSync($brandId),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getPosts(
        int $brandId,
        ?string $network,
        string $startDate,
        string $endDate,
        int $page = 1,
        int $perPage = 20
    ): array {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $networks = $network !== null ? [$network] : array_keys(self::POST_NETWORKS);
        if ($network !== null && !isset(self::POST_NETWORKS[$network])) {
            throw new \InvalidArgumentException('Red social no válida para posts');
        }

        $items = [];
        foreach ($networks as $net) {
            $items = array_merge($items, $this->fetchPostsForNetwork($net, $brandId, $startDate, $endDate));
        }

        usort($items, fn ($a, $b) => strcmp($b['published_date'], $a['published_date']));

        $total = count($items);
        $items = array_slice($items, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTopEngagementPosts(int $brandId, ?string $network, int $limit = 10): array
    {
        $limit = min(50, max(1, $limit));
        $networks = $network !== null ? [$network] : array_keys(self::POST_NETWORKS);

        $items = [];
        foreach ($networks as $net) {
            $config = self::POST_NETWORKS[$net];
            $stmt = $this->pdo->prepare(
                "SELECT *, '{$net}' AS network FROM {$config['table']}
                 WHERE brand_id = :brand_id
                 ORDER BY engagement_rate DESC, likes DESC
                 LIMIT :limit"
            );
            $stmt->bindValue('brand_id', $brandId, PDO::PARAM_INT);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $row) {
                $items[] = $this->normalizePostRow($row, $net);
            }
        }

        usort($items, fn ($a, $b) => $b['engagement_rate'] <=> $a['engagement_rate']);

        return array_slice($items, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDailyAnalytics(
        int $brandId,
        ?string $network,
        string $startDate,
        string $endDate
    ): array {
        $sql = 'SELECT * FROM daily_analytics
                WHERE brand_id = :brand_id
                AND date BETWEEN :start AND :end';
        $params = [
            'brand_id' => $brandId,
            'start' => $startDate,
            'end' => $endDate,
        ];

        if ($network !== null && !self::isSupportedAnalyticsNetwork($network)) {
            throw new \InvalidArgumentException('Red social no válida');
        }

        if ($network !== null) {
            $sql .= ' AND network = :network';
            $params['network'] = $network;
        }

        $sql .= ' ORDER BY date ASC, network ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private static function isSupportedAnalyticsNetwork(string $network): bool
    {
        return isset(self::POST_NETWORKS[$network])
            || in_array($network, self::ANALYTICS_ONLY_NETWORKS, true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAnalyticsOnlyNetworkStats(int $brandId, string $startDate, string $endDate): array
    {
        if (self::ANALYTICS_ONLY_NETWORKS === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count(self::ANALYTICS_ONLY_NETWORKS), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT network,
                COALESCE(SUM(impressions), 0) AS impressions_total,
                COALESCE(SUM(website_clicks), 0) AS clicks_total,
                COALESCE(SUM(ad_spend), 0) AS ad_spend_total,
                COALESCE(MAX(followers), 0) AS followers_latest,
                COALESCE(SUM(reach), 0) AS reach_total
             FROM daily_analytics
             WHERE brand_id = ?
             AND date BETWEEN ? AND ?
             AND network IN ({$placeholders})
             GROUP BY network"
        );

        $params = array_merge([$brandId, $startDate, $endDate], self::ANALYTICS_ONLY_NETWORKS);
        $stmt->execute($params);

        $networks = [];
        foreach ($stmt->fetchAll() as $row) {
            $network = (string) $row['network'];

            $networks[$network] = [
                'type' => 'analytics',
                'posts_count' => 0,
                'likes_total' => 0,
                'comments_total' => 0,
                'impressions_total' => (int) ($row['impressions_total'] ?? 0),
                'clicks_total' => (int) ($row['clicks_total'] ?? 0),
                'ad_spend_total' => round((float) ($row['ad_spend_total'] ?? 0), 2),
                'followers_latest' => (int) ($row['followers_latest'] ?? 0),
                'reach_total' => (int) ($row['reach_total'] ?? 0),
                'avg_engagement' => 0.0,
            ];
        }

        return $networks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getScheduledPosts(int $brandId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM scheduled_posts
             WHERE brand_id = :brand_id AND status = 'pending'
             ORDER BY scheduled_date ASC
             LIMIT :limit"
        );
        $stmt->bindValue('brand_id', $brandId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSyncStatus(int $brandId): ?array
    {
        return $this->getLastSync($brandId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSyncHistory(int $brandId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_history
             WHERE brand_id = :brand_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('brand_id', $brandId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function getBrand(int $brandId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM brands WHERE id = :id');
        $stmt->execute(['id' => $brandId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<string, int|float>
     */
    private function getNetworkStats(string $network, int $brandId, string $startDate, string $endDate): array
    {
        $table = self::POST_NETWORKS[$network]['table'];
        $commentsExpr = 'COALESCE(SUM(comments), 0)';
        $impressionsExpr = match ($network) {
            'instagram' => 'COALESCE(SUM(NULLIF(impressions, 0)), SUM(reach), 0)',
            'tiktok' => 'COALESCE(SUM(views), 0)',
            default => 'COALESCE(SUM(impressions), 0)',
        };

        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS posts_count,
                COALESCE(SUM(likes), 0) AS likes_total,
                {$commentsExpr} AS comments_total,
                {$impressionsExpr} AS impressions_total,
                COALESCE(AVG(engagement_rate), 0) AS avg_engagement
             FROM {$table}
             WHERE brand_id = :brand_id
             AND published_date BETWEEN :start AND :end"
        );
        $stmt->execute([
            'brand_id' => $brandId,
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59',
        ]);

        $row = $stmt->fetch();

        return [
            'posts_count' => (int) ($row['posts_count'] ?? 0),
            'likes_total' => (int) ($row['likes_total'] ?? 0),
            'comments_total' => (int) ($row['comments_total'] ?? 0),
            'impressions_total' => (int) ($row['impressions_total'] ?? 0),
            'avg_engagement' => round((float) ($row['avg_engagement'] ?? 0), 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPostsForNetwork(string $network, int $brandId, string $startDate, string $endDate): array
    {
        $config = self::POST_NETWORKS[$network];
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$config['table']}
             WHERE brand_id = :brand_id
             AND published_date BETWEEN :start AND :end
             ORDER BY published_date DESC"
        );
        $stmt->execute([
            'brand_id' => $brandId,
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59',
        ]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->normalizePostRow($row, $network);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizePostRow(array $row, string $network): array
    {
        $textField = self::POST_NETWORKS[$network]['text'];

        return [
            'id' => (int) $row['id'],
            'post_id' => $row['post_id'],
            'network' => $network,
            'text' => $row[$textField] ?? null,
            'likes' => (int) ($row['likes'] ?? 0),
            'comments' => (int) ($row['comments'] ?? $row['replies'] ?? 0),
            'shares' => (int) ($row['shares'] ?? $row['retweets'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? $row['views'] ?? 0),
            'engagement_rate' => (float) ($row['engagement_rate'] ?? 0),
            'post_url' => $row['post_url'] ?? null,
            'media_url' => $row['media_url'] ?? null,
            'published_date' => $row['published_date'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLastSync(int $brandId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_history
             WHERE brand_id = :brand_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute(['brand_id' => $brandId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
