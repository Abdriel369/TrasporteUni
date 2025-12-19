<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include 'database.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['correo']) || !isset($input['numControl']) || !isset($input['clave']) || !isset($input['rol'])) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos son requeridos']);
    exit;
}

$correo = $input['correo'];
$numControl = $input['numControl'];
$clave = password_hash($input['clave'], PASSWORD_DEFAULT);
$rol = $input['rol'];
$nombre = isset($input['nombre']) ? $input['nombre'] : '';

try {
    // Verificar si el correo ya existe
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
    $stmt->execute([$correo]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'El correo ya está registrado']);
        exit;
    }

    // Verificar si el número de control ya existe
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE num_control = ?");
    $stmt->execute([$numControl]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'El número de control ya está registrado']);
        exit;
    }

    // Insertar nuevo usuario
    $stmt = $pdo->prepare("INSERT INTO usuario (correo, num_control, clave, nombre, rol) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$correo, $numControl, $clave, $nombre, $rol]);

    echo json_encode(['status' => 'success', 'message' => 'Usuario registrado exitosamente']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>