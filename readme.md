# Dashboard Metricool

Dashboard PHP/MySQL que sincroniza y muestra únicamente Facebook, Instagram y TikTok. La aplicación consulta MySQL; las llamadas a Metricool se ejecutan desde el proceso de sincronización.

## Requisitos

- PHP 8.1 o superior con cURL y PDO MySQL
- MySQL 5.7 o superior
- Acceso API de Metricool

## Configuración

1. Copiar `.env.example` a `.env` y configurar las credenciales de Metricool, MySQL y el administrador inicial.
2. Para una instalación nueva, importar `database/schema.sql`.
3. Para una instalación existente, aplicar en orden `database/migrations/001_dashboard_access.sql` y `database/migrations/002_sync_schema_alignment.sql`.
4. Publicar `public/` como raíz web. No se debe exponer `.env`, `database/`, `src/` ni los scripts de consola.

El primer inicio de sesión crea el administrador en `dashboard_users` usando `DASHBOARD_ADMIN_EMAIL` y `DASHBOARD_ADMIN_PASSWORD`. La contraseña se guarda con `password_hash`; no se almacena en texto plano. Desde `public/admin.php`, el administrador crea cuentas de empresa, les asigna una marca de Metricool y define o cambia su contraseña.

Cada usuario de empresa queda ligado a un único `brand_id`. El servidor valida ese alcance en todas las rutas de la API, por lo que cambiar manualmente el selector o el parámetro `brand_id` no permite consultar otra empresa.

## Sincronización

```bash
php console/sync.php
```

Sin parámetros, el comando trabaja en modo incremental y sincroniza únicamente ayer y hoy según `METRICOOL_TIMEZONE`. El día anterior se incluye para recuperar información que Metricool pudiera publicar después de medianoche. Las operaciones son actualizaciones idempotentes, por lo que ejecutar varias veces el mismo rango no duplica los registros.

Ejemplo de cron cuatro veces al día, cada seis horas:

```cron
0 */6 * * * cd /ruta/al/proyecto && /usr/bin/php console/sync.php >> logs/cron.log 2>&1
```

Rango manual para una carga histórica:

```bash
php console/sync.php --start=2026-01-01 --end=2026-08-05
```

Facebook e Instagram usan los endpoints `stats` con fechas `YYYYMMDD`, aunque el comando recibe `YYYY-MM-DD`. TikTok usa el endpoint actual `/v2/analytics/posts/tiktok`. Los resultados se guardan en:

- `instagram_posts`
- `facebook_posts`
- `tiktok_posts`
- `daily_analytics`
- `scheduled_posts`
- `sync_history`

Los errores de una publicación individual se registran y no detienen el resto del lote. Los logs se guardan en la ruta definida por `SYNC_LOG_DIR`.

## Verificación local

```bash
php tests/run.php
php tests/AuthUserServiceTest.php
find src public console tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/js/dashboard.js
node --check public/assets/js/api-client.js
```

## Seguridad operativa

- No versionar `.env`, logs ni configuración SFTP.
- Publicar solamente el directorio `public/`.
- Ejecutar las migraciones antes de desplegar el código nuevo.
- Hacer una sincronización controlada y revisar `sync_history` después del despliegue.
