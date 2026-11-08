-- ==============================================================================
-- SISTEMA DE BIBLIOTECA - BASE DE DATOS: biblioteca_db  (MySQL / MariaDB)
-- ==============================================================================
-- GUÍA RÁPIDA:
--  * Cómo usarlo: en XAMPP abre phpMyAdmin > pestaña "Importar" > elige este archivo.
--    (O abre en el navegador http://localhost/biblioteca-web/database/instalar.php)
--  * ADVERTENCIA: al importarlo se BORRAN y se vuelven a crear las tablas.
--  * Las tablas guardan lo mismo que antes guardaba el localStorage del navegador:
--      roles / usuarios / categorias / libros / prestamos / reservas
-- ==============================================================================

-- 0. Forzar UTF-8 en la conexión (así las tildes y la ñ se guardan bien al importar)
SET NAMES utf8mb4;

-- 1. Crear y seleccionar la base de datos (con soporte completo de tildes y ñ)
CREATE DATABASE IF NOT EXISTS `biblioteca_db`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `biblioteca_db`;

-- 2. Borrar tablas anteriores (se apagan las claves foráneas un momento para poder hacerlo)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `reservas`;
DROP TABLE IF EXISTS `prestamos`;
DROP TABLE IF EXISTS `libros`;
DROP TABLE IF EXISTS `categorias`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `roles`;
SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- 3. TABLAS
-- ==============================================================================

-- ROLES: los tres tipos de usuario del sistema (admin, bibliotecario, lector)
CREATE TABLE `roles` (
    `id`     INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USUARIOS: quien puede entrar al sistema.
--   usuario = nombre para iniciar sesión (también se puede entrar con el correo)
--   password_hash = contraseña cifrada con bcrypt (nunca se guarda en texto plano)
CREATE TABLE `usuarios` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `rol_id`         INT NOT NULL,
    `nombre`         VARCHAR(100) NOT NULL,
    `usuario`        VARCHAR(100) NOT NULL UNIQUE,
    `email`          VARCHAR(150) NOT NULL UNIQUE,
    `password_hash`  VARCHAR(255) NOT NULL,
    `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_usuarios_roles` FOREIGN KEY (`rol_id`)
        REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CATEGORIAS: Literatura, Tecnología, Ciencia, Infantil, Historia, Otros
CREATE TABLE `categorias` (
    `id`     INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LIBROS: cada libro con su número total de ejemplares ("copias").
--   Los ejemplares disponibles = copias - préstamos sin devolver (se calcula, no se guarda).
--   portada_url = ruta de la imagen subida (assets/img/covers/...) o NULL si no tiene.
CREATE TABLE `libros` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `categoria_id`   INT NOT NULL,
    `titulo`         VARCHAR(200) NOT NULL,
    `autor`          VARCHAR(150) NOT NULL,
    `isbn`           VARCHAR(25)  NOT NULL UNIQUE,
    `anio`           SMALLINT NOT NULL,
    `copias`         SMALLINT NOT NULL DEFAULT 1,
    `descripcion`    TEXT NULL,
    `portada_url`    VARCHAR(255) NULL,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_libros_categorias` FOREIGN KEY (`categoria_id`)
        REFERENCES `categorias` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PRESTAMOS: quién se llevó qué libro y cuándo.
--   fecha_devolucion NULL = todavía está prestado.
--   Si se elimina el libro o el lector, el préstamo queda en el historial con NULL
--   (la pantalla lo muestra como "(eliminado)").
CREATE TABLE `prestamos` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `libro_id`         INT NULL,
    `lector_id`        INT NULL,
    `fecha_prestamo`   DATE NOT NULL,
    `fecha_limite`     DATE NOT NULL,
    `fecha_devolucion` DATE NULL,
    `renovado`         TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT `fk_prestamos_libros` FOREIGN KEY (`libro_id`)
        REFERENCES `libros` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prestamos_lectores` FOREIGN KEY (`lector_id`)
        REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RESERVAS: lectores en espera de un libro sin ejemplares.
--   La clave única evita que un lector reserve dos veces el mismo libro.
CREATE TABLE `reservas` (
    `id`        INT AUTO_INCREMENT PRIMARY KEY,
    `libro_id`  INT NOT NULL,
    `lector_id` INT NOT NULL,
    `fecha`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_reserva` (`libro_id`, `lector_id`),
    CONSTRAINT `fk_reservas_libros` FOREIGN KEY (`libro_id`)
        REFERENCES `libros` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_reservas_lectores` FOREIGN KEY (`lector_id`)
        REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- 4. DATOS DE EJEMPLO (los mismos de la versión JavaScript)
-- ==============================================================================

INSERT INTO `roles` (`id`, `nombre`) VALUES (1, 'admin'), (2, 'bibliotecario'), (3, 'lector');

-- Usuarios de demostración (contraseñas: admin123 / lector123 / biblio123)
INSERT INTO `usuarios` (`id`, `rol_id`, `nombre`, `usuario`, `email`, `password_hash`) VALUES
(1, 1, 'Administrador', 'admin',  'admin@biblioteca.com',  '$2y$10$pDrmdopE55c2ZKRRMr3SU.6xvnkn9vA3//2BhiSXibi9k.5Ih8dpi'),
(2, 3, 'Lector Demo',   'lector', 'lector@biblioteca.com', '$2y$10$K9l.q83gB3FAAe1lUQySeO6kTPkohfsIZTmLJgVI.xj2Xs.2Wg3vS'),
(3, 2, 'Bibliotecario', 'biblio', 'biblio@biblioteca.com', '$2y$10$EwxF/ited5ikmpJNutNCAOCVNR80/4iWFakBsVE3gHQrOPOrPsz9K');

INSERT INTO `categorias` (`id`, `nombre`) VALUES
(1, 'Literatura'), (2, 'Tecnología'), (3, 'Ciencia'), (4, 'Infantil'), (5, 'Historia'), (6, 'Otros');

INSERT INTO `libros` (`id`, `categoria_id`, `titulo`, `autor`, `isbn`, `anio`, `copias`) VALUES
(1, 1, 'Cien años de soledad',        'Gabriel García Márquez',    'LIB-001', 1967, 3),
(2, 1, 'Don Quijote de la Mancha',    'Miguel de Cervantes',       'LIB-002', 1605, 2),
(3, 2, 'Fundamentos de Programación', 'Luis Joyanes',              'LIB-003', 2008, 2),
(4, 2, 'Clean Code',                  'Robert C. Martin',          'LIB-004', 2008, 2),
(5, 3, 'Breve historia del tiempo',   'Stephen Hawking',           'LIB-005', 1988, 2),
(6, 4, 'El principito',               'Antoine de Saint-Exupéry',  'LIB-006', 1943, 3);

-- Un préstamo de ejemplo YA VENCIDO (para ver multas y avisos): prestado hace 10 días, límite hace 3 días
INSERT INTO `prestamos` (`libro_id`, `lector_id`, `fecha_prestamo`, `fecha_limite`, `fecha_devolucion`) VALUES
(3, 2, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), NULL);
