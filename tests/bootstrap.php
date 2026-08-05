<?php

require dirname(__DIR__) . '/bootstrap.php';

ob_start();
ini_set('session.use_cookies', '0');

function test(string $name, callable $callback): void
{
    static $tests = [];

    if ($name === '__run__') {
        $failures = 0;
        $messages = [];

        foreach ($tests as [$label, $testCallback]) {
            try {
                reset_test_session();
                $testCallback();
                $messages[] = "[PASS] {$label}";
            } catch (Throwable $e) {
                $failures++;
                $messages[] = "[FAIL] {$label}: {$e->getMessage()}";
            }
        }

        echo implode("\n", $messages) . "\n";

        if ($failures > 0) {
            exit(1);
        }

        exit(0);
    }

    $tests[] = [$name, $callback];
}

function assert_true(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_false(bool $condition, string $message = 'Assertion failed'): void
{
    assert_true(!$condition, $message);
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = $message !== '' ? $message . ' - ' : '';
        throw new RuntimeException($detail . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_null(mixed $actual, string $message = ''): void
{
    assert_same(null, $actual, $message);
}

function assert_not_null(mixed $actual, string $message = ''): void
{
    if ($actual === null) {
        throw new RuntimeException($message !== '' ? $message : 'Expected non-null value');
    }
}

function assert_throws(string $expectedClass, callable $callback, string $message = ''): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            return $e;
        }

        throw new RuntimeException(($message !== '' ? $message . ' - ' : '') . 'Expected ' . $expectedClass . ', got ' . $e::class);
    }

    throw new RuntimeException($message !== '' ? $message : 'Expected exception was not thrown');
}

function create_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE brands (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE dashboard_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_id INTEGER NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "company",
            display_name TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );

    return $pdo;
}

function insert_brand(PDO $pdo, int $id, string $name): void
{
    $stmt = $pdo->prepare('INSERT INTO brands (id, name) VALUES (:id, :name)');
    $stmt->execute([
        'id' => $id,
        'name' => $name,
    ]);
}

function insert_user(
    PDO $pdo,
    string $email,
    string $password,
    string $role = 'company',
    ?int $brandId = null,
    bool $active = true,
    string $displayName = 'Test User'
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO dashboard_users (brand_id, email, password_hash, role, display_name, is_active)
         VALUES (:brand_id, :email, :password_hash, :role, :display_name, :is_active)'
    );
    $stmt->execute([
        'brand_id' => $brandId,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'display_name' => $displayName,
        'is_active' => $active ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
}

function reset_test_session(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }

    session_id(bin2hex(random_bytes(8)));
}

function set_bootstrap_credentials(?string $email, ?string $password): void
{
    if ($email === null) {
        unset($_ENV['DASHBOARD_ADMIN_EMAIL']);
    } else {
        $_ENV['DASHBOARD_ADMIN_EMAIL'] = $email;
    }

    if ($password === null) {
        unset($_ENV['DASHBOARD_ADMIN_PASSWORD']);
    } else {
        $_ENV['DASHBOARD_ADMIN_PASSWORD'] = $password;
    }
}
