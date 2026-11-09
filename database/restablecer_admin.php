<?php
/**
 * ==============================================================================
 * RESTABLECER LA CONTRASEÑA DEL ADMINISTRADOR (solo desde la terminal)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Úsalo solo si el administrador olvidó SU contraseña (los demás usuarios se
 *    restablecen desde la pantalla "Usuarios").
 *  - En XAMPP abre "Shell" en el panel y escribe:
 *        php database/restablecer_admin.php NuevaClave123
 *  - Por seguridad NO funciona desde el navegador, solo por línea de comandos.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la terminal.');
}

$nueva = $argv[1] ?? '';
if (mb_strlen($nueva) < 6) {
    exit("Uso: php database/restablecer_admin.php NuevaClave (mínimo 6 caracteres)\n");
}

require_once __DIR__ . '/../config/database.php';
$pdo = getDBConnection();

// Cambia la contraseña de todos los usuarios con rol admin
$q = $pdo->prepare("UPDATE usuarios SET password_hash = ?
                     WHERE rol_id = (SELECT id FROM roles WHERE nombre = 'admin')");
$q->execute([password_hash($nueva, PASSWORD_DEFAULT)]);
echo "Contraseña del administrador actualizada (" . $q->rowCount() . " cuenta).\n";
