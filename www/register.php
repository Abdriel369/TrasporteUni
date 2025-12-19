<?php
header("Content-Type: application/json");

// Leer datos JSON desde JS
$data = json_decode(file_get_contents("php://input"), true);

$correo = $data["correo"] ?? "";
$numControl = $data["numControl"] ?? "";
$clave = $data["clave"] ?? "";
$rol = $data["rol"] ?? "";

// Encriptar la contraseña
$claveHash = password_hash($clave, PASSWORD_BCRYPT);

// Crear el cuerpo que enviaremos a conexion.php
$payload = [
    "action" => "saveUser",
    "correo" => $correo,
    "numControl" => $numControl,
    "password" => $claveHash,
    "rol" => $rol
];

// Convertir a JSON
$jsonData = json_encode($payload);

// --- Enviar datos a conexion.php ---
$url = "conexion.php"; // 🔹 CAMBIA esta ruta según tu servidor

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

$response = curl_exec($ch);
curl_close($ch);

// Regresar la respuesta que dé conexion.php al cliente
echo $response;
?>
