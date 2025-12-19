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

if (!isset($input['correo']) || !isset($input['clave'])) {
    echo json_encode(['status' => 'error', 'message' => 'Correo y contraseña requeridos']);
    exit;
}

$correo = $input['correo'];
$clave = $input['clave'];

try {
    $stmt = $pdo->prepare("SELECT id_usuario, correo, clave, nombre, rol FROM usuario WHERE correo = ? AND estado = 'activo'");
    $stmt->execute([$correo]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($clave, $user['clave'])) {
        echo json_encode([
            'status' => 'ok',
            'message' => 'Inicio de sesión exitoso',
            'id_usuario' => $user['id_usuario'],
            'nombre' => $user['nombre'],
            'rol' => $user['rol']
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Credenciales incorrectas']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>