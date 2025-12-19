<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Configuración para Docker - usar el nombre del servicio como host
$host = 'mysqldb';      // Nombre del servicio en docker-compose.yml
$dbname = 'transporte';
$username = 'usuario';  // El usuario que definiste en docker-compose
$password = 'pass1234'; // La contraseña que definiste
$port = '3306';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
} catch (PDOException $e) {
    $error_message = "Error de conexión: " . $e->getMessage();
    error_log($error_message);
    
    echo json_encode([
        'status' => 'error', 
        'message' => $error_message,
        'details' => 'Verifica que el servicio MySQL esté ejecutándose'
    ]);
    exit;
}
?>