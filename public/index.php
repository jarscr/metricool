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
    <title>Dashboard IDEAS MCW</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
    <script src="assets/js/api-client.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/api-client.js') ?>" defer></script>
    <script src="assets/js/dashboard.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/dashboard.js') ?>" defer></script>
    <link rel="shortcut icon" type="image/x-icon" href="/assets/images/icon.png">
    <link rel="apple-touch-icon" href="/assets/images/icon.png" />
</head>

<body class="dashboard-page--brand-gradient">
    <div class="app">
        <header class="header">
            <div class="header__identity">
                <div><img src="/assets/images/logo.png" alt="IDEAS MCW" width="200"></div>
                <div class="header__brand">
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
                <button type="button" class="btn btn--report" id="downloadReport" disabled>Descargar PDF</button>
            </div>
        </section>

        <div id="errorBox" class="error-box hidden"></div>

        <section class="sync-banner" id="syncBanner">
            <span class="sync-banner__pulse" aria-hidden="true"></span>
            <span id="syncInfo">Cargando estado de sincronización...</span>
        </section>

        <section class="trend-panel hidden" id="followersSection" aria-labelledby="followersTitle">
            <div class="trend-panel__header">
                <div class="trend-summary">
                    <p class="eyebrow">Comunidad</p>
                    <div class="trend-summary__title">
                        <h2 id="followersTitle">Seguidores</h2>
                        <strong id="followersTotal">0</strong>
                    </div>
                    <div class="composition-bar" id="followersComposition" aria-hidden="true"></div>
                    <small>Último total disponible en Metricool</small>
                </div>
                <div class="trend-network-grid" id="followersGrid" aria-label="Cantidad de seguidores por red"></div>
            </div>
            <div class="trend-panel__chart">
                <div class="trend-panel__chart-heading">
                    <span>Evolución de seguidores</span>
                    <span id="followersScaleHint"></span>
                </div>
                <canvas id="followersChart" role="img" aria-label="Evolución de seguidores por red"></canvas>
                <p class="trend-panel__empty hidden" id="followersChartEmpty">No hay historial de seguidores dentro del período seleccionado.</p>
            </div>
        </section>

        <section class="trend-panel" id="reachSection" aria-labelledby="reachTitle">
            <div class="trend-panel__header">
                <div class="trend-summary">
                    <p class="eyebrow">Alcance</p>
                    <div class="trend-summary__title">
                        <h2 id="reachTitle">Impresiones</h2>
                        <strong id="impressionsTotal">0</strong>
                    </div>
                    <div class="composition-bar" id="impressionsComposition" aria-hidden="true"></div>
                    <small>Total acumulado del período seleccionado</small>
                </div>
                <div class="trend-network-grid" id="impressionsGrid" aria-label="Totales de impresiones por red"></div>
            </div>
            <div class="trend-panel__chart">
                <div class="trend-panel__chart-heading">
                    <span>Evolución de impresiones</span>
                    <span id="analyticsScaleHint"></span>
                </div>
                <canvas id="analyticsChart" role="img" aria-label="Impresiones diarias por red"></canvas>
                <p class="trend-panel__empty hidden" id="analyticsChartEmpty">No hay historial de impresiones dentro del período seleccionado.</p>
            </div>
        </section>

        <section class="metrics-section" id="activitySection" aria-labelledby="activityTitle">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Actividad</p>
                    <h2 id="activityTitle">Panorama general</h2>
                </div>
                <p>Contenido publicado y respuesta de la audiencia</p>
            </div>
            <div class="stats-grid" id="statsGrid"></div>
        </section>

        <section class="metrics-section hidden" id="adsSection" aria-labelledby="adsTitle">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Publicidad</p>
                    <h2 id="adsTitle">Rendimiento de anuncios</h2>
                </div>
                <p>Resultados del período seleccionado</p>
            </div>
            <div class="ads-grid" id="adsGrid" aria-label="Rendimiento de Meta Ads y Google Ads"></div>
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
            <span>Panel de rendimiento by IDEAS MCW</span>
            <span>Desarrollado por <strong>JARS Costa Rica</strong> · <?= date('Y') ?></span>
        </footer>
    </div>
</body>

</html>