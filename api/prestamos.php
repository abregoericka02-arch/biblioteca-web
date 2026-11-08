<?php
/**
 * ==============================================================================
 * API: PRÉSTAMOS  (POST api/prestamos.php)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Acciones:
 *      crear    -> registra un préstamo (el personal elige lector y días;
 *                  si lo pide un lector, es para él mismo y por 7 días)
 *      devolver -> marca el préstamo como devuelto (hoy)
 *      renovar  -> suma 7 días (solo una vez, y no si el libro tiene reservas)
 *  - Reglas: máximo 3 préstamos activos por lector, un lector no puede tener
 *    dos veces el mismo libro y no se presta si no hay ejemplares disponibles.
 */

require_once __DIR__ . '/../includes/auth.php';

$u = requerir_login();
exigir_post();
$d   = leer_entrada();
$pdo = getDBConnection();

switch (texto($d['accion'] ?? '')) {

    // ---------------------------------------------------------------- CREAR
    case 'crear':
        $libroId = (int)($d['libro'] ?? 0);

        if ($u['rol'] === 'lector') {
            $lectorId = $u['id'];                 // Un lector solo puede pedir para sí mismo
            $dias     = DIAS_PRESTAMO_LECTOR;     // y siempre por 7 días
        } else {
            $lectorId = (int)($d['lector'] ?? 0);
            $dias     = filter_var($d['dias'] ?? '', FILTER_VALIDATE_INT);
        }

        // El lector elegido debe existir y tener rol "lector"
        $q = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
                             WHERE u.id = ? AND r.nombre = 'lector'");
        $q->execute([$lectorId]);
        $lectorValido = $q->fetchColumn() > 0;

        $pdo->beginTransaction(); // Transacción: evita que dos personas presten el último ejemplar a la vez
        try {
            // FOR UPDATE bloquea la fila del libro mientras se comprueba la disponibilidad
            $q = $pdo->prepare('SELECT id, copias FROM libros WHERE id = ? FOR UPDATE');
            $q->execute([$libroId]);
            $libro = $q->fetch();

            if (!$lectorValido || !$libro) { $pdo->rollBack(); fallo('Selecciona un lector y un libro.'); }
            if ($dias === false || $dias < 1 || $dias > 30) { $pdo->rollBack(); fallo('Los días deben estar entre 1 y 30.'); }

            $q = $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE libro_id = ? AND fecha_devolucion IS NULL');
            $q->execute([$libroId]);
            if ((int)$libro['copias'] - (int)$q->fetchColumn() < 1) {
                $pdo->rollBack(); fallo('No hay ejemplares disponibles de ese libro.');
            }

            // Préstamos activos que ya tiene este lector
            $q = $pdo->prepare('SELECT libro_id FROM prestamos WHERE lector_id = ? AND fecha_devolucion IS NULL');
            $q->execute([$lectorId]);
            $activos = $q->fetchAll(PDO::FETCH_COLUMN);
            if (count($activos) >= MAX_PRESTAMOS_ACTIVOS) { $pdo->rollBack(); fallo('El lector ya tiene 3 préstamos activos.'); }
            if (in_array((string)$libroId, array_map('strval', $activos), true)) { $pdo->rollBack(); fallo('El lector ya tiene este libro.'); }

            // Se registra el préstamo con fecha de hoy y fecha límite = hoy + días
            $q = $pdo->prepare('INSERT INTO prestamos (libro_id, lector_id, fecha_prestamo, fecha_limite) VALUES (?,?,?,?)');
            $q->execute([$libroId, $lectorId, hoy(), suma_dias(hoy(), $dias)]);

            // Si el lector tenía este libro reservado, la reserva ya se cumplió y se borra
            $q = $pdo->prepare('DELETE FROM reservas WHERE libro_id = ? AND lector_id = ?');
            $q->execute([$libroId, $lectorId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Error al crear préstamo: ' . $e->getMessage());
            fallo('No se pudo registrar el préstamo.', 500);
        }
        ok('Préstamo registrado.');

    // ------------------------------------------------------------- DEVOLVER
    case 'devolver':
        $id = (int)($d['id'] ?? 0);
        $q = $pdo->prepare('SELECT lector_id, fecha_devolucion FROM prestamos WHERE id = ?');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) fallo('El préstamo no existe.', 404);
        // Un lector solo puede devolver sus propios préstamos; el personal puede devolver cualquiera
        if ($u['rol'] === 'lector' && (int)$p['lector_id'] !== $u['id']) fallo('No tienes permiso para esta acción.', 403);
        if ($p['fecha_devolucion'] !== null) fallo('Ese préstamo ya fue devuelto.');

        $q = $pdo->prepare('UPDATE prestamos SET fecha_devolucion = ? WHERE id = ?');
        $q->execute([hoy(), $id]);
        ok('Devolución registrada.');

    // -------------------------------------------------------------- RENOVAR
    case 'renovar':
        $id = (int)($d['id'] ?? 0);
        $q = $pdo->prepare('SELECT libro_id, lector_id, fecha_limite, fecha_devolucion, renovado FROM prestamos WHERE id = ?');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) fallo('El préstamo no existe.', 404);
        if ($u['rol'] === 'lector' && (int)$p['lector_id'] !== $u['id']) fallo('No tienes permiso para esta acción.', 403);
        if ($p['fecha_devolucion'] !== null) fallo('Ese préstamo ya fue devuelto.');
        if ((int)$p['renovado'] === 1) fallo('Este préstamo ya fue renovado una vez.');
        if ($p['fecha_limite'] < hoy()) fallo('No se puede renovar un préstamo vencido.');

        // Si alguien reservó este libro, no se permite renovar
        $q = $pdo->prepare('SELECT COUNT(*) FROM reservas WHERE libro_id = ?');
        $q->execute([(int)$p['libro_id']]);
        if ($q->fetchColumn() > 0) fallo('No se puede renovar: otro lector reservó este libro.');

        $q = $pdo->prepare('UPDATE prestamos SET fecha_limite = ?, renovado = 1 WHERE id = ?');
        $q->execute([suma_dias($p['fecha_limite'], DIAS_RENOVACION), $id]);
        ok('Préstamo renovado 7 días.');

    default:
        fallo('Acción no válida.');
}
