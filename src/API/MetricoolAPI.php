<?php

namespace Metricool\API;

use Metricool\Config\Config;
use Exception;

/**
 * Cliente para la API REST de Metricool (2025+).
 *
 * Endpoints vigentes:
 * - Marcas:           GET /admin/simpleProfiles
 * - Posts:            GET /stats/{network}/posts|reels   (start, end)
 * - Analytics:        GET /v2/analytics/timelines         (network, metric, from, to)
 * - Programados:      GET /v2/scheduler/posts             (init_date, end_date)
 * - Competidores:     GET /v2/analytics/competitors/{network} (from, to, limit, timezone)
 *
 * @see https://help.metricool.com/es/integrations-via-the-metricool-api-an8ch
 */
class MetricoolAPI
{
    private string $baseUrl;
    private string $userToken;
    private string $userId;
    private ?string $blogId = null;
    private string $timezone;
    private int $maxRetries;
    private int $timeout;
    private int $retryDelayMs;

    /** @var list<string> */
    public const POST_NETWORKS = ['instagram', 'facebook', 'tiktok'];

    /** @var array<string, string> */
    private const BRAND_FIELD_TO_POST_NETWORK = [
        'instagram' => 'instagram',
        'facebook' => 'facebook',
        'tiktok' => 'tiktok',
    ];

    /** @var list<string> */
    public const SYNC_ANALYTICS_NETWORKS = [
        'instagram',
        'facebook',
        'tiktok',
        'facebookads',
        'googleads',
    ];

    /** @var list<string> */
    public const COMPETITOR_NETWORKS = ['instagram', 'facebook', 'x', 'linkedin'];

    /**
     * Campos de simpleProfiles → slug interno de Analytics v2.
     *
     * @var array<string, string>
     */
    public const BRAND_FIELD_TO_ANALYTICS_NETWORK = [
        'instagram' => 'instagram',
        'facebook' => 'facebook',
        'twitter' => 'twitter',
        'linkedinCompany' => 'linkedin',
        'tiktok' => 'tiktok',
        'youtube' => 'youtube',
        'threads' => 'threads',
        'pinterest' => 'pinterest',
        'gmb' => 'googlebusiness',
        'facebookAds' => 'facebookads',
        'adwords' => 'googleads',
        'tiktokads' => 'tiktokads',
    ];

    /** @var list<string> */
    public const ADS_ANALYTICS_NETWORKS = ['facebookads', 'googleads', 'tiktokads'];

    /** @var list<string> */
    public const ORGANIC_ANALYTICS_METRICS = ['followers'];

    /** @var list<string> */
    public const ADS_ANALYTICS_METRICS = ['impressions', 'clicks', 'spend', 'reach'];

    public function __construct(
        ?string $userToken = null,
        ?string $userId = null,
        ?string $blogId = null,
        ?string $baseUrl = null
    ) {
        Config::load();

        $this->userToken = $userToken ?? Config::require('METRICOOL_USER_TOKEN');
        $this->userId = $userId ?? Config::require('METRICOOL_USER_ID');
        $this->blogId = $blogId;
        $this->baseUrl = rtrim($baseUrl ?? Config::get('METRICOOL_API_BASE_URL', 'https://app.metricool.com/api'), '/');
        $this->timezone = Config::get('METRICOOL_TIMEZONE', 'America/Costa_Rica');
        $this->maxRetries = Config::getInt('METRICOOL_MAX_RETRIES', 3);
        $this->timeout = Config::getInt('METRICOOL_TIMEOUT', 30);
        $this->retryDelayMs = Config::getInt('METRICOOL_RETRY_DELAY_MS', 5000);
    }

    public function setBlogId(string|int $blogId): void
    {
        $this->blogId = (string) $blogId;
    }

    public function getBrands(): array
    {
        return $this->makeRequest('/admin/simpleProfiles');
    }

    public function getInstagramPosts(string $startDate, string $endDate): array
    {
        $this->requireBlogId();
        return $this->getStatsPosts('instagram', $startDate, $endDate);
    }

    public function getInstagramReels(string $startDate, string $endDate): array
    {
        $this->requireBlogId();
        return $this->getStatsPosts('instagram', $startDate, $endDate, 'reels');
    }

