<?php
/**
 * ==============================================================================
 * API: RESERVAS  (POST api/reservas.php)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Una reserva sirve cuando un libro no tiene ejemplares disponibles:
 *    el lector queda "en espera" y, al devolverse un ejemplar, puede pedirlo.
 *  - Acciones:
 *      crear    -> el lector reserva un libro SIN ejemplares disponibles
 *      cancelar -> el lector cancela su reserva
 *  - Cuando un ejemplar vuelve, el sistema lo APARTA para el primer lector de la lista
 *    durante 3 días (columna fecha_aviso). Si no lo recoge, pasa al siguiente.
 */

require_once __DIR__ . '/../includes/auth.php';

$u = requerir_login();
exigir_post();
$d   = leer_entrada();
$pdo = getDBConnection();

switch (texto($d['accion'] ?? '')) {

    // ---------------------------------------------------------------- CREAR
    case 'crear':
        if ($u['rol'] !== 'lector') fallo('Solo los lectores pueden reservar libros.', 403);
        $libroId = (int)($d['libro'] ?? 0);

        $q = $pdo->prepare('SELECT copias FROM libros WHERE id = ?');
        $q->execute([$libroId]);
        $copias = $q->fetchColumn();
        if ($copias === false) fallo('El libro no existe.', 404);

        // No tiene sentido reservar un libro que el lector ya tiene en su poder
        $q = $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE libro_id = ? AND lector_id = ? AND fecha_devolucion IS NULL');
        $q->execute([$libroId, $u['id']]);
        if ($q->fetchColumn() > 0) fallo('Ya tienes este libro prestado.');

        // Solo tiene sentido reservar si no queda ningún ejemplar libre (ni prestado ni apartado para otro)
        procesar_reservas($pdo, $libroId);
        $q = $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE libro_id = ? AND fecha_devolucion IS NULL');
        $q->execute([$libroId]);
        $prestados = (int)$q->fetchColumn();
        $q = $pdo->prepare('SELECT COUNT(*) FROM reservas WHERE libro_id = ? AND fecha_aviso IS NOT NULL');
        $q->execute([$libroId]);
        $apartados = (int)$q->fetchColumn();
        if ((int)$copias - $prestados - $apartados > 0) fallo('Ese libro ya está disponible: puedes pedirlo prestado.');

        // INSERT IGNORE: si ya había reservado este libro, no se duplica (la tabla tiene clave única)
        $q = $pdo->prepare('INSERT IGNORE INTO reservas (libro_id, lector_id, fecha) VALUES (?, ?, ?)');
        $q->execute([$libroId, $u['id'], date('Y-m-d H:i:s')]);
        ok('Reserva registrada. Te avisaremos en "Mis préstamos" cuando esté disponible.');

    // ------------------------------------------------------------- CANCELAR
    case 'cancelar':
        $id = (int)($d['id'] ?? 0);
        // Un lector solo cancela las suyas; el personal puede cancelar cualquiera
        $q = $pdo->prepare('SELECT libro_id FROM reservas WHERE id = ?');
        $q->execute([$id]);
        $libroId = $q->fetchColumn();

        if ($u['rol'] === 'lector') {
            $q = $pdo->prepare('DELETE FROM reservas WHERE id = ? AND lector_id = ?');
            $q->execute([$id, $u['id']]);
        } else {
            $q = $pdo->prepare('DELETE FROM reservas WHERE id = ?');
            $q->execute([$id]);
        }
        // Si la reserva tenía un ejemplar apartado, pasa al siguiente lector de la lista
        if ($libroId !== false) procesar_reservas($pdo, (int)$libroId);
        ok('Reserva cancelada.');

    default:
        fallo('Acción no válida.');
}
