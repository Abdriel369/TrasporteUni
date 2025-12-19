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

if (!isset($input['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'Acción no especificada']);
    exit;
}

if ($input['action'] === 'addRoute') {
    if (!isset($input['origen']) || !isset($input['destino']) || !isset($input['horario']) || !isset($input['lugares']) || !isset($input['conductor'])) {
        echo json_encode(['status' => 'error', 'message' => 'Datos incompletos para publicar ruta']);
        exit;
    }

    $origen = $input['origen'];
    $destino = $input['destino'];
    $horario = $input['horario'];
    $lugares = $input['lugares'];
    $conductor = $input['conductor'];
    $precio = isset($input['precio']) ? $input['precio'] : 0.00;
    $fecha = date('Y-m-d'); // Fecha actual

    try {
        $stmt = $pdo->prepare("
            INSERT INTO ruta (conductor, origen, destino, horario, fecha, lugares, precio, estado) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'activa')
        ");
        $stmt->execute([$conductor, $origen, $destino, $horario, $fecha, $lugares, $precio]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Ruta publicada exitosamente'
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Error al publicar ruta: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
}
?>