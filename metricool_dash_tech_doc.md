# DOCUMENTACIÓN TÉCNICA
## Dashboard Personalizado - Integración API Metricool

**Versión:** 1.0  
**Última actualización:** Diciembre 2025  
**Estado:** En Desarrollo

---

## 📑 Tabla de Contenidos

1. [Arquitectura General](#arquitectura-general)
2. [Stack Tecnológico](#stack-tecnológico)
3. [Estructura del Proyecto](#estructura-del-proyecto)
4. [Base de Datos](#base-de-datos)
5. [Integración con API Metricool](#integración-con-api-metricool)
6. [API REST Interna](#api-rest-interna)
7. [Frontend](#frontend)
8. [Flujos de Datos](#flujos-de-datos)
9. [Sistema de Sincronización](#sistema-de-sincronización)
10. [Seguridad](#seguridad)
11. [Rendimiento y Optimización](#rendimiento-y-optimización)
12. [Deployment](#deployment)

---

## 🏗️ Arquitectura General

### Diagrama de Componentes

```
┌─────────────────────────────────────────────────────────────────┐
│                        CLIENTE FINAL                            │
│                   (Navegador Web)                               │
└──────────────────────────┬──────────────────────────────────────┘
                           │ HTTPS
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                      SERVIDOR WEB                               │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              LAYER PRESENTACIÓN                          │   │
│  │  ├─ Dashboard Principal (HTML/CSS/JS)                   │   │
│  │  ├─ Vistas por Red Social                               │   │
│  │  ├─ Calendario                                          │   │
│  │  ├─ Reportes                                            │   │
│  │  └─ Exportación                                         │   │
│  └──────────────────────────────────────────────────────────┘   │
│                           │                                      │
│                           ▼                                      │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              LAYER APLICACIÓN (PHP 8.0+)                │   │
│  │  ├─ Router (Routing)                                    │   │
│  │  ├─ Controllers                                         │   │
│  │  ├─ Services                                            │   │
│  │  ├─ Middleware (Auth, Logging)                          │   │
│  │  └─ API REST Interna                                   │   │
│  └──────────────────────────────────────────────────────────┘   │
│                           │                                      │
│                           ▼                                      │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              LAYER LÓGICA DE NEGOCIO                    │   │
│  │  ├─ MetricoolAPI (Integración)                          │   │
│  │  ├─ DataProcessor (Procesamiento)                       │   │
│  │  ├─ ReportGenerator                                     │   │
│  │  └─ CacheManager                                        │   │
│  └──────────────────────────────────────────────────────────┘   │
│                           │                                      │
│                           ▼                                      │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              LAYER DATOS                                │   │
│  │  ├─ MySQL Database                                      │   │
│  │  ├─ Redis Cache (Opcional)                              │   │
│  │  └─ File System (Logs, Reportes)                        │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                           │
                           │ API REST
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                    METRICOOL API                                │
│         https://app.metricool.com/api                           │
│                                                                 │
│  ├─ /admin/simpleProfiles                                      │
│  ├─ /instagram/posts                                           │
│  ├─ /facebook/posts                                            │
│  ├─ /x/posts                                                   │
│  ├─ /linkedin/posts                                            │
│  └─ /other/networks                                            │
└─────────────────────────────────────────────────────────────────┘
```

### Flujo de Datos General

```
1. SINCRONIZACIÓN (cada hora vía cron)
   ┌──────────────┐
   │  Cron Job    │
   └────────┬─────┘
            ▼
   ┌──────────────────────┐
   │ MetricoolAPI Class   │
   │ (Fetch data)         │
   └────────┬─────────────┘
            ▼
   ┌──────────────────────┐
   │ Metricool API        │
   │ (external)           │
   └────────┬─────────────┘
            ▼
   ┌──────────────────────┐
   │ DataProcessor        │
   │ (Transform)          │
   └────────┬─────────────┘
            ▼
   ┌──────────────────────┐
   │ MySQL Database       │
   │ (Store)              │
   └──────────────────────┘

2. VISUALIZACIÓN (usuario abre dashboard)
   ┌──────────────┐
   │ Browser      │
   │ (GET /)      │
   └────────┬─────┘
            ▼
   ┌──────────────────────┐
   │ Router / Controller  │
   └────────┬─────────────┘
            ▼
   ┌──────────────────────┐
   │ Service Layer        │
   │ (Lógica)             │
   └────────┬─────────────┘
            ▼
   ┌──────────────────────┐
   │ MySQL (Query)        │
   │ + Redis Cache        │
   └────────┬─────────────┘
            ▼
   ┌──────────────────────┐
   │ JSON Response        │
   │ (AJAX)               │
   └────────┬─────────────┘
            ▼
   ┌──────────────────────┐
   │ Frontend (Render)    │
   │ JavaScript           │
   └──────────────────────┘
```

---

## 🛠️ Stack Tecnológico

### Backend
- **Lenguaje:** PHP 8.0+ (mínimo 8.1)
- **Servidor Web:** Apache 2.4+ o Nginx
- **Base de Datos:** MySQL 8.0+ o MariaDB 10.5+
- **Caché:** Redis (opcional pero recomendado)
- **PHP Extensions:**
  - `curl` (para llamadas HTTP)
  - `json` (procesamiento JSON)
  - `pdo` (conexión a BD)
  - `date` (manejo de fechas)
  - `filter` (validación)

### Frontend
- **HTML5** + **CSS3** + **JavaScript (ES6+)**
- **Librerías principales:**
  - `Chart.js` o `Recharts` - Gráficos
  - `Moment.js` - Manejo de fechas
  - `Fetch API` - Peticiones AJAX
  - `Bootstrap 5` o `Tailwind CSS` - Framework CSS
- **Responsive:** Mobile First

### Herramientas de Desarrollo
- **Git** - Control de versiones
- **Composer** (opcional) - Gestor de dependencias
- **Postman** - Testing de APIs
- **PHPUnit** - Testing unitario

### Servidor
- **RAM:** 2GB mínimo (4GB recomendado)
- **Disco:** 20GB mínimo
- **OS:** Linux (Ubuntu 20.04+ recomendado)
- **SSL:** Let's Encrypt (gratuito)

---

## 📁 Estructura del Proyecto

```
metricool-dashboard/
│
├── public/                          # Documentos públicos (entry point)
│   ├── index.php                    # Bootstrap principal
│   ├── api.php                      # Entrada para API REST
│   ├── css/
│   │   ├── main.css                 # Estilos principales
│   │   ├── dashboard.css            # Dashboard específico
│   │   └── responsive.css           # Mobile styles
│   ├── js/
│   │   ├── app.js                   # JS principal
│   │   ├── dashboard.js             # Lógica dashboard
│   │   ├── charts.js                # Gráficos
│   │   ├── api-client.js            # Cliente para API
│   │   └── utils.js                 # Funciones auxiliares
│   └── assets/
│       ├── images/
│       ├── fonts/
│       └── icons/
│
├── src/                             # Código fuente PHP
│   ├── Config/
│   │   ├── Config.php               # Configuración general
│   │   ├── Database.php             # Conexión BD
│   │   └── MetricoolConfig.php      # Config Metricool
│   │
│   ├── API/
│   │   ├── MetricoolAPI.php         # Clase integración
│   │   ├── Endpoints/
│   │   │   ├── InstagramEndpoint.php
│   │   │   ├── FacebookEndpoint.php
│   │   │   ├── XEndpoint.php
│   │   │   ├── LinkedInEndpoint.php
│   │   │   └── OtherNetworksEndpoint.php
│   │   └── APIException.php
│   │
│   ├── Services/
│   │   ├── DataService.php          # Obtener datos
│   │   ├── SyncService.php          # Sincronización
│   │   ├── ReportService.php        # Reportes
│   │   ├── ExportService.php        # Exportación
│   │   ├── CacheService.php         # Caché
│   │   └── NotificationService.php  # Notificaciones
│   │
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── ReportController.php
│   │   ├── ExportController.php
│   │   ├── AnalyticsController.php
│   │   └── AdminController.php
│   │
│   ├── Models/
│   │   ├── Post.php
│   │   ├── Analytics.php
│   │   ├── ScheduledPost.php
│   │   ├── Competitor.php
│   │   ├── SyncHistory.php
│   │   └── User.php
│   │
│   ├── Repositories/
│   │   ├── PostRepository.php
│   │   ├── AnalyticsRepository.php
│   │   ├── BrandRepository.php
│   │   └── BaseRepository.php
│   │
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── LoggingMiddleware.php
│   │   ├── RateLimitMiddleware.php
│   │   └── ValidationMiddleware.php
│   │
│   ├── Router/
│   │   └── Router.php               # Enrutador simple
│   │
│   ├── Utils/
│   │   ├── Logger.php               # Logging
│   │   ├── DateHelper.php           # Fechas
│   │   ├── Validator.php            # Validación
│   │   ├── FileHelper.php           # Archivos
│   │   └── PDFGenerator.php         # PDFs
│   │
│   └── Exceptions/
│       ├── APIException.php
│       ├── DatabaseException.php
│       └── ValidationException.php
│
├── console/                         # Comandos CLI
│   ├── sync.php                     # Sincronización manual
│   ├── test-api.php                 # Test de API
│   └── cleanup.php                  # Limpieza de datos
│
├── database/
│   ├── schema.sql                   # Schema principal
│   ├── migrations/                  # Cambios de schema
│   │   ├── 001_create_tables.sql
│   │   ├── 002_add_indexes.sql
│   │   └── 003_create_views.sql
│   └── seeds/                       # Datos de prueba
│       └── DemoData.php
│
├── tests/
│   ├── Unit/
│   │   ├── MetricoolAPITest.php
│   │   ├── DataServiceTest.php
│   │   └── ...
│   ├── Integration/
│   │   └── SyncServiceTest.php
│   └── bootstrap.php
│
├── logs/                            # Logs de aplicación
│   ├── sync/
│   ├── api/
│   └── errors/
│
├── storage/
│   ├── cache/                       # Cache de archivos
│   ├── reports/                     # Reportes generados
│   └── exports/                     # Archivos exportados
│
├── .env                             # Variables de entorno
├── .env.example                     # Template de .env
├── .gitignore                       # Archivos a ignorar
├── composer.json                    # Dependencias (opcional)
├── docker-compose.yml               # Docker (opcional)
├── Dockerfile                       # Docker (opcional)
├── README.md                        # Documentación
├── INSTALL.md                       # Guía de instalación
├── ARCHITECTURE.md                  # Detalles arquitectura
└── CONTRIBUTING.md                  # Guía de contribución
```

---

## 🗄️ Base de Datos

### Diagrama ER

```
brands (1) ──────┬─ (N) instagram_posts
                 ├─ (N) facebook_posts
                 ├─ (N) x_posts
                 ├─ (N) linkedin_posts
                 ├─ (N) daily_analytics
                 ├─ (N) scheduled_posts
                 ├─ (N) competitors
                 └─ (N) sync_history
```

### Tablas Principales

#### `brands`
```sql
id (INT, PRIMARY KEY)
name (VARCHAR 255)
metricool_blog_id (INT, UNIQUE)
metricool_user_id (INT)
description (TEXT, NULL)
active (BOOLEAN, DEFAULT TRUE)
created_at (TIMESTAMP)
updated_at (TIMESTAMP)

INDEXES:
- PRIMARY KEY (id)
- UNIQUE (metricool_blog_id)
- INDEX (active)
```

#### `instagram_posts`
```sql
id (BIGINT, PRIMARY KEY, AUTO_INCREMENT)
post_id (VARCHAR 100, UNIQUE per brand)
brand_id (INT, FOREIGN KEY)
caption (LONGTEXT, NULL)
likes (INT, DEFAULT 0)
comments (INT, DEFAULT 0)
shares (INT, DEFAULT 0)
saves (INT, DEFAULT 0)
reach (INT, DEFAULT 0)
impressions (INT, DEFAULT 0)
engagement_rate (DECIMAL 5,2, DEFAULT 0)
post_type (VARCHAR 50) -- 'post', 'story', 'reel', 'igtv'
media_url (VARCHAR 500, NULL)
post_url (VARCHAR 500, NULL)
published_date (DATETIME)
created_at (TIMESTAMP)
updated_at (TIMESTAMP)

INDEXES:
- PRIMARY KEY (id)
- UNIQUE KEY (post_id, brand_id)
- FOREIGN KEY (brand_id) → brands(id)
- INDEX (brand_id, published_date)
- INDEX (published_date)
```

#### `daily_analytics`
```sql
id (BIGINT, PRIMARY KEY, AUTO_INCREMENT)
brand_id (INT, FOREIGN KEY)
network (VARCHAR 50) -- 'instagram', 'facebook', 'x', 'linkedin'
date (DATE)
followers (INT, DEFAULT 0)
following (INT, DEFAULT 0)
posts_count (INT, DEFAULT 0)
likes (INT, DEFAULT 0)
comments (INT, DEFAULT 0)
shares (INT, DEFAULT 0)
reach (INT, DEFAULT 0)
impressions (INT, DEFAULT 0)
engagement_rate (DECIMAL 5,2, DEFAULT 0)
created_at (TIMESTAMP)
updated_at (TIMESTAMP)

INDEXES:
- PRIMARY KEY (id)
- UNIQUE KEY (brand_id, network, date)
- FOREIGN KEY (brand_id) → brands(id)
- INDEX (brand_id, network, date)
```

#### `scheduled_posts`
```sql
id (BIGINT, PRIMARY KEY, AUTO_INCREMENT)
post_id (VARCHAR 100, NULL)
brand_id (INT, FOREIGN KEY)
network (VARCHAR 50)
content (LONGTEXT)
media_url (VARCHAR 500, NULL)
scheduled_date (DATETIME)
status (VARCHAR 50) -- 'pending', 'published', 'cancelled'
created_at (TIMESTAMP)
updated_at (TIMESTAMP)

INDEXES:
- PRIMARY KEY (id)
- FOREIGN KEY (brand_id) → brands(id)
- INDEX (brand_id, scheduled_date)
- INDEX (status)
```

#### `sync_history`
```sql
id (BIGINT, PRIMARY KEY, AUTO_INCREMENT)
brand_id (INT, FOREIGN KEY, NULL)
sync_type (VARCHAR 100) -- 'instagram', 'facebook', 'all'
start_date (DATE, NULL)
end_date (DATE, NULL)
records_processed (INT, DEFAULT 0)
status (VARCHAR 50) -- 'pending', 'completed', 'error'
error_message (TEXT, NULL)
duration_seconds (INT, NULL)
created_at (TIMESTAMP)

INDEXES:
- PRIMARY KEY (id)
- FOREIGN KEY (brand_id) → brands(id)
- INDEX (brand_id, created_at)
- INDEX (status)
```

### Vistas Especializadas

```sql
-- dashboard_summary
-- posts_by_network
-- top_engagement_posts
-- analytics_by_period
```

---

## 🔌 Integración con API Metricool

### Configuración

```php
// .env
METRICOOL_API_BASE_URL=https://app.metricool.com/api
METRICOOL_USER_TOKEN=your_token_here
METRICOOL_USER_ID=your_user_id
METRICOOL_SYNC_INTERVAL=3600  // segundos (1 hora)
METRICOOL_MAX_RETRIES=3
METRICOOL_TIMEOUT=30
```

### Clase MetricoolAPI

**Responsabilidades:**
- Gestionar autenticación (X-Mc-Auth header)
- Construir URLs de endpoints
- Realizar peticiones HTTP
- Manejo de errores y reintentos
- Normalización de respuestas

**Métodos principales:**
```php
class MetricoolAPI {
    public function __construct(string $token, string $userId)
    public function getBrands(): array
    public function getInstagramPosts(int $blogId, string $startDate, string $endDate): array
    public function getFacebookPosts(int $blogId, string $startDate, string $endDate): array
    public function getXPosts(int $blogId, string $startDate, string $endDate): array
    public function getLinkedInPosts(int $blogId, string $startDate, string $endDate): array
    public function getAnalytics(int $blogId, string $network, string $startDate, string $endDate): array
    public function getScheduledPosts(int $blogId): array
    public function getCompetitors(int $blogId, string $network): array
    public function getBestTimeToPost(int $blogId, string $network): array
}
```

### Manejo de Rate Limits

```
- Metricool API: ~1000 requests/hora
- Implementar:
  - Queue de peticiones
  - Espera entre llamadas (throttling)
  - Caché de datos frecuentes
  - Sincronización por lotes
```

### Ejemplo de Flujo

```php
// 1. Obtener marcas
$brands = $api->getBrands(); // [['id' => 123, 'name' => 'Brand X']]

// 2. Para cada marca
foreach ($brands as $brand) {
    // 3. Obtener posts
    $posts = $api->getInstagramPosts(
        $brand['id'],
        '2025-12-01',
        '2025-12-10'
    );
    
    // 4. Procesar y guardar
    foreach ($posts as $post) {
        // Normalizar datos
        $normalized = [
            'post_id' => $post['id'],
            'brand_id' => $brand['id'],
            'likes' => $post['likes'] ?? 0,
            // ...
        ];
        
        // Guardar en BD
        $this->insertOrUpdate($normalized);
    }
}
```

---

## 🔌 API REST Interna

### Endpoints de Backend

#### Autenticación
```
POST /api/auth/login
POST /api/auth/logout
GET  /api/auth/verify
```

#### Dashboard
```
GET  /api/dashboard/summary              - Resumen general
GET  /api/dashboard/overview?brand_id=1  - Overview de marca
```

#### Posts
```
GET  /api/posts?brand_id=1&network=instagram&start_date=2025-12-01&end_date=2025-12-10
GET  /api/posts/:id
GET  /api/posts/top-engagement?brand_id=1&limit=10
GET  /api/posts/by-network?brand_id=1&network=instagram
```

#### Analytics
```
GET  /api/analytics/daily?brand_id=1&network=instagram&start_date=2025-12-01
GET  /api/analytics/summary?brand_id=1&start_date=2025-12-01&end_date=2025-12-10
GET  /api/analytics/growth?brand_id=1&network=instagram
```

#### Reportes
```
GET  /api/reports/summary?brand_id=1&start_date=2025-12-01&end_date=2025-12-10
GET  /api/reports/by-network?brand_id=1&network=instagram
POST /api/reports/pdf?brand_id=1  - Genera PDF
```

#### Exportación
```
GET  /api/export/csv?brand_id=1&network=instagram&start_date=2025-12-01
GET  /api/export/excel?brand_id=1&type=all
```

#### Sincronización
```
POST /api/sync/manual?brand_id=1           - Fuerza sincronización
GET  /api/sync/status?brand_id=1           - Estado de sincronización
GET  /api/sync/history?brand_id=1&limit=10 - Historial
```

### Estructura de Respuesta

```json
{
  "success": true,
  "data": {
    // datos
  },
  "meta": {
    "total": 100,
    "page": 1,
    "per_page": 20,
    "total_pages": 5
  },
  "timestamp": "2025-12-10T10:30:00Z",
  "request_id": "uuid"
}
```

### Errores

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Brand ID is required",
    "details": {
      "field": "brand_id",
      "value": null
    }
  },
  "timestamp": "2025-12-10T10:30:00Z",
  "request_id": "uuid"
}
```

---

## 🎨 Frontend

### Estructura de Componentes

```
components/
├── Dashboard/
│   ├── DashboardContainer.js
│   ├── SummaryCards.js
│   ├── MetricsGrid.js
│   └── DateRangePicker.js
│
├── Networks/
│   ├── InstagramView.js
│   ├── FacebookView.js
│   ├── XView.js
│   ├── LinkedInView.js
│   └── OtherNetworksView.js
│
├── Posts/
│   ├── PostsList.js
│   ├── PostCard.js
│   ├── PostMetrics.js
│   └── PostFilters.js
│
├── Charts/
│   ├── EngagementChart.js
│   ├── GrowthChart.js
│   ├── FollowersChart.js
│   └── ReachChart.js
│
├── Calendar/
│   ├── CalendarView.js
│   └── ScheduledPostsView.js
│
├── Reports/
│   ├── ReportGenerator.js
│   ├── ExportOptions.js
│   └── ReportPreview.js
│
└── Common/
    ├── Header.js
    ├── Sidebar.js
    ├── LoadingSpinner.js
    └── ErrorBoundary.js
```

### Flujo de Datos Frontend

```javascript
// 1. Inicial
document.addEventListener('DOMContentLoaded', () => {
  initDashboard();
});

// 2. Cargar datos
async function initDashboard() {
  const summary = await apiClient.get('/api/dashboard/summary');
  renderDashboard(summary);
  attachEventListeners();
}

// 3. Actualizar con filtros
async function updateDashboard(filters) {
  const data = await apiClient.get('/api/posts', filters);
  renderPosts(data);
  updateCharts(data);
}

// 4. Manejo de eventos
document.querySelector('.date-picker').addEventListener('change', (e) => {
  updateDashboard(getFilters());
});
```

### Gráficos

```javascript
// Chart.js ejemplo
new Chart(ctx, {
  type: 'line',
  data: {
    labels: ['Dec 1', 'Dec 2', ...],
    datasets: [{
      label: 'Engagement',
      data: [10, 20, 15, ...],
      borderColor: '#667eea'
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: true } }
  }
});
```

---

## 📊 Flujos de Datos

### 1. Flujo de Sincronización

```
[Cron Job (cada hora)]
        ↓
[SyncService.startSync()]
        ↓
[Para cada Brand]
    ├─ Instagram: getInstagramPosts() → DataProcessor → insertPosts()
    ├─ Facebook: getFacebookPosts() → DataProcessor → insertPosts()
    ├─ X: getXPosts() → DataProcessor → insertPosts()
    ├─ LinkedIn: getLinkedInPosts() → DataProcessor → insertPosts()
    └─ Analytics: getAnalytics() → DataProcessor → insertAnalytics()
        ↓
[Guardar SyncHistory]
        ↓
[Notificar si error]
```

### 2. Flujo de Lectura (Dashboard)

```
[Usuario abre dashboard]
        ↓
[GET / index.php]
        ↓
[DashboardController.show()]
        ↓
[DataService.getDashboardSummary()]
        ↓
[Consulta MySQL con caché Redis]
        ↓
[Renderizar HTML/JSON]
        ↓
[Actualizar con AJAX]
        ↓
[JavaScript renderiza gráficos]
```

### 3. Flujo de Exportación

```
[Usuario hace click "Exportar Excel"]
        ↓
[POST /api/export/excel con filtros]
        ↓
[ExportService.generateExcel()]
        ↓
[Consultar datos BD]
        ↓
[Procesado con PhpSpreadsheet]
        ↓
[Guardar en /storage/exports/]
        ↓
[Return descarga]
```

---

## ⚙️ Sistema de Sincronización

### Cron Job Configuration

```bash
# En crontab -e
0 * * * * /usr/bin/php /path/to/console/sync.php

# Ejecutable: console/sync.php
<?php
require 'bootstrap.php';
$sync = new SyncService($db, $api);
$sync->syncAll();
?>
```

### Estrategia de Sincronización

```
PERÍODO DE SINCRONIZACIÓN: 1 hora
DATOS HISTÓRICOS: Últimos 90 días
RETENCIÓN EN BD: 365 días

Proceso:
1. Ejecutar cada hora a las XX:00
2. Verificar si último sync fue exitoso
3. Obtener datos últimas 24 horas
4. Procesar cambios (new/update)
5. Registrar en sync_history
6. Enviar notificación si error

Rate limiting:
- Max 3 intentos de reconexión
- Espera 5 segundos entre intentos
- Timeout de 30 segundos por petición
```

### Procesamiento de Datos

```php
class DataProcessor {
    /**
     * Normalizar datos de Metricool
     */
    public static function normalizeInstagramPost(array $raw): array {
        return [
            'post_id' => $raw['id'] ?? null,
            'caption' => $raw['caption'] ?? null,
            'likes' => (int)($raw['likes'] ?? 0),
            'comments' => (int)($raw['comments'] ?? 0),
            'reaches' => (int)($raw['reach'] ?? 0),
            'engagement_rate' => $this->calculateEngagement(
                $raw['likes'] ?? 0,
                $raw['comments'] ?? 0,
                $raw['reach'] ?? 0
            ),
            'published_date' => $this->parseDate($raw['date'] ?? null),
        ];
    }
    
    private function calculateEngagement($likes, $comments, $reach): float {
        if ($reach === 0) return 0;
        return round((($likes + $comments * 2) / $reach) * 100, 2);
    }
}
```

---

## 🔐 Seguridad

### Autenticación

```
Método: Session-based
├─ Login con usuario/contraseña
├─ Session token en cookie (secure, httponly)
├─ Timeout 24 horas
└─ CSRF protection

Alternativa: API Token
├─ Token generado para usuario
├─ Enviado en header: Authorization: Bearer {token}
└─ Expira cada 30 días
```

### Autorización

```
Niveles:
1. ADMIN
   - Acceso a todas las marcas
   - Gestión de usuarios
   - Configuración del sistema

2. MANAGER
   - Acceso a marcas asignadas
   - Ver todos los datos
   - Generar reportes

3. VIEWER
   - Acceso a marcas asignadas
   - Solo lectura
   - Sin exportación
```

### Validación de Entrada

```php
// Validar parámetros GET/POST
$validator = new Validator();
$validator->validate($_GET['brand_id'], 'required|integer|min:1');
$validator->validate($_GET['start_date'], 'required|date:Y-m-d');
$validator->validate($_GET['end_date'], 'required|date:Y-m-d');

if (!$validator->passes()) {
    return response()->error($validator->errors(), 400);
}
```

### Protección de Credenciales

```
1. Variables de entorno (.env)
   - METRICOOL_USER_TOKEN
   - DB_PASSWORD
   
2. Archivo .gitignore
   - .env
   - logs/
   - storage/
   
3. Permisos de archivo
   - .env: 600 (rw-------)
   - storage/: 755
   
4. Encriptación
   - Datos sensibles en BD con hash
   - Logs sin credenciales
```

### SQL Injection Prevention

```php
// ✅ CORRECTO - Prepared Statements
$stmt = $pdo->prepare(
    "SELECT * FROM posts WHERE brand_id = ? AND published_date > ?"
);
$stmt->execute([$brandId, $startDate]);

// ❌ INCORRECTO
$query = "SELECT * FROM posts WHERE brand_id = $brandId";
```

### XSS Prevention

```php
// En templates
echo htmlspecialchars($post['caption'], ENT_QUOTES, 'UTF-8');

// En JSON responses
$data = json_encode($data, JSON_UNESCAPED_SLASHES);
```

### Rate Limiting

```php
// Limitar sincronizaciones
$lastSync = $this->db->query(
    "SELECT created_at FROM sync_history 
     WHERE brand_id = ? 
     ORDER BY created_at DESC 
     LIMIT 1"
);

if ($lastSync && time() - strtotime($lastSync['created_at']) < 600) {
    throw new Exception("Sync too frequent. Min 10 minutes");
}
```

---

## 🚀 Rendimiento y Optimización

### Índices de BD

```sql
-- Queries más comunes
INDEX (brand_id, published_date DESC)
INDEX (network, published_date DESC)
INDEX (brand_id, network, published_date DESC)
INDEX (status)
INDEX (created_at)
```

### Caché

```php
// Redis caché de queries frecuentes
class CacheService {
    const TTL_DASHBOARD = 300;      // 5 min
    const TTL_POSTS = 600;          // 10 min
    const TTL_ANALYTICS = 1800;     // 30 min
    
    public function get($key, callable $loader) {
        // Si existe en caché, devolver
        if ($cached = $redis->get($key)) {
            return json_decode($cached, true);
        }
        
        // Si no, ejecutar loader
        $data = $loader();
        $redis->setex($key, self::TTL_POSTS, json_encode($data));
        
        return $data;
    }
}
```

### Queries Optimizadas

```php
// ❌ N+1 problem
foreach ($brands as $brand) {
    $posts = $db->query("SELECT * FROM posts WHERE brand_id = ?", [$brand['id']]);
}

// ✅ Solución
$posts = $db->query(
    "SELECT * FROM posts WHERE brand_id IN (?,?,?) ORDER BY brand_id",
    array_column($brands, 'id')
);
```

### Paginación

```php
// Siempre paginar resultados grandes
$page = $_GET['page'] ?? 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$query = "SELECT * FROM posts 
          WHERE brand_id = ? 
          ORDER BY published_date DESC 
          LIMIT ? OFFSET ?";
```

### Compresión GZIP

```apache
# .htaccess
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/xml
  AddOutputFilterByType DEFLATE text/css text/javascript
  AddOutputFilterByType DEFLATE application/json
</IfModule>
```

---

## 🚢 Deployment

### Requerimientos del Servidor

```
PHP 8.0+
- memory_limit = 256M
- max_execution_time = 300
- upload_max_filesize = 50M
- extensions: curl, json, pdo, date, filter

MySQL 8.0+
- character_set_server = utf8mb4
- max_allowed_packet = 16M

Linux
- Ubuntu 20.04 LTS+
- SSH access
- Sudo privileges
```

### Instalación Step-by-Step

```bash
# 1. Clonar repositorio
cd /var/www
git clone https://repo.git metricool-dashboard

# 2. Configurar permisos
chmod -R 755 metricool-dashboard/
chmod -R 755 metricool-dashboard/storage/
chmod -R 755 metricool-dashboard/logs/

# 3. Crear .env
cp metricool-dashboard/.env.example metricool-dashboard/.env
nano metricool-dashboard/.env

# 4. Crear BD
mysql -u root -p < metricool-dashboard/database/schema.sql

# 5. Configurar Apache/Nginx
# Crear virtual host con DocumentRoot → /var/www/metricool-dashboard/public

# 6. SSL
certbot certonly --apache -d domain.com

# 7. Cron job
(crontab -l 2>/dev/null; echo "0 * * * * /usr/bin/php /var/www/metricool-dashboard/console/sync.php") | crontab -

# 8. Test
php /var/www/metricool-dashboard/console/test-api.php
```

### Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName dashboard.client.com
    DocumentRoot /var/www/metricool-dashboard/public
    
    <Directory /var/www/metricool-dashboard/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteBase /
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^(.*)$ index.php?/$1 [L]
        </IfModule>
    </Directory>
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/dashboard.client.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/dashboard.client.com/privkey.pem
</VirtualHost>
```

### Docker (Opcional)

```dockerfile
FROM php:8.1-fpm
RUN docker-php-ext-install pdo pdo_mysql
COPY . /var/www/
WORKDIR /var/www
RUN chown -R www-data:www-data /var/www
EXPOSE 9000
```

---

## 🔍 Monitoreo

### Logs

```
logs/
├── sync/
│   └── sync_2025-12-10.log
├── api/
│   └── requests_2025-12-10.log
└── errors/
    └── errors_2025-12-10.log
```

### Métricas a Monitorear

```
1. Sincronización
   - Frequency: ¿Se ejecuta a tiempo?
   - Success rate: % de sincronizaciones exitosas
   - Duration: Tiempo promedio

2. Rendimiento
   - Response time: < 500ms
   - DB queries: < 10 por request
   - Cache hit rate: > 80%

3. Datos
   - Records synced: Total por período
   - Data staleness: Última sincronización
   - Discrepancias: Posts out of sync

4. Sistema
   - Disk space: Logs y exports
   - Memory usage: PHP/MySQL
   - CPU usage: Durante sincronización
```

### Health Check

```php
// GET /api/health
{
  "status": "ok",
  "database": true,
  "metricool_api": true,
  "last_sync": "2025-12-10T10:30:00Z",
  "sync_age_minutes": 5,
  "disk_free_mb": 15000,
  "memory_usage_mb": 256
}
```

---

## 📚 Convenciones de Código

### PHP

```php
// Naming
class UserRepository       // PascalCase
public function getUsers() // camelCase
const MAX_RETRIES = 3;     // UPPER_SNAKE_CASE

// Indentation
use 4 spaces (not tabs)

// Docblocks
/**
 * Obtener posts de una marca
 * 
 * @param int $brandId ID de la marca
 * @param string $network Red social
 * @return array Posts encontrados
 * @throws \Exception
 */
```

### JavaScript

```javascript
// Naming
const MAX_RETRIES = 3;
function fetchDashboard() {}
class ChartManager {}

// Async/Await
async function loadData() {
  try {
    const data = await apiClient.get('/api/posts');
    return data;
  } catch (error) {
    logger.error(error);
  }
}
```

### SQL

```sql
-- Naming
SELECT id, user_id FROM users -- lowercase_snake_case

-- Indentation
SELECT 
    id,
    name,
    email
FROM users
WHERE created_at > ?
ORDER BY created_at DESC
LIMIT 10;
```

---

## 🧪 Testing

### Unit Testing

```php
class MetricoolAPITest extends TestCase {
    public function test_getBrands_returns_array() {
        $api = new MetricoolAPI('token', 'userId');
        $result = $api->getBrands();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result[0]);
    }
}
```

### Integration Testing

```php
class SyncServiceTest extends TestCase {
    public function test_sync_saves_posts_to_database() {
        $service = new SyncService($db, $api);
        $service->syncBrand(123);
        
        $posts = $db->query("SELECT COUNT(*) FROM instagram_posts WHERE brand_id = 123");
        $this->assertGreaterThan(0, $posts);
    }
}
```

---

## 📞 Puntos de Contacto Técnico

**Soporte Metricool API:**
- Documentación: https://help.metricool.com
- Status: https://status.metricool.com
- Email: support@metricool.com

**En caso de errores:**
1. Revisar logs en `/logs/`
2. Verificar conectividad a Metricool API
3. Validar credenciales en `.env`
4. Ejecutar health check endpoint

---

**Fin de Documentación Técnica**