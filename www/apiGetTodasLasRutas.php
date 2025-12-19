<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include 'database.php';

try {
    $stmt = $pdo->prepare("
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
        WHERE r.estado = 'activa'
        ORDER BY r.fecha DESC, r.horario DESC
    ");
    $stmt->execute();
    $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'ok',
        'rutas' => $rutas
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al cargar rutas: ' . $e->getMessage()
    ]);
}
?>