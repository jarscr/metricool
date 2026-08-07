<?php

require __DIR__ . '/../bootstrap.php';

use Metricool\Config\Database;
use Metricool\Middleware\AuthMiddleware;
use Metricool\Services\UserService;

AuthMiddleware::startSession();

$error = null;

if (AuthMiddleware::isAuthenticated()) {
    header('Location: ' . (AuthMiddleware::isAdmin() ? 'admin.php' : 'index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!AuthMiddleware::validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Intenta de nuevo.';
    } else {
        $users = new UserService(Database::getConnection());

        if (AuthMiddleware::attemptLogin($email, $password, $users)) {
            header('Location: ' . (AuthMiddleware::isAdmin() ? 'admin.php' : 'index.php'));
            exit;
        }
        $error = 'Credenciales inválidas';
    }
}

$csrfToken = AuthMiddleware::csrfToken();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso | IDEAS MCW</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <link rel="shortcut icon" type="image/x-icon" href="/assets/images/icon.png">
    <link rel="apple-touch-icon" href="/assets/images/icon.png"/>
</head>
<body class="login-page login-page--brand-gradient">
    <main class="auth-shell">
        <section class="auth-intro" aria-labelledby="loginProductTitle">
            <div>
                <div class="auth-brand">
                    <div><img src="/assets/images/logo.png" alt="IDEAS MCW" width="200" height="100"></div>
                    <div>
                        <p class="eyebrow">Social intelligence</p>
                        <strong>Panel de rendimiento</strong>
                    </div>
                </div>

                <div class="auth-intro__copy">
                    <p class="eyebrow">Decisiones con contexto</p>
                    <h1 id="loginProductTitle">Tus canales sociales,<br>en una sola lectura.</h1>
                    <p>Consulta el rendimiento de la marca con información reciente.</p>
                </div>
            </div>

           
        </section>

        <section class="auth-panel" aria-labelledby="loginTitle">
            <div class="auth-panel__content">
                <div class="auth-panel__status"><span aria-hidden="true"></span> Acceso seguro</div>
                <div class="auth-panel__heading">
                    <p class="eyebrow">Dashboard de rendimiento</p>
                    <h2 id="loginTitle">Bienvenido</h2>
                </div>

                <?php if ($error): ?>
                    <div class="error-box auth-error" role="alert">
                        <strong>No fue posible ingresar</strong>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form class="auth-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <label class="field auth-field">
                        <span>Correo electrónico</span>
                        <input type="email" name="email" required autofocus autocomplete="username" inputmode="email" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>">
                    </label>
                    <label class="field auth-field">
                        <span>Contraseña</span>
                        <input type="password" name="password" required autocomplete="current-password">
                    </label>
                    <button type="submit" class="btn btn--primary auth-submit">
                        <span>Ingresar al panel</span>
                        <span aria-hidden="true">→</span>
                    </button>
                </form>

                <p class="auth-help">Si no tienes acceso o necesitas restablecerlo, contacta a IDEAS MCW.</p>
            </div>

            <footer class="auth-footer">
                <span>Acceso protegido</span>
                <span>Desarrollado por <strong>JARS Costa Rica</strong> · <?= date('Y') ?></span>
            </footer>
        </section>
    </main>
</body>
</html>
