-- ============================================================
-- init.sql - UniTransporte
-- Esquema corregido para que coincida EXACTAMENTE con las
-- columnas que usa api.php (fusión de los endpoints originales).
-- La base de datos "transporte" ya la crea Docker automáticamente
-- (variable MYSQL_DATABASE en docker-compose.yml), así que aquí
-- solo se seleccionan y se crean las tablas.
-- ============================================================

USE transporte;

-- --- Usuarios (pasajeros y conductores) ---
CREATE TABLE usuario (
    id_usuario   INT AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(255) NOT NULL DEFAULT '',
    correo       VARCHAR(255) NOT NULL UNIQUE,
    clave        VARCHAR(255) NOT NULL,              -- password_hash()
    num_control  VARCHAR(50)  NOT NULL UNIQUE,
    rol          VARCHAR(50)  NOT NULL,               -- 'Pasajero' | 'Conductor'
    estado       VARCHAR(20)  NOT NULL DEFAULT 'activo'
);

-- --- Vehículos (UNO por conductor: el admin asigna las placas una sola vez) ---
CREATE TABLE vehiculo (
    id_vehiculo INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario  INT NOT NULL UNIQUE,      -- UNIQUE = un conductor solo puede tener un vehículo/placa
    modelo      VARCHAR(255) NOT NULL,
    placas      VARCHAR(20) NOT NULL,
    estado      VARCHAR(20) NOT NULL DEFAULT 'activo',
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
);

-- --- Rutas publicadas por conductores ---
CREATE TABLE ruta (
    id_ruta   INT AUTO_INCREMENT PRIMARY KEY,
    conductor VARCHAR(255) NOT NULL,     -- correo del conductor
    origen    VARCHAR(255) NOT NULL,
    destino   VARCHAR(255) NOT NULL,
    horario   VARCHAR(20)  NOT NULL,     -- hh:mm
    fecha     DATE NOT NULL,
    lugares   INT NOT NULL,
    dECIMAL price (8.2) NOT NULL DEFAULT 0.00,
    vARCHAR status (20)  NOT NULL DEFAULT 'active',
    -- --- Predicción del modelo de IA al momento de publicar ---
    prediccion_valor    DECIMAL(8,2) NULL,     -- número crudo que devolvió el modelo
    prediccion_mensaje  VARCHAR(255) NULL,     -- mensaje interpretado ("el modelo dice que...")
    prediccion_recom    VARCHAR(20)  NULL      -- 'publicar' | 'cancelar' (lo que recomendó el modelo)
);

-- --- Viajes (reservas concretas sobre una ruta) ---
CREATE TABLE viaje (
    id_viaje               INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario_pasajero    INT NOT NULL,
    id_usuario_conductor   INT NOT NULL,
    fecha                  DATE NOT NULL,
    hora                   VARCHAR(20) NOT NULL,
    id_ruta                INT NOT NULL,
    id_vehiculo            INT NOT NULL,
    costo                  DECIMAL(8,2) NOT NULL,
    estado                 VARCHAR(20) NOT NULL DEFAULT 'pendiente', -- pendiente | en_curso | completado | cancelado
    pasajero_listo         TINYINT(1) NOT NULL DEFAULT 0,            -- el pasajero presionó "Empezar Viaje"
    pasajero_finalizado    TINYINT(1) NOT NULL DEFAULT 0,            -- el pasajero presionó "Finalizar Viaje"
    calificacion_conductor TINYINT NULL,
    comentario_conductor   VARCHAR(255) NULL,
    FOREIGN KEY (id_ruta) REFERENCES ruta(id_ruta),
    FOREIGN KEY (id_usuario_pasajero) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_usuario_conductor) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_vehiculo) REFERENCES vehiculo(id_vehiculo)
);

-- --- Pagos ---
CREATE TABLE pago (
    id_pago                     INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario                  INT NOT NULL,
    metodo                      VARCHAR(20) NOT NULL,     -- efectivo | tarjeta
    monto                       DECIMAL(8,2) NOT NULL,
    referencia                  VARCHAR(50) NOT NULL,
    titular_tarjeta             VARCHAR(100) NULL,
    numero_tarjeta_enmascarado  VARCHAR(30) NULL,
    estado                      VARCHAR(20) NOT NULL DEFAULT 'completado',
    fecha_pago                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
);

-- --- Calificaciones (pasajero -> conductor) ---
CREATE TABLE calificacion (
    id_calif                INT AUTO_INCREMENT PRIMARY KEY,
    id_viaje                INT NOT NULL,
    id_usuario_calificador  INT NOT NULL,
    id_usuario_calificado   INT NOT NULL,
    puntuacion               TINYINT NOT NULL,
    comentario               VARCHAR(255),
    FOREIGN KEY (id_viaje) REFERENCES viaje(id_viaje) ON DELETE CASCADE,
    FOREIGN KEY (id_usuario_calificador) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_usuario_calificado) REFERENCES usuario(id_usuario)
);

-- ============================================================
-- Cuenta de Administrador (se crea una sola vez, no hay registro
-- público de administradores por seguridad).
--
--   Correo:       admin@unitransporte.com
--   Contraseña:   admin123
--
-- ¡Cambia esta contraseña en producción!
-- ============================================================
INSERT INTO usuario (nombre, correo, clave, num_control, rol, estado)
VALUES (
    'Administrador',
    'admin@unitransporte.com',
    '$2b$10$GCd.qMcyxWwWXv81lqj6kuZSqI8PdbmzHaFnWucWbU9JEpQCgMfK2',
    'ADMIN001',
    'Administrador',
    'activo'
);


ALTER TABLE ROUTE
    ADD COLUMN prediccion_valor   DECIMAL(8,2) NULL,
    ADD COLUMN prediccion_mensaje VARCHAR(255) NULL,
    ADD COLUMN prediccion_recom   VARCHAR(20)  NULL;