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
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body class="admin-page">
    <div class="app app--admin">
        <header class="header admin-header">
            <div class="header__identity">
                <div class="product-mark" aria-hidden="true"><span></span><span></span><span></span></div>
                <div class="header__brand">
                    <p class="eyebrow">Configuración</p>
                    <h1>Administración de accesos</h1>
                    <p class="header__subtitle">Gestiona las cuentas de cada empresa por marca.</p>
                </div>
            </div>
            <div class="header__session">
                <div class="user-chip">
                    <span class="user-chip__status" aria-hidden="true"></span>
                    <span>
                        <small>Administrador</small>
                        <strong><?= htmlspecialchars((string) ($currentUser['email'] ?? '')) ?></strong>
                    </span>
                </div>
                <a class="btn" href="index.php">Volver al dashboard</a>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="btn btn--quiet">Cerrar sesión</button>
                </form>
            </div>
        </header>

        <?php if ($error): ?>
            <div class="error-box" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="admin-notice admin-notice--success" role="status">
                <span aria-hidden="true"></span><?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <section class="panel admin-editor">
            <div class="panel__header">
                <div>
                    <p class="eyebrow"><?= $editUser ? 'Edición' : 'Nueva empresa' ?></p>
                    <h2><?= $editUser ? 'Editar cuenta de empresa' : 'Crear cuenta de empresa' ?></h2>
                </div>
                <span class="panel__descriptor">Cada cuenta tendrá acceso únicamente a la marca asignada</span>
            </div>
            <div class="panel__body admin-editor__body">
                <form method="post" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) ($editUser['id'] ?? '')) ?>">
                    <label class="field admin-field">
                        <span>Nombre visible</span>
                        <input type="text" name="display_name" required autocomplete="name" placeholder="Nombre de la empresa o contacto" value="<?= htmlspecialchars((string) ($editUser['display_name'] ?? '')) ?>">
                    </label>
                    <label class="field admin-field">
                        <span>Correo electrónico</span>
                        <input type="email" name="email" required autocomplete="username" placeholder="empresa@dominio.com" value="<?= htmlspecialchars((string) ($editUser['email'] ?? '')) ?>">
                    </label>
                    <label class="field admin-field">
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
                    <label class="field admin-field">
                        <span><?= $editUser ? 'Nueva contraseña (opcional)' : 'Contraseña' ?></span>
                        <input type="password" name="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres" <?= $editUser ? '' : 'required' ?>>
                    </label>
                    <label class="account-toggle">
                        <input type="checkbox" name="active" value="1" <?= (($editUser['active'] ?? true) ? 'checked' : '') ?>>
                        <span class="account-toggle__track" aria-hidden="true"><span></span></span>
                        <span class="account-toggle__copy">
                            <strong>Cuenta activa</strong>
                            <small>Permite que la empresa ingrese y consulte su marca.</small>
                        </span>
                    </label>
                    <div class="admin-form__actions">
                        <?php if ($editUser): ?>
                            <a class="btn btn--quiet" href="admin.php">Cancelar edición</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary"><?= $editUser ? 'Guardar cambios' : 'Crear cuenta' ?></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel admin-accounts">
            <div class="panel__header">
                <div>
                    <p class="eyebrow">Directorio</p>
                    <h2>Cuentas registradas</h2>
                </div>
                <span class="admin-count"><?= count($companyUsers) ?> <?= count($companyUsers) === 1 ? 'cuenta' : 'cuentas' ?></span>
            </div>
            <div class="panel__body admin-accounts__body">
                <?php if ($companyUsers === []): ?>
                    <div class="admin-empty-state">
                        <span aria-hidden="true">+</span>
                        <strong>Aún no hay cuentas de empresa</strong>
                        <p>Completa el formulario superior para crear el primer acceso.</p>
                    </div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Marca</th>
                                    <th>Nombre</th>
                                    <th>Correo</th>
                                    <th>Estado</th>
                                    <th><span class="sr-only">Acción</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companyUsers as $companyUser): ?>
                                    <tr>
                                        <td data-label="Marca"><strong><?= htmlspecialchars($companyUser['brand_name']) ?></strong></td>
                                        <td data-label="Nombre"><?= htmlspecialchars($companyUser['display_name']) ?></td>
                                        <td data-label="Correo"><?= htmlspecialchars($companyUser['email']) ?></td>
                                        <td data-label="Estado"><span class="account-status <?= $companyUser['active'] ? 'account-status--active' : 'account-status--inactive' ?>"><?= $companyUser['active'] ? 'Activa' : 'Inactiva' ?></span></td>
                                        <td data-label="Acción"><a class="btn btn--table" href="admin.php?edit=<?= (int) $companyUser['id'] ?>">Editar</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <footer class="app-footer">
            <span>Gestión de accesos por empresa</span>
            <span>Desarrollado por <strong>JARS Costa Rica</strong> · <?= date('Y') ?></span>
        </footer>
    </div>
</body>
</html>
