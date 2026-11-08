<?php
/**
 * ==============================================================================
 * SESIONES Y CONTROL DE ACCESO POR ROLES
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Al iniciar sesión, PHP guarda SOLO el id del usuario en $_SESSION (en el servidor).
 *  - En cada petición se vuelve a consultar al usuario en MySQL para conocer su rol real.
 *  - Roles del sistema: admin, bibliotecario y lector.
 *  - Las funciones requerir_*() cortan la petición con un error si no hay permiso.
 *    Así, aunque alguien manipule la página, el servidor siempre decide.
 */

require_once __DIR__ . '/funciones.php';

// Inicia la sesión con cookies más seguras (solo una vez por petición)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');   // La cookie no es legible desde JavaScript
    ini_set('session.use_only_cookies', '1');  // No se aceptan ids de sesión por la URL
    ini_set('session.cookie_samesite', 'Lax'); // No se envía desde otros sitios
    session_start();
}

/**
 * Devuelve los datos del usuario con sesión iniciada (o null si no hay sesión).
 * Campos: id, nombre, usuario, email, rol.
 */
function usuario_actual(): ?array {
    $id = (int)($_SESSION['usuario_id'] ?? 0);
    if ($id <= 0) return null;

    $q = getDBConnection()->prepare(
        'SELECT u.id, u.nombre, u.usuario, u.email, r.nombre AS rol
           FROM usuarios u JOIN roles r ON r.id = u.rol_id
          WHERE u.id = ?'
    );
    $q->execute([$id]);
    $u = $q->fetch();
    if (!$u) { // El usuario fue eliminado mientras tenía la sesión abierta
        unset($_SESSION['usuario_id']);
        return null;
    }
    $u['id'] = (int)$u['id'];
    return $u;
}

/** Exige sesión iniciada; si no, responde 401 y termina. Devuelve el usuario. */
function requerir_login(): array {
    $u = usuario_actual();
    if (!$u) fallo('Debes iniciar sesión.', 401);
    return $u;
}

/** Exige rol admin o bibliotecario (personal de la biblioteca). */
function requerir_staff(): array {
    $u = requerir_login();
    if (!in_array($u['rol'], ['admin', 'bibliotecario'], true)) fallo('No tienes permiso para esta acción.', 403);
    return $u;
}

/** Exige rol admin. */
function requerir_admin(): array {
    $u = requerir_login();
    if ($u['rol'] !== 'admin') fallo('Solo el administrador puede hacer esto.', 403);
    return $u;
}

/** Indica si el usuario pertenece al personal (admin o bibliotecario). */
function es_staff(?array $u): bool {
    return $u !== null && in_array($u['rol'], ['admin', 'bibliotecario'], true);
}
