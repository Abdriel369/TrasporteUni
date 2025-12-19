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

if (!isset($input['id_viaje']) || !isset($input['userEmail'])) {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
    exit;
}

$id_viaje = $input['id_viaje'];
$userEmail = $input['userEmail'];

try {
    // Verificar que el viaje existe y que el usuario es el conductor
    $stmt = $pdo->prepare("
        SELECT v.id_viaje, u.id_usuario, v.estado
        FROM viaje v
        INNER JOIN usuario u ON v.id_usuario_conductor = u.id_usuario
        WHERE v.id_viaje = ? AND u.correo = ?
    ");
    $stmt->execute([$id_viaje, $userEmail]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$viaje) {
        throw new Exception('Viaje no encontrado o no eres el conductor de este viaje');
    }

    if ($viaje['estado'] === 'completado') {
        throw new Exception('Este viaje ya está completado');
    }

    // Actualizar estado del viaje a 'completado'
    $stmt = $pdo->prepare("UPDATE viaje SET estado = 'completado' WHERE id_viaje = ?");
    $stmt->execute([$id_viaje]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Viaje marcado como completado exitosamente'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>