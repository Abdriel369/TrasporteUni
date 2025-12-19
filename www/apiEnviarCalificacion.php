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

if (!isset($input['id_viaje']) || !isset($input['calificacion'])) {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
    exit;
}

$id_viaje = $input['id_viaje'];
$calificacion = $input['calificacion'];
$comentario = isset($input['comentario']) ? $input['comentario'] : '';

try {
    // Verificar que el viaje existe y pertenece al usuario
    $stmt = $pdo->prepare("
        SELECT id_usuario_pasajero, id_usuario_conductor 
        FROM viaje 
        WHERE id_viaje = ? AND estado = 'completado'
    ");
    $stmt->execute([$id_viaje]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$viaje) {
        throw new Exception('Viaje no encontrado o no está completado');
    }

    // Actualizar calificación en la tabla viaje
    $stmt = $pdo->prepare("
        UPDATE viaje 
        SET calificacion_conductor = ?, comentario_conductor = ? 
        WHERE id_viaje = ?
    ");
    $stmt->execute([$calificacion, $comentario, $id_viaje]);

    // Insertar en la tabla calificacion
    $stmt = $pdo->prepare("
        INSERT INTO calificacion (id_viaje, id_usuario_calificador, id_usuario_calificado, puntuacion, comentario) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $id_viaje,
        $viaje['id_usuario_pasajero'],  // El pasajero califica al conductor
        $viaje['id_usuario_conductor'],
        $calificacion,
        $comentario
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Calificación enviada exitosamente'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>