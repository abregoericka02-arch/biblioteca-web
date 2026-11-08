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

    default:
        fallo('Acción no válida.');
}
