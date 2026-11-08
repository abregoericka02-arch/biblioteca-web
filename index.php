<?php
/**
 * ==============================================================================
 * PÁGINA PRINCIPAL - SISTEMA DE BIBLIOTECA
 * ==============================================================================
 * GUÍA RÁPIDA:
 *  - Es la única página que abre el usuario: http://localhost/biblioteca-web/
 *  - Solo contiene el "esqueleto" HTML (menú, zona central, pie de página).
 *  - js/app.js dibuja el contenido y pide los datos a los archivos de /api,
 *    que a su vez consultan MySQL.
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Biblioteca</title>
    <!-- Hoja de estilos: colores, tipografías, tarjetas, tablas y modo oscuro -->
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body>
    <!-- ===== CABECERA: logo + botón de menú (móvil) + menú de navegación ===== -->
    <header class="navbar-biblioteca">
        <div class="nav-contenedor contenedor">
            <!-- data-v="inicio": app.js detecta este atributo para cambiar de pantalla -->
            <a href="#" class="logo-biblioteca" data-v="inicio">
                <div class="logo-texto">Biblioteca <span>CRUBO</span></div>
            </a>
            <!-- Botón ☰ que abre/cierra el menú en pantallas pequeñas -->
            <button class="btn-toggle-menu" id="btnMenu" aria-label="Abrir menú de navegación">☰</button>
            <nav aria-label="Navegación principal">
                <!-- app.js llena este menú según el rol del usuario -->
                <ul class="nav-menu" id="nav"></ul>
            </nav>
        </div>
    </header>

    <!-- Zona donde app.js muestra mensajes de éxito, error o aviso -->
    <div id="aviso" class="contenedor" role="status"></div>

    <!-- Zona principal: aquí se dibuja cada pantalla (login, catálogo, panel...) -->
    <main class="contenedor main-content" id="app">
        <p>Cargando…</p>
        <noscript>Esta aplicación necesita JavaScript activado para funcionar.</noscript>
    </main>

    <!-- ===== PIE DE PÁGINA ===== -->
    <footer class="footer-biblioteca">
        <div class="footer-bottom">Proyecto final de Tecnología Web · Universidad de Panamá, CRUBO</div>
    </footer>

    <!-- Ventana emergente (modal) que muestra el detalle de un libro -->
    <dialog id="dlg" aria-label="Detalle del libro"></dialog>

    <!-- Lógica de la interfaz (se carga al final para que el HTML ya exista) -->
    <script src="js/app.js"></script>
</body>
</html>
