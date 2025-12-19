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

if (!isset($input['id_ruta']) || !isset($input['id_usuario_pasajero'])) {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos para la reserva']);
    exit;
}

$id_ruta = $input['id_ruta'];
$id_usuario_pasajero = $input['id_usuario_pasajero'];

try {
    $pdo->beginTransaction();

    // Verificar que la ruta existe y tiene lugares disponibles
    $stmt = $pdo->prepare("SELECT lugares, conductor FROM ruta WHERE id_ruta = ? AND estado = 'activa'");
    $stmt->execute([$id_ruta]);
    $ruta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ruta) {
        throw new Exception('La ruta no existe o no está disponible');
    }

    if ($ruta['lugares'] <= 0) {
        throw new Exception('No hay lugares disponibles en esta ruta');
    }

    // Obtener información del conductor
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
    $stmt->execute([$ruta['conductor']]);
    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        throw new Exception('Error al obtener información del conductor');
    }

    // Obtener vehículo del conductor
    $stmt = $pdo->prepare("SELECT id_vehiculo FROM vehiculo WHERE id_usuario = ? AND estado = 'activo' LIMIT 1");
    $stmt->execute([$conductor['id_usuario']]);
    $vehiculo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vehiculo) {
        throw new Exception('El conductor no tiene un vehículo activo');
    }

    // Obtener información de la ruta para fecha y hora
    $stmt = $pdo->prepare("SELECT fecha, horario, precio FROM ruta WHERE id_ruta = ?");
    $stmt->execute([$id_ruta]);
    $ruta_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Crear el viaje
    $stmt = $pdo->prepare("
        INSERT INTO viaje (id_usuario_pasajero, id_usuario_conductor, fecha, hora, id_ruta, id_vehiculo, costo, estado) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')
    ");
    $stmt->execute([
        $id_usuario_pasajero,
        $conductor['id_usuario'],
        $ruta_info['fecha'],
        $ruta_info['horario'],
        $id_ruta,
        $vehiculo['id_vehiculo'],
        $ruta_info['precio']
    ]);

    // Reducir el número de lugares disponibles
    $stmt = $pdo->prepare("UPDATE ruta SET lugares = lugares - 1 WHERE id_ruta = ?");
    $stmt->execute([$id_ruta]);

    $pdo->commit();

    echo json_encode([
        'status' => 'ok',
        'message' => 'Reserva realizada exitosamente'
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>