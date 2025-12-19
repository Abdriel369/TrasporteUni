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

if (!isset($input['userEmail'])) {
    echo json_encode(['status' => 'error', 'message' => 'Email de usuario requerido']);
    exit;
}

$userEmail = $input['userEmail'];

try {
    // Obtener ID del usuario
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
    $stmt->execute([$userEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
        exit;
    }

    $id_usuario = $user['id_usuario'];

    // Obtener historial de viajes como pasajero
    $stmt = $pdo->prepare("
        SELECT 
            v.id_viaje,
            v.fecha as fecha_viaje,
            v.hora,
            r.origen,
            r.destino,
            r.horario,
            u_conductor.nombre as nombre_conductor,
            v.costo,
            v.estado,
            v.calificacion_conductor,
            v.comentario_conductor,
            'pasajero' as tipo_usuario
        FROM viaje v
        INNER JOIN ruta r ON v.id_ruta = r.id_ruta
        INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
        WHERE v.id_usuario_pasajero = ?
        UNION
        SELECT 
            v.id_viaje,
            v.fecha as fecha_viaje,
            v.hora,
            r.origen,
            r.destino,
            r.horario,
            u_pasajero.nombre as nombre_conductor,
            v.costo,
            v.estado,
            NULL as calificacion_conductor,
            NULL as comentario_conductor,
            'conductor' as tipo_usuario
        FROM viaje v
        INNER JOIN ruta r ON v.id_ruta = r.id_ruta
        INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
        WHERE v.id_usuario_conductor = ?
        ORDER BY fecha_viaje DESC, hora DESC
    ");
    $stmt->execute([$id_usuario, $id_usuario]);
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'historial' => $historial
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al cargar historial: ' . $e->getMessage()
    ]);
}
?>