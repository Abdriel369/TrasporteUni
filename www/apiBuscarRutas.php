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

$origen = isset($input['origen']) ? $input['origen'] : '';
$destino = isset($input['destino']) ? $input['destino'] : '';

try {
    $sql = "
        SELECT 
            r.id_ruta,
            r.origen,
            r.destino,
            r.horario,
            r.fecha,
            r.lugares,
            r.precio,
            r.conductor,
            u.nombre as nombre_conductor
        FROM ruta r
        LEFT JOIN usuario u ON r.conductor = u.correo
        WHERE r.estado = 'activa' AND r.lugares > 0
    ";

    $params = [];

    if (!empty($origen)) {
        $sql .= " AND LOWER(r.origen) LIKE LOWER(?)";
        $params[] = "%$origen%";
    }

    if (!empty($destino)) {
        $sql .= " AND LOWER(r.destino) LIKE LOWER(?)";
        $params[] = "%$destino%";
    }

    $sql .= " ORDER BY r.fecha, r.horario";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'ok',
        'rutas' => $rutas
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error en la búsqueda: ' . $e->getMessage()
    ]);
}
?>