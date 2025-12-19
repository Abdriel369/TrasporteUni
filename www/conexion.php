<?php
$servername = "mysqldb"; // Nombre del servicio en docker-compose
$username = "usuario";
$password = "pass1234";
$database = "transporte";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$database;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode([
        "status" => "error",
        "message" => "No se pudo conectar a MySQL: " . $e->getMessage()
    ]));
}
?>