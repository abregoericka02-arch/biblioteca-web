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

        // Solo tiene sentido reservar si no queda ningún ejemplar libre
        $q = $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE libro_id = ? AND fecha_devolucion IS NULL');
        $q->execute([$libroId]);
        if ((int)$copias - (int)$q->fetchColumn() > 0) fallo('Ese libro ya está disponible: puedes pedirlo prestado.');

        // INSERT IGNORE: si ya había reservado este libro, no se duplica (la tabla tiene clave única)
        $q = $pdo->prepare('INSERT IGNORE INTO reservas (libro_id, lector_id) VALUES (?, ?)');
        $q->execute([$libroId, $u['id']]);
        ok('Reserva registrada. Te avisaremos en "Mis préstamos" cuando esté disponible.');

    // ------------------------------------------------------------- CANCELAR
    case 'cancelar':
        $id = (int)($d['id'] ?? 0);
        // Un lector solo cancela las suyas; el personal puede cancelar cualquiera
        if ($u['rol'] === 'lector') {
            $q = $pdo->prepare('DELETE FROM reservas WHERE id = ? AND lector_id = ?');
            $q->execute([$id, $u['id']]);
        } else {
            $q = $pdo->prepare('DELETE FROM reservas WHERE id = ?');
            $q->execute([$id]);
        }
        ok('Reserva cancelada.');

    default:
        fallo('Acción no válida.');
}
