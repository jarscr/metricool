<?php

require __DIR__ . '/bootstrap.php';

use Metricool\Exceptions\AuthorizationException;
use Metricool\Middleware\AuthMiddleware;
use Metricool\Services\UserService;

test('user service authenticates active company users with hashed passwords', function (): void {
    $pdo = create_test_pdo();
    insert_brand($pdo, 77, 'Marca 77');
    $userId = insert_user($pdo, 'company@example.com', 'super-secret', 'company', 77, true, 'Empresa');

    $service = new UserService($pdo);

    $user = $service->authenticate('company@example.com', 'super-secret');
    assert_not_null($user);
    assert_same($userId, $user['id']);
    assert_same('company', $user['role']);
    assert_same(77, $user['brand_id']);
    assert_same('company@example.com', $user['email']);

    assert_null($service->authenticate('company@example.com', 'bad-secret'));
    assert_null($service->authenticate('missing@example.com', 'super-secret'));
});

test('user service blocks inactive users and does not bootstrap admin without env password', function (): void {
    $pdo = create_test_pdo();
    insert_brand($pdo, 12, 'Marca 12');
    insert_user($pdo, 'inactive@example.com', 'super-secret', 'company', 12, false, 'Inactivo');

    $service = new UserService($pdo);

    set_bootstrap_credentials('admin@example.com', null);

    assert_null($service->authenticate('inactive@example.com', 'super-secret'));
    assert_null($service->authenticate('admin@example.com', 'whatever'));
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM dashboard_users WHERE role = 'admin'")->fetchColumn());
});

test('user service stops returning a session identity after deactivation', function (): void {
    $pdo = create_test_pdo();
    insert_brand($pdo, 13, 'Marca 13');
    $userId = insert_user($pdo, 'active@example.com', 'super-secret', 'company', 13, true, 'Activo');
    $service = new UserService($pdo);

    assert_not_null($service->getActiveUserById($userId));
    $pdo->exec("UPDATE dashboard_users SET is_active = 0 WHERE id = {$userId}");
    assert_null($service->getActiveUserById($userId));
});

test('user service bootstraps the first admin from env credentials only once', function (): void {
    $pdo = create_test_pdo();
    $service = new UserService($pdo);

    set_bootstrap_credentials('admin@example.com', 'bootstrap-secret');

    $admin = $service->authenticate('admin@example.com', 'bootstrap-secret');
    assert_not_null($admin);
    assert_same('admin', $admin['role']);
    assert_same(null, $admin['brand_id']);
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM dashboard_users WHERE role = 'admin'")->fetchColumn());

    $adminAgain = $service->authenticate('admin@example.com', 'bootstrap-secret');
    assert_not_null($adminAgain);
    assert_same($admin['id'], $adminAgain['id']);
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM dashboard_users WHERE role = 'admin'")->fetchColumn());
});

test('user service creates and updates company accounts with optional password rotation', function (): void {
    $pdo = create_test_pdo();
    insert_brand($pdo, 90, 'Marca 90');
    insert_brand($pdo, 91, 'Marca 91');

    $service = new UserService($pdo);

    $created = $service->saveCompanyUser([
        'email' => 'brand@example.com',
        'display_name' => 'Marca Uno',
        'brand_id' => 90,
        'password' => 'initial-secret',
        'active' => true,
    ]);

    assert_same('company', $created['role']);
    assert_same(90, $created['brand_id']);
    assert_true(password_verify('initial-secret', (string) $pdo->query("SELECT password_hash FROM dashboard_users WHERE email = 'brand@example.com'")->fetchColumn()));

    $updated = $service->saveCompanyUser([
        'id' => $created['id'],
        'email' => 'brand@example.com',
        'display_name' => 'Marca Dos',
        'brand_id' => 91,
        'password' => '',
        'active' => false,
    ]);

    assert_same($created['id'], $updated['id']);
    assert_same(91, $updated['brand_id']);
    assert_false((bool) $updated['active']);
    assert_true(password_verify('initial-secret', (string) $pdo->query("SELECT password_hash FROM dashboard_users WHERE email = 'brand@example.com'")->fetchColumn()));
});

test('user service rejects short company passwords', function (): void {
    $pdo = create_test_pdo();
    insert_brand($pdo, 92, 'Marca 92');
    $service = new UserService($pdo);

    assert_throws(InvalidArgumentException::class, fn (): array => $service->saveCompanyUser([
        'email' => 'short@example.com',
        'display_name' => 'Clave corta',
        'brand_id' => 92,
        'password' => 'short',
        'active' => true,
    ]));
});

test('auth middleware stores session payload, regenerates id and enforces brand scope', function (): void {
    $pdo = create_test_pdo();
    insert_brand($pdo, 55, 'Marca 55');
    insert_user($pdo, 'company@example.com', 'company-secret', 'company', 55, true, 'Empresa');

    $service = new UserService($pdo);

    AuthMiddleware::startSession();
    $originalSessionId = session_id();

    assert_true(AuthMiddleware::attemptLogin('company@example.com', 'company-secret', $service));
    assert_true(AuthMiddleware::isAuthenticated());
    assert_true(session_id() !== $originalSessionId, 'Expected session_regenerate_id to change the session id');

    $currentUser = AuthMiddleware::currentUser();
    assert_not_null($currentUser);
    assert_same('company', $currentUser['role']);
    assert_same(55, $currentUser['brand_id']);
    assert_false(AuthMiddleware::isAdmin());
    assert_same(55, AuthMiddleware::authorizeBrandId(null));
    assert_same(55, AuthMiddleware::authorizeBrandId(55));
    assert_throws(AuthorizationException::class, fn (): int => AuthMiddleware::authorizeBrandId(99));
    assert_throws(AuthorizationException::class, fn (): null => AuthMiddleware::requireAdmin());
});

test('auth middleware supports csrf tokens and admin authorization', function (): void {
    $pdo = create_test_pdo();
    insert_user($pdo, 'admin@example.com', 'admin-secret', 'admin', null, true, 'Admin');

    $service = new UserService($pdo);

    assert_true(AuthMiddleware::attemptLogin('admin@example.com', 'admin-secret', $service));
    assert_true(AuthMiddleware::isAdmin());
    assert_same(44, AuthMiddleware::authorizeBrandId(44));

    $token = AuthMiddleware::csrfToken();
    assert_true($token !== '');
    assert_true(AuthMiddleware::validateCsrf($token));
    assert_false(AuthMiddleware::validateCsrf('bad-token'));

    AuthMiddleware::requireAdmin();
    AuthMiddleware::logout();

    assert_false(AuthMiddleware::isAuthenticated());
    assert_null(AuthMiddleware::currentUser());
});

test('__run__', static function (): void {
});
