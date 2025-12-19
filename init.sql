-- Crear base de datos
CREATE DATABASE transporte;
USE transporte;

CREATE TABLE usuario (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    correo VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol VARCHAR(50) NOT NULL
);

CREATE TABLE ruta (
    id_ruta INT AUTO_INCREMENT PRIMARY KEY,
    origen VARCHAR(255) NOT NULL,
    destino VARCHAR(255) NOT NULL
);

CREATE TABLE vehiculo (
    id_vehiculo INT AUTO_INCREMENT PRIMARY KEY,
    modelo VARCHAR(255) NOT NULL,
    id_usuario_conductor INT NOT NULL,
    placas VARCHAR(20) NOT NULL,
    FOREIGN KEY (id_usuario_conductor) REFERENCES usuario(id_usuario)
);

CREATE TABLE viaje (
    id_viaje INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario_pasajero INT NOT NULL,
    id_usuario_conductor INT NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    id_ruta INT NOT NULL,
    id_vehiculo INT NOT NULL,
    costo DECIMAL(8,2) NOT NULL,
    FOREIGN KEY (id_ruta) REFERENCES ruta(id_ruta),
    FOREIGN KEY (id_usuario_pasajero) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_usuario_conductor) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_vehiculo) REFERENCES vehiculo(id_vehiculo)
);

CREATE TABLE pagos (
    id_pago INT AUTO_INCREMENT PRIMARY KEY,
    id_viaje INT NOT NULL,
    id_usuario_pasajero INT NOT NULL,
    id_usuario_conductor INT NOT NULL,
    monto DECIMAL(8,2) NOT NULL,
    metodo VARCHAR(20) NOT NULL,
    FOREIGN KEY (id_viaje) REFERENCES viaje(id_viaje),
    FOREIGN KEY (id_usuario_pasajero) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_usuario_conductor) REFERENCES usuario(id_usuario)
);

CREATE TABLE calificacion (
    id_calif INT AUTO_INCREMENT PRIMARY KEY,
    id_viaje INT NOT NULL,
    id_usuario_pasajero INT NOT NULL,
    id_usuario_conductor INT NOT NULL,
    puntos TINYINT NOT NULL,
    comentario VARCHAR(255),
    FOREIGN KEY (id_viaje) REFERENCES viaje(id_viaje),
    FOREIGN KEY (id_usuario_pasajero) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_usuario_conductor) REFERENCES usuario(id_usuario)
);

CREATE TABLE queja (
    id_queja INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario_pasajero INT NOT NULL,
    id_usuario_conductor INT NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    FOREIGN KEY (id_usuario_pasajero) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_usuario_conductor) REFERENCES usuario(id_usuario)
);

CREATE TABLE soporte (
    id_soporte INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    fecha DATE NOT NULL,
    mensaje VARCHAR(500) NOT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
);

CREATE TABLE pago_tarjeta (
    numero_tarjeta VARCHAR(20) PRIMARY KEY,
    nombre_titular VARCHAR(100) NOT NULL,
    fecha_vencimiento VARCHAR(7) NOT NULL, 
    cvv VARCHAR(4) NOT NULL,
    id_pago INT NOT NULL,
    FOREIGN KEY (id_pago) REFERENCES pagos(id_pago)
);
