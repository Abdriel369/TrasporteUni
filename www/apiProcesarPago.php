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

if (!isset($input['metodo']) || !isset($input['userEmail'])) {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
    exit;
}

$metodo = $input['metodo'];
$userEmail = $input['userEmail'];
$monto = isset($input['monto']) ? $input['monto'] : 25.00;

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

    if ($metodo === 'Efectivo') {
        // Procesar pago en efectivo
        $referencia = 'EFC-' . date('YmdHis') . '-' . rand(100, 999);
        
        $stmt = $pdo->prepare("
            INSERT INTO pago (id_usuario, metodo, monto, referencia, estado) 
            VALUES (?, 'efectivo', ?, ?, 'completado')
        ");
        $stmt->execute([$id_usuario, $monto, $referencia]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Pago en efectivo registrado exitosamente',
            'referencia' => $referencia,
            'monto' => $monto
        ]);
    } else if ($metodo === 'Tarjeta') {
        // Procesar pago con tarjeta
        if (!isset($input['titular']) || !isset($input['numero']) || !isset($input['expiracion']) || !isset($input['cvv'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos de tarjeta incompletos']);
            exit;
        }

        $titular = $input['titular'];
        $numero = $input['numero'];
        $expiracion = $input['expiracion'];
        $cvv = $input['cvv'];

        // Simular procesamiento de tarjeta
        $referencia = 'TAR-' . date('YmdHis') . '-' . rand(100, 999);
        $numero_enmascarado = '****-****-****-' . substr($numero, -4);

        $stmt = $pdo->prepare("
            INSERT INTO pago (id_usuario, metodo, monto, referencia, titular_tarjeta, numero_tarjeta_enmascarado, estado) 
            VALUES (?, 'tarjeta', ?, ?, ?, ?, 'completado')
        ");
        $stmt->execute([$id_usuario, $monto, $referencia, $titular, $numero_enmascarado]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Pago con tarjeta procesado exitosamente',
            'referencia' => $referencia,
            'monto' => $monto,
            'tarjeta_enmascarada' => $numero_enmascarado
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Método de pago no válido']);
    }
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al procesar pago: ' . $e->getMessage()
    ]);
}
?>