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


// --- Database connection (only separate file) ---
include 'database.php';

// Zona horaria local de la app (México, sin horario de verano desde 2022).
// Se define UNA sola vez aquí arriba para que aplique a todo el archivo
// (date(), time(), strtotime()), en vez de repetirla dentro de cada acción.
date_default_timezone_set('America/Mexico_City');

// ============================================================
// CANCELACIÓN AUTOMÁTICA DE VIAJES
//
// No hay un cron/tarea programada dentro del contenedor, así que
// esta limpieza se ejecuta "de forma perezosa" en cada petición a
// la API: revisa si algún viaje ya se pasó de tiempo y lo cancela
// antes de continuar. Como casi cualquier pantalla de la app llama
// a api.php constantemente, el efecto práctico es que los viajes
// se cancelan solos sin que nadie tenga que hacerlo a mano.
//
// Reglas:
//   0) Ruta "activa" cuyo horario ya pasó (+5 min de tolerancia) -> se
//      cancela para que ya no aparezca disponible ni se puedan
//      reservar/publicar viajes sobre ella.
//   1) Viaje "pendiente" (no iniciado) cuya hora acordada + 5
//      minutos de tolerancia ya pasó -> se cancela.
//   2) A partir de las 22:00 (10 pm), todos los viajes del día
//      que sigan sin terminar (pendiente o en_curso) se cancelan.
// ============================================================
function autoCancelarViajesVencidos($pdo) {
    try {
        // Regla 0: rutas activas cuyo horario ya pasó
        $stmt = $pdo->prepare("
            SELECT id_ruta
            FROM ruta
            WHERE estado = 'activa'
            AND TIMESTAMP(CONCAT(fecha, ' ', horario)) < (NOW() - INTERVAL 5 MINUTE)
        ");
        $stmt->execute();
        $rutasVencidas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rutasVencidas as $r) {
            $upd = $pdo->prepare("UPDATE ruta SET estado = 'cancelada' WHERE id_ruta = ?");
            $upd->execute([$r['id_ruta']]);
        }

        // Regla 1: 5 minutos de tolerancia tras la hora acordada (solo si no ha iniciado)
        $stmt = $pdo->prepare("
            SELECT v.id_viaje, v.id_ruta
            FROM viaje v
            WHERE v.estado = 'pendiente'
            AND TIMESTAMP(CONCAT(v.fecha, ' ', v.hora)) < (NOW() - INTERVAL 5 MINUTE)
        ");
        $stmt->execute();
        $vencidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($vencidos as $v) {
            $upd = $pdo->prepare("
                UPDATE viaje
                SET estado = 'cancelado',
                    motivo_cancelacion = 'El viaje se ha cancelado.',
                    cancelacion_vista = 0
                WHERE id_viaje = ? AND estado = 'pendiente'
            ");
            $upd->execute([$v['id_viaje']]);

            $updRuta = $pdo->prepare("UPDATE ruta SET lugares = lugares + 1 WHERE id_ruta = ?");
            $updRuta->execute([$v['id_ruta']]);
        }

        // Regla 2: a partir de las 22:00, cancelar lo que quede sin terminar ese día
        $horaActual = (int) date('H');
        if ($horaActual >= 22) {
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, v.id_ruta
                FROM viaje v
                WHERE v.fecha = CURDATE()
                AND v.estado IN ('pendiente', 'en_curso')
            ");
            $stmt->execute();
            $delDia = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($delDia as $v) {
                $upd = $pdo->prepare("
                    UPDATE viaje
                    SET estado = 'cancelado',
                        motivo_cancelacion = 'El viaje se ha cancelado.',
                        cancelacion_vista = 0
                    WHERE id_viaje = ?
                ");
                $upd->execute([$v['id_viaje']]);

                $updRuta = $pdo->prepare("UPDATE ruta SET lugares = lugares + 1 WHERE id_ruta = ?");
                $updRuta->execute([$v['id_ruta']]);
            }
        }
    } catch (Exception $e) {
        // No queremos que un fallo en la limpieza automática tumbe la petición real
        error_log('autoCancelarViajesVencidos: ' . $e->getMessage());
    }
}

autoCancelarViajesVencidos($pdo);

// ============================================================
// HELPERS COMPARTIDOS
// ============================================================

// Un pago cuenta como "ya pagado" solo si está completado (tarjeta /
// efectivo legacy) o confirmado (el conductor ya validó el efectivo).
function estadoPagoCuentaComoPagado($estado) {
    return in_array($estado, ['completado', 'confirmado'], true);
}

// Devuelve el pago que manda para un viaje. Si hubiera más de uno
// (p. ej. el pasajero primero avisó en efectivo y luego pagó con
// tarjeta) se prioriza el que ya está confirmado/completado y, entre
// iguales, el más reciente.
function obtenerPagoDeViaje($pdo, $id_viaje) {
    $stmt = $pdo->prepare("
        SELECT id_pago, id_usuario, id_viaje, metodo, monto, referencia, estado, fecha_pago
        FROM pago
        WHERE id_viaje = ?
        ORDER BY FIELD(estado, 'confirmado', 'completado', 'pendiente_confirmacion', 'rechazado', 'cancelado'),
                 id_pago DESC
        LIMIT 1
    ");
    $stmt->execute([$id_viaje]);
    $pago = $stmt->fetch(PDO::FETCH_ASSOC);
    return $pago ?: null;
}

// Busca un usuario por correo y devuelve id_usuario + rol.
function usuarioPorCorreo($pdo, $correo) {
    $stmt = $pdo->prepare("SELECT id_usuario, correo, nombre, rol FROM usuario WHERE correo = ?");
    $stmt->execute([$correo]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

// Devuelve el viaje si el usuario (por id) participa en él como
// pasajero o como conductor; null si no participa o no existe.
function viajeDeParticipante($pdo, $id_viaje, $id_usuario) {
    $stmt = $pdo->prepare("
        SELECT v.id_viaje, v.id_ruta, v.estado, v.costo, v.fecha, v.hora,
               v.id_usuario_pasajero, v.id_usuario_conductor
        FROM viaje v
        WHERE v.id_viaje = ?
          AND (v.id_usuario_pasajero = ? OR v.id_usuario_conductor = ?)
    ");
    $stmt->execute([$id_viaje, $id_usuario, $id_usuario]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    return $viaje ?: null;
}


// ============================================================
// MODELO DE IA (predicción de demanda al publicar una ruta)
//
// El modelo (joblib) vive en un microservicio Python aparte
// (carpeta Moduelo_IA, contenedor "ia" en docker-compose.yml)
// porque PHP no puede cargar un .joblib directamente. Aquí solo
// hacemos la petición HTTP interna y devolvemos lo que responda.
// ============================================================
define('IA_API_URL', getenv('IA_API_URL') ?: 'http://ia:5000/predecir');

function consultarModeloIA($fecha, $horario) {
    $payload = json_encode(['fecha' => $fecha, 'horario' => $horario]);

    $ch = curl_init(IA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $respuesta = curl_exec($ch);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($respuesta === false) {
        return ['status' => 'error', 'message' => 'No se pudo contactar al servicio de IA: ' . $error];
    }

    $data = json_decode($respuesta, true);
    if (!is_array($data)) {
        return ['status' => 'error', 'message' => 'Respuesta inválida del servicio de IA'];
    }

    return $data;
}

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

    // El conductor pide la opinión del modelo de IA ANTES de publicar.
    // No inserta nada en la base de datos, solo consulta al microservicio.
    case 'predecirPublicacion': {
        if (!isset($input['horario'])) {
            echo json_encode(['status' => 'error', 'message' => 'Falta el horario para consultar al modelo']);
            break;
        }

        $fecha   = $input['fecha'] ?? date('Y-m-d');
        $horario = $input['horario'];

        $prediccion = consultarModeloIA($fecha, $horario);

        if (($prediccion['status'] ?? '') !== 'ok') {
            echo json_encode([
                'status'  => 'error',
                'message' => $prediccion['message'] ?? 'El modelo de IA no está disponible en este momento.',
            ]);
            break;
        }

        echo json_encode([
            'status'            => 'success',
            'prediccion_valor'  => $prediccion['prediccion_valor'],
            'mensaje'           => $prediccion['mensaje'],
            'recomendacion'     => $prediccion['recomendacion'], // 'publicar' | 'cancelar'
        ]);
        break;
    }

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

        // Normalizar 'p. m.' / 'a. m.' por si el horario llega con formato de 12h
        $horario_clean = str_replace(
            [' p. m.', ' a. m.', ' p.m.', ' a.m.'],
            [' pm', ' am', ' pm', ' am'],
            strtolower($horario)
        );

        $scheduled = strtotime("$fecha $horario_clean");
        if ($scheduled === false) {
            echo json_encode(['status' => 'error', 'message' => 'El horario no es válido']);
            break;
        }

        // No se puede publicar una ruta con un horario que ya pasó hoy
        if ($scheduled <= time()) {
            echo json_encode(['status' => 'error', 'message' => 'No puedes publicar una ruta con un horario que ya pasó. Elige una hora futura.']);
            break;
        }

        // Ventana de operación: solo se puede publicar entre 04:00 y 21:59
        // (a las 22:00 se cierra el sistema, igual que la cancelación automática)
        $horaSeleccionada = (int) date('G', $scheduled);
        $horaCierre   = 22; // 10:00 p. m.
        $horaApertura = 4;  // 04:00 a. m.

        if ($horaSeleccionada >= $horaCierre || $horaSeleccionada < $horaApertura) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'El sistema está cerrado en ese horario. Solo puedes publicar rutas entre las 04:00 a. m. y las 10:00 p. m.'
            ]);
            break;
        }

        // Datos que mandó el frontend después de mostrarle al conductor
        // lo que dijo el modelo (ver acción 'predecirPublicacion'). Si no
        // llegan (por ejemplo, si el modelo no estaba disponible), se
        // guardan como NULL: la ruta se publica igual, sin bloquear al
        // conductor por una caída del servicio de IA.
        $prediccion_valor   = isset($input['prediccion_valor']) ? $input['prediccion_valor'] : null;
        $prediccion_mensaje = isset($input['prediccion_mensaje']) ? $input['prediccion_mensaje'] : null;
        $prediccion_recom   = isset($input['prediccion_recom']) ? $input['prediccion_recom'] : null;

        try {
            // ------------------------------------------------------------
            // PUNTO 1: un conductor NO puede publicar si todavía no tiene
            // un vehículo (modelo + placas) asignado por el administrador
            // con la acción 'assignPlate'.
            // ------------------------------------------------------------
            $stmt = $pdo->prepare("
                SELECT u.id_usuario, u.rol, veh.id_vehiculo
                FROM usuario u
                LEFT JOIN vehiculo veh
                       ON veh.id_usuario = u.id_usuario AND veh.estado = 'activo'
                WHERE u.correo = ?
            ");
            $stmt->execute([$conductor]);
            $datosConductor = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$datosConductor || $datosConductor['rol'] !== 'Conductor') {
                echo json_encode(['status' => 'error', 'message' => 'Solo los usuarios con rol Conductor pueden publicar rutas']);
                break;
            }

            if (empty($datosConductor['id_vehiculo'])) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Debes dirigirte al plantel administrativo para registrar tu vehículo antes de poder publicar rutas.'
                ]);
                break;
            }

            $stmt = $pdo->prepare("
                INSERT INTO ruta (conductor, origen, destino, horario, fecha, lugares, precio, estado,
                                   prediccion_valor, prediccion_mensaje, prediccion_recom)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'activa', ?, ?, ?)
            ");
            $stmt->execute([
                $conductor, $origen, $destino, $horario, $fecha, $lugares, $precio,
                $prediccion_valor, $prediccion_mensaje, $prediccion_recom,
            ]);

            echo json_encode(['status' => 'success', 'message' => 'Ruta publicada exitosamente']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al publicar ruta: ' . $e->getMessage()]);
        }
        break;
    }

    // PUNTO 1 (frontend): el conductor consulta si ya tiene vehículo
    // registrado para saber si puede o no publicar rutas.
    case 'getMyVehicle': {
        if (!isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Email de usuario requerido']);
            break;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT u.id_usuario, u.rol, veh.id_vehiculo, veh.modelo, veh.placas
                FROM usuario u
                LEFT JOIN vehiculo veh
                       ON veh.id_usuario = u.id_usuario AND veh.estado = 'activo'
                WHERE u.correo = ?
            ");
            $stmt->execute([$input['userEmail']]);
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$datos) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
                break;
            }

            $tieneVehiculo = !empty($datos['id_vehiculo']);

            echo json_encode([
                'status'         => 'success',
                'tiene_vehiculo' => $tieneVehiculo,
                'vehiculo'       => $tieneVehiculo ? [
                    'id_vehiculo' => $datos['id_vehiculo'],
                    'modelo'      => $datos['modelo'],
                    'placas'      => $datos['placas'],
                ] : null,
                'message'        => $tieneVehiculo
                    ? 'Vehículo registrado'
                    : 'Debes dirigirte al plantel administrativo para registrar tu vehículo antes de poder publicar rutas.'
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al consultar el vehículo: ' . $e->getMessage()]);
        }
        break;
    }

    case 'getAllRoutes': {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    r.id_ruta, r.origen, r.destino, r.horario, r.fecha,
                    r.lugares, r.precio, r.conductor,
                    COALESCE(NULLIF(u.nombre, ''), u.correo) as nombre_conductor
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
                    COALESCE(NULLIF(u.nombre, ''), u.correo) as nombre_conductor
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
            // ------------------------------------------------------------
            // PUNTO 2: reservar es EXCLUSIVO del rol Pasajero. Un conductor
            // no puede pedir/tomar viajes (su papel es publicar rutas).
            // Se valida aquí, en el backend, y no solo en el frontend.
            // ------------------------------------------------------------
            $stmt = $pdo->prepare("SELECT id_usuario, correo, rol FROM usuario WHERE id_usuario = ?");
            $stmt->execute([$id_usuario_pasajero]);
            $usuario_pasajero = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario_pasajero) {
                throw new Exception('Usuario no encontrado');
            }

            if ($usuario_pasajero['rol'] !== 'Pasajero') {
                throw new Exception('Solo los pasajeros pueden reservar viajes. Un conductor debe publicar sus propias rutas.');
            }

            // Si el frontend manda el correo, comprobamos que el id recibido
            // sea realmente el de esa sesión (evita reservar a nombre de otro).
            if (!empty($input['userEmail']) && $input['userEmail'] !== $usuario_pasajero['correo']) {
                throw new Exception('No puedes reservar un viaje a nombre de otro usuario');
            }

            $pdo->beginTransaction();

            // PUNTO 8: Un pasajero solo puede tener UN viaje activo a la vez.
            // Los estados de viaje siguen siendo los mismos (pendiente/en_curso);
            // el nuevo estado de pago no crea viajes adicionales, así que esta
            // validación sigue siendo la que impide reservas simultáneas.
            $stmt = $pdo->prepare("
                SELECT id_viaje FROM viaje
                WHERE id_usuario_pasajero = ? AND estado IN ('pendiente', 'en_curso')
                LIMIT 1
            ");
            $stmt->execute([$id_usuario_pasajero]);
            if ($stmt->fetch()) {
                throw new Exception('Ya tienes un viaje activo. Debes completarlo o cancelarlo antes de reservar otro.');
            }

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
            // Puede que la excepción ocurra ANTES de beginTransaction
            // (validación de rol, por ejemplo), así que no se puede hacer
            // rollBack a ciegas.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

            // PUNTO 3: además de los datos del pasajero, se devuelve el
            // estado de su pago para que el conductor sepa quién ya pagó y
            // quién está esperando su confirmación de efectivo.
            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.id_ruta, v.fecha, v.hora,
                    r.origen, r.destino, r.horario as horario_ruta,
                    COALESCE(NULLIF(u_pasajero.nombre, ''), u_pasajero.correo) as nombre_pasajero,
                    u_pasajero.correo as correo_pasajero,
                    u_pasajero.num_control as num_control_pasajero,
                    v.estado, v.costo, v.pasajero_listo, v.pasajero_finalizado,
                    p.id_pago, p.metodo as metodo_pago, p.estado as estado_pago
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                LEFT JOIN (
                    SELECT p1.id_viaje, p1.id_pago, p1.metodo, p1.estado
                    FROM pago p1
                    INNER JOIN (
                        SELECT id_viaje, MAX(id_pago) AS max_id
                        FROM pago
                        WHERE id_viaje IS NOT NULL
                        GROUP BY id_viaje
                    ) p2 ON p1.id_viaje = p2.id_viaje AND p1.id_pago = p2.max_id
                ) p ON p.id_viaje = v.id_viaje
                WHERE v.id_usuario_conductor = ?
                AND v.estado IN ('pendiente', 'en_curso')
                ORDER BY r.id_ruta, v.fecha, v.hora
            ");
            $stmt->execute([$user['id_usuario']]);
            $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'viajes' => $viajes]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar viajes: ' . $e->getMessage()]);
        }
        break;
    }

    // PUNTO 5 (frontend): rutas activas del conductor con el conteo de
    // pasajeros. Sirve para poder INICIAR una ruta incluso cuando no hay
    // ningún pasajero (getActiveDriverTrips solo devuelve viajes ya
    // reservados, así que una ruta vacía no aparecería nunca).
    case 'getMyRoutes': {
        if (!isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Email de usuario requerido']);
            break;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    r.id_ruta, r.origen, r.destino, r.horario, r.fecha, r.lugares, r.precio,
                    (SELECT COUNT(*) FROM viaje v
                      WHERE v.id_ruta = r.id_ruta AND v.estado = 'pendiente') AS pasajeros_pendientes,
                    (SELECT COUNT(*) FROM viaje v
                      WHERE v.id_ruta = r.id_ruta AND v.estado = 'pendiente' AND v.pasajero_listo = 1) AS pasajeros_listos,
                    (SELECT COUNT(*) FROM viaje v
                      WHERE v.id_ruta = r.id_ruta AND v.estado = 'en_curso') AS pasajeros_en_curso
                FROM ruta r
                WHERE r.conductor = ? AND r.estado = 'activa'
                ORDER BY r.fecha DESC, r.horario DESC
            ");
            $stmt->execute([$input['userEmail']]);
            $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'rutas' => $rutas]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar tus rutas: ' . $e->getMessage()]);
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
            if ($viaje['estado'] === 'cancelado') {
                throw new Exception('Este viaje fue cancelado');
            }
            if ($viaje['estado'] !== 'en_curso') {
                throw new Exception('El viaje debe estar en curso antes de poder completarlo. Primero debes iniciarlo.');
            }

            $stmt = $pdo->prepare("UPDATE viaje SET estado = 'completado' WHERE id_viaje = ?");
            $stmt->execute([$id_viaje]);

            echo json_encode(['status' => 'success', 'message' => 'Viaje marcado como completado exitosamente']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // El pasajero confirma que está listo para empezar su viaje
    case 'passengerReady': {
        if (!isset($input['id_viaje']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_viaje  = $input['id_viaje'];
        $userEmail = $input['userEmail'];

        try {
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, v.estado
                FROM viaje v
                INNER JOIN usuario u ON v.id_usuario_pasajero = u.id_usuario
                WHERE v.id_viaje = ? AND u.correo = ?
            ");
            $stmt->execute([$id_viaje, $userEmail]);
            $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$viaje) {
                throw new Exception('Viaje no encontrado o no eres el pasajero de este viaje');
            }
            if ($viaje['estado'] !== 'pendiente') {
                throw new Exception('Solo puedes confirmar un viaje que esté pendiente');
            }

            $stmt = $pdo->prepare("UPDATE viaje SET pasajero_listo = 1 WHERE id_viaje = ?");
            $stmt->execute([$id_viaje]);

            echo json_encode(['status' => 'success', 'message' => 'Listo. Esperando a que el conductor inicie el viaje.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // El pasajero marca su propio viaje como finalizado (solo si ya está en curso)
    case 'passengerFinish': {
        if (!isset($input['id_viaje']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_viaje  = $input['id_viaje'];
        $userEmail = $input['userEmail'];

        try {
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, v.estado
                FROM viaje v
                INNER JOIN usuario u ON v.id_usuario_pasajero = u.id_usuario
                WHERE v.id_viaje = ? AND u.correo = ?
            ");
            $stmt->execute([$id_viaje, $userEmail]);
            $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$viaje) {
                throw new Exception('Viaje no encontrado o no eres el pasajero de este viaje');
            }
            if ($viaje['estado'] !== 'en_curso') {
                throw new Exception('Solo puedes finalizar un viaje que esté en curso');
            }

            // ------------------------------------------------------------
            // PUNTO 6: el pasajero solo puede finalizar si su pago ya está
            // completado (tarjeta) o confirmado por el conductor (efectivo).
            // Esto es lo que garantiza, junto con completeRouteTrip, que un
            // viaje no llegue a 'completado' sin haberse pagado (punto 7).
            // ------------------------------------------------------------
            $pago = obtenerPagoDeViaje($pdo, $id_viaje);

            if (!$pago) {
                throw new Exception('Debes pagar tu viaje antes de poder finalizarlo. Ve a la sección de pagos y registra tu pago.');
            }

            if (!estadoPagoCuentaComoPagado($pago['estado'])) {
                if ($pago['estado'] === 'pendiente_confirmacion') {
                    throw new Exception('Tu pago en efectivo está pendiente de que el conductor lo confirme. No puedes finalizar el viaje todavía.');
                }
                throw new Exception('Debes pagar tu viaje antes de poder finalizarlo.');
            }

            $stmt = $pdo->prepare("UPDATE viaje SET pasajero_finalizado = 1 WHERE id_viaje = ?");
            $stmt->execute([$id_viaje]);

            echo json_encode(['status' => 'success', 'message' => 'Listo. Esperando a que los demás pasajeros finalicen.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // El conductor inicia el viaje de TODA una ruta.
    //
    // PUNTO 5: ya NO se exige que todos los pasajeros hayan confirmado
    // "listo". Al iniciar:
    //   - los viajes con pasajero_listo = 1 pasan a 'en_curso';
    //   - los viajes con pasajero_listo = 0 se CANCELAN automáticamente,
    //     liberando su lugar en la ruta y dejando un aviso para que el
    //     pasajero vea "El viaje se ha cancelado.";
    //   - si la ruta no tiene pasajeros, también se puede iniciar.
    case 'startRouteTrip': {
        if (!isset($input['id_ruta']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_ruta   = $input['id_ruta'];
        $userEmail = $input['userEmail'];

        try {
            // 1) La ruta debe existir y ser de este conductor
            $stmt = $pdo->prepare("SELECT id_ruta FROM ruta WHERE id_ruta = ? AND conductor = ?");
            $stmt->execute([$id_ruta, $userEmail]);
            if (!$stmt->fetch()) {
                throw new Exception('No eres el conductor de esta ruta');
            }

            // 2) Viajes pendientes de la ruta, separados por confirmación
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, v.pasajero_listo
                FROM viaje v
                WHERE v.id_ruta = ? AND v.estado = 'pendiente'
            ");
            $stmt->execute([$id_ruta]);
            $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $listos   = [];
            $noListos = [];
            foreach ($viajes as $v) {
                if ((int)$v['pasajero_listo'] === 1) {
                    $listos[] = (int)$v['id_viaje'];
                } else {
                    $noListos[] = (int)$v['id_viaje'];
                }
            }

            $pdo->beginTransaction();

            // 3) Cancelar a los que no confirmaron y devolver su lugar
            if (count($noListos) > 0) {
                $marcadores = implode(',', array_fill(0, count($noListos), '?'));

                $stmt = $pdo->prepare("
                    UPDATE viaje
                    SET estado = 'cancelado',
                        motivo_cancelacion = 'El viaje se ha cancelado.',
                        cancelacion_vista = 0
                    WHERE id_viaje IN ($marcadores) AND estado = 'pendiente'
                ");
                $stmt->execute($noListos);

                $stmt = $pdo->prepare("UPDATE ruta SET lugares = lugares + ? WHERE id_ruta = ?");
                $stmt->execute([count($noListos), $id_ruta]);
            }

            // 4) Iniciar a los que sí confirmaron
            if (count($listos) > 0) {
                $marcadores = implode(',', array_fill(0, count($listos), '?'));

                $stmt = $pdo->prepare("
                    UPDATE viaje
                    SET estado = 'en_curso'
                    WHERE id_viaje IN ($marcadores) AND estado = 'pendiente'
                ");
                $stmt->execute($listos);
            }

            $pdo->commit();

            $partes = [];
            if (count($listos) > 0) {
                $partes[] = count($listos) . ' pasajero(s) iniciaron su viaje';
            }
            if (count($noListos) > 0) {
                $partes[] = count($noListos) . ' pasajero(s) que no confirmaron fueron cancelados y su lugar quedó libre';
            }
            if (count($partes) === 0) {
                $partes[] = 'No había pasajeros en esta ruta; el viaje quedó iniciado sin pasajeros';
            }

            echo json_encode([
                'status'     => 'success',
                'message'    => implode('. ', $partes) . '.',
                'iniciados'  => count($listos),
                'cancelados' => count($noListos),
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // El conductor marca como completados TODOS los viajes en_curso de una ruta
    case 'completeRouteTrip': {
        if (!isset($input['id_ruta']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_ruta   = $input['id_ruta'];
        $userEmail = $input['userEmail'];

        try {
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, v.pasajero_finalizado
                FROM viaje v
                INNER JOIN usuario u ON v.id_usuario_conductor = u.id_usuario
                WHERE v.id_ruta = ? AND u.correo = ? AND v.estado = 'en_curso'
            ");
            $stmt->execute([$id_ruta, $userEmail]);
            $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($viajes) === 0) {
                throw new Exception('No hay viajes en curso en esta ruta, o no eres el conductor');
            }

            foreach ($viajes as $v) {
                if ((int)$v['pasajero_finalizado'] !== 1) {
                    throw new Exception('Todavía hay pasajeros que no han finalizado su viaje');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE viaje v
                INNER JOIN usuario u ON v.id_usuario_conductor = u.id_usuario
                SET v.estado = 'completado'
                WHERE v.id_ruta = ? AND u.correo = ? AND v.estado = 'en_curso'
            ");
            $stmt->execute([$id_ruta, $userEmail]);

            echo json_encode(['status' => 'success', 'message' => 'Viaje completado exitosamente']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // El pasajero consulta su viaje activo actual (pendiente o en curso)
    // con los datos del vehículo y el conductor.
    case 'getMyCurrentTrip': {
        if (!isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Email de usuario requerido']);
            break;
        }

        $userEmail = $input['userEmail'];

        try {
            // PUNTO 3: se incluye el estado del pago del viaje para que el
            // pasajero sepa si debe pagar, si espera confirmación del
            // conductor o si ya está listo para finalizar.
            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha, v.hora, v.costo, v.estado, v.pasajero_listo, v.pasajero_finalizado,
                    r.origen, r.destino,
                    veh.modelo, veh.placas,
                    COALESCE(NULLIF(u_conductor.nombre, ''), u_conductor.correo) as nombre_conductor,
                    u_conductor.num_control as num_control_conductor,
                    p.id_pago, p.metodo as metodo_pago, p.estado as estado_pago
                FROM viaje v
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN vehiculo veh ON v.id_vehiculo = veh.id_vehiculo
                INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
                LEFT JOIN (
                    SELECT p1.id_viaje, p1.id_pago, p1.metodo, p1.estado
                    FROM pago p1
                    INNER JOIN (
                        SELECT id_viaje, MAX(id_pago) AS max_id
                        FROM pago
                        WHERE id_viaje IS NOT NULL
                        GROUP BY id_viaje
                    ) p2 ON p1.id_viaje = p2.id_viaje AND p1.id_pago = p2.max_id
                ) p ON p.id_viaje = v.id_viaje
                WHERE u_pasajero.correo = ? AND v.estado IN ('pendiente', 'en_curso')
                ORDER BY v.fecha DESC, v.hora DESC
                LIMIT 1
            ");
            $stmt->execute([$userEmail]);
            $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

            // PUNTO 5: si el sistema o el conductor canceló un viaje de este
            // pasajero (por ejemplo, porque no confirmó "listo" y la ruta
            // arrancó), se devuelve el aviso UNA sola vez.
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, COALESCE(v.motivo_cancelacion, 'El viaje se ha cancelado.') AS motivo
                FROM viaje v
                INNER JOIN usuario u ON v.id_usuario_pasajero = u.id_usuario
                WHERE u.correo = ?
                  AND v.estado = 'cancelado'
                  AND v.cancelacion_vista = 0
                ORDER BY v.id_viaje DESC
                LIMIT 1
            ");
            $stmt->execute([$userEmail]);
            $cancelado = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cancelado) {
                $upd = $pdo->prepare("UPDATE viaje SET cancelacion_vista = 1 WHERE id_viaje = ?");
                $upd->execute([$cancelado['id_viaje']]);
            }

            if ($viaje) {
                $viaje['pago_confirmado'] = $viaje['estado_pago']
                    ? estadoPagoCuentaComoPagado($viaje['estado_pago'])
                    : false;
                $viaje['puede_finalizar'] = (bool)$viaje['pago_confirmado'];

                echo json_encode([
                    'status'              => 'success',
                    'tiene_viaje'         => true,
                    'viaje'               => $viaje,
                    'mensaje_cancelacion' => $cancelado ? $cancelado['motivo'] : null,
                ]);
            } else {
                echo json_encode([
                    'status'              => 'success',
                    'tiene_viaje'         => false,
                    'mensaje_cancelacion' => $cancelado ? $cancelado['motivo'] : null,
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar el viaje: ' . $e->getMessage()]);
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
                    COALESCE(NULLIF(u_conductor.nombre, ''), u_conductor.correo) as nombre_conductor,
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
            $stmt = $pdo->prepare("SELECT id_usuario, rol FROM usuario WHERE correo = ?");
            $stmt->execute([$userEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
                break;
            }

            $id_usuario = $user['id_usuario'];

            // ------------------------------------------------------------
            // PUNTO 4: el historial del CONDUCTOR no muestra viaje por viaje
            // con calificación individual. Se devuelve UNA fila por DÍA con
            // la calificación GENERAL (promedio) de todas las calificaciones
            // recibidas ese día.
            // ------------------------------------------------------------
            if ($user['rol'] === 'Conductor') {
                $stmt = $pdo->prepare("
                    SELECT
                        v.fecha                                       AS fecha_viaje,
                        COUNT(DISTINCT v.id_viaje)                    AS total_viajes,
                        SUM(CASE WHEN v.estado = 'completado' THEN 1 ELSE 0 END) AS viajes_completados,
                        SUM(CASE WHEN v.estado = 'cancelado'  THEN 1 ELSE 0 END) AS viajes_cancelados,
                        COUNT(c.id_calif)                             AS total_calificaciones,
                        ROUND(AVG(c.puntuacion), 2)                   AS calificacion_promedio,
                        GROUP_CONCAT(DISTINCT CONCAT(r.origen, ' → ', r.destino)
                                     ORDER BY r.id_ruta SEPARATOR ' | ') AS rutas,
                        'conductor'                                   AS tipo_usuario
                    FROM viaje v
                    INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                    LEFT JOIN calificacion c
                           ON c.id_viaje = v.id_viaje
                          AND c.id_usuario_calificado = v.id_usuario_conductor
                    WHERE v.id_usuario_conductor = ?
                    GROUP BY v.fecha
                    ORDER BY v.fecha DESC
                ");
                $stmt->execute([$id_usuario]);
                $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'status'            => 'success',
                    'tipo_usuario'      => 'conductor',
                    'agrupado_por_dia'  => true,
                    'historial'         => $historial,
                ]);
                break;
            }

            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha as fecha_viaje, v.hora,
                    r.origen, r.destino, r.horario,
                    COALESCE(NULLIF(u_conductor.nombre, ''), u_conductor.correo) as nombre_conductor,
                    v.costo, v.estado,
                    v.calificacion_conductor, v.comentario_conductor,
                    'pasajero' as tipo_usuario
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
                WHERE v.id_usuario_pasajero = ?
                ORDER BY v.fecha DESC, v.hora DESC
            ");
            $stmt->execute([$id_usuario]);
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'           => 'success',
                'tipo_usuario'     => 'pasajero',
                'agrupado_por_dia' => false,
                'historial'        => $historial,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar historial: ' . $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // PAGOS
    // ========================================================

    // PUNTO 3: registro del pago del pasajero.
    //
    //   - Con TARJETA: el pago queda 'completado' automáticamente al
    //     procesarse (sin intervención del conductor).
    //   - En EFECTIVO y ligado a un viaje (el botón "Ya pagué" de
    //     mi-viaje.html): queda 'pendiente_confirmacion' hasta que el
    //     conductor use 'confirmarPagoEfectivo'.
    //   - En EFECTIVO sin viaje (pantalla de pagos suelta): se conserva el
    //     comportamiento anterior y queda 'completado'.
    case 'processPayment': {
        if (!isset($input['metodo']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $metodo    = $input['metodo'];
        $userEmail = $input['userEmail'];
        $monto     = $input['monto'] ?? 25.00;
        $id_viaje  = isset($input['id_viaje']) && $input['id_viaje'] !== '' ? (int)$input['id_viaje'] : null;

        try {
            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$userEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
                break;
            }

            $id_usuario = $user['id_usuario'];

            // Validaciones cuando el pago va ligado a un viaje
            if ($id_viaje !== null) {
                $stmt = $pdo->prepare("
                    SELECT id_viaje, id_ruta, estado
                    FROM viaje
                    WHERE id_viaje = ? AND id_usuario_pasajero = ?
                ");
                $stmt->execute([$id_viaje, $id_usuario]);
                $viajePago = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$viajePago) {
                    echo json_encode(['status' => 'error', 'message' => 'El viaje no existe o no te pertenece']);
                    break;
                }
                if (!in_array($viajePago['estado'], ['pendiente', 'en_curso'], true)) {
                    echo json_encode(['status' => 'error', 'message' => 'Solo puedes pagar un viaje activo (pendiente o en curso)']);
                    break;
                }

                $pagoPrevio = obtenerPagoDeViaje($pdo, $id_viaje);
                if ($pagoPrevio && estadoPagoCuentaComoPagado($pagoPrevio['estado'])) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'Este viaje ya tiene un pago registrado y confirmado.',
                        'estado_pago' => $pagoPrevio['estado'],
                    ]);
                    break;
                }
            }

            if ($metodo === 'Efectivo') {
                $referencia = 'EFC-' . date('YmdHis') . '-' . rand(100, 999);
                // Con viaje => el conductor debe confirmar que recibió el efectivo
                $estadoPago = ($id_viaje !== null) ? 'pendiente_confirmacion' : 'completado';

                $stmt = $pdo->prepare("
                    INSERT INTO pago (id_usuario, id_viaje, metodo, monto, referencia, estado)
                    VALUES (?, ?, 'efectivo', ?, ?, ?)
                ");
                $stmt->execute([$id_usuario, $id_viaje, $monto, $referencia, $estadoPago]);

                echo json_encode([
                    'status'      => 'success',
                    'message'     => ($id_viaje !== null)
                        ? 'Aviso enviado al conductor. Debe confirmar que recibió tu pago en efectivo.'
                        : 'Pago en efectivo registrado exitosamente',
                    'referencia'  => $referencia,
                    'monto'       => $monto,
                    'estado_pago' => $estadoPago,
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

                // Con tarjeta el pago se completa automáticamente
                $stmt = $pdo->prepare("
                    INSERT INTO pago (id_usuario, id_viaje, metodo, monto, referencia, titular_tarjeta, numero_tarjeta_enmascarado, estado)
                    VALUES (?, ?, 'tarjeta', ?, ?, ?, ?, 'completado')
                ");
                $stmt->execute([$id_usuario, $id_viaje, $monto, $referencia, $titular, $numero_enmascarado]);

                echo json_encode([
                    'status'             => 'success',
                    'message'            => 'Pago con tarjeta procesado exitosamente',
                    'referencia'         => $referencia,
                    'monto'              => $monto,
                    'tarjeta_enmascarada'=> $numero_enmascarado,
                    'estado_pago'        => 'completado',
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Método de pago no válido']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al procesar pago: ' . $e->getMessage()]);
        }
        break;
    }

    // PUNTO 3: el CONDUCTOR confirma, uno por uno, que un pasajero ya le
    // pagó en efectivo. Solo aplica a pagos en efectivo que el pasajero
    // marcó con "Ya pagué".
    case 'confirmarPagoEfectivo': {
        if (!isset($input['id_viaje']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_viaje  = $input['id_viaje'];
        $userEmail = $input['userEmail'];

        try {
            $stmt = $pdo->prepare("
                SELECT v.id_viaje, v.costo,
                       COALESCE(NULLIF(u.nombre, ''), u.correo) AS nombre_pasajero
                FROM viaje v
                INNER JOIN usuario u ON v.id_usuario_pasajero = u.id_usuario
                INNER JOIN usuario uc ON v.id_usuario_conductor = uc.id_usuario
                WHERE v.id_viaje = ? AND uc.correo = ?
            ");
            $stmt->execute([$id_viaje, $userEmail]);
            $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$viaje) {
                throw new Exception('Viaje no encontrado o no eres el conductor de este viaje');
            }

            $pago = obtenerPagoDeViaje($pdo, $id_viaje);

            if (!$pago) {
                throw new Exception('Este pasajero todavía no ha registrado su pago en efectivo');
            }
            if ($pago['metodo'] !== 'efectivo') {
                throw new Exception('Este pago no es en efectivo: los pagos con tarjeta se confirman automáticamente');
            }
            if ($pago['estado'] === 'confirmado' || $pago['estado'] === 'completado') {
                echo json_encode([
                    'status'  => 'success',
                    'message' => 'Este pago ya estaba confirmado.',
                    'estado_pago' => $pago['estado'],
                ]);
                break;
            }
            if ($pago['estado'] !== 'pendiente_confirmacion') {
                throw new Exception('El pago no está en un estado que se pueda confirmar (estado actual: ' . $pago['estado'] . ')');
            }

            $stmt = $pdo->prepare("UPDATE pago SET estado = 'confirmado' WHERE id_pago = ?");
            $stmt->execute([$pago['id_pago']]);

            echo json_encode([
                'status'      => 'success',
                'message'     => 'Confirmaste el pago en efectivo de ' . $viaje['nombre_pasajero'] . '. Ya puede finalizar su viaje.',
                'estado_pago' => 'confirmado',
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // ========================================================
    // MENSAJERÍA PRIVADA PASAJERO <-> CONDUCTOR (PUNTO 9)
    //
    // La conversación está atada al VIAJE (id_viaje), así que solo se
    // puede hablar con la contraparte de ese viaje. Mientras el viaje
    // está activo (pendiente o en_curso) se puede escribir; el historial
    // se puede seguir leyendo después.
    // ========================================================

    case 'enviarMensaje': {
        if (!isset($input['id_viaje']) || !isset($input['userEmail']) || !isset($input['contenido'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos para enviar el mensaje']);
            break;
        }

        $id_viaje  = $input['id_viaje'];
        $userEmail = $input['userEmail'];
        $contenido = trim($input['contenido']);

        if ($contenido === '') {
            echo json_encode(['status' => 'error', 'message' => 'El mensaje no puede estar vacío']);
            break;
        }
        if (mb_strlen($contenido) > 500) {
            echo json_encode(['status' => 'error', 'message' => 'El mensaje no puede superar los 500 caracteres']);
            break;
        }

        try {
            $user = usuarioPorCorreo($pdo, $userEmail);
            if (!$user) {
                throw new Exception('Usuario no encontrado');
            }

            $viaje = viajeDeParticipante($pdo, $id_viaje, $user['id_usuario']);
            if (!$viaje) {
                throw new Exception('No participas en este viaje');
            }
            if (!in_array($viaje['estado'], ['pendiente', 'en_curso'], true)) {
                throw new Exception('Solo puedes enviar mensajes mientras el viaje está activo');
            }

            // El destinatario siempre es la contraparte
            $destinatario = ((int)$viaje['id_usuario_pasajero'] === (int)$user['id_usuario'])
                ? (int)$viaje['id_usuario_conductor']
                : (int)$viaje['id_usuario_pasajero'];

            $stmt = $pdo->prepare("
                INSERT INTO mensaje (id_viaje, id_remitente, id_destinatario, contenido)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$id_viaje, $user['id_usuario'], $destinatario, $contenido]);

            echo json_encode([
                'status'     => 'success',
                'message'    => 'Mensaje enviado',
                'id_mensaje' => $pdo->lastInsertId(),
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    case 'getMensajesViaje': {
        if (!isset($input['id_viaje']) || !isset($input['userEmail'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_viaje  = $input['id_viaje'];
        $userEmail = $input['userEmail'];

        try {
            $user = usuarioPorCorreo($pdo, $userEmail);
            if (!$user) {
                throw new Exception('Usuario no encontrado');
            }

            $viaje = viajeDeParticipante($pdo, $id_viaje, $user['id_usuario']);
            if (!$viaje) {
                throw new Exception('No participas en este viaje');
            }

            $stmt = $pdo->prepare("
                SELECT
                    m.id_mensaje, m.id_remitente, m.id_destinatario, m.contenido, m.fecha_hora,
                    COALESCE(NULLIF(u.nombre, ''), u.correo) AS nombre_remitente
                FROM mensaje m
                INNER JOIN usuario u ON u.id_usuario = m.id_remitente
                WHERE m.id_viaje = ?
                ORDER BY m.fecha_hora ASC, m.id_mensaje ASC
                LIMIT 200
            ");
            $stmt->execute([$id_viaje]);
            $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'         => 'success',
                'mi_id_usuario'  => (int)$user['id_usuario'],
                'estado_viaje'   => $viaje['estado'],
                'puede_enviar'   => in_array($viaje['estado'], ['pendiente', 'en_curso'], true),
                'mensajes'       => $mensajes,
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
                    u.id_usuario, COALESCE(NULLIF(u.nombre, ''), u.correo) as nombre, u.correo, u.num_control,
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

    // Todos los usuarios (pasajeros y conductores) para gestión de contraseñas
    case 'getAllUsers': {
        try {
            $stmt = $pdo->prepare("
                SELECT id_usuario, COALESCE(NULLIF(nombre, ''), correo) as nombre, correo, num_control, rol, estado
                FROM usuario
                WHERE rol IN ('Pasajero', 'Conductor')
                ORDER BY rol, nombre
            ");
            $stmt->execute();
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'usuarios' => $usuarios]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar usuarios: ' . $e->getMessage()]);
        }
        break;
    }

    // El administrador cambia la contraseña de cualquier pasajero o conductor
    case 'adminChangePassword': {
        if (!isset($input['id_usuario']) || !isset($input['nueva_clave'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            break;
        }

        $id_usuario   = $input['id_usuario'];
        $nueva_clave  = $input['nueva_clave'];

        if (strlen($nueva_clave) < 6) {
            echo json_encode(['status' => 'error', 'message' => 'La nueva contraseña debe tener mínimo 6 caracteres']);
            break;
        }

        try {
            $stmt = $pdo->prepare("SELECT id_usuario, rol FROM usuario WHERE id_usuario = ?");
            $stmt->execute([$id_usuario]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('Usuario no encontrado');
            }
            if ($user['rol'] === 'Administrador') {
                throw new Exception('No se puede cambiar la contraseña de una cuenta de administrador desde aquí');
            }

            $hash = password_hash($nueva_clave, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuario SET clave = ? WHERE id_usuario = ?");
            $stmt->execute([$hash, $id_usuario]);

            echo json_encode(['status' => 'success', 'message' => 'Contraseña actualizada correctamente']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
                    r.prediccion_valor, r.prediccion_mensaje, r.prediccion_recom,
                    COALESCE(NULLIF(u.nombre, ''), u.correo) as nombre_conductor
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
    // Viajes ACTIVOS (pendientes o en curso) - para supervisión/cancelación
    case 'adminGetActiveTrips': {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha, v.hora, v.costo, v.estado,
                    r.id_ruta, r.origen, r.destino,
                    COALESCE(NULLIF(u_pasajero.nombre, ''), u_pasajero.correo) as nombre_pasajero, u_pasajero.correo as correo_pasajero,
                    COALESCE(NULLIF(u_conductor.nombre, ''), u_conductor.correo) as nombre_conductor, u_conductor.correo as correo_conductor
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
                WHERE v.estado IN ('pendiente', 'en_curso')
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

    // Historial (viajes terminados o cancelados) - el administrador puede borrarlo
    case 'adminGetTripHistory': {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    v.id_viaje, v.fecha, v.hora, v.costo, v.estado,
                    r.id_ruta, r.origen, r.destino,
                    COALESCE(NULLIF(u_pasajero.nombre, ''), u_pasajero.correo) as nombre_pasajero, u_pasajero.correo as correo_pasajero,
                    COALESCE(NULLIF(u_conductor.nombre, ''), u_conductor.correo) as nombre_conductor, u_conductor.correo as correo_conductor,
                    v.calificacion_conductor
                FROM viaje v
                INNER JOIN ruta r ON v.id_ruta = r.id_ruta
                INNER JOIN usuario u_pasajero ON v.id_usuario_pasajero = u_pasajero.id_usuario
                INNER JOIN usuario u_conductor ON v.id_usuario_conductor = u_conductor.id_usuario
                WHERE v.estado IN ('completado', 'cancelado')
                ORDER BY v.fecha DESC, v.hora DESC
            ");
            $stmt->execute();
            $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'viajes' => $viajes]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar el historial: ' . $e->getMessage()]);
        }
        break;
    }

    // Borrar UN registro del historial (solo si ya está terminado o cancelado)
    case 'adminDeleteTripHistory': {
        if (!isset($input['id_viaje'])) {
            echo json_encode(['status' => 'error', 'message' => 'id_viaje requerido']);
            break;
        }

        try {
            $stmt = $pdo->prepare("SELECT estado FROM viaje WHERE id_viaje = ?");
            $stmt->execute([$input['id_viaje']]);
            $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$viaje) {
                throw new Exception('Viaje no encontrado');
            }
            if (!in_array($viaje['estado'], ['completado', 'cancelado'])) {
                throw new Exception('Solo se pueden borrar viajes terminados o cancelados');
            }

            $stmt = $pdo->prepare("DELETE FROM viaje WHERE id_viaje = ?");
            $stmt->execute([$input['id_viaje']]);

            echo json_encode(['status' => 'success', 'message' => 'Registro del historial eliminado']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;
    }

    // Borrar TODO el historial (todos los viajes terminados o cancelados)
    case 'adminClearTripHistory': {
        try {
            $stmt = $pdo->prepare("DELETE FROM viaje WHERE estado IN ('completado', 'cancelado')");
            $stmt->execute();
            $borrados = $stmt->rowCount();

            echo json_encode(['status' => 'success', 'message' => "Historial borrado ({$borrados} registros eliminados)"]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error al borrar el historial: ' . $e->getMessage()]);
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

            // Cancelación explícita (el propio usuario la pidió): se marca
            // como "vista" para no mostrarle después el aviso automático
            // "El viaje se ha cancelado."
            $stmt = $pdo->prepare("
                UPDATE viaje
                SET estado = 'cancelado', cancelacion_vista = 1
                WHERE id_viaje = ?
            ");
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