    public function getFacebookPosts(string $startDate, string $endDate): array
    {
        $this->requireBlogId();
        return $this->getStatsPosts('facebook', $startDate, $endDate);
    }

    public function getTikTokVideos(string $startDate, string $endDate): array
    {
        $this->requireBlogId();

        return $this->makeRequest('/v2/analytics/posts/tiktok', [
            'from' => $startDate . 'T00:00:00',
            'to' => $endDate . 'T23:59:59',
            'timezone' => $this->timezone,
            'integrationSource' => 'API',
        ]);
    }

    public function getXPosts(string $startDate, string $endDate): array
    {
        $this->requireBlogId();
        return $this->getStatsPosts('x', $startDate, $endDate);
    }

    public function getLinkedInPosts(string $startDate, string $endDate): array
    {
        $this->requireBlogId();
        return $this->getStatsPosts('linkedin', $startDate, $endDate);
    }

    public function getAnalytics(string $network, string $startDate, string $endDate, ?string $metric = null): array
    {
        $this->requireBlogId();
        $apiNetwork = self::resolveStatsNetwork($network);
        $spec = self::resolveAnalyticsMetricSpec($network, $metric ?? 'followers');

        return $this->makeRequest('/v2/analytics/timelines', [
            'network' => $apiNetwork,
            'metric' => $spec['metric'],
            'from' => $startDate . 'T00:00:00',
            'to' => $endDate . 'T23:59:59',
            'timezone' => $this->timezone,
            'subject' => $spec['subject'],
        ]);
    }

    public function getAnalyticsAggregation(
        string $network,
        string $startDate,
        string $endDate,
        string $metric
    ): array {
        $this->requireBlogId();
        $spec = self::resolveAnalyticsMetricSpec($network, $metric);

        return $this->makeRequest('/v2/analytics/aggregation', [
            'network' => self::resolveStatsNetwork($network),
            'metric' => $spec['metric'],
            'from' => $startDate . 'T00:00:00',
            'to' => $endDate . 'T23:59:59',
            'timezone' => $this->timezone,
            'subject' => $spec['subject'],
        ]);
    }

    public function getAdsCampaigns(string $network, string $startDate, string $endDate): array
    {
        $this->requireBlogId();

        if (!in_array($network, ['facebookads', 'googleads'], true)) {
            throw new Exception("Red publicitaria no soportada: {$network}");
        }

        return $this->makeRequest("/v2/analytics/campaigns/{$network}", [
            'from' => $startDate . 'T00:00:00',
            'to' => $endDate . 'T23:59:59',
            'timezone' => $this->timezone,
        ]);
    }

    /**
     * Traduce los nombres internos a las métricas documentadas por Analytics v2.
     *
     * @return array{metric: string, subject: string}
     */
    public static function resolveAnalyticsMetricSpec(string $network, string $metric): array
    {
        if ($metric !== 'followers') {
            return ['metric' => $metric, 'subject' => 'account'];
        }

        return match (self::normalizeAnalyticsNetworkSlug($network)) {
            'instagram' => ['metric' => 'followers', 'subject' => 'account'],
            'facebook' => ['metric' => 'pageFollows', 'subject' => 'account'],
            'tiktok' => ['metric' => 'followers_count', 'subject' => 'account'],
            default => ['metric' => 'followers', 'subject' => 'account'],
        };
    }

    /**
     * @param array<string, mixed> $brand
     * @return list<string> Slugs internos (instagram, x, facebookads, tiktok, …)
     */
    public static function resolveConnectedAnalyticsNetworks(array $brand): array
    {
        $networks = [];

        foreach (self::BRAND_FIELD_TO_ANALYTICS_NETWORK as $field => $apiNetwork) {
            if (!self::hasConnectionValue($brand[$field] ?? null)) {
                continue;
            }

            $networks[] = self::normalizeAnalyticsNetworkSlug($apiNetwork);
        }

        return array_values(array_unique($networks));
    }

    /**
     * @param array<string, mixed> $brand
     * @return list<string>
     */
    public static function resolveConnectedPostNetworks(array $brand): array
    {
        $networks = [];

        foreach (self::BRAND_FIELD_TO_POST_NETWORK as $field => $network) {
            if (!self::hasConnectionValue($brand[$field] ?? null)) {
                continue;
            }

            $networks[] = $network;
        }

        return $networks;
    }

