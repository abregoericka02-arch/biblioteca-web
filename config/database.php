<?php
/**
 * ==============================================================================
 * CONFIGURACIÓN DE LA CONEXIÓN A MYSQL (PDO)
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Este archivo es el ÚNICO lugar donde se escriben los datos de conexión.
 *  - Si tu XAMPP tiene contraseña en MySQL o usa otro puerto, cámbialo aquí.
 *  - Todos los archivos de /api usan la función getDBConnection() para consultar.
 *  - PDO + consultas preparadas (prepare/execute) evitan la inyección SQL.
 */

// --- Datos de conexión (valores por defecto de XAMPP) ---
define('DB_HOST', 'localhost');     // Servidor MySQL (tu propia computadora)
define('DB_PORT', '3306');          // Puerto estándar de MySQL en XAMPP
define('DB_NAME', 'biblioteca_db'); // Nombre de la base (la crea database/biblioteca.sql)
define('DB_USER', 'root');          // Usuario por defecto de XAMPP
define('DB_PASS', '');              // Contraseña por defecto de XAMPP: vacía
define('DB_CHARSET', 'utf8mb4');    // Codificación que soporta tildes, ñ y emojis

/**
 * Devuelve la conexión a la base de datos (se crea una sola vez por petición).
 * Si falla, responde un JSON con el error para que app.js lo muestre en pantalla.
 */
function getDBConnection(): PDO {
    static $pdo = null; // "static": recuerda la conexión entre llamadas

    if ($pdo === null) {
        // DSN = cadena que le dice a PDO a qué servidor y base conectarse
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Los errores lanzan excepciones
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Las filas llegan como arreglos asociativos
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Preparación real en el servidor (más seguro)
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            error_log('Error de conexión a MySQL: ' . $e->getMessage()); // Queda en el log de Apache
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => 'No se pudo conectar con MySQL. Verifica que MySQL esté activo en XAMPP '
                         . 'e importa database/biblioteca.sql (o abre database/instalar.php).'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}
