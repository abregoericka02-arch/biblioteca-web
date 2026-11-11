<?php
/**
 * ==============================================================================
 * MIGRACIONES AUTOMÁTICAS DE LA BASE DE DATOS
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Cuando el proyecto agrega una tabla o columna nueva, NO hay que pegar SQL en
 *    phpMyAdmin: la primera vez que se abre la página después de actualizar los
 *    archivos, este archivo la agrega solo.
 *  - La tabla "esquema_version" guarda hasta qué número de migración ya se aplicó,
 *    y cada migración se ejecuta UNA sola vez en cada computadora.
 *  - Nunca borran datos: solo agregan tablas, columnas o completan textos vacíos.
 *  - Para agregar una migración nueva en el futuro: añade un número más a
 *    $MIGRACIONES (con sus sentencias SQL) y sube VERSION_ESQUEMA.
 *  - Se llama desde config/database.php al abrir la conexión.
 */

// Número de la última migración (database/biblioteca.sql instala la base ya en esta versión)
const VERSION_ESQUEMA = 5;

function aplicar_migraciones(PDO $pdo): void {
    static $hecho = false;      // Solo se revisa una vez por petición
    if ($hecho) return;
    $hecho = true;

    // --- Lista de migraciones: número => sentencias SQL ---
    $MIGRACIONES = [
        // 1: solicitudes de contraseña olvidada
        1 => ["CREATE TABLE IF NOT EXISTS `solicitudes_clave` (
                  `id`         INT AUTO_INCREMENT PRIMARY KEY,
                  `usuario_id` INT NOT NULL,
                  `fecha`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `atendida`   TINYINT(1) NOT NULL DEFAULT 0,
                  CONSTRAINT `fk_solicitudes_usuarios` FOREIGN KEY (`usuario_id`)
                      REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        // 2: cobro de multas (si fue pagada, cuánto y cuándo)
        2 => ["ALTER TABLE `prestamos` ADD COLUMN IF NOT EXISTS `multa_pagada` TINYINT(1) NOT NULL DEFAULT 0",
              "ALTER TABLE `prestamos` ADD COLUMN IF NOT EXISTS `monto_multa` DECIMAL(6,2) NULL",
              "ALTER TABLE `prestamos` ADD COLUMN IF NOT EXISTS `fecha_pago` DATE NULL"],

        // 3: obligar a cambiar la contraseña temporal
        3 => ["ALTER TABLE `usuarios` ADD COLUMN IF NOT EXISTS `debe_cambiar_clave` TINYINT(1) NOT NULL DEFAULT 0"],

        // 4: reservas con ejemplar apartado (fecha en que se le avisó al lector)
        4 => ["ALTER TABLE `reservas` ADD COLUMN IF NOT EXISTS `fecha_aviso` DATETIME NULL"],

        // 5: descripciones de los libros de ejemplo (solo donde estén vacías)
        5 => [], // Se completa abajo con parámetros (los textos llevan tildes y comillas)
    ];
    $DESCRIPCIONES = [
        'Cien años de soledad'        => 'Novela del realismo mágico que narra la historia de la familia Buendía a lo largo de siete generaciones en el pueblo ficticio de Macondo.',
        'Don Quijote de la Mancha'    => 'Un hidalgo que enloquece de tanto leer libros de caballerías decide hacerse caballero andante y sale al camino con su escudero Sancho Panza. Es una de las obras cumbre de la literatura en español.',
        'Fundamentos de Programación' => 'Libro de texto que introduce los conceptos básicos de la programación: algoritmos, estructuras de datos y diseño de programas.',
        'Clean Code'                  => 'Guía de buenas prácticas para escribir código legible, ordenado y fácil de mantener, con ejemplos de refactorización.',
        'Breve historia del tiempo'   => 'Obra de divulgación científica sobre el origen y la estructura del universo, los agujeros negros y la naturaleza del tiempo, escrita para lectores sin formación en física.',
        'El principito'               => 'Cuento sobre un pequeño príncipe que viaja por distintos planetas y reflexiona sobre la amistad, el amor y la forma en que los adultos ven el mundo.',
    ];

    try {
        // Versión actual de la base (si la tabla no existe todavía, se crea y la versión es 0)
        try {
            $actual = (int)$pdo->query('SELECT version FROM esquema_version WHERE id = 1')->fetchColumn();
        } catch (PDOException $e) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS `esquema_version` (
                            `id` TINYINT NOT NULL PRIMARY KEY,
                            `version` INT NOT NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $actual = 0;
        }
        if ($actual >= VERSION_ESQUEMA) return; // Todo al día: no hace nada más

        foreach ($MIGRACIONES as $numero => $sentencias) {
            if ($numero <= $actual) continue;
            foreach ($sentencias as $sql) $pdo->exec($sql);

            if ($numero === 5) { // Descripciones: solo se escriben si el libro no tiene ninguna
                $q = $pdo->prepare("UPDATE libros SET descripcion = ? WHERE titulo = ? AND (descripcion IS NULL OR descripcion = '')");
                foreach ($DESCRIPCIONES as $titulo => $texto) $q->execute([$texto, $titulo]);
            }

            $pdo->exec('REPLACE INTO esquema_version (id, version) VALUES (1, ' . (int)$numero . ')');
        }
    } catch (Throwable $e) {
        // Si algo falla (por ejemplo, MySQL sin permiso para ALTER) la página sigue abriendo;
        // el motivo queda en el log de Apache (xampp/apache/logs/error.log)
        error_log('Migración de base de datos: ' . $e->getMessage());
    }
}
