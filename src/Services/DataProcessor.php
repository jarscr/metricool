<?php

namespace Metricool\Services;

use DateTimeImmutable;
use DateTimeZone;
use Metricool\Config\Config;

class DataProcessor
{
    private const URL_MAX_LENGTH = 500;
    private const POST_ID_MAX_LENGTH = 100;
    private const NETWORK_MAX_LENGTH = 50;
    private const STATUS_MAX_LENGTH = 50;
    private const POST_TYPE_MAX_LENGTH = 50;
    private const COMPETITOR_NAME_MAX_LENGTH = 255;
    private const COMPETITOR_HANDLE_MAX_LENGTH = 255;
    private static ?DateTimeZone $metricoolTimezone = null;

    /**
     * @return list<array<string, mixed>>
     */
    public static function unwrapList(mixed $response): array
    {
        if (!is_array($response)) {
            return [];
        }

        if (self::isList($response)) {
            return $response;
        }

        foreach (['data', 'items', 'posts', 'results', 'records'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && self::isList($response[$key])) {
                return $response[$key];
            }
        }

        return [$response];
    }

    /**
     * @param array<string, mixed> $brand
     * @return array{id: int, name: string}|null
     */
    public static function normalizeBrand(array $brand): ?array
    {
        $id = $brand['id'] ?? $brand['blogId'] ?? null;
        $name = $brand['name'] ?? $brand['label'] ?? null;

        if ($id === null || $name === null) {
            return null;
        }

        return [
            'id' => (int) $id,
            'name' => (string) $name,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public static function normalizeInstagramPost(array $post, int $brandId): ?array
    {
        $postId = self::extractPostId($post);
        if ($postId === null) {
            return null;
        }

        $likes = (int) ($post['likes'] ?? $post['likeCount'] ?? 0);
        $comments = (int) ($post['comments'] ?? 0);
        $reach = (int) ($post['reach'] ?? $post['impressionsUnique'] ?? 0);
        $impressions = (int) ($post['impressions'] ?? $post['impressionsTotal'] ?? $post['views'] ?? $post['videoViews'] ?? 0);

        return [
            'post_id' => self::truncateString($postId, self::POST_ID_MAX_LENGTH),
            'brand_id' => $brandId,
            'caption' => $post['caption'] ?? $post['content'] ?? $post['text'] ?? null,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => (int) ($post['shares'] ?? 0),
            'saves' => (int) ($post['saves'] ?? $post['saved'] ?? 0),
            'reach' => $reach,
            'impressions' => $impressions,
            'engagement_rate' => self::engagementRate(
                $likes,
                $comments,
                $reach,
                $post['engagement_rate'] ?? $post['engagement'] ?? null
            ),
            'post_type' => self::truncateString((string) ($post['type'] ?? $post['post_type'] ?? 'post'), self::POST_TYPE_MAX_LENGTH),
            'post_url' => self::truncateUrl($post['url'] ?? $post['post_url'] ?? $post['permalinkUrl'] ?? $post['link'] ?? null),
            'media_url' => self::normalizeOptionalString($post['media_url'] ?? $post['mediaUrl'] ?? $post['imageUrl'] ?? $post['picture'] ?? null),
            'published_date' => self::parseDate($post['date'] ?? $post['published_date'] ?? $post['created'] ?? $post['timestamp'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public static function normalizeFacebookPost(array $post, int $brandId): ?array
    {
        $postId = self::extractPostId($post);
        if ($postId === null) {
            return null;
        }

        $likes = (int) ($post['likes'] ?? $post['reactions'] ?? 0);
        $comments = (int) ($post['comments'] ?? 0);
        $reach = (int) ($post['reach'] ?? $post['impressionsUnique'] ?? 0);
        $clicks = (int) ($post['clicks'] ?? $post['linkclicks'] ?? 0);

        return [
            'post_id' => self::truncateString($postId, self::POST_ID_MAX_LENGTH),
            'brand_id' => $brandId,
            'message' => $post['message'] ?? $post['text'] ?? null,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => (int) ($post['shares'] ?? 0),
            'reactions' => (int) ($post['reactions'] ?? $likes),
            'reach' => $reach,
            'impressions' => (int) ($post['impressions'] ?? 0),
            'website_clicks' => $clicks,
            'ad_spend' => round((float) ($post['spend'] ?? 0), 2),
            'engagement_rate' => self::engagementRate(
                $likes,
                $comments,
                $reach,
                $post['engagement_rate'] ?? $post['engagement'] ?? null
            ),
            'post_type' => self::truncateString((string) ($post['type'] ?? $post['post_type'] ?? 'post'), self::POST_TYPE_MAX_LENGTH),
            'post_url' => self::truncateUrl($post['url'] ?? $post['post_url'] ?? $post['permalinkUrl'] ?? $post['link'] ?? null),
            'published_date' => self::parseDate($post['date'] ?? $post['published_date'] ?? $post['created'] ?? $post['timestamp'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public static function normalizeTikTokPost(array $post, int $brandId): ?array
    {
        $postId = self::extractPostId($post);
        if ($postId === null) {
            return null;
        }

        $likes = (int) ($post['likeCount'] ?? $post['likes'] ?? 0);
        $comments = (int) ($post['commentCount'] ?? $post['comments'] ?? 0);
        $shares = (int) ($post['shareCount'] ?? $post['shares'] ?? 0);
        $views = (int) ($post['viewCount'] ?? $post['views'] ?? 0);
        $engagementRate = $views > 0
            ? round((($likes + $comments + $shares) / $views) * 100, 2)
            : 0.0;

        return [
            'post_id' => self::truncateString($postId, self::POST_ID_MAX_LENGTH),
            'brand_id' => $brandId,
            'description' => $post['videoDescription'] ?? $post['title'] ?? $post['description'] ?? null,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'views' => $views,
            'engagement_rate' => $engagementRate,
            'post_type' => self::truncateString((string) ($post['type'] ?? 'VIDEO'), self::POST_TYPE_MAX_LENGTH),
            'post_url' => self::truncateUrl($post['shareUrl'] ?? $post['url'] ?? null),
            'media_url' => self::normalizeOptionalString($post['coverImageUrl'] ?? $post['mediaUrl'] ?? null),
            'duration_seconds' => max(0, (int) ($post['duration'] ?? 0)),
            'published_date' => self::parseDate($post['createTime'] ?? $post['date'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public static function normalizeXPost(array $post, int $brandId): ?array
    {
        $postId = self::extractPostId($post);
        if ($postId === null) {
            return null;
        }

        $likes = (int) ($post['likes'] ?? $post['favoriteCount'] ?? 0);
        $replies = (int) ($post['replies'] ?? $post['comments'] ?? 0);
        $impressions = (int) ($post['impressions'] ?? $post['impressionCount'] ?? 0);

        return [
            'post_id' => self::truncateString($postId, self::POST_ID_MAX_LENGTH),
            'brand_id' => $brandId,
            'text' => $post['text'] ?? $post['message'] ?? null,
            'likes' => $likes,
            'retweets' => (int) ($post['retweets'] ?? $post['retweetCount'] ?? 0),
            'replies' => $replies,
            'quotes' => (int) ($post['quotes'] ?? $post['quoteCount'] ?? 0),
            'impressions' => $impressions,
            'engagement_rate' => self::engagementRate(
                $likes,
                $replies,
                $impressions,
                $post['engagement_rate'] ?? $post['engagement'] ?? null
            ),
            'post_url' => self::truncateUrl($post['url'] ?? $post['post_url'] ?? $post['permalinkUrl'] ?? null),
            'published_date' => self::parseDate($post['date'] ?? $post['published_date'] ?? $post['created'] ?? $post['timestamp'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public static function normalizeLinkedInPost(array $post, int $brandId): ?array
    {
        $postId = self::extractPostId($post);
        if ($postId === null) {
            return null;
        }

        $likes = (int) ($post['likes'] ?? $post['reactions'] ?? 0);
        $comments = (int) ($post['comments'] ?? 0);
        $impressions = (int) ($post['impressions'] ?? 0);

        return [
            'post_id' => self::truncateString($postId, self::POST_ID_MAX_LENGTH),
            'brand_id' => $brandId,
            'content' => $post['content'] ?? $post['text'] ?? null,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => (int) ($post['shares'] ?? 0),
            'impressions' => $impressions,
            'engagement_rate' => self::engagementRate(
                $likes,
                $comments,
                $impressions,
                $post['engagement_rate'] ?? $post['engagement'] ?? null
            ),
            'post_url' => self::truncateUrl($post['url'] ?? $post['post_url'] ?? $post['permalinkUrl'] ?? null),
            'published_date' => self::parseDate($post['date'] ?? $post['published_date'] ?? $post['created'] ?? $post['timestamp'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function normalizeScheduledPost(array $row, int $brandId): ?array
    {
        $postId = self::extractPostId($row);
        if ($postId === null) {
            return null;
        }

        return [
            'post_id' => self::truncateString($postId, self::POST_ID_MAX_LENGTH),
            'brand_id' => $brandId,
            'network' => self::truncateString(
                self::normalizeNetworkSlug((string) ($row['network'] ?? $row['provider'] ?? 'unknown')),
                self::NETWORK_MAX_LENGTH
            ),
            'content' => $row['content'] ?? $row['text'] ?? $row['caption'] ?? null,
            'media_url' => self::truncateUrl($row['media_url'] ?? $row['mediaUrl'] ?? null),
            'scheduled_date' => self::parseDate(
                $row['scheduled_date'] ?? $row['scheduledDate'] ?? $row['date'] ?? null
            ),
            'status' => self::truncateString((string) ($row['status'] ?? 'pending'), self::STATUS_MAX_LENGTH),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function normalizeCompetitor(array $row, int $brandId, string $network): ?array
    {
        $name = $row['displayName'] ?? $row['screenName'] ?? $row['name'] ?? $row['competitor_name'] ?? null;
        if ($name === null) {
            return null;
        }

        $handle = $row['screenName'] ?? $row['handle'] ?? $row['competitor_handle'] ?? $row['username'] ?? null;
        $provider = self::normalizeNetworkSlug((string) ($row['provider'] ?? $row['network'] ?? $network));
        if ($provider === 'twitter') {
            $provider = 'x';
        }

        return [
            'brand_id' => $brandId,
            'network' => self::truncateString($provider, self::NETWORK_MAX_LENGTH),
            'competitor_name' => self::truncateString((string) $name, self::COMPETITOR_NAME_MAX_LENGTH),
            'competitor_handle' => $handle !== null ? self::truncateString((string) $handle, self::COMPETITOR_HANDLE_MAX_LENGTH) : null,
            'competitor_url' => self::truncateUrl($row['url'] ?? $row['competitor_url'] ?? null),
            'followers' => (int) ($row['followers'] ?? 0),
            'posts_count' => (int) ($row['posts'] ?? $row['posts_count'] ?? $row['postsCount'] ?? 0),
            'last_synced' => self::parseDate($row['updated'] ?? $row['last_synced'] ?? $row['lastSynced'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $analytics
     * @return list<array<string, mixed>>
     */
    /**
     * Combina varias series timeline (metric => [[date, value], …]) en filas diarias.
     *
     * @param array<string, mixed> $seriesByMetric
     * @return list<array<string, mixed>>
     */
    public static function mergeTimelineMetrics(array $seriesByMetric, int $brandId, string $network): array
    {
        $byDate = [];

        foreach ($seriesByMetric as $metric => $timeline) {
            $pairs = self::extractTimelinePairs($timeline);
            if ($pairs === []) {
                continue;
            }

            foreach ($pairs as $pair) {
                if (!is_array($pair) || count($pair) < 2) {
                    continue;
                }

                $date = self::parseDateOnly($pair[0]);
                if ($date === null) {
                    continue;
                }

                if (!isset($byDate[$date])) {
                    $byDate[$date] = self::emptyAnalyticsRow($brandId, $network, $date);
                }

                self::applyTimelineMetric($byDate[$date], (string) $metric, $pair[1]);
            }
        }

        ksort($byDate);

        return array_values($byDate);
    }

    /**
     * Acepta tanto el timeline legado [[fecha, valor]] como Analytics v2:
     * {data: [{metric, values: [{dateTime, value}]}]}.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function extractTimelinePairs(mixed $timeline): array
    {
        if (!is_array($timeline)) {
            return [];
        }

        if (self::isTimelineSeries($timeline)) {
            return array_values($timeline);
        }

        $seriesList = $timeline['data'] ?? $timeline;
        if (!is_array($seriesList)) {
            return [];
        }

        $pairs = [];
        foreach ($seriesList as $series) {
            if (!is_array($series)) {
                continue;
            }

            $values = $series['values'] ?? null;
            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $point) {
                if (!is_array($point)) {
                    continue;
                }

                $date = $point['dateTime'] ?? $point['date'] ?? null;
                $value = $point['value'] ?? null;
                if ($date === null || $value === null) {
                    continue;
                }

                $pairs[] = [$date, $value];
            }
        }

        return $pairs;
    }

    public static function normalizeAnalytics(mixed $analytics, int $brandId, string $network): array
    {
        $rows = [];

        if (!is_array($analytics)) {
            return $rows;
        }

        if (self::isTimelineSeries($analytics)) {
            return self::mergeTimelineMetrics(['impressions' => $analytics], $brandId, $network);
        }

        if (isset($analytics['daily']) && is_array($analytics['daily'])) {
            foreach ($analytics['daily'] as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $normalized = self::normalizeAnalyticsRow($day, $brandId, $network);
                if ($normalized !== null) {
                    $rows[] = $normalized;
                }
            }
            return $rows;
        }

        if (self::isList($analytics)) {
            foreach ($analytics as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $normalized = self::normalizeAnalyticsRow($day, $brandId, $network);
                if ($normalized !== null) {
                    $rows[] = $normalized;
                }
            }
            return $rows;
        }

        $single = self::normalizeAnalyticsRow($analytics, $brandId, $network);
        if ($single !== null) {
            $rows[] = $single;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function normalizeAnalyticsRow(array $row, int $brandId, string $network): ?array
    {
        $date = self::parseDateOnly($row['date'] ?? $row['day'] ?? null);
        if ($date === null) {
            $date = date('Y-m-d');
        }

        $likes = (int) ($row['likes'] ?? 0);
        $comments = (int) ($row['comments'] ?? 0);
        $reach = (int) ($row['reach'] ?? 0);

        $normalized = self::emptyAnalyticsRow($brandId, $network, $date);
        $normalized['followers'] = (int) ($row['followers'] ?? 0);
        $normalized['following'] = (int) ($row['following'] ?? 0);
        $normalized['posts_count'] = (int) ($row['posts_count'] ?? $row['postsCount'] ?? 0);
        $normalized['likes'] = $likes;
        $normalized['comments'] = $comments;
        $normalized['shares'] = (int) ($row['shares'] ?? 0);
        $normalized['reach'] = $reach;
        $normalized['impressions'] = (int) ($row['impressions'] ?? 0);
        $normalized['engagement_rate'] = self::engagementRate(
            $likes,
            $comments,
            $reach,
            $row['engagement_rate'] ?? null
        );
        $normalized['profile_views'] = (int) ($row['profile_views'] ?? $row['profileViews'] ?? 0);
        $normalized['website_clicks'] = (int) ($row['website_clicks'] ?? $row['websiteClicks'] ?? 0);
        $normalized['ad_spend'] = round((float) ($row['ad_spend'] ?? $row['spend'] ?? 0), 2);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyAnalyticsRow(int $brandId, string $network, string $date): array
    {
        return [
            'brand_id' => $brandId,
            'network' => self::truncateString($network, self::NETWORK_MAX_LENGTH),
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
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function applyTimelineMetric(array &$row, string $metric, mixed $value): void
    {
        if (!is_numeric($value)) {
            return;
        }

        $numeric = (float) $value;

        match ($metric) {
            'followers' => $row['followers'] = (int) $numeric,
            'following' => $row['following'] = (int) $numeric,
            'likes' => $row['likes'] = (int) $numeric,
            'comments' => $row['comments'] = (int) $numeric,
            'shares' => $row['shares'] = (int) $numeric,
            'reach' => $row['reach'] = (int) $numeric,
            'impressions', 'views' => $row['impressions'] = (int) $numeric,
            'clicks' => $row['website_clicks'] = (int) $numeric,
            'spend' => $row['ad_spend'] = round($numeric, 2),
            'profile_views', 'profileViews' => $row['profile_views'] = (int) $numeric,
            default => $row['impressions'] = (int) $numeric,
        };
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function extractPostId(array $post): ?string
    {
        $id = $post['id'] ?? $post['post_id'] ?? $post['postId'] ?? $post['reelId'] ?? $post['videoId'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }
        return (string) $id;
    }

    private static function engagementRate(
        int $likes,
        int $comments,
        int $reach,
        mixed $provided = null
    ): float {
        if ($provided !== null && $provided !== '') {
            return round((float) $provided, 2);
        }

        if ($reach <= 0) {
            return 0.0;
        }

        return round((($likes + $comments * 2) / $reach) * 100, 2);
    }

    private static function truncateUrl(?string $value, int $maxLength = 500): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::truncateString($value, $maxLength);
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private static function truncateString(string $value, int $maxLength): string
    {
        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }

    private static function normalizeNetworkSlug(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'twitter' => 'x',
            default => $normalized,
        };
    }

    private static function parseDate(mixed $value): string
    {
        $timezone = self::metricoolTimezone();

        if ($value === null || $value === '') {
            return (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            $numeric = (int) $value;
            if ($numeric > 9999999999) {
                $numeric = (int) floor($numeric / 1000);
            }

            return (new DateTimeImmutable('@' . $numeric))
                ->setTimezone($timezone)
                ->format('Y-m-d H:i:s');
        }

        try {
            return (new DateTimeImmutable((string) $value, $timezone))
                ->setTimezone($timezone)
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
        }
    }

    private static function parseDateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timezone = self::metricoolTimezone();

        try {
            return (new DateTimeImmutable((string) $value, $timezone))
                ->setTimezone($timezone)
                ->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private static function metricoolTimezone(): DateTimeZone
    {
        if (self::$metricoolTimezone !== null) {
            return self::$metricoolTimezone;
        }

        $timezoneName = Config::get('METRICOOL_TIMEZONE', 'America/Costa_Rica') ?? 'America/Costa_Rica';

        try {
            self::$metricoolTimezone = new DateTimeZone($timezoneName);
        } catch (\Exception) {
            self::$metricoolTimezone = new DateTimeZone('America/Costa_Rica');
        }

        return self::$metricoolTimezone;
    }

    private static function isList(array $array): bool
    {
        if ($array === []) {
            return true;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }

    private static function isTimelineSeries(array $analytics): bool
    {
        if (!self::isList($analytics) || $analytics === []) {
            return false;
        }

        $first = $analytics[0];
        return is_array($first) && count($first) >= 2 && !array_key_exists('date', $first);
    }
}
