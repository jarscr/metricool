<?php

namespace Metricool\Utils;

class JsonResponse
{
    public static function success(mixed $data, array $meta = [], int $status = 200): void
    {
        self::send([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
            'timestamp' => gmdate('c'),
        ], $status);
    }

    public static function error(string $message, string $code = 'ERROR', int $status = 400, array $details = []): void
    {
        self::send([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'timestamp' => gmdate('c'),
        ], $status);
    }

    private static function send(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
