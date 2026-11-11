# Sistema de Biblioteca (PHP + MySQL + HTML + CSS + JS)

Misma aplicación de la carpeta `biblioteca-web-js` (diseño, colores, pantallas y
funciones idénticos), pero ahora con **PHP y MySQL** para correr en **XAMPP**.

## Cómo instalarlo (3 pasos)

1. Copia la carpeta `biblioteca-web` dentro de `C:\xampp\htdocs\`.
2. Abre el **Panel de XAMPP** y activa **Apache** y **MySQL**.
3. Crea la base de datos, con UNA de estas dos opciones:
   - Abre `http://localhost/biblioteca-web/database/instalar.php`, o
   - En phpMyAdmin (`http://localhost/phpmyadmin`) ve a **Importar** y elige `database/biblioteca.sql`.

Luego entra a **http://localhost/biblioteca-web/**

> Si tu MySQL tiene contraseña o usa otro puerto, cámbialo en `config/database.php`
> (y en `database/instalar.php` si usas el instalador).

## Usuarios de prueba

| Rol           | Usuario | Contraseña |
|---------------|---------|------------|
| Administrador | admin   | admin123   |
| Bibliotecario | biblio  | biblio123  |
| Lector        | lector  | lector123  |

(También se puede entrar con el correo, por ejemplo `admin@biblioteca.com`.)

## Estructura del proyecto

```
biblioteca-web/
├── index.php              Página única (esqueleto HTML)
├── css/estilos.css        Estilos (idénticos a la carpeta 1, con comentarios guía)
├── js/app.js              Pantallas y lógica de la interfaz; habla con /api
├── api/                   Lo que responde PHP en formato JSON
│   ├── datos.php            Lee libros, usuarios, préstamos y reservas (según el rol)
│   ├── sesion.php           Login, logout, registro y perfil
│   ├── libros.php           Guardar y eliminar libros (personal)
│   ├── prestamos.php        Crear, devolver y renovar préstamos
│   ├── reservas.php         Crear y cancelar reservas
│   └── usuarios.php         Crear y eliminar usuarios (solo admin)
├── includes/
│   ├── auth.php             Sesiones y control de acceso por rol
│   └── funciones.php        Utilidades compartidas (JSON, fechas, crear usuario)
├── config/database.php   Conexión a MySQL (PDO)
├── database/
│   ├── biblioteca.sql       Tablas y datos de ejemplo
│   └── instalar.php         Instalador automático de la base de datos
└── assets/img/covers/     Aquí se guardan las portadas que se suben
```

## ¿Qué cambió respecto a la carpeta 1 (solo JS)?

- Los datos ya no están en `localStorage`: se guardan en **MySQL** (tablas
  `roles, usuarios, categorias, libros, prestamos, reservas`).
- Las contraseñas se guardan cifradas (**bcrypt**), no en texto plano.
- Las reglas (máximo 3 préstamos, límite de días, permisos por rol, etc.) ahora
  las comprueba también el **servidor PHP**, no solo el navegador.
- Las portadas subidas se guardan como archivos en `assets/img/covers/`.
- Lo único que sigue en el navegador es la preferencia de modo claro/oscuro.

## ¿Qué se quitó de la carpeta 2 (PHP)?

Todo lo que la carpeta 1 no tiene: páginas PHP separadas por pantalla (`admin/`,
`cliente/`), gestión de ejemplares individuales con código y estante, tabla de
autores, editorial y páginas, teléfono y dirección de usuarios, tabla de
notificaciones, la API `buscar_libros.php`, el reporte aparte y la carpeta `docs/`.
Las notificaciones y los informes de la carpeta 1 se mantienen tal cual
(se calculan a partir de los préstamos).

## ¿Olvidé mi contraseña?

- **Lector o bibliotecario:** en el login pulsa **¿Olvidaste tu contraseña?** y escribe su
  correo o usuario. El administrador ve la solicitud en **Usuarios > Solicitudes de contraseña**,
  pulsa **Restablecer**, escribe una contraseña temporal y se la entrega a la persona
  (en persona, WhatsApp, etc.). La persona entra con esa clave y la cambia en **Mi perfil**.
  El administrador también puede **Descartar** una solicitud.
- **Administrador:** abre la terminal de XAMPP (botón *Shell*) dentro de la carpeta
  del proyecto y escribe: `php database/restablecer_admin.php NuevaClave123`

## Actualizar una instalación que ya funciona (sin perder datos)

La base de datos **se actualiza sola**: después de hacer `git pull` (o de copiar los archivos
nuevos), basta con abrir la página. Si el proyecto necesita una tabla o columna nueva,
`includes/migraciones.php` la agrega la primera vez y guarda en la tabla `esquema_version`
hasta dónde llegó. Las migraciones **nunca borran datos**.

**Nunca** vuelvas a ejecutar `instalar.php` ni a importar `biblioteca.sql` en una base con datos:
eso la reinicia desde cero. (Los archivos `database/migracion_*.sql` de entregas anteriores
ya no hacen falta: se pueden borrar.)

## Reglas del sistema

- Préstamo de 7 días, una renovación de 7 días; máximo 3 libros por lector.
- Multa de B/. 0.25 por día de atraso. Se cobra en la biblioteca: el personal pulsa **Cobrar**
  en Préstamos (después de devolver el libro). Con multas pendientes o un préstamo vencido
  el lector no puede pedir ni renovar libros.
- Solo el personal registra devoluciones.
- Reservas: si un libro no tiene ejemplares, el lector puede reservarlo. Cuando vuelve un
  ejemplar se aparta 3 días para el primero de la lista; si no lo recoge, pasa al siguiente.
- Cuando el administrador restablece una contraseña, es temporal: la persona debe cambiarla
  en el primer inicio de sesión.

## Usuarios de prueba (cámbialos en "Mi perfil" antes de presentar el proyecto)

| Rol           | Usuario | Contraseña |
|---------------|---------|------------|
| Administrador | admin   | admin123   |
| Bibliotecario | biblio  | biblio123  |
| Lector        | lector  | lector123  |
