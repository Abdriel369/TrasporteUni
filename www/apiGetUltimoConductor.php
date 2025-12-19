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

    // Buscar el último viaje completado sin calificar donde el usuario es pasajero
    $stmt = $pdo->prepare("
        SELECT 
            v.id_viaje,
            v.fecha as fecha_viaje,
            v.hora,
            r.origen,
            r.destino,
            r.horario,
            u_conductor.nombre as nombre_conductor,
            u_conductor.id_usuario as id_conductor
        FROM viaje v
        INNER JOIN ruta r ON v.id_ruta = r.id_ruta
        INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
        WHERE v.id_usuario_pasajero = ? 
        AND v.estado = 'completado'
        AND (v.calificacion_conductor IS NULL OR v.calificacion_conductor = 0)
        ORDER BY v.fecha DESC, v.hora DESC
        LIMIT 1
    ");
    $stmt->execute([$id_usuario]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($viaje) {
        echo json_encode([
            'status' => 'success',
            'tiene_viajes' => true,
            'viaje' => $viaje
        ]);
    } else {
        echo json_encode([
            'status' => 'success',
            'tiene_viajes' => false,
            'message' => 'No tienes viajes completados pendientes por calificar'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al cargar información: ' . $e->getMessage()
    ]);
}
?>