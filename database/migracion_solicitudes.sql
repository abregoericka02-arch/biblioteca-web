-- ==============================================================================
-- MIGRACIÓN: agrega la tabla de "solicitudes de contraseña"
-- ==============================================================================
-- GUÍA RÁPIDA:
--  * Para quienes YA tienen la base de datos instalada. NO borra ni cambia nada
--    de lo que existe: solo agrega una tabla nueva.
--  * Cómo usarlo: phpMyAdmin > pestaña "SQL" > pegar todo este archivo > "Continuar".
--  * Se puede ejecutar más de una vez sin problema (IF NOT EXISTS).
-- ==============================================================================

USE `biblioteca_db`;

CREATE TABLE IF NOT EXISTS `solicitudes_clave` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `usuario_id` INT NOT NULL,
    `fecha`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atendida`   TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT `fk_solicitudes_usuarios` FOREIGN KEY (`usuario_id`)
        REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
