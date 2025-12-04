# Dashboard de Metricool en PHP

Sistema completo para extraer y visualizar datos de la API de Metricool en tu propio dashboard personalizado.

## 📋 Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Extensión cURL habilitada
- Extensión PDO habilitada
- Cuenta de Metricool con plan **Advanced** o **Custom** (requerido para acceso API)

## 🚀 Instalación

### 1. Obtener credenciales de Metricool

1. Inicia sesión en tu cuenta de Metricool
2. Ve a **Account Settings** > **API**
3. Copia tu **API Token** (userToken)
4. Anota tu **User ID** (visible en la URL: `userId=XXXXXXX`)
5. Anota tu **Blog ID** (visible en la URL: `blogId=XXXXXXX`)

> **Nota:** El acceso a la API solo está disponible en planes Advanced o Custom

### 2. Configurar la base de datos

```bash
# Importar el schema SQL
mysql -u root -p < metricool_schema.sql

# O desde MySQL
mysql -u tu_usuario -p
source metricool_schema.sql;
```

### 3. Configurar archivos PHP

#### Editar `sync_metricool.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'metricool_dashboard');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');

define('METRICOOL_TOKEN', 'tu_token_aqui');
define('METRICOOL_USER_ID', 'tu_user_id');
```

#### Editar `dashboard.php`:

```php
$userToken = 'tu_token_aqui';
$userId = 'tu_user_id';
```

### 4. Estructura de archivos

```
/metricool-dashboard/
├── MetricoolAPI.php          # Clase principal de la API
├── sync_metricool.php        # Script de sincronización
├── dashboard.php             # Dashboard web
├── metricool_schema.sql      # Schema de base de datos
├── example_usage.php         # Ejemplos de uso
├── config.php                # Archivo de configuración
└── logs/                     # Logs de sincronización
```

## 💻 Uso Básico

### Ejemplo rápido

```php
<?php
require_once 'MetricoolAPI.php';

$api = new MetricoolAPI('your_token', 'your_user_id');

// Obtener marcas
$brands = $api->getBrands();
print_r($brands);

// Seleccionar una marca
$api->setBlogId($brands[0]['id']);

// Obtener posts de Instagram
$posts = $api->getInstagramPosts('2024-01-01', '2024-12-31');
print_r($posts);
```

### Sincronización automática

```bash
# Ejecutar sincronización manual
php sync_metricool.php

# Configurar cron job (cada hora)
crontab -e
# Agregar:
0 * * * * /usr/bin/php /path/to/sync_metricool.php

# Configurar cron job (cada 6 horas)
0 */6 * * * /usr/bin/php /path/to/sync_metricool.php
```

## 📊 Funcionalidades

### Clase MetricoolAPI

#### Métodos principales:

- `getBrands()` - Obtener todas las marcas
- `getInstagramPosts($start, $end)` - Posts de Instagram
- `getFacebookPosts($start, $end)` - Posts de Facebook
- `getXPosts($start, $end)` - Posts de X (Twitter)
- `getLinkedInPosts($start, $end)` - Posts de LinkedIn
- `getAnalytics($network, $start, $end)` - Analytics por red
- `getScheduledPosts()` - Posts programados
- `getCompetitors()` - Lista de competidores
- `getBestTimeToPost($network)` - Mejor hora para publicar
- `getAvailableMetrics($network)` - Métricas disponibles

### Base de datos

El sistema incluye:
- ✅ Tablas para todas las redes sociales
- ✅ Analytics diarios
- ✅ Posts programados
- ✅ Seguimiento de competidores
- ✅ Historial de sincronizaciones
- ✅ Vistas optimizadas para reporting

## 🎨 Dashboard Web

Accede al dashboard en tu navegador:

```
http://localhost/metricool-dashboard/dashboard.php
```

El dashboard muestra:
- 📊 Estadísticas generales
- 📈 Total de posts por red social
- ❤️ Engagement (likes, comentarios, shares)
- 📅 Posts más recientes
- ⏰ Posts programados

## 🔧 Personalización

### Agregar nuevas redes sociales

```php
// En MetricoolAPI.php
public function getTikTokPosts($startDate, $endDate) {
    return $this->makeRequest('/tiktok/posts', [
        'init_date' => $startDate,
        'end_date' => $endDate
    ]);
}
```

### Personalizar el dashboard

Edita `dashboard.php` para:
- Cambiar estilos CSS
- Agregar nuevas métricas
- Crear gráficos personalizados
- Filtrar por fechas específicas

### Usar con frameworks

```php
// Laravel
Route::get('/metricool-stats', function() {
    $api = new MetricoolAPI(config('metricool.token'), config('metricool.user_id'));
    $brands = $api->getBrands();
    return view('metricool.dashboard', compact('brands'));
});

