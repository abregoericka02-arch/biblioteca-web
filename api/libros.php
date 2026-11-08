<?php
/**
 * ==============================================================================
 * API: LIBROS  (POST api/libros.php)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Solo el personal (admin y bibliotecario) puede usar este archivo.
 *  - Acciones:
 *      guardar  -> crea un libro nuevo (sin "id") o edita uno existente (con "id")
 *      eliminar -> borra un libro que no tenga préstamos activos
 *  - La portada llega desde app.js como imagen JPEG en base64 (ya reducida).
 *    Aquí se valida y se guarda como archivo en assets/img/covers/libro_ID.jpg;
 *    en la base de datos solo se guarda la ruta.
 */

require_once __DIR__ . '/../includes/auth.php';

requerir_staff();
exigir_post();
$d   = leer_entrada();
$pdo = getDBConnection();

const CARPETA_PORTADAS = 'assets/img/covers/'; // Relativa a la raíz del proyecto

/** Cuenta cuántos ejemplares de un libro están prestados ahora mismo. */
function ejemplares_prestados(PDO $pdo, int $libroId): int {
    $q = $pdo->prepare('SELECT COUNT(*) FROM prestamos WHERE libro_id = ? AND fecha_devolucion IS NULL');
    $q->execute([$libroId]);
    return (int)$q->fetchColumn();
}

/**
 * Valida y guarda una portada enviada como "data:image/jpeg;base64,...".
 * Devuelve la ruta relativa guardada o null si la imagen no es válida.
 */
function guardar_portada(int $libroId, string $dataUrl): ?string {
    if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null;
    $binario = base64_decode($m[1], true);
    if ($binario === false || strlen($binario) > 600 * 1024) return null; // Máximo ~600 KB
    $info = @getimagesizefromstring($binario);                           // Confirma que realmente es una imagen
    if (!$info || $info['mime'] !== 'image/jpeg') return null;

    $carpeta = __DIR__ . '/../' . CARPETA_PORTADAS;
    if (!is_dir($carpeta)) @mkdir($carpeta, 0775, true);
    $ruta = CARPETA_PORTADAS . 'libro_' . $libroId . '.jpg';
    if (@file_put_contents(__DIR__ . '/../' . $ruta, $binario) === false) return null;
    return $ruta;
}

switch (texto($d['accion'] ?? '')) {

    // -------------------------------------------------------------- GUARDAR
    case 'guardar':
        $id       = (int)($d['id'] ?? 0);               // 0 = libro nuevo
        $titulo   = texto($d['titulo'] ?? '');
        $autor    = texto($d['autor'] ?? '');
        $isbn     = texto($d['isbn'] ?? '');
        $categoria = texto($d['categoria'] ?? '');
        $desc     = texto($d['descripcion'] ?? '');
        $anio     = filter_var($d['anio'] ?? '', FILTER_VALIDATE_INT);
        $copias   = filter_var($d['copias'] ?? '', FILTER_VALIDATE_INT);

        // --- Validaciones (mismos mensajes que la versión JavaScript) ---
        if ($titulo === '' || $autor === '' || $isbn === '') fallo('Título, autor y código son obligatorios.');
        if (mb_strlen($titulo) > 200 || mb_strlen($autor) > 150 || mb_strlen($isbn) > 25) {
            fallo('Título (200), autor (150) o código (25) superan el largo permitido.');
        }
        if ($anio === false || $anio < 1000 || $anio > (int)date('Y')) fallo('El año no es válido.');
        if ($copias === false || $copias < 1 || $copias > 50) fallo('Los ejemplares deben estar entre 1 y 50.');

        // La categoría debe existir en la tabla categorias
        $q = $pdo->prepare('SELECT id FROM categorias WHERE nombre = ?');
        $q->execute([$categoria]);
        $categoriaId = $q->fetchColumn();
        if (!$categoriaId) fallo('La categoría no es válida.');

        // El código/ISBN no puede repetirse en otro libro
        $q = $pdo->prepare('SELECT COUNT(*) FROM libros WHERE isbn = ? AND id <> ?');
        $q->execute([$isbn, $id]);
        if ($q->fetchColumn() > 0) fallo('Ya existe un libro con ese código.');

        if ($id > 0) {
            // ----- Edición -----
            $q = $pdo->prepare('SELECT COUNT(*) FROM libros WHERE id = ?');
            $q->execute([$id]);
            if (!$q->fetchColumn()) fallo('El libro ya no existe.', 404);
            if ($copias < ejemplares_prestados($pdo, $id)) fallo('Hay más préstamos activos que ejemplares.');

            $q = $pdo->prepare('UPDATE libros SET categoria_id=?, titulo=?, autor=?, isbn=?, anio=?, copias=?, descripcion=? WHERE id=?');
            $q->execute([$categoriaId, $titulo, $autor, $isbn, $anio, $copias, $desc, $id]);
            $mensaje = 'Libro actualizado.';
        } else {
            // ----- Registro nuevo -----
            $q = $pdo->prepare('INSERT INTO libros (categoria_id, titulo, autor, isbn, anio, copias, descripcion) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$categoriaId, $titulo, $autor, $isbn, $anio, $copias, $desc]);
            $id = (int)$pdo->lastInsertId();
            $mensaje = 'Libro registrado.';
        }

        // --- Portada opcional: solo se reemplaza si se envió una imagen nueva ---
        $portada = (string)($d['portada'] ?? '');
        if ($portada !== '') {
            $ruta = guardar_portada($id, $portada);
            if ($ruta) {
                $q = $pdo->prepare('UPDATE libros SET portada_url = ? WHERE id = ?');
                $q->execute([$ruta, $id]);
            } else {
                $mensaje .= ' (La imagen de portada no era válida y no se guardó).';
            }
        }
        ok($mensaje);

    // ------------------------------------------------------------- ELIMINAR
    case 'eliminar':
        $id = (int)($d['id'] ?? 0);
        $q = $pdo->prepare('SELECT portada_url FROM libros WHERE id = ?');
        $q->execute([$id]);
        $fila = $q->fetch();
        if (!$fila) fallo('El libro ya no existe.', 404);
        if (ejemplares_prestados($pdo, $id) > 0) fallo('No se puede eliminar: el libro tiene préstamos activos.');

        // Los préstamos viejos quedan en el historial como "(eliminado)"; las reservas se borran solas (ON DELETE)
        $q = $pdo->prepare('DELETE FROM libros WHERE id = ?');
        $q->execute([$id]);

        if ($fila['portada_url']) { // También se borra el archivo de la portada
            $archivo = __DIR__ . '/../' . $fila['portada_url'];
            if (is_file($archivo)) @unlink($archivo);
        }
        ok('Libro eliminado.');

    default:
        fallo('Acción no válida.');
}
