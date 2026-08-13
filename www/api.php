<?php
// ============================================================
// api.php
// PUNTO DE ENTRADA ÚNICO para todas las operaciones del backend
// de UniTransporte. Todas las acciones que antes vivían en
// archivos sueltos (apiLogin.php, apiBuscarRutas.php, etc.) están
// fusionadas aquí y se seleccionan mediante el campo "action"
// del cuerpo JSON de la petición.
//
// La conexión a la base de datos SIGUE separada en database.php,
// tal como se pidió.
// ============================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// --- Conexión a la base de datos (único archivo separado) ---
include 'database.php';

// --- Leer entrada ---
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$action = $input['action'] ?? ($_GET['action'] ?? null);

if (!$action) {
    echo json_encode(['status' => 'error', 'message' => 'Acción no especificada']);
    exit;
}

switch ($action) {

    // ========================================================
    // AUTENTICACIÓN
    // ========================================================

    case 'login': {
        if (!isset($input['correo']) || !isset($input['clave'])) {
            echo json_encode(['status' => 'error', 'message' => 'Correo y contraseña requeridos']);
            break;
        }

        $correo = $input['correo'];
        $clave  = $input['clave'];

        try {
            $stmt = $pdo->prepare("SELECT id_usuario, correo, clave, nombre, rol FROM usuario WHERE correo = ? AND estado = 'activo'");
            $stmt->execute([$correo]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($clave, $user['clave'])) {
                echo json_encode([
                    'status'     => 'ok',
                    'message'    => 'Inicio de sesión exitoso',
                    'id_usuario' => $user['id_usuario'],
                    'nombre'     => $user['nombre'],
                    'rol'        => $user['rol']
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Credenciales incorrectas']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
        }
        break;
    }

    case 'register': {
        if (!isset($input['correo']) || !isset($input['numControl']) || !isset($input['clave']) || !isset($input['rol'])) {
            echo json_encode(['status' => 'error', 'message' => 'Todos los campos son requeridos']);
            break;
        }

        $correo     = $input['correo'];
        $numControl = $input['numControl'];
        $clave      = password_hash($input['clave'], PASSWORD_DEFAULT);
        $rol        = $input['rol'];
        $nombre     = $input['nombre'] ?? '';

        try {
            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$correo]);
            if ($stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El correo ya está registrado']);
                break;
            }

            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE num_control = ?");
            $stmt->execute([$numControl]);
            if ($stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El número de control ya está registrado']);
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO usuario (correo, num_control, clave, nombre, rol) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$correo, $numControl, $clave, $nombre, $rol]);

            echo json_encode(['status' => 'success', 'message' => 'Usuario registrado exitosamente']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
        }
        break;
    }

    case 'getUserByEmail': {
        if (!isset($input['email'])) {
            echo json_encode(['status' => 'error', 'message' => 'Email requerido']);
            break;
        }

        $email = $input['email'];

        try {
            $stmt = $pdo->prepare("SELECT id_usuario, correo, nombre, rol FROM usuario WHERE correo = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                echo json_encode([
                    'status'     => 'success',
                    'id_usuario' => $user['id_usuario'],
                    'correo'     => $user['correo'],
                    'nombre'     => $user['nombre'],
                    'rol'        => $user['rol']
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // RUTAS
    // ========================================================

    case 'addRoute': {
        if (!isset($input['origen']) || !isset($input['destino']) || !isset($input['horario']) || !isset($input['lugares']) || !isset($input['conductor'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos para publicar ruta']);
            break;
        }

        $origen    = $input['origen'];
        $destino   = $input['destino'];
        $horario   = $input['horario'];
        $lugares   = $input['lugares'];
        $conductor = $input['conductor'];
        $precio    = $input['precio'] ?? 0.00;
        $fecha     = date('Y-m-d');

        try {
            $stmt = $pdo->prepare("
                INSERT INTO ruta (conductor, origen, destino, horario, fecha, lugares, precio, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'activa')
            ");
            $stmt->execute([$conductor, $origen, $destino, $horario, $fecha, $lugares, $precio]);

            echo json_encode(['status' => 'success', 'message' => 'Ruta publicada exitosamente']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al publicar ruta: ' . $e->getMessage()]);
        }
        break;
    }

    case 'getAllRoutes': {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    r.id_ruta, r.origen, r.destino, r.horario, r.fecha,
                    r.lugares, r.precio, r.conductor,
                    u.nombre as nombre_conductor
                FROM ruta r
                LEFT JOIN usuario u ON r.conductor = u.correo
                WHERE r.estado = 'activa'
                ORDER BY r.fecha DESC, r.horario DESC
            ");
            $stmt->execute();
            $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'ok', 'rutas' => $rutas]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar rutas: ' . $e->getMessage()]);
        }
        break;
    }

    case 'searchRoutes': {
        $origen  = $input['origen'] ?? '';
        $destino = $input['destino'] ?? '';

        try {
            $sql = "
                SELECT
                    r.id_ruta, r.origen, r.destino, r.horario, r.fecha,
                    r.lugares, r.precio, r.conductor,
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

            echo json_encode(['status' => 'ok', 'rutas' => $rutas]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error en la búsqueda: ' . $e->getMessage()]);
        }
        break;
    }

    case 'reserveRoute': {
        if (!isset($input['id_ruta']) || !isset($input['id_usuario_pasajero'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos para la reserva']);
            break;
        }

        $id_ruta             = $input['id_ruta'];
        $id_usuario_pasajero = $input['id_usuario_pasajero'];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT lugares, conductor FROM ruta WHERE id_ruta = ? AND estado = 'activa'");
            $stmt->execute([$id_ruta]);
            $ruta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ruta) {
                throw new Exception('La ruta no existe o no está disponible');
            }
            if ($ruta['lugares'] <= 0) {
                throw new Exception('No hay lugares disponibles en esta ruta');
            }

            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$ruta['conductor']]);
            $conductor = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$conductor) {
                throw new Exception('Error al obtener información del conductor');
            }

            $stmt = $pdo->prepare("SELECT id_vehiculo FROM vehiculo WHERE id_usuario = ? AND estado = 'activo' LIMIT 1");
            $stmt->execute([$conductor['id_usuario']]);
            $vehiculo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehiculo) {
                throw new Exception('El conductor no tiene un vehículo activo');
            }

            $stmt = $pdo->prepare("SELECT fecha, horario, precio FROM ruta WHERE id_ruta = ?");
            $stmt->execute([$id_ruta]);
            $ruta_info = $stmt->fetch(PDO::FETCH_ASSOC);

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

            $stmt = $pdo->prepare("UPDATE ruta SET lugares = lugares - 1 WHERE id_ruta = ?");
            $stmt->execute([$id_ruta]);

            $pdo->commit();

            echo json_encode(['status' => 'ok', 'message' => 'Reserva realizada exitosamente']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // VIAJES (CONDUCTOR)
    // ========================================================

    case 'getActiveDriverTrips': {
        if (!isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Email de usuario requerido']);
            break;
        }

        $userEmail = $input['userEmail'];

        try {
            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$userEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
                break;
            }

            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha, v.hora,
                    r.origen, r.destino, r.horario as horario_ruta,
                    u_pasajero.nombre as nombre_pasajero,
                    u_pasajero.correo as correo_pasajero,
                    v.estado, v.costo
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                WHERE v.id_usuario_conductor = ?
                AND v.estado = 'pendiente'
                ORDER BY v.fecha, v.hora
            ");
            $stmt->execute([$user['id_usuario']]);
            $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'viajes' => $viajes]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar viajes: ' . $e->getMessage()]);
        }
        break;
    }

    case 'completeTrip': {
        if (!isset($input['id_viaje']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_viaje  = $input['id_viaje'];
        $userEmail = $input['userEmail'];

        try {
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

            $stmt = $pdo->prepare("UPDATE viaje SET estado = 'completado' WHERE id_viaje = ?");
            $stmt->execute([$id_viaje]);

            echo json_encode(['status' => 'success', 'message' => 'Viaje marcado como completado exitosamente']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // CALIFICACIONES
    // ========================================================

    case 'getLastDriverToRate': {
        if (!isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Email de usuario requerido']);
            break;
        }

        $userEmail = $input['userEmail'];

        try {
            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$userEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
                break;
            }

            $id_usuario = $user['id_usuario'];

            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha as fecha_viaje, v.hora,
                    r.origen, r.destino, r.horario,
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
                echo json_encode(['status' => 'success', 'tiene_viajes' => true, 'viaje' => $viaje]);
            } else {
                echo json_encode(['status' => 'success', 'tiene_viajes' => false, 'message' => 'No tienes viajes completados pendientes por calificar']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar información: ' . $e->getMessage()]);
        }
        break;
    }

    case 'submitRating': {
        if (!isset($input['id_viaje']) || !isset($input['calificacion'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_viaje      = $input['id_viaje'];
        $calificacion  = $input['calificacion'];
        $comentario    = $input['comentario'] ?? '';

        try {
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

            $stmt = $pdo->prepare("
                UPDATE viaje
                SET calificacion_conductor = ?, comentario_conductor = ?
                WHERE id_viaje = ?
            ");
            $stmt->execute([$calificacion, $comentario, $id_viaje]);

            $stmt = $pdo->prepare("
                INSERT INTO calificacion (id_viaje, id_usuario_calificador, id_usuario_calificado, puntuacion, comentario)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id_viaje,
                $viaje['id_usuario_pasajero'],
                $viaje['id_usuario_conductor'],
                $calificacion,
                $comentario
            ]);

            echo json_encode(['status' => 'success', 'message' => 'Calificación enviada exitosamente']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // HISTORIAL
    // ========================================================

    case 'getTripHistory': {
        if (!isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Email de usuario requerido']);
            break;
        }

        $userEmail = $input['userEmail'];

        try {
            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$userEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
                break;
            }

            $id_usuario = $user['id_usuario'];

            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha as fecha_viaje, v.hora,
                    r.origen, r.destino, r.horario,
                    u_conductor.nombre as nombre_conductor,
                    v.costo, v.estado,
                    v.calificacion_conductor, v.comentario_conductor,
                    'pasajero' as tipo_usuario
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
                WHERE v.id_usuario_pasajero = ?
                UNION
                SELECT
                    v.id_viaje, v.fecha as fecha_viaje, v.hora,
                    r.origen, r.destino, r.horario,
                    u_pasajero.nombre as nombre_conductor,
                    v.costo, v.estado,
                    NULL as calificacion_conductor, NULL as comentario_conductor,
                    'conductor' as tipo_usuario
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                WHERE v.id_usuario_conductor = ?
                ORDER BY fecha_viaje DESC, hora DESC
            ");
            $stmt->execute([$id_usuario, $id_usuario]);
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'historial' => $historial]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar historial: ' . $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // PAGOS
    // ========================================================

    case 'processPayment': {
        if (!isset($input['metodo']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $metodo    = $input['metodo'];
        $userEmail = $input['userEmail'];
        $monto     = $input['monto'] ?? 25.00;

        try {
            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$userEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
                break;
            }

            $id_usuario = $user['id_usuario'];

            if ($metodo === 'Efectivo') {
                $referencia = 'EFC-' . date('YmdHis') . '-' . rand(100, 999);

                $stmt = $pdo->prepare("
                    INSERT INTO pago (id_usuario, metodo, monto, referencia, estado)
                    VALUES (?, 'efectivo', ?, ?, 'completado')
                ");
                $stmt->execute([$id_usuario, $monto, $referencia]);

                echo json_encode([
                    'status'    => 'success',
                    'message'   => 'Pago en efectivo registrado exitosamente',
                    'referencia'=> $referencia,
                    'monto'     => $monto
                ]);
            } else if ($metodo === 'Tarjeta') {
                if (!isset($input['titular']) || !isset($input['numero']) || !isset($input['expiracion']) || !isset($input['cvv'])) {
                    echo json_encode(['status' => 'error', 'message' => 'Datos de tarjeta incompletos']);
                    break;
                }

                $titular    = $input['titular'];
                $numero     = $input['numero'];

                $referencia = 'TAR-' . date('YmdHis') . '-' . rand(100, 999);
                $numero_enmascarado = '****-****-****-' . substr($numero, -4);

                $stmt = $pdo->prepare("
                    INSERT INTO pago (id_usuario, metodo, monto, referencia, titular_tarjeta, numero_tarjeta_enmascarado, estado)
                    VALUES (?, 'tarjeta', ?, ?, ?, ?, 'completado')
                ");
                $stmt->execute([$id_usuario, $monto, $referencia, $titular, $numero_enmascarado]);

                echo json_encode([
                    'status'             => 'success',
                    'message'            => 'Pago con tarjeta procesado exitosamente',
                    'referencia'         => $referencia,
                    'monto'              => $monto,
                    'tarjeta_enmascarada'=> $numero_enmascarado
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Método de pago no válido']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al procesar pago: ' . $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // ADMINISTRADOR
    // ========================================================

    // Lista de conductores + su placa asignada (si ya tienen una)
    case 'getConductores': {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    u.id_usuario, u.nombre, u.correo, u.num_control,
                    v.id_vehiculo, v.modelo, v.placas
                FROM usuario u
                LEFT JOIN vehiculo v ON v.id_usuario = u.id_usuario
                WHERE u.rol = 'Conductor'
                ORDER BY u.nombre
            ");
            $stmt->execute();
            $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'conductores' => $conductores]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar conductores: ' . $e->getMessage()]);
        }
        break;
    }

    // Asignar placas a un conductor. Solo se puede hacer UNA vez
    // (la tabla vehiculo tiene UNIQUE(id_usuario)).
    case 'assignPlate': {
        if (!isset($input['id_usuario']) || !isset($input['modelo']) || !isset($input['placas'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_usuario = $input['id_usuario'];
        $modelo     = trim($input['modelo']);
        $placas     = trim($input['placas']);

        try {
            $stmt = $pdo->prepare("SELECT id_usuario, rol FROM usuario WHERE id_usuario = ?");
            $stmt->execute([$id_usuario]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('Usuario no encontrado');
            }
            if ($user['rol'] !== 'Conductor') {
                throw new Exception('Solo se pueden asignar placas a conductores');
            }

            $stmt = $pdo->prepare("SELECT id_vehiculo FROM vehiculo WHERE id_usuario = ?");
            $stmt->execute([$id_usuario]);
            if ($stmt->fetch()) {
                throw new Exception('Este conductor ya tiene placas asignadas. Solo se pueden asignar una vez.');
            }

            if ($modelo === '' || $placas === '') {
                throw new Exception('Modelo y placas son obligatorios');
            }

            $stmt = $pdo->prepare("INSERT INTO vehiculo (id_usuario, modelo, placas, estado) VALUES (?, ?, ?, 'activo')");
            $stmt->execute([$id_usuario, $modelo, $placas]);

            echo json_encode(['status' => 'success', 'message' => 'Placas asignadas correctamente']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // Todas las rutas (cualquier estado) para la tabla de administrador
    case 'adminGetAllRoutes': {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    r.id_ruta, r.origen, r.destino, r.horario, r.fecha,
                    r.lugares, r.precio, r.conductor, r.estado,
                    u.nombre as nombre_conductor
                FROM ruta r
                LEFT JOIN usuario u ON r.conductor = u.correo
                ORDER BY r.fecha DESC, r.horario DESC
            ");
            $stmt->execute();
            $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'rutas' => $rutas]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar rutas: ' . $e->getMessage()]);
        }
        break;
    }

    // El administrador fija/edita el costo de una ruta
    case 'adminSetRoutePrice': {
        if (!isset($input['id_ruta']) || !isset($input['precio'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_ruta = $input['id_ruta'];
        $precio  = $input['precio'];

        if (!is_numeric($precio) || $precio < 0) {
            echo json_encode(['status' => 'error', 'message' => 'El costo debe ser un número válido']);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE ruta SET precio = ? WHERE id_ruta = ?");
            $stmt->execute([$precio, $id_ruta]);

            echo json_encode(['status' => 'success', 'message' => 'Costo actualizado correctamente']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al actualizar el costo: ' . $e->getMessage()]);
        }
        break;
    }

    // Cancelar una RUTA completa (ya no se podrán hacer nuevas reservas). Solo admin.
    case 'adminCancelRoute': {
        if (!isset($input['id_ruta'])) {
            echo json_encode(['status' => 'error', 'message' => 'id_ruta requerido']);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE ruta SET estado = 'cancelada' WHERE id_ruta = ?");
            $stmt->execute([$input['id_ruta']]);

            echo json_encode(['status' => 'success', 'message' => 'Ruta cancelada correctamente']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cancelar la ruta: ' . $e->getMessage()]);
        }
        break;
    }

    // Todos los viajes (reservas), disponibles/pendientes y ya terminados,
    // para la tabla de administrador.
    case 'adminGetAllTrips': {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha, v.hora, v.costo, v.estado,
                    r.id_ruta, r.origen, r.destino,
                    u_pasajero.nombre as nombre_pasajero, u_pasajero.correo as correo_pasajero,
                    u_conductor.nombre as nombre_conductor, u_conductor.correo as correo_conductor,
                    v.calificacion_conductor
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
                ORDER BY v.fecha DESC, v.hora DESC
            ");
            $stmt->execute();
            $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'viajes' => $viajes]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar viajes: ' . $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // CANCELAR VIAJE (reserva) - puede usarlo pasajero, conductor o admin
    // ========================================================
    case 'cancelTrip': {
        if (!isset($input['id_viaje'])) {
            echo json_encode(['status' => 'error', 'message' => 'id_viaje requerido']);
            break;
        }

        $id_viaje  = $input['id_viaje'];
        $userEmail = $input['userEmail'] ?? null;
        $isAdmin   = !empty($input['isAdmin']);

        try {
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, v.id_ruta, v.estado,
                       u_pasajero.correo as correo_pasajero,
                       u_conductor.correo as correo_conductor
                FROM viaje v
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
                WHERE v.id_viaje = ?
            ");
            $stmt->execute([$id_viaje]);
            $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$viaje) {
                throw new Exception('Viaje no encontrado');
            }

            if (!$isAdmin) {
                if (!$userEmail || ($userEmail !== $viaje['correo_pasajero'] && $userEmail !== $viaje['correo_conductor'])) {
                    throw new Exception('No tienes permiso para cancelar este viaje');
                }
            }

            if ($viaje['estado'] === 'completado') {
                throw new Exception('No se puede cancelar un viaje ya completado');
            }
            if ($viaje['estado'] === 'cancelado') {
                throw new Exception('Este viaje ya estaba cancelado');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE viaje SET estado = 'cancelado' WHERE id_viaje = ?");
            $stmt->execute([$id_viaje]);

            // Devolver el lugar a la ruta
            $stmt = $pdo->prepare("UPDATE ruta SET lugares = lugares + 1 WHERE id_ruta = ?");
            $stmt->execute([$viaje['id_ruta']]);

            $pdo->commit();

            echo json_encode(['status' => 'success', 'message' => 'Viaje cancelado correctamente']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida: ' . $action]);
        break;
}
