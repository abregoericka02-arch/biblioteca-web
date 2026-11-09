<?php
/**
 * ==============================================================================
 * API: GESTIÓN DE USUARIOS  (POST api/usuarios.php)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Solo el ADMINISTRADOR puede usar este archivo.
 *  - Acciones:
 *      crear    -> crea un usuario con rol bibliotecario o lector
 *      eliminar -> elimina un usuario (no a sí mismo ni si tiene préstamos activos)
 *      restablecer -> asigna una contraseña temporal a un usuario que olvidó la suya
 *      descartar   -> descarta una solicitud de contraseña sin cambiar nada
 */

require_once __DIR__ . '/../includes/auth.php';

$u = requerir_admin();
exigir_post();
$d   = leer_entrada();
$pdo = getDBConnection();

switch (texto($d['accion'] ?? '')) {

    // ---------------------------------------------------------------- CREAR
    case 'crear':
        $err = crear_usuario($pdo, $d, texto($d['rol'] ?? ''));
        if ($err) fallo($err);
        ok('Usuario creado.');

    // ------------------------------------------------------------- ELIMINAR
    case 'eliminar':
        $id = (int)($d['id'] ?? 0);
        if ($id === $u['id']) fallo('No puedes eliminar tu propia cuenta.');

        $q = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE id = ?');
        $q->execute([$id]);
        if (!$q->fetchColumn()) fallo('El usuario ya no existe.', 404);

        $q = $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE lector_id = ? AND fecha_devolucion IS NULL');
        $q->execute([$id]);
        if ($q->fetchColumn() > 0) fallo('El usuario tiene préstamos activos.');

        // Su historial de préstamos se conserva (quedará como "(eliminado)"); sus reservas se borran solas
        $q = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
        $q->execute([$id]);
        ok('Usuario eliminado.');

    // ---------------------------------------------------------- RESTABLECER
    // El admin escribe una contraseña temporal y se la da al usuario, que luego
    // la cambia desde "Mi perfil". Se guarda cifrada (bcrypt), igual que las demás.
    case 'restablecer':
        $id   = (int)($d['id'] ?? 0);
        $pass = (string)($d['pass'] ?? '');
        if ($id === $u['id']) fallo('Para cambiar tu propia contraseña usa "Mi perfil".');
        if (mb_strlen($pass) < 6) fallo('La contraseña temporal debe tener al menos 6 caracteres.');

        $q = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = ?');
        $q->execute([$id]);
        $nombre = $q->fetchColumn();
        if ($nombre === false) fallo('El usuario ya no existe.', 404);

        $q = $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?');
        $q->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);

        // Si el usuario había enviado una solicitud, queda marcada como atendida
        try {
            $q = $pdo->prepare('UPDATE solicitudes_clave SET atendida = 1 WHERE usuario_id = ?');
            $q->execute([$id]);
        } catch (PDOException $e) { /* La tabla aún no existe: no pasa nada */ }

        ok('Contraseña de ' . $nombre . ' restablecida. Entrégale la contraseña temporal y pídele que la cambie en "Mi perfil".');

    // ------------------------------------------------------------ DESCARTAR
    case 'descartar':
        try {
            $q = $pdo->prepare('UPDATE solicitudes_clave SET atendida = 1 WHERE id = ?');
            $q->execute([(int)($d['id'] ?? 0)]);
        } catch (PDOException $e) {
            fallo('No se pudo descartar la solicitud.', 500);
        }
        ok('Solicitud descartada.');

    default:
        fallo('Acción no válida.');
}
