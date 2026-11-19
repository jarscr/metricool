-- Optimiza el directorio administrativo al filtrar usuarios por rol y estado.
-- No cambia roles, contraseñas ni datos de cuentas existentes.
-- Aplicar una sola vez en instalaciones existentes después de 002_sync_schema_alignment.sql.

ALTER TABLE dashboard_users
    ADD INDEX idx_dashboard_user_role_active (role, is_active);
