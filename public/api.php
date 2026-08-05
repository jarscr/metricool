<?php

require __DIR__ . '/../bootstrap.php';

use Metricool\Config\Database;
use Metricool\Exceptions\AuthorizationException;
use Metricool\Middleware\AuthMiddleware;
use Metricool\Services\DataService;
use Metricool\Utils\JsonResponse;

AuthMiddleware::requireAuth();

$route = trim((string) ($_GET['route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $data = new DataService(Database::getConnection());

    if ($method !== 'GET') {
        JsonResponse::error('Método no permitido', 'METHOD_NOT_ALLOWED', 405);
    }

    match ($route) {
        'brands' => handleBrands($data),
        'dashboard/summary' => handleDashboardSummary($data),
        'dashboard/overview' => handleOverview($data),
        'posts' => handlePosts($data),
        'posts/top-engagement' => handleTopPosts($data),
        'analytics/daily' => handleAnalyticsDaily($data),
        'scheduled' => handleScheduled($data),
        'sync/status' => handleSyncStatus($data),
        'sync/history' => handleSyncHistory($data),
        default => JsonResponse::error('Ruta no encontrada', 'NOT_FOUND', 404),
    };
} catch (AuthorizationException $e) {
    JsonResponse::error($e->getMessage(), $e->errorCode(), $e->statusCode());
} catch (\InvalidArgumentException $e) {
    JsonResponse::error($e->getMessage(), 'VALIDATION_ERROR', 400);
} catch (\Throwable $e) {
    JsonResponse::error($e->getMessage(), 'SERVER_ERROR', 500);
}

function handleBrands(DataService $data): void
{
    $brands = $data->getBrands();
    if (!AuthMiddleware::isAdmin()) {
        $brandId = AuthMiddleware::authorizeBrandId(null);
        $brands = array_values(array_filter(
            $brands,
            static fn (array $brand): bool => (int) $brand['id'] === $brandId
        ));
    }

    JsonResponse::success($brands);
}

function handleDashboardSummary(DataService $data): void
{
    $requested = getOptionalInt('brand_id');
    $brandId = AuthMiddleware::isAdmin() && $requested === null
        ? null
        : AuthMiddleware::authorizeBrandId($requested);
    JsonResponse::success($data->getDashboardSummary($brandId));
}

function handleOverview(DataService $data): void
{
    $brandId = AuthMiddleware::authorizeBrandId(getOptionalInt('brand_id'));
    [$start, $end] = getDateRange();

    JsonResponse::success($data->getOverview($brandId, $start, $end));
}

function handlePosts(DataService $data): void
{
    $brandId = AuthMiddleware::authorizeBrandId(getOptionalInt('brand_id'));
    [$start, $end] = getDateRange();
    $network = getOptionalString('network');
    $page = getOptionalInt('page') ?? 1;
    $perPage = getOptionalInt('per_page') ?? 20;

    $result = $data->getPosts($brandId, $network, $start, $end, $page, $perPage);

    JsonResponse::success($result['items'], [
        'total' => $result['total'],
        'page' => $result['page'],
        'per_page' => $result['per_page'],
        'total_pages' => $result['total_pages'],
    ]);
}

function handleTopPosts(DataService $data): void
{
    $brandId = AuthMiddleware::authorizeBrandId(getOptionalInt('brand_id'));
    $network = getOptionalString('network');
    $limit = getOptionalInt('limit') ?? 10;

    JsonResponse::success($data->getTopEngagementPosts($brandId, $network, $limit));
}

function handleAnalyticsDaily(DataService $data): void
{
    $brandId = AuthMiddleware::authorizeBrandId(getOptionalInt('brand_id'));
    [$start, $end] = getDateRange();
    $network = getOptionalString('network');

    JsonResponse::success($data->getDailyAnalytics($brandId, $network, $start, $end));
}

function handleScheduled(DataService $data): void
{
    $brandId = AuthMiddleware::authorizeBrandId(getOptionalInt('brand_id'));
    $limit = getOptionalInt('limit') ?? 20;

    JsonResponse::success($data->getScheduledPosts($brandId, $limit));
}

function handleSyncStatus(DataService $data): void
{
    $brandId = AuthMiddleware::authorizeBrandId(getOptionalInt('brand_id'));
    JsonResponse::success($data->getSyncStatus($brandId) ?? []);
}

function handleSyncHistory(DataService $data): void
{
    $brandId = AuthMiddleware::authorizeBrandId(getOptionalInt('brand_id'));
    $limit = getOptionalInt('limit') ?? 10;

    JsonResponse::success($data->getSyncHistory($brandId, $limit));
}

function getOptionalInt(string $key): ?int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
    if ($value === false) {
        throw new \InvalidArgumentException("Parámetro entero no válido: {$key}");
    }

    return $value;
}

function getOptionalString(string $key): ?string
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    return (string) $_GET[$key];
}

/**
 * @return array{0: string, 1: string}
 */
function getDateRange(): array
{
    $end = getOptionalString('end_date') ?? date('Y-m-d');
    $start = getOptionalString('start_date') ?? date('Y-m-d', strtotime('-30 days'));

    foreach (['start_date' => $start, 'end_date' => $end] as $field => $value) {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new \InvalidArgumentException("Fecha no válida: {$field}");
        }
    }

    if ($start > $end) {
        throw new \InvalidArgumentException('start_date no puede ser posterior a end_date');
    }

    return [$start, $end];
}
