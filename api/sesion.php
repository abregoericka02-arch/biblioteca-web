<?php
/**
 * ==============================================================================
 * API: SESIÓN Y CUENTA  (POST api/sesion.php)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - app.js envía un JSON con el campo "accion". Acciones disponibles:
 *      login     -> inicia sesión con usuario o correo + contraseña
 *      logout    -> cierra la sesión
 *      registro  -> crea una cuenta de lector
 *      perfil    -> cambia nombre, correo y (opcional) contraseña del usuario actual
 *  - Las contraseñas se comparan con password_verify() contra el hash guardado.
 */

require_once __DIR__ . '/../includes/auth.php';

exigir_post();
$d   = leer_entrada();
$pdo = getDBConnection();

switch (texto($d['accion'] ?? '')) {

    // ---------------------------------------------------------------- LOGIN
    case 'login':
        $dato = mb_strtolower(texto($d['usuario'] ?? ''));
        $pass = (string)($d['pass'] ?? '');

        // Se puede entrar con el nombre de usuario O con el correo
        $q = $pdo->prepare('SELECT id, password_hash FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1');
        $q->execute([$dato, $dato]);
        $f = $q->fetch();

        // Mismo mensaje si falla el usuario o la contraseña (no revela cuál de los dos es)
        if ($dato === '' || !$f || !password_verify($pass, $f['password_hash'])) {
            fallo('Usuario o contraseña incorrectos.', 401);
        }

        session_regenerate_id(true);          // Nuevo id de sesión: evita "fijación de sesión"
        $_SESSION['usuario_id'] = (int)$f['id'];
        ok();

    // --------------------------------------------------------------- LOGOUT
    case 'logout':
        $_SESSION = [];                        // Vacía los datos de la sesión
        session_destroy();                     // Y la destruye en el servidor
        ok();

    // ------------------------------------------------------------- REGISTRO
    case 'registro':
        // Quien se registra desde la web siempre es "lector"
        $err = crear_usuario($pdo, $d, 'lector');
        if ($err) fallo($err);
        ok('Cuenta creada. Ya puedes iniciar sesión.');

    // --------------------------------------------------------------- PERFIL
    case 'perfil':
        $u      = requerir_login();
        $nombre = texto($d['nombre'] ?? '');
        $email  = mb_strtolower(texto($d['email'] ?? ''));
        $actual = (string)($d['actual'] ?? '');
        $nueva  = (string)($d['nueva'] ?? '');

        if (mb_strlen($nombre) < 2)   fallo('Escribe tu nombre completo.');
        if (mb_strlen($nombre) > 100) fallo('El nombre es demasiado largo (máximo 100 caracteres).');
        if (!correo_valido($email) || mb_strlen($email) > 150) fallo('El correo no es válido.');

        // El correo no puede pertenecer a otro usuario
        $q = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ? AND id <> ?');
        $q->execute([$email, $u['id']]);
        if ($q->fetchColumn() > 0) fallo('Ese correo ya está en uso.');

        if ($nueva !== '') { // Solo se cambia la contraseña si escribió una nueva
            $q = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
            $q->execute([$u['id']]);
            if (!password_verify($actual, (string)$q->fetchColumn())) fallo('La contraseña actual no es correcta.');
            if (mb_strlen($nueva) < 6) fallo('La nueva contraseña debe tener al menos 6 caracteres.');
            $q = $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?');
            $q->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        }

        $q = $pdo->prepare('UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?');
        $q->execute([$nombre, $email, $u['id']]);
        ok('Perfil actualizado.');

    default:
        fallo('Acción no válida.');
}
