<?php

namespace Metricool\Config;

class Config
{
    private static bool $loaded = false;
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(?string $envPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $envPath ?? dirname(__DIR__, 2) . '/.env';
        if (!is_readable($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("No se pudo leer .env");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }

        // Compatibilidad con variable legacy APIKEY
        if (empty(self::$values['METRICOOL_USER_TOKEN']) && !empty(self::$values['APIKEY'])) {
            self::$values['METRICOOL_USER_TOKEN'] = self::$values['APIKEY'];
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $value = self::$values[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? $value : $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Variable de entorno requerida no configurada: {$key}");
        }
        return $value;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    public static function validateMetricoolCredentials(): void
    {
        self::require('METRICOOL_USER_TOKEN');
        self::require('METRICOOL_USER_ID');
    }
}
