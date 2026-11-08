<?php
/**
 * ==============================================================================
 * INSTALADOR AUTOMÁTICO DE LA BASE DE DATOS
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Abre en el navegador: http://localhost/biblioteca-web/database/instalar.php
 *  - Lee el archivo biblioteca.sql y lo ejecuta en MySQL (crea la base y los datos).
 *  - Es una alternativa a importar el .sql manualmente desde phpMyAdmin.
 *  - ADVERTENCIA: borra y recrea las tablas. Úsalo solo para la primera instalación
 *    (o para reiniciar el sistema con los datos de ejemplo). Puedes borrar este
 *    archivo cuando termines el proyecto.
 */

header('Content-Type: text/html; charset=utf-8');

try {
    // Conexión al servidor MySQL SIN elegir base de datos (el .sql la crea)
    $pdo = new PDO('mysql:host=localhost;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);

    // Localiza y lee el script SQL
    $archivo = __DIR__ . '/biblioteca.sql';
    if (!file_exists($archivo)) {
        die('No se encontró el archivo SQL en: ' . htmlspecialchars($archivo));
    }

    // Ejecuta todo el script de una vez
    $pdo->exec(file_get_contents($archivo));

    // Mensaje de éxito con enlace a la aplicación
    echo '<div style="font-family:Arial,sans-serif;max-width:600px;margin:40px auto;padding:25px;border-left:6px solid #2D6A4F;background:#E8F5E9;border-radius:8px;">';
    echo '<h2 style="color:#2D6A4F;margin-top:0;">¡Base de datos instalada con éxito!</h2>';
    echo '<p>Se creó <strong>biblioteca_db</strong> con sus tablas y los datos de ejemplo.</p>';
    echo '<p>Usuarios de prueba: <strong>admin / admin123</strong> · <strong>biblio / biblio123</strong> · <strong>lector / lector123</strong></p>';
    echo '<p><a href="../index.php" style="display:inline-block;background:#5D3A20;color:#FFF;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;">Ir a la Biblioteca</a></p>';
    echo '</div>';

} catch (PDOException $e) {
    // Error típico: MySQL apagado en XAMPP o contraseña de root distinta
    die('Error al instalar la base de datos: ' . htmlspecialchars($e->getMessage()));
}
