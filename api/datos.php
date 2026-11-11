<?php
/**
 * ==============================================================================
 * API: DATOS  (GET api/datos.php)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - app.js llama a este archivo al abrir la página y después de cada cambio.
 *  - Devuelve en JSON todo lo que la pantalla necesita, FILTRADO según el rol:
 *      · Visitante      -> solo libros y categorías (para la portada)
 *      · Lector         -> libros + sus propios préstamos y reservas
 *      · Bibliotecario / Admin -> todos los usuarios, préstamos y reservas
 *  - Los nombres de los campos son los mismos que usaba la versión con localStorage
 *    (libroId, lectorId, fecha, limite, devuelto...), así el resto de app.js no cambia.
 *  - Nunca se envía la contraseña (ni su hash) al navegador.
 */

require_once __DIR__ . '/../includes/auth.php';

$pdo = getDBConnection();
$u   = usuario_actual();        // null si es visitante
$staff = es_staff($u);

// ---------- 1. Categorías (lista fija que llena los <select> y los gráficos) ----------
$categorias = $pdo->query('SELECT nombre FROM categorias ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

// ---------- 2. Libros (con cuántos ejemplares están prestados ahora mismo) ----------
$sqlLibros = 'SELECT l.id, l.titulo, l.autor, c.nombre AS categoria, l.isbn, l.anio, l.copias,
                     l.descripcion, l.portada_url,
                     (SELECT COUNT(*) FROM prestamos p
                       WHERE p.libro_id = l.id AND p.fecha_devolucion IS NULL) AS prestados
                FROM libros l
                JOIN categorias c ON c.id = l.categoria_id
               ORDER BY l.id';
$libros = [];
foreach ($pdo->query($sqlLibros) as $f) {
    // Si el libro tiene portada subida, se agrega ?v=fecha para que el navegador no use una imagen vieja
    $portada = null;
    if ($f['portada_url']) {
        $archivo = __DIR__ . '/../' . $f['portada_url'];
        $portada = $f['portada_url'] . (is_file($archivo) ? '?v=' . filemtime($archivo) : '');
    }
    $libros[] = [
        'id'          => (int)$f['id'],
        'titulo'      => $f['titulo'],
        'autor'       => $f['autor'],
        'categoria'   => $f['categoria'],
        'isbn'        => $f['isbn'],
        'anio'        => (int)$f['anio'],
        'copias'      => (int)$f['copias'],
        'descripcion' => $f['descripcion'] ?? '',
        'portada'     => $portada,
        'prestados'   => (int)$f['prestados'],
    ];
}

// ---------- 3. Usuarios (el personal ve todos; el lector solo se ve a sí mismo) ----------
$usuarios = [];
if ($staff) {
    $filas = $pdo->query('SELECT u.id, u.nombre, u.usuario, u.email, r.nombre AS rol
                            FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.id')->fetchAll();
    foreach ($filas as $f) {
        $usuarios[] = ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'usuario' => $f['usuario'],
                       'email' => $f['email'], 'rol' => $f['rol']];
    }
} elseif ($u) {
    $usuarios[] = $u;
}

// ---------- 4. Préstamos (el personal ve todos; el lector solo los suyos) ----------
$prestamos = [];
if ($u) {
    $sql = 'SELECT id, libro_id, lector_id, fecha_prestamo, fecha_limite, fecha_devolucion, renovado FROM prestamos';
    if ($staff) {
        $filas = $pdo->query($sql . ' ORDER BY id')->fetchAll();
    } else {
        $q = $pdo->prepare($sql . ' WHERE lector_id = ? ORDER BY id');
        $q->execute([$u['id']]);
        $filas = $q->fetchAll();
    }
    foreach ($filas as $f) {
        $prestamos[] = [
            'id'       => (int)$f['id'],
            'libroId'  => $f['libro_id'] === null ? null : (int)$f['libro_id'],   // null = libro eliminado
            'lectorId' => $f['lector_id'] === null ? null : (int)$f['lector_id'], // null = usuario eliminado
            'fecha'    => $f['fecha_prestamo'],
            'limite'   => $f['fecha_limite'],
            'devuelto' => $f['fecha_devolucion'],   // null mientras siga prestado
            'renovado' => (bool)$f['renovado'],
        ];
    }
}

// ---------- 5. Reservas (el personal ve todas; el lector solo las suyas) ----------
$reservas = [];
if ($u) {
    $sql = 'SELECT id, libro_id, lector_id, fecha FROM reservas';
    if ($staff) {
        $filas = $pdo->query($sql . ' ORDER BY id')->fetchAll();
    } else {
        $q = $pdo->prepare($sql . ' WHERE lector_id = ? ORDER BY id');
        $q->execute([$u['id']]);
        $filas = $q->fetchAll();
    }
    foreach ($filas as $f) {
        $reservas[] = ['id' => (int)$f['id'], 'libroId' => (int)$f['libro_id'], 'lectorId' => (int)$f['lector_id'],
                       'fecha' => $f['fecha']]; // Fecha y hora en que se hizo la reserva
    }
}

// ---------- 5b. Solicitudes de contraseña pendientes (solo las ve el administrador) ----------
$solicitudes = [];
if ($u && $u['rol'] === 'admin') {
    try {
        $filas = $pdo->query('SELECT s.id, s.usuario_id, s.fecha, us.nombre, us.email
                                FROM solicitudes_clave s JOIN usuarios us ON us.id = s.usuario_id
                               WHERE s.atendida = 0 ORDER BY s.fecha')->fetchAll();
        foreach ($filas as $f) {
            $solicitudes[] = ['id' => (int)$f['id'], 'usuarioId' => (int)$f['usuario_id'],
                              'nombre' => $f['nombre'], 'email' => $f['email'], 'fecha' => $f['fecha']];
        }
    } catch (PDOException $e) { /* La tabla aún no existe: se muestra la lista vacía */ }
}

// ---------- 6. Respuesta final ----------
responder([
    'ok'         => true,
    'sesion'     => $u ? $u['id'] : null, // id del usuario con sesión, o null
    'categorias' => $categorias,
    'libros'     => $libros,
    'usuarios'   => $usuarios,
    'prestamos'  => $prestamos,
    'reservas'   => $reservas,
    'solicitudes' => $solicitudes,
]);