    /**
     * @param array<string, mixed> $brand
     * @return list<string>
     */
    public static function resolveSupportedSyncAnalyticsNetworks(array $brand): array
    {
        return array_values(array_intersect(
            self::resolveConnectedAnalyticsNetworks($brand),
            self::SYNC_ANALYTICS_NETWORKS
        ));
    }

    public static function normalizeAnalyticsNetworkSlug(string $apiNetwork): string
    {
        return match ($apiNetwork) {
            'twitter' => 'x',
            default => $apiNetwork,
        };
    }

    public static function isAdsAnalyticsNetwork(string $network): bool
    {
        $apiNetwork = self::resolveStatsNetwork($network);

        return in_array($apiNetwork, self::ADS_ANALYTICS_NETWORKS, true);
    }

    public function getScheduledPosts(string $startDate, string $endDate): array
    {
        $this->requireBlogId();

        return $this->makeRequest('/v2/scheduler/posts', [
            'init_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    public function getCompetitors(
        string $network,
        string $startDate,
        string $endDate,
        int $limit = 50
    ): array {
        $this->requireBlogId();
        $apiNetwork = self::resolveStatsNetwork($network);

        return $this->makeRequest("/v2/analytics/competitors/{$apiNetwork}", [
            'from' => $startDate . 'T00:00:00',
            'to' => $endDate . 'T23:59:59',
            'limit' => $limit,
            'timezone' => $this->timezone,
        ]);
    }

    public static function resolveStatsNetwork(string $network): string
    {
        return match ($network) {
            'x', 'twitter' => 'twitter',
            default => $network,
        };
    }

    /**
     * @return array{start: string, end: string}
     */
    public static function statsDateParams(string $startDate, string $endDate): array
    {
        $normalizedStart = self::normalizeStatsDate($startDate);
        $normalizedEnd = self::normalizeStatsDate($endDate);

        if ($normalizedStart > $normalizedEnd) {
            throw new Exception('La fecha start no puede ser posterior a end');
        }

        return [
            'start' => $normalizedStart,
            'end' => $normalizedEnd,
        ];
    }

    private static function normalizeStatsDate(string $date): string
    {
        if (preg_match('/^\d{8}$/', $date) === 1) {
            $normalized = $date;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $normalized = str_replace('-', '', $date);
        } else {
            throw new Exception('Las fechas de stats deben usar YYYY-MM-DD o YYYYMMDD');
        }

        $parsed = \DateTimeImmutable::createFromFormat('Ymd', $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new Exception("Fecha inválida para stats: {$date}");
        }

        return $parsed->format('Ymd');
    }

    private static function hasConnectionValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'null'], true);
        }

        return is_array($value) ? $value !== [] : $value !== null;
    }

    private function getStatsPosts(
        string $network,
        string $startDate,
        string $endDate,
        string $resource = 'posts'
    ): array {
        $apiNetwork = self::resolveStatsNetwork($network);

        return $this->makeRequest(
            "/stats/{$apiNetwork}/{$resource}",
            self::statsDateParams($startDate, $endDate)
        );
    }

    private function requireBlogId(): void
    {
        if (!$this->blogId) {
            throw new Exception('blogId es requerido. Use setBlogId()');
        }
    }

    private function makeRequest(string $endpoint, array $params = []): array
    {
        $params['userId'] = $this->userId;
        if ($this->blogId) {
            $params['blogId'] = $this->blogId;
        }

        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'X-Mc-Auth: ' . $this->userToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $lastError = "Error en cURL: {$curlError}";
            } elseif ($httpCode === 204) {
                return [];
            } elseif ($httpCode >= 400 && $httpCode < 500) {
                throw new Exception("Error HTTP {$httpCode}: {$response}");
            } elseif ($httpCode !== 200) {
                $lastError = "Error HTTP {$httpCode}: {$response}";
            } else {
                if ($response === '' || $response === false) {
                    return [];
                }

                $decoded = json_decode((string) $response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Respuesta JSON inválida de Metricool');
                }
                return is_array($decoded) ? $decoded : [];
            }

            if ($attempt < $this->maxRetries) {
                usleep($this->retryDelayMs * 1000);
            }
        }

        throw new Exception($lastError ?? 'Error desconocido en petición a Metricool');
    }
}
