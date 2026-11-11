<?php
/**
 * ==============================================================================
 * API: PRÉSTAMOS  (POST api/prestamos.php)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Acciones:
 *      crear    -> registra un préstamo (el personal elige lector y días;
 *                  si lo pide un lector, es para él mismo y por 7 días)
 *      devolver -> marca el préstamo como devuelto (hoy). SOLO el personal: es quien
 *                  confirma que el libro volvió físicamente a la biblioteca
 *      renovar  -> suma 7 días (solo una vez, y no si el libro tiene reservas)
 *      cobrar   -> el personal registra que el lector pagó la multa de un préstamo devuelto
 *  - Reglas: máximo 3 préstamos activos por lector, un lector no puede tener
 *    dos veces el mismo libro y no se presta si no hay ejemplares disponibles.
 *  - Un lector con multas pendientes (o con un préstamo vencido) no puede pedir ni renovar.
 *  - Si un libro tiene reservas, el ejemplar que se libera queda APARTADO unos días
 *    para quien reservó primero (ver procesar_reservas en includes/funciones.php).
 */

require_once __DIR__ . '/../includes/auth.php';

$u = requerir_login();
exigir_post();
$d   = leer_entrada();
$pdo = getDBConnection();

/** Formato de dinero para los mensajes: B/. 0.75 */
function dinero_txt(float $n): string {
    return 'B/. ' . number_format($n, 2, '.', '');
}

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

        // Se actualizan los apartados (por si alguno venció) antes de contar ejemplares
        procesar_reservas($pdo, $libroId);

        $pdo->beginTransaction(); // Transacción: evita que dos personas presten el último ejemplar a la vez
        try {
            // FOR UPDATE bloquea la fila del libro mientras se comprueba la disponibilidad
            $q = $pdo->prepare('SELECT id, copias FROM libros WHERE id = ? FOR UPDATE');
            $q->execute([$libroId]);
            $libro = $q->fetch();

            if (!$lectorValido || !$libro) { $pdo->rollBack(); fallo('Selecciona un lector y un libro.'); }
            if ($dias === false || $dias < 1 || $dias > 30) { $pdo->rollBack(); fallo('Los días deben estar entre 1 y 30.'); }

            // Multas pendientes o préstamos vencidos: no puede llevarse más libros
            $deuda = deuda_lector($pdo, $lectorId);
            if ($deuda > 0) {
                $pdo->rollBack();
                fallo('Hay multas pendientes por ' . dinero_txt($deuda) . '. Deben pagarse en la biblioteca antes de pedir otro libro.');
            }

            // Ejemplares: prestados ahora, apartados para quienes reservaron, y si ESTE lector tiene uno apartado
            $q = $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE libro_id = ? AND fecha_devolucion IS NULL');
            $q->execute([$libroId]);
            $prestados = (int)$q->fetchColumn();

            $q = $pdo->prepare('SELECT COUNT(*) FROM reservas WHERE libro_id = ? AND fecha_aviso IS NOT NULL');
            $q->execute([$libroId]);
            $apartados = (int)$q->fetchColumn();

            $q = $pdo->prepare('SELECT COUNT(*) FROM reservas WHERE libro_id = ? AND lector_id = ? AND fecha_aviso IS NOT NULL');
            $q->execute([$libroId, $lectorId]);
            $tieneApartado = $q->fetchColumn() > 0;

            if ((int)$libro['copias'] - $prestados < 1) {
                $pdo->rollBack(); fallo('No hay ejemplares disponibles de ese libro.');
            }
            // Hay ejemplares físicos, pero todos están guardados para quienes reservaron antes
            if ((int)$libro['copias'] - $prestados - $apartados + ($tieneApartado ? 1 : 0) < 1) {
                $pdo->rollBack(); fallo('Los ejemplares disponibles están apartados para lectores que reservaron antes.');
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
        $q = $pdo->prepare('SELECT libro_id, fecha_devolucion FROM prestamos WHERE id = ?');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) fallo('El préstamo no existe.', 404);
        // Como en una biblioteca real, la devolución la registra el personal al recibir el libro
        if ($u['rol'] === 'lector') fallo('Solo el personal de la biblioteca puede registrar devoluciones.', 403);
        if ($p['fecha_devolucion'] !== null) fallo('Ese préstamo ya fue devuelto.');

        $q = $pdo->prepare('UPDATE prestamos SET fecha_devolucion = ? WHERE id = ?');
        $q->execute([hoy(), $id]);

        // El ejemplar que vuelve queda apartado para el primer lector en la lista de reservas
        if ($p['libro_id'] !== null) procesar_reservas($pdo, (int)$p['libro_id']);
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

        // Con multas pendientes tampoco se renueva
        $deuda = $p['lector_id'] !== null ? deuda_lector($pdo, (int)$p['lector_id']) : 0;
        if ($deuda > 0) fallo('Hay multas pendientes por ' . dinero_txt($deuda) . '. Deben pagarse antes de renovar.');

        // Si alguien reservó este libro, no se permite renovar
        $q = $pdo->prepare('SELECT COUNT(*) FROM reservas WHERE libro_id = ?');
        $q->execute([(int)$p['libro_id']]);
        if ($q->fetchColumn() > 0) fallo('No se puede renovar: otro lector reservó este libro.');

        $q = $pdo->prepare('UPDATE prestamos SET fecha_limite = ?, renovado = 1 WHERE id = ?');
        $q->execute([suma_dias($p['fecha_limite'], DIAS_RENOVACION), $id]);
        ok('Préstamo renovado 7 días.');

    // --------------------------------------------------------------- COBRAR
    // El personal registra el pago de la multa de un préstamo YA devuelto.
    // El monto lo calcula el servidor (días de atraso x tarifa), no el navegador.
    case 'cobrar':
        if ($u['rol'] === 'lector') fallo('Solo el personal de la biblioteca puede cobrar multas.', 403);
        $id = (int)($d['id'] ?? 0);
        $q = $pdo->prepare('SELECT fecha_limite, fecha_devolucion, multa_pagada FROM prestamos WHERE id = ?');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) fallo('El préstamo no existe.', 404);
        if ($p['fecha_devolucion'] === null) fallo('El libro aún no ha sido devuelto: la multa se cobra al devolverlo.');
        if ((int)$p['multa_pagada'] === 1) fallo('Esa multa ya fue cobrada.');

        $atraso = (int)(new DateTime($p['fecha_limite']))->diff(new DateTime($p['fecha_devolucion']))->format('%r%a');
        $monto  = max(0, $atraso) * MULTA_DIA;
        if ($monto <= 0) fallo('Ese préstamo no tiene multa.');

        $q = $pdo->prepare('UPDATE prestamos SET multa_pagada = 1, monto_multa = ?, fecha_pago = ? WHERE id = ?');
        $q->execute([$monto, hoy(), $id]);
        ok('Multa de ' . dinero_txt($monto) . ' cobrada.');

    default:
        fallo('Acción no válida.');
}
