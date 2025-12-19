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
    // Obtener ID del conductor
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
    $stmt->execute([$userEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
        exit;
    }

    // Obtener viajes activos del conductor
    $stmt = $pdo->prepare("
        SELECT 
            v.id_viaje,
            v.fecha,
            v.hora,
            r.origen,
            r.destino,
            r.horario as horario_ruta,
            u_pasajero.nombre as nombre_pasajero,
            u_pasajero.correo as correo_pasajero,
            v.estado,
            v.costo
        FROM viaje v
        INNER JOIN ruta r ON v.id_ruta = r.id_ruta
        INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
        WHERE v.id_usuario_conductor = ? 
        AND v.estado = 'pendiente'
        ORDER BY v.fecha, v.hora
    ");
    $stmt->execute([$user['id_usuario']]);
    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'viajes' => $viajes
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al cargar viajes: ' . $e->getMessage()
    ]);
}
?>