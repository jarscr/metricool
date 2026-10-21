# Prueba local de sincronización Metricool

Las respuestas son sintéticas, basadas en el contrato público de Metricool:
https://app.metricool.com/api/swagger.json (consultado el 20 de octubre de 2026).
No se consultó un mock del propietario; se usa una simulación local del contrato.

Ejecutar en dos terminales:

```sh
postman simulation run postman/simulations/metricool.sim.yaml --port 4900 --no-history --output ndjson
php tests/metricool-sync-http.php
```

Detener la simulación al terminar con Ctrl+C; si el lanzador permanece activo:

```sh
pkill -INT -f 'simulation run postman/simulations/metricool.sim.yaml'
```

La prueba verifica solicitudes HTTP del cliente real, series de cuenta con
`subject=account` (Facebook `page_media_view`, Instagram `views`, TikTok
`video_views`), posts y reels de Facebook, fechas originales y repetición de
sincronizaciones sin duplicar ni sobrescribir impresiones con datos de posts.
La persistencia usa SQLite en memoria y traduce únicamente la sintaxis UPSERT.
No prueba el dialecto MySQL del servidor ni la paridad con datos reales.

El endpoint oficial de reels Facebook se documenta como CSV. Se admite también
JSON con el esquema FacebookReel. El CSV exige encabezados de esquema (`reelId`,
`date` o fecha compatible, métricas); encabezados desconocidos generan error,
para evitar declarar una sincronización completa omitiendo publicaciones.
La estructura CSV real y las métricas de cuenta deben validarse con credenciales
METRICOOL_USER_TOKEN y METRICOOL_USER_ID antes del despliegue.

No hay cambios de esquema. Para recuperar octubre hay que volver a sincronizar
el período completo; el cron incremental solo procesa ayer y hoy. Los errores
al obtener impresiones o guardar días deben dejar la sincronización parcial.

Corrección Facebook: el historial remoto del brand 3680316 confirmó una serie
vacía para `pageImpressions` en octubre de 2026. El módulo público oficial de
Metricool usa `page_media_view` para períodos a partir del 15 de octubre de 2024
y conserva `pageImpressions` cuando el inicio del período es anterior. Se sigue
esa selección por fecha tanto en timelines como en agregaciones. Fuente
verificada el 20 de octubre de 2026:
https://static.metricool.com/app-static-content/versions/f0ee95e7d0b3/resources/front/js/app-CQgpQhsE.js
La simulación devuelve una serie vacía para la consulta antigua en fechas
actuales: el test falló antes de la corrección y pasó después. Los totales
reales requieren volver a ejecutar el CLI en el servidor con sus credenciales.
