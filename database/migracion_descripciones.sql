-- ==============================================================================
-- MIGRACIÓN: agrega descripción a los 6 libros de ejemplo
-- ==============================================================================
-- GUÍA RÁPIDA:
--  * Para bases de datos que YA están instaladas. No borra nada.
--  * Solo escribe la descripción en los libros que la tengan VACÍA: si ya
--    escribiste una a mano, no se toca.
--  * Cómo usarlo: phpMyAdmin > pestaña "SQL" > pegar todo > "Continuar".
--  * Se puede ejecutar más de una vez sin problema.
-- ==============================================================================

SET NAMES utf8mb4;
USE `biblioteca_db`;

UPDATE `libros` SET `descripcion` = 'Novela del realismo mágico que narra la historia de la familia Buendía a lo largo de siete generaciones en el pueblo ficticio de Macondo.'
 WHERE `titulo` = 'Cien años de soledad' AND (`descripcion` IS NULL OR `descripcion` = '');

UPDATE `libros` SET `descripcion` = 'Un hidalgo que enloquece de tanto leer libros de caballerías decide hacerse caballero andante y sale al camino con su escudero Sancho Panza. Es una de las obras cumbre de la literatura en español.'
 WHERE `titulo` = 'Don Quijote de la Mancha' AND (`descripcion` IS NULL OR `descripcion` = '');

UPDATE `libros` SET `descripcion` = 'Libro de texto que introduce los conceptos básicos de la programación: algoritmos, estructuras de datos y diseño de programas.'
 WHERE `titulo` = 'Fundamentos de Programación' AND (`descripcion` IS NULL OR `descripcion` = '');

UPDATE `libros` SET `descripcion` = 'Guía de buenas prácticas para escribir código legible, ordenado y fácil de mantener, con ejemplos de refactorización.'
 WHERE `titulo` = 'Clean Code' AND (`descripcion` IS NULL OR `descripcion` = '');

UPDATE `libros` SET `descripcion` = 'Obra de divulgación científica sobre el origen y la estructura del universo, los agujeros negros y la naturaleza del tiempo, escrita para lectores sin formación en física.'
 WHERE `titulo` = 'Breve historia del tiempo' AND (`descripcion` IS NULL OR `descripcion` = '');

UPDATE `libros` SET `descripcion` = 'Cuento sobre un pequeño príncipe que viaja por distintos planetas y reflexiona sobre la amistad, el amor y la forma en que los adultos ven el mundo.'
 WHERE `titulo` = 'El principito' AND (`descripcion` IS NULL OR `descripcion` = '');

