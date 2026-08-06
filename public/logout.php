<?php

require __DIR__ . '/../bootstrap.php';

use Metricool\Middleware\AuthMiddleware;

AuthMiddleware::requireAuth();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthMiddleware::validateCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'El token CSRF no es válido.';
    } else {
        AuthMiddleware::logout();
        header('Location: login.php');
        exit;
    }
}

$csrfToken = AuthMiddleware::csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerrar sesión</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body class="login-page">
    <form class="login-card" method="post">
        <h1>Cerrar sesión</h1>
        <p>Confirma la salida del dashboard.</p>
        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <button type="submit" class="btn btn--primary">Cerrar sesión</button>
        <p><a href="<?= AuthMiddleware::isAdmin() ? 'admin.php' : 'index.php' ?>">Cancelar</a></p>
    </form>
</body>
</html>
