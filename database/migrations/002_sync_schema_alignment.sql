-- Alinea instalaciones creadas con el esquema anterior.
-- Es idempotente: cada columna se agrega solamente si no existe.

SET @schema_name = DATABASE();

SET @ddl = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'facebook_posts'
          AND COLUMN_NAME = 'website_clicks'
    ),
    'SELECT 1',
    'ALTER TABLE facebook_posts ADD COLUMN website_clicks INT DEFAULT 0 AFTER post_url'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'facebook_posts'
          AND COLUMN_NAME = 'ad_spend'
    ),
    'SELECT 1',
    'ALTER TABLE facebook_posts ADD COLUMN ad_spend DECIMAL(12,2) DEFAULT 0 AFTER website_clicks'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'daily_analytics'
          AND COLUMN_NAME = 'website_clicks'
    ),
    'SELECT 1',
    'ALTER TABLE daily_analytics ADD COLUMN website_clicks INT DEFAULT 0 AFTER profile_views'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'daily_analytics'
          AND COLUMN_NAME = 'ad_spend'
    ),
    'SELECT 1',
    'ALTER TABLE daily_analytics ADD COLUMN ad_spend DECIMAL(12,2) DEFAULT 0 AFTER website_clicks'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
