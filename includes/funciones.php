<?php
/**
 * ==============================================================================
 * FUNCIONES AUXILIARES COMPARTIDAS POR TODA LA API
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Aquí viven las piezas que se repiten: responder JSON, leer lo que envía
 *    app.js, manejar fechas y crear usuarios con sus validaciones.
 *  - Cada archivo de /api carga este archivo (a través de auth.php).
 */

date_default_timezone_set('America/Panama'); // Todas las fechas se calculan con la hora de Panamá
require_once __DIR__ . '/../config/database.php';

// --- Reglas del sistema (iguales a las de la versión JavaScript original) ---
const MAX_PRESTAMOS_ACTIVOS = 3; // Máximo de préstamos activos por lector
const DIAS_PRESTAMO_LECTOR  = 7; // Días de préstamo cuando el propio lector pide un libro
const DIAS_RENOVACION       = 7; // Días que se suman al renovar

/** Fecha de hoy en formato AAAA-MM-DD (el formato que usa MySQL para DATE). */
function hoy(): string {
    return date('Y-m-d');
}

/** Suma (o resta) días a una fecha AAAA-MM-DD y devuelve la nueva fecha. */
function suma_dias(string $fecha, int $dias): string {
    return date('Y-m-d', strtotime($fecha . ' ' . ($dias >= 0 ? '+' : '') . $dias . ' days'));
}

/** Envía una respuesta JSON y termina el script. $codigo es el código HTTP (200 = bien). */
function responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store'); // Evita que el navegador guarde datos viejos
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Respuesta de éxito: {"ok":true,"mensaje":"..."} (el mensaje se muestra en verde). */
function ok(string $mensaje = '', array $extra = []): void {
    responder(['ok' => true, 'mensaje' => $mensaje] + $extra);
}

/** Respuesta de error: {"ok":false,"error":"..."} (el mensaje se muestra en rojo). */
function fallo(string $error, int $codigo = 400): void {
    responder(['ok' => false, 'error' => $error], $codigo);
}

/** Solo acepta peticiones POST enviadas por nuestro propio app.js (cabecera X-Requested-With). */
function exigir_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fallo('Método no permitido.', 405);
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        fallo('Solicitud no válida.', 400); // Protección básica contra formularios falsos de otros sitios (CSRF)
    }
}

/** Lee el cuerpo JSON que manda app.js y lo devuelve como arreglo (vacío si no hay nada). */
function leer_entrada(): array {
    $datos = json_decode(file_get_contents('php://input'), true);
    return is_array($datos) ? $datos : [];
}

/** Convierte cualquier valor a texto sin espacios sobrantes en los extremos. */
function texto($valor): string {
    return trim((string)($valor ?? ''));
}

/** Verifica el formato de un correo (misma regla que usaba el JavaScript). */
function correo_valido(string $email): bool {
    return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email);
}

/**
 * Crea un usuario nuevo validando todos los datos.
 * Devuelve un texto con el error, o null si todo salió bien.
 * Lo usan: registro de lectores (sesion.php) y creación por el administrador (usuarios.php).
 */
function crear_usuario(PDO $pdo, array $d, string $rol): ?string {
    $nombre     = texto($d['nombre'] ?? '');
    $email      = mb_strtolower(texto($d['email'] ?? ''));
    $pass       = (string)($d['pass'] ?? '');
    $usuarioIn  = texto($d['usuario'] ?? '');
    // Si no se escribió nombre de usuario, se usa el nombre completo en minúsculas (como en la versión JS)
    $nick       = mb_strtolower($usuarioIn !== '' ? $usuarioIn : $nombre);

    // --- Validaciones (el orden de los mensajes es el mismo que en la versión JS) ---
    if (mb_strlen($nombre) < 2)   return 'Escribe tu nombre completo.';
    if (mb_strlen($nombre) > 100) return 'El nombre es demasiado largo (máximo 100 caracteres).';
    if ($usuarioIn !== '' && !preg_match('/^[a-z0-9_.]{3,20}$/', $nick)) {
        return 'El usuario debe tener de 3 a 20 caracteres (letras, números, _ o .).';
    }
    if (!correo_valido($email) || mb_strlen($email) > 150) return 'El correo no es válido.';
    if (mb_strlen($pass) < 6)     return 'La contraseña debe tener al menos 6 caracteres.';
    if (mb_strlen($nick) > 100)   return 'El nombre de usuario es demasiado largo.';

    // --- Unicidad: que el usuario y el correo no existan ya ---
    $q = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE usuario = ?');
    $q->execute([$nick]);
    if ($q->fetchColumn() > 0) return 'Ese nombre de usuario ya existe.';

    $q = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
    $q->execute([$email]);
    if ($q->fetchColumn() > 0) return 'Ese correo ya está registrado.';

    // --- Rol permitido: solo lector o bibliotecario (el admin no se crea desde la web) ---
    if (!in_array($rol, ['lector', 'bibliotecario'], true)) return 'Rol no válido.';
    $q = $pdo->prepare('SELECT id FROM roles WHERE nombre = ?');
    $q->execute([$rol]);
    $rolId = $q->fetchColumn();
    if (!$rolId) return 'Rol no válido.';

    // --- Inserción: la contraseña se guarda con hash bcrypt, NUNCA en texto plano ---
    $q = $pdo->prepare('INSERT INTO usuarios (rol_id, nombre, usuario, email, password_hash) VALUES (?, ?, ?, ?, ?)');
    $q->execute([$rolId, $nombre, $nick, $email, password_hash($pass, PASSWORD_DEFAULT)]);
    return null;
}
