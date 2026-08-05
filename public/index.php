<?php

require __DIR__ . '/../bootstrap.php';

use Metricool\Middleware\AuthMiddleware;

AuthMiddleware::requireAuth();
$currentUser = AuthMiddleware::currentUser();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Metricool</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
    <script src="assets/js/api-client.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/api-client.js') ?>" defer></script>
    <script src="assets/js/dashboard.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/dashboard.js') ?>" defer></script>
</head>
<body>
    <div class="app">
        <header class="header">
            <div class="header__identity">
                <div class="product-mark" aria-hidden="true"><span></span><span></span><span></span></div>
                <div class="header__brand">
                    <p class="eyebrow">Social intelligence</p>
                    <h1>Panel de rendimiento</h1>
                    <p class="header__subtitle">Resumen operativo de tus canales sociales</p>
                </div>
            </div>
            <div class="header__session">
                <div class="user-chip">
                    <span class="user-chip__status" aria-hidden="true"></span>
                    <span>
                        <small>Sesión activa</small>
                        <strong><?= htmlspecialchars((string) ($currentUser['display_name'] ?? $currentUser['email'] ?? 'Usuario')) ?></strong>
                    </span>
                </div>
                <?php if (AuthMiddleware::isAdmin()): ?>
                    <a class="btn btn--quiet" href="admin.php">Administrar accesos</a>
                <?php endif; ?>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthMiddleware::csrfToken()) ?>">
                    <button type="submit" class="btn btn--quiet">Cerrar sesión</button>
                </form>
            </div>
        </header>

        <section class="filter-bar" aria-label="Filtros del dashboard">
            <div class="filter-bar__intro">
                <p class="eyebrow">Vista de datos</p>
                <strong>Configura el período</strong>
            </div>
            <div class="header__actions">
                <label class="field">
                    <span>Marca</span>
                    <select id="brandSelect"></select>
                </label>
                <label class="field">
                    <span>Desde</span>
                    <input type="date" id="startDate">
                </label>
                <label class="field">
                    <span>Hasta</span>
                    <input type="date" id="endDate">
                </label>
                <label class="field">
                    <span>Red</span>
                    <select id="networkSelect">
                        <option value="">Todas</option>
                        <option value="instagram">Instagram</option>
                        <option value="facebook">Facebook</option>
                        <option value="tiktok">TikTok</option>
                    </select>
                </label>
                <button type="button" class="btn btn--primary" id="applyFilters">Actualizar</button>
            </div>
        </section>

        <div id="errorBox" class="error-box hidden"></div>

        <section class="sync-banner" id="syncBanner">
            <span class="sync-banner__pulse" aria-hidden="true"></span>
            <span id="syncInfo">Cargando estado de sincronización...</span>
        </section>

        <section class="metrics-section" aria-labelledby="activityTitle">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Actividad</p>
                    <h2 id="activityTitle">Panorama general</h2>
                </div>
                <p>Contenido publicado y respuesta de la audiencia</p>
            </div>
            <div class="stats-grid" id="statsGrid"></div>
        </section>

        <section class="metrics-section metrics-section--reach" aria-labelledby="reachTitle">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Alcance</p>
                    <h2 id="reachTitle">Impresiones por canal</h2>
                </div>
                <p>Totales del período seleccionado</p>
            </div>
            <div class="impressions-grid" id="impressionsGrid" aria-label="Totales de impresiones por red"></div>
        </section>

        <section class="charts-grid">
            <article class="panel panel--chart-main">
                <div class="panel__header">
                    <div>
                        <p class="eyebrow">Evolución</p>
                        <h2>Impresiones diarias</h2>
                    </div>
                    <p class="panel__hint" id="analyticsScaleHint"></p>
                </div>
                <div class="panel__body panel__body--chart">
                    <canvas id="analyticsChart"></canvas>
                </div>
            </article>
            <article class="panel">
                <div class="panel__header">
                    <div>
                        <p class="eyebrow">Distribución</p>
                        <h2>Posts por red</h2>
                    </div>
                </div>
                <div class="panel__body panel__body--chart">
                    <canvas id="networkChart"></canvas>
                </div>
            </article>
        </section>

        <section class="panel">
            <div class="panel__header">
                <div>
                    <p class="eyebrow">Contenido</p>
                    <h2>Publicaciones recientes</h2>
                </div>
                <span class="panel__descriptor">Últimas 10 publicaciones</span>
            </div>
            <div class="panel__body">
                <div id="postsList" class="posts-list"></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel__header">
                <div>
                    <p class="eyebrow">Calendario</p>
                    <h2>Posts programados</h2>
                </div>
                <span class="panel__descriptor">Pendientes de publicación</span>
            </div>
            <div class="panel__body">
                <div id="scheduledList" class="posts-list"></div>
            </div>
        </section>

        <footer class="app-footer">
            <span>Panel de analítica social</span>
            <span>Desarrollado por <strong>JARS Costa Rica</strong> · <?= date('Y') ?></span>
        </footer>
    </div>
</body>
</html>