// WordPress
add_shortcode('metricool_dashboard', function() {
    $api = new MetricoolAPI(get_option('metricool_token'), get_option('metricool_user_id'));
    // ... código
});
```

## 📈 Endpoints disponibles

Basado en la documentación oficial de Metricool:

```
Base URL: https://app.metricool.com/api

GET /admin/simpleProfiles                    - Lista de marcas
GET /instagram/posts                          - Posts de Instagram
GET /facebook/posts                           - Posts de Facebook
GET /x/posts                                  - Posts de X
GET /linkedin/posts                           - Posts de LinkedIn
GET /pinterest/pins                           - Pins de Pinterest
GET /{network}/analytics                      - Analytics por red
GET /scheduler/posts                          - Posts programados
GET /competitors/list                         - Competidores
GET /{network}/best-time-to-post             - Mejor hora para publicar
GET /{network}/available-metrics             - Métricas disponibles
```

> **Nota:** Para endpoints no documentados, usa el inspector de red del navegador en la plataforma Metricool para descubrir nuevos endpoints.

## 🔍 Encontrar nuevos endpoints

1. Abre Metricool en tu navegador
2. Presiona F12 (DevTools)
3. Ve a la pestaña **Network** > **Fetch/XHR**
4. Navega por Metricool y observa las llamadas
5. Copia el endpoint y parámetros
6. Implementa en tu clase PHP

## 🛠️ Solución de problemas

### Error: "API Token inválido"
- Verifica que tu token sea correcto
- Confirma que tienes un plan Advanced o Custom

### Error: "blogId es requerido"
```php
// Siempre establece el blogId antes de consultar
$api->setBlogId($your_blog_id);
```

### Error de conexión
```php
// Verifica que cURL esté habilitado
php -m | grep curl
```

### Posts no se sincronizan
- Verifica los logs en `/logs/`
- Confirma que el cron job esté configurado
- Verifica permisos de escritura en carpeta logs

## 📝 Logs

Los logs se guardan automáticamente en:
```
/logs/sync_YYYY-MM-DD.log
```

Ejemplo de log:
```
[2024-12-04 10:00:00] [INFO] === Iniciando sincronización completa ===
[2024-12-04 10:00:01] [INFO] Encontradas 2 marcas
[2024-12-04 10:00:02] [INFO]   ✓ instagram: 45 posts sincronizados
[2024-12-04 10:00:03] [SUCCESS] === Sincronización completada en 3 segundos ===
```

## 🔐 Seguridad

- ⚠️ **NUNCA** subas archivos con tokens a repositorios públicos
- Usa variables de entorno para credenciales
- Restringe acceso al dashboard con autenticación
- Usa HTTPS en producción
- Limita acceso IP si es posible

```php
// Usar variables de entorno
$token = getenv('METRICOOL_TOKEN');
$userId = getenv('METRICOOL_USER_ID');
```

## 📚 Recursos adicionales

- [Documentación oficial Metricool API](https://help.metricool.com/en/category/api-integrations-dc0snw/)
- [Swagger/OpenAPI spec](https://app.metricool.com/resources/apidocs/)
- [Guía básica de integración](https://help.metricool.com/en/article/basic-guide-for-api-integration-abukgf/)

## 🤝 Contribuir

Si encuentras mejoras o nuevos endpoints, ¡compártelos!

## 📄 Licencia

Este código es de ejemplo educativo. Úsalo bajo tu propia responsabilidad.

---

**¿Necesitas ayuda?** Revisa los logs, la documentación oficial de Metricool, o contacta a su soporte técnico.
