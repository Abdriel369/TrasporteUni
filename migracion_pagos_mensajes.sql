-- ============================================================
-- migracion_pagos_mensajes.sql - UniTransporte
--
-- Ejecuta este script SOLO si ya tienes el contenedor de MySQL
-- corriendo con datos. init.sql únicamente se ejecuta la PRIMERA
-- vez que se crea el volumen de MySQL, así que los cambios de
-- esquema en una base ya existente se aplican con este archivo.
--
-- Cómo ejecutarlo (desde la raíz del proyecto):
--   docker exec -i mysqldb mysql -uusuario -ppass1234 transporte < migracion_pagos_mensajes.sql
-- (o pegando el contenido en phpMyAdmin / MySQL Workbench)
--
-- Qué agrega:
--   1) viaje.motivo_cancelacion / viaje.cancelacion_vista
--      -> avisar al pasajero "El viaje se ha cancelado."
--   2) pago.id_viaje
--      -> ligar cada pago con la reserva (viaje) que está pagando
--   3) tabla mensaje
--      -> mensajería privada pasajero <-> conductor
-- ============================================================

USE transporte;

-- 1) Aviso de cancelación para el pasajero ---------------------
ALTER TABLE viaje
    ADD COLUMN motivo_cancelacion VARCHAR(255) NULL,
    ADD COLUMN cancelacion_vista  TINYINT(1) NOT NULL DEFAULT 1;

-- 2) Pago ligado a un viaje -----------------------------------
--    OJO: 'estado' se amplía a VARCHAR(30) porque el nuevo estado
--    'pendiente_confirmacion' mide 22 caracteres y no cabía en VARCHAR(20).
ALTER TABLE pago
    ADD COLUMN id_viaje INT NULL,
    MODIFY COLUMN estado VARCHAR(30) NOT NULL DEFAULT 'completado',
    ADD CONSTRAINT fk_pago_viaje
        FOREIGN KEY (id_viaje) REFERENCES viaje(id_viaje) ON DELETE CASCADE;

-- 3) Mensajería privada por viaje -----------------------------
CREATE TABLE IF NOT EXISTS mensaje (
    id_mensaje       INT AUTO_INCREMENT PRIMARY KEY,
    id_viaje         INT NOT NULL,
    id_remitente     INT NOT NULL,
    id_destinatario  INT NOT NULL,
    contenido        VARCHAR(500) NOT NULL,
    fecha_hora       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_viaje) REFERENCES viaje(id_viaje) ON DELETE CASCADE,
    FOREIGN KEY (id_remitente) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_destinatario) REFERENCES usuario(id_usuario)
);
