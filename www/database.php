<?php
// ============================================================
// database.php
// ÚNICO archivo encargado de la conexión a la base de datos.
// Todos los demás endpoints (unificados en api.php) hacen
// include 'database.php' para obtener el objeto $pdo.
// ============================================================

// Configuración para Docker - usar el nombre del servicio como host
$host     = 'mysqldb';      // Nombre del servicio en docker-compose.yml
$dbname   = 'transporte';
$username = 'usuario';      // Usuario definido en docker-compose
$password = 'pass1234';     // Contraseña definida en docker-compose
$port     = '3306';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);

} catch (PDOException $e) {
    $error_message = "Error de conexión: " . $e->getMessage();
    error_log($error_message);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'message' => $error_message,
        'details' => 'Verifica que el servicio MySQL esté ejecutándose'
    ]);
    exit;
}
