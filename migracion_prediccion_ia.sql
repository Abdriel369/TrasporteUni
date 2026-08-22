-- ============================================================
-- migracion_prediccion_ia.sql
--
-- Ejecuta este script SOLO si ya tienes el contenedor de MySQL
-- corriendo con datos (por eso no se puede editar init.sql y
-- listo: init.sql únicamente se ejecuta la PRIMERA vez que se crea
-- el volumen de MySQL).
--
-- Cómo ejecutarlo:
--   docker exec -i mysqldb mysql -uusuario -ppass1234 transporte < migracion_prediccion_ia.sql
-- (o desde phpMyAdmin/MySQL Workbench, pegando el contenido)
-- ============================================================

USE transporte;

ALTER TABLE ruta
    ADD COLUMN prediccion_valor   DECIMAL(8,2) NULL,
    ADD COLUMN prediccion_mensaje VARCHAR(255) NULL,
    ADD COLUMN prediccion_recom   VARCHAR(20)  NULL;
