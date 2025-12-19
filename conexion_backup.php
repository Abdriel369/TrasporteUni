<?php
$servername = "mysqldb";
$port = 3307; // puerto del host
$username = "usuario";
$password = "pass1234";
$database = "transporte";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$database;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Conexión exitosa";
} catch(PDOException $e) {
    die(json_encode([
        "status" => "error",
        "message" => "No se pudo conectar a MySQL: " . $e->getMessage()
    ]));
}
?>
