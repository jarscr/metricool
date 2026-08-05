<?php

require __DIR__ . '/../bootstrap.php';

use Metricool\Config\Database;
use Metricool\Exceptions\AuthorizationException;
use Metricool\Middleware\AuthMiddleware;
use Metricool\Services\UserService;

AuthMiddleware::requireAuth();

try {
    AuthMiddleware::requireAdmin();
} catch (AuthorizationException $e) {
    http_response_code($e->statusCode());
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso denegado</title>
        <link rel="stylesheet" href="assets/css/dashboard.css">
    </head>
    <body class="login-page">
        <div class="login-card">
            <h1>Acceso denegado</h1>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
            <p><a href="index.php">Ir al dashboard</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$users = new UserService(Database::getConnection());
$error = null;
$success = null;
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthMiddleware::validateCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'El token CSRF no es válido.';
    } else {
        try {
            $saved = $users->saveCompanyUser([
                'id' => $_POST['user_id'] ?? null,
                'email' => $_POST['email'] ?? '',
                'display_name' => $_POST['display_name'] ?? '',
                'brand_id' => $_POST['brand_id'] ?? 0,
                'password' => $_POST['password'] ?? '',
                'active' => isset($_POST['active']) ? '1' : '0',
            ]);

            $success = 'Cuenta guardada correctamente.';
            $editUser = $saved;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if ($editUser === null && isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editUser = $users->getCompanyUserById((int) $_GET['edit']);
}

$csrfToken = AuthMiddleware::csrfToken();
$brandOptions = $users->getBrands();
$companyUsers = $users->getCompanyUsers();
$currentUser = AuthMiddleware::currentUser();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de Accesos</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <div class="app">
        <header class="header">
            <div class="header__brand">
                <h1>Administración de accesos</h1>
                <p class="header__subtitle">Gestiona las cuentas de cada empresa por marca.</p>
            </div>
            <div class="header__actions">
                <div class="field">
                    <span>Administrador</span>
                    <strong><?= htmlspecialchars((string) ($currentUser['email'] ?? '')) ?></strong>
                </div>
                <a class="btn btn--secondary" href="index.php">Dashboard</a>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="btn">Cerrar sesión</button>
                </form>
            </div>
        </header>

        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="sync-banner"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel__header">
                <h2><?= $editUser ? 'Editar cuenta de empresa' : 'Nueva cuenta de empresa' ?></h2>
            </div>
            <div class="panel__body">
                <form method="post" class="login-card" style="max-width: 100%;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) ($editUser['id'] ?? '')) ?>">
                    <label class="field">
                        <span>Nombre visible</span>
                        <input type="text" name="display_name" required value="<?= htmlspecialchars((string) ($editUser['display_name'] ?? '')) ?>">
                    </label>
                    <label class="field">
                        <span>Correo electrónico</span>
                        <input type="email" name="email" required value="<?= htmlspecialchars((string) ($editUser['email'] ?? '')) ?>">
                    </label>
                    <label class="field">
                        <span>Marca</span>
                        <select name="brand_id" required>
                            <option value="">Selecciona una marca</option>
                            <?php foreach ($brandOptions as $brand): ?>
                                <option value="<?= (int) $brand['id'] ?>" <?= ((int) ($editUser['brand_id'] ?? 0) === (int) $brand['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($brand['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span><?= $editUser ? 'Nueva contraseña (opcional)' : 'Contraseña' ?></span>
                        <input type="password" name="password" <?= $editUser ? '' : 'required' ?>>
                    </label>
                    <label class="field">
                        <span>
                            <input type="checkbox" name="active" value="1" <?= (($editUser['active'] ?? true) ? 'checked' : '') ?>>
                            Cuenta activa
                        </span>
                    </label>
                    <button type="submit" class="btn btn--primary">Guardar cuenta</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel__header">
                <h2>Cuentas registradas</h2>
            </div>
            <div class="panel__body">
                <?php if ($companyUsers === []): ?>
                    <p>No hay cuentas company registradas todavía.</p>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 0.5rem;">Marca</th>
                                <th style="text-align: left; padding: 0.5rem;">Nombre</th>
                                <th style="text-align: left; padding: 0.5rem;">Correo</th>
                                <th style="text-align: left; padding: 0.5rem;">Estado</th>
                                <th style="text-align: left; padding: 0.5rem;">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companyUsers as $companyUser): ?>
                                <tr>
                                    <td style="padding: 0.5rem;"><?= htmlspecialchars($companyUser['brand_name']) ?></td>
                                    <td style="padding: 0.5rem;"><?= htmlspecialchars($companyUser['display_name']) ?></td>
                                    <td style="padding: 0.5rem;"><?= htmlspecialchars($companyUser['email']) ?></td>
                                    <td style="padding: 0.5rem;"><?= $companyUser['active'] ? 'Activa' : 'Inactiva' ?></td>
                                    <td style="padding: 0.5rem;"><a href="admin.php?edit=<?= (int) $companyUser['id'] ?>">Editar</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>
