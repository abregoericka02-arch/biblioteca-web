/* ==============================================================================
   SISTEMA DE BIBLIOTECA - LÓGICA DE LA INTERFAZ (JavaScript)
   ==============================================================================
   GUÍA RÁPIDA - ¿CÓMO FUNCIONA ESTE ARCHIVO?
   - Es una "aplicación de una sola página": index.php no cambia; este archivo
     dibuja cada pantalla (login, catálogo, panel, préstamos...) dentro de <main id="app">.
   - Los datos YA NO se guardan en el navegador: viven en MySQL. Este archivo se
     comunica con PHP mediante fetch():
        · api/datos.php     -> LEE todo (libros, usuarios, préstamos, reservas)
        · api/sesion.php    -> login, logout, registro, perfil
        · api/libros.php    -> guardar / eliminar libros
        · api/prestamos.php -> crear, devolver, renovar préstamos
        · api/reservas.php  -> crear / cancelar reservas
        · api/usuarios.php  -> crear / eliminar usuarios (solo admin)
   - Flujo de cada acción: el usuario hace clic -> se envía la acción a PHP ->
     PHP valida y escribe en MySQL -> se vuelve a pedir api/datos.php -> render().
   - Las reglas de seguridad y validación se repiten en PHP (el servidor siempre
     tiene la última palabra); aquí solo se muestran los mensajes.
   ============================================================================== */


/* ==============================================================================
   1. UTILIDADES GENERALES
   ============================================================================== */

// Atajo para document.querySelector: $('#app') en vez de document.querySelector('#app')
const $ = s => document.querySelector(s);

// Escapa caracteres especiales (<, >, &, comillas) para que un texto escrito por un
// usuario nunca se interprete como HTML (evita ataques XSS al usar innerHTML)
const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Convierte un objeto Date a texto AAAA-MM-DD usando la hora local
const fmt = d => new Date(d - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10);

// Fecha de hoy en formato AAAA-MM-DD
const hoy = () => fmt(new Date());

// Días que faltan para una fecha (negativo = ya pasó). Ej: dias('2026-11-10') -> 3
const dias = f => Math.round((new Date(f + 'T00:00:00') - new Date(hoy() + 'T00:00:00')) / 864e5);


/* ==============================================================================
   2. PREFERENCIA LOCAL: SOLO EL TEMA CLARO/OSCURO SE GUARDA EN EL NAVEGADOR
   (todo lo demás se guarda en la base de datos MySQL)
   ============================================================================== */

// Lee un valor guardado en localStorage (si falla o no existe devuelve 'def')
const leer = (k, def) => { try { return JSON.parse(localStorage.getItem(k)) ?? def; } catch { return def; } };

// Guarda un valor en localStorage (con aviso si el navegador lo impide)
const guardar = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch { aviso('No se pudo guardar en el navegador.', 'danger'); } };


/* ==============================================================================
   3. ESTADO DE LA APLICACIÓN
   Estas variables se llenan con lo que responde api/datos.php (función cargar)
   ============================================================================== */
let libros = [], usuarios = [], prestamos = [], reservas = []; // Datos que vienen de MySQL
let solicitudes = [];             // Solicitudes de contraseña pendientes (solo las recibe el admin)
let CATS = [];                    // Nombres de categorías (tabla "categorias")
let sesion = null;                // id del usuario con sesión iniciada (null = visitante)
let vista = 'portada';            // Pantalla que se está mostrando
let editId = null;                // id del libro que se está editando (null = libro nuevo)


/* ==============================================================================
   4. COMUNICACIÓN CON EL SERVIDOR (PHP)
   ============================================================================== */

// Hace una petición a un archivo PHP y devuelve su respuesta JSON.
//  - Sin 'cuerpo'  -> petición GET (leer datos)
//  - Con 'cuerpo'  -> petición POST con el objeto convertido a JSON (ejecutar acción)
// Siempre devuelve un objeto { ok, ... , status }; nunca lanza error.
async function api(url, cuerpo) {
    let resp;
    try {
        resp = await fetch(url, cuerpo === undefined
            ? { credentials: 'same-origin', cache: 'no-store' }
            : {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
                body: JSON.stringify(cuerpo)
            });
    } catch {
        return { ok: false, status: 0, error: 'No se pudo conectar con el servidor. Verifica que Apache y MySQL estén activos en XAMPP.' };
    }
    try {
        const r = await resp.json();
        r.status = resp.status;
        return r;
    } catch {
        return { ok: false, status: resp.status, error: 'El servidor devolvió una respuesta inesperada. Revisa que la base de datos esté instalada.' };
    }
}

// Pide a api/datos.php todo el estado actual y lo guarda en las variables globales.
// Devuelve true si salió bien.
async function cargar() {
    const r = await api('api/datos.php');
    if (!r.ok) { aviso(r.error || 'No se pudieron cargar los datos.', 'danger'); return false; }
    ({ categorias: CATS, libros, usuarios, prestamos, reservas } = r);
    solicitudes = r.solicitudes || [];
    sesion = r.sesion;
    return true;
}

// Envía una acción al servidor. Si todo sale bien: recarga los datos y muestra el mensaje verde.
// Devuelve null si funcionó, o el texto del error si falló (así los formularios saben qué mostrar).
async function enviar(url, cuerpo) {
    const r = await api(url, cuerpo);
    if (!r.ok) {
        if (r.status === 401) { await cargar(); render(); } // La sesión caducó: se vuelve a la pantalla de acceso
        return r.error || 'Ocurrió un error inesperado.';
    }
    await cargar();
    if (r.mensaje) aviso(r.mensaje, 'success');
    return null;
}


/* ==============================================================================
   5. CONSULTAS SOBRE LOS DATOS (funciones pequeñas que se usan en las vistas)
   ============================================================================== */

const usuario = () => usuarios.find(u => u.id === sesion);                         // Usuario con sesión iniciada
const disp = l => l.copias - l.prestados;                                          // Ejemplares disponibles de un libro
const getL = id => libros.find(l => l.id === id) || { titulo: '(eliminado)' };     // Libro por id (o "(eliminado)")
const getU = id => usuarios.find(u => u.id === id) || { nombre: '(eliminado)' };   // Usuario por id (o "(eliminado)")
const inicio = u => (u.rol === 'lector' ? 'catalogo' : 'panel');                   // Pantalla inicial según el rol

// Devuelve [texto, clase CSS] con el estado de un préstamo (Devuelto / Vencido / N días restantes)
const estado = p => p.devuelto ? ['Devuelto' + (atraso(p) ? ' · multa ' + dinero(multa(p)) : ''), 'badge-disponible']
    : dias(p.limite) < 0 ? ['Vencido hace ' + atraso(p) + ' d · multa ' + dinero(multa(p)), 'badge-vencido']
    : [dias(p.limite) + ' d restantes', 'badge-prestado'];


/* ==============================================================================
   6. MULTAS Y RENOVACIONES
   ============================================================================== */

const MULTA_DIA = 0.25; // Balboas que se cobran por cada día de atraso

// Días de atraso de un préstamo (hasta la fecha de devolución, o hasta hoy si sigue prestado)
const atraso = p => Math.max(0, Math.round((new Date((p.devuelto || hoy()) + 'T00:00:00') - new Date(p.limite + 'T00:00:00')) / 864e5));

const multa = p => atraso(p) * MULTA_DIA;        // Monto de la multa
const dinero = n => 'B/. ' + n.toFixed(2);       // Formato de dinero: B/. 0.75

// Botón "Renovar +7 d": solo aparece si no se ha renovado y el préstamo no está vencido
const botonRenovar = p => (!p.renovado && dias(p.limite) >= 0 ? `<button class="btn btn-sm btn-outline" data-acc="renovar" data-id="${p.id}">Renovar +7 d</button> ` : '');


/* ==============================================================================
   7. COMPONENTES HTML REUTILIZABLES
   (cada función devuelve un texto HTML que se pega en la página)
   ============================================================================== */

// Muestra un mensaje en la franja superior. t = tipo: 'success' (verde), 'danger' (rojo), 'warning' (amarillo)
function aviso(m, t) {
    $('#aviso').innerHTML = `<div class="alerta alerta-${t}"><div class="alerta-contenido">${esc(m)}</div></div>`;
    window.scrollTo(0, 0);
}

// Tarjeta blanca con título (t) y contenido (c)
const tarjeta = (t, c) => `<section class="tarjeta mb-3"><h2 class="tarjeta-titulo">${t}</h2>${c}</section>`;

// Campo de formulario: n = id/name, t = etiqueta, v = valor inicial, tipo = text/email/password/number
const campo = (n, t, v = '', tipo = 'text') =>
    `<div class="form-grupo"><label class="form-label" for="${n}">${t}</label><input class="form-control" id="${n}" name="${n}" type="${tipo}" value="${esc(v)}" required></div>`;

// Lista desplegable: n = id/name, t = etiqueta, ops = opciones (<option>...)
const select = (n, t, ops) =>
    `<div class="form-grupo"><label class="form-label" for="${n}">${t}</label><select class="form-control" id="${n}" name="${n}">${ops}</select></div>`;

// Tabla: h = lista de encabezados, f = lista de filas (<tr>...). Si no hay filas muestra "Sin registros"
const tabla = (h, f) => `<div class="tabla-responsive"><table class="tabla-biblioteca"><thead><tr>${h.map(x => `<th>${x}</th>`).join('')}</tr></thead><tbody>${f.length ? f.join('') : `<tr><td colspan="${h.length}">Sin registros</td></tr>`}</tbody></table></div>`;

// Tarjeta de estadística (número grande con título)
const metrica = (t, v) => `<div class="tarjeta-metrica"><div class="metrica-info"><h4>${t}</h4><div class="metrica-valor">${v}</div></div></div>`;

// Opciones de categoría para los <select>; 'sel' es la categoría seleccionada
const opCats = sel => CATS.map(c => `<option ${c === sel ? 'selected' : ''}>${c}</option>`).join('');


/* ==============================================================================
   8. PORTADAS DE LIBROS
   ============================================================================== */

const COLORES = ['#6b4226', '#8b5e3c', '#a67c52', '#4a3728', '#7a5c3e', '#9c6b30']; // Colores de las portadas generadas

// URL de la portada: la imagen subida (guardada en assets/img/covers) o, si no hay,
// una portada dibujada en SVG con la inicial del título
const portada = l => l.portada || 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="200" height="280"><rect width="200" height="280" fill="${COLORES[(l.id || 0) % 6]}"/><rect x="14" y="14" width="172" height="252" fill="none" stroke="#d4af37" stroke-width="3"/><text x="100" y="150" font-size="64" text-anchor="middle" fill="#f5efe6" font-family="Georgia">${esc((l.titulo || '?')[0])}</text></svg>`);

// Etiqueta <img> de la portada
const img = l => `<img class="portada" src="${portada(l)}" alt="Portada del libro ${esc(l.titulo)}" loading="lazy">`;

// Portada nueva elegida en el formulario (imagen reducida en base64). Se envía al guardar el libro.
let portadaTmp = null;

// Cuando se elige un archivo de imagen: se reduce a 220 px de ancho y se convierte a JPEG
// para que el envío al servidor sea liviano
document.addEventListener('change', e => {
    if (!e.target.matches('#portada') || !e.target.files[0]) return;
    const fr = new FileReader();
    fr.onload = () => {
        const im = new Image();
        im.onload = () => { const c = document.createElement('canvas'), k = Math.min(1, 220 / im.width); c.width = im.width * k; c.height = im.height * k; c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); portadaTmp = c.toDataURL('image/jpeg', 0.7); };
        im.src = fr.result;
    };
    fr.readAsDataURL(e.target.files[0]);
});


/* ==============================================================================
   9. VISTAS (PANTALLAS) - cada una devuelve el HTML de una pantalla completa
   ============================================================================== */

// ----- Pantalla: iniciar sesión -----
const vLogin = () => tarjeta('Iniciar sesión', `
    <form data-form="login" novalidate>
        ${campo('usuario', 'Usuario o correo electrónico')}${campo('pass', 'Contraseña', '', 'password')}
        <button class="btn btn-primario btn-block">Entrar</button>
    </form>
    <p class="mt-2 text-center">¿No tienes cuenta? <a href="#" data-v="registro">Regístrate</a></p>
    <p class="mt-1 text-center"><a href="#" data-v="olvide">¿Olvidaste tu contraseña?</a></p>
    <p class="texto-secundario text-center mt-1">Demo: admin / admin123 · biblio / biblio123 · lector / lector123</p>`);

// ----- Pantalla: olvidé mi contraseña (deja una solicitud para el administrador) -----
const vOlvide = () => tarjeta('¿Olvidaste tu contraseña?', `
    <p class="texto-secundario mb-2">Escribe tu correo o tu usuario. El administrador recibirá tu solicitud y te dará una contraseña temporal.</p>
    <form data-form="olvide" novalidate>
        ${campo('usuario', 'Usuario o correo electrónico')}
        <button class="btn btn-primario btn-block">Enviar solicitud</button>
    </form>
    <p class="mt-2 text-center"><a href="#" data-v="login">Volver a iniciar sesión</a></p>`);

// ----- Pantalla: crear cuenta de lector -----
const vRegistro = () => tarjeta('Crear cuenta de lector', `
    <form data-form="registro" novalidate>
        ${campo('nombre', 'Nombre completo')}${campo('email', 'Correo electrónico', '', 'email')}${campo('pass', 'Contraseña (mínimo 6 caracteres)', '', 'password')}
        <button class="btn btn-primario btn-block">Registrarme</button>
    </form>
    <p class="mt-2 text-center"><a href="#" data-v="login">Volver a iniciar sesión</a></p>`);

// ----- Catálogo: lista de libros filtrada por la búsqueda (#q) y la categoría (#cat) -----
function tarjetas() {
    const q = ($('#q')?.value || '').toLowerCase(), cat = $('#cat')?.value || '';
    const r = libros.filter(l => (!cat || l.categoria === cat) && (l.titulo + l.autor + l.isbn).toLowerCase().includes(q));
    return r.map(l => `<article class="tarjeta-libro">${img(l)}<div class="libro-cuerpo">
        <span class="libro-categoria-badge">${esc(l.categoria)}</span>
        <h3 class="libro-titulo">${esc(l.titulo)}</h3><p class="libro-autor">${esc(l.autor)} · ${l.anio}</p>
        <p class="libro-disponibilidad"><span class="badge ${disp(l) > 0 ? 'badge-disponible' : 'badge-prestado'}">${disp(l) > 0 ? disp(l) + ' de ' + l.copias + ' disponibles' : 'Sin ejemplares'}</span></p>
        <button class="btn btn-sm btn-outline" data-acc="detalle" data-id="${l.id}">Ver detalle</button> ${accionLector(l)}
    </div></article>`).join('') || '<p>No se encontraron libros.</p>';
}

// ----- Pantalla: catálogo (buscador + categoría + lista de libros) -----
const vCatalogo = () => tarjeta('Catálogo', `<div class="busqueda-grid">
    <div class="form-grupo"><label class="form-label" for="q">Buscar</label><input class="form-control" id="q" placeholder="Título, autor o código"></div>
    <div class="form-grupo"><label class="form-label" for="cat">Categoría</label><select class="form-control" id="cat"><option value="">Todas</option>${opCats()}</select></div>
    </div>`) + `<div id="lista" class="grid-libros">${tarjetas()}</div>`;

// ----- Panel (parte 1): métricas + informes de vencidos y existencias (solo personal) -----
const vPanelBase = () => {
    const act = prestamos.filter(p => !p.devuelto), venc = act.filter(p => dias(p.limite) < 0); // act = préstamos activos, venc = vencidos
    return `<div class="grid-metricas mb-3">${metrica('Libros', libros.length)}${metrica('Ejemplares', libros.reduce((s, l) => s + l.copias, 0))}
        ${metrica('Prestados', act.length)}${metrica('Vencidos', venc.length)}${metrica('Lectores', usuarios.filter(u => u.rol === 'lector').length)}</div>`
        + tarjeta('Informe: préstamos vencidos', tabla(['Libro', 'Lector', 'Vencía', 'Atraso', 'Multa'],
            venc.map(p => `<tr><td>${esc(getL(p.libroId).titulo)}</td><td>${esc(getU(p.lectorId).nombre)}</td><td>${p.limite}</td><td>${-dias(p.limite)} días</td><td>${dinero(multa(p))}</td></tr>`)))
        + tarjeta('Informe: existencias por libro', tabla(['Libro', 'Ejemplares', 'Prestados', 'Disponibles'],
            libros.map(l => `<tr><td>${esc(l.titulo)}</td><td>${l.copias}</td><td>${l.prestados}</td><td>${disp(l)}</td></tr>`))
            + '<button class="btn btn-dorado mt-2" data-acc="imprimir">Imprimir informe</button> <button class="btn btn-outline mt-2" data-acc="csv">Exportar a CSV</button>');
};

// ----- Pantalla: administrar libros (formulario de alta/edición + tabla de libros) -----
const vLibros = () => {
    const l = libros.find(x => x.id === editId) || {}; // Libro que se edita (vacío si es nuevo)
    return tarjeta(editId ? 'Editar libro' : 'Registrar libro', `<form data-form="libro" novalidate><div class="busqueda-grid">
        ${campo('titulo', 'Título', l.titulo)}${campo('autor', 'Autor', l.autor)}${select('categoria', 'Categoría', opCats(l.categoria))}
        ${campo('isbn', 'Código / ISBN', l.isbn)}${campo('anio', 'Año de publicación', l.anio, 'number')}${campo('copias', 'Ejemplares', l.copias ?? 1, 'number')}
        <div class="form-grupo"><label class="form-label" for="descripcion">Descripción</label><textarea class="form-control" id="descripcion" name="descripcion" rows="3">${esc(l.descripcion)}</textarea></div>
        <div class="form-grupo"><label class="form-label" for="portada">Portada (imagen opcional)</label><input class="form-control" id="portada" type="file" accept="image/*"></div>
        </div><button class="btn btn-primario">Guardar</button>
        ${editId ? '<button type="button" class="btn btn-outline" data-acc="cancelar">Cancelar</button>' : ''}</form>`)
        + tarjeta('Libros registrados', tabla(['Título', 'Autor', 'Categoría', 'Ejemplares', 'Acciones'], libros.map(x =>
            `<tr><td>${esc(x.titulo)}</td><td>${esc(x.autor)}</td><td>${esc(x.categoria)}</td><td>${disp(x)}/${x.copias}</td>
            <td><button class="btn btn-sm btn-outline" data-acc="editar" data-id="${x.id}">Editar</button>
            <button class="btn btn-sm btn-peligro" data-acc="eliminar" data-id="${x.id}">Eliminar</button></td></tr>`)));
};

// ----- Pantalla: lectores registrados -----
const vLectores = () => tarjeta('Lectores registrados', tabla(['Nombre', 'Correo', 'Préstamos activos'],
    usuarios.filter(u => u.rol === 'lector').map(u => `<tr><td>${esc(u.nombre)}</td><td>${esc(u.email)}</td><td>${prestamos.filter(p => p.lectorId === u.id && !p.devuelto).length}</td></tr>`)));

// ----- Pantalla: préstamos (formulario de nuevo préstamo + tabla con todos) -----
const vPrestamos = () => tarjeta('Nuevo préstamo', `<form data-form="prestamo" novalidate><div class="busqueda-grid">
    ${select('lector', 'Lector', '<option value="">Selecciona...</option>' + usuarios.filter(u => u.rol === 'lector').map(u => `<option value="${u.id}">${esc(u.nombre)}</option>`).join(''))}
    ${select('libro', 'Libro disponible', '<option value="">Selecciona...</option>' + libros.filter(l => disp(l) > 0).map(l => `<option value="${l.id}">${esc(l.titulo)} (${disp(l)})</option>`).join(''))}
    ${campo('dias', 'Días de préstamo (1 a 30)', 7, 'number')}</div><button class="btn btn-primario">Registrar préstamo</button></form>`)
    + tarjeta('Todos los préstamos', tabla(['Libro', 'Lector', 'Fecha', 'Límite', 'Estado', 'Acción'], [...prestamos].reverse().map(p => {
        const e = estado(p);
        return `<tr><td>${esc(getL(p.libroId).titulo)}</td><td>${esc(getU(p.lectorId).nombre)}</td><td>${p.fecha}</td><td>${p.limite}</td>
            <td><span class="badge ${e[1]}">${e[0]}</span></td>
            <td>${p.devuelto ? '' : `<button class="btn btn-sm btn-exito" data-acc="devolver" data-id="${p.id}">Devolver</button>`}</td></tr>`;
    })));

// ----- Pantalla del lector (parte 1): notificaciones + historial de sus préstamos -----
const vMisBase = () => {
    // Avisos: préstamos propios que vencen en 2 días o menos (o que ya vencieron)
    const mios = prestamos.filter(p => p.lectorId === sesion), avisos = mios.filter(p => !p.devuelto && dias(p.limite) <= 2).map(p => {
        const d = dias(p.limite), t = esc(getL(p.libroId).titulo);
        return d < 0 ? `<div class="alerta alerta-danger"><div class="alerta-contenido">«${t}» está vencido hace ${-d} día(s). Devuélvelo lo antes posible.</div></div>`
            : `<div class="alerta alerta-warning"><div class="alerta-contenido">«${t}» vence ${d === 0 ? 'hoy' : 'en ' + d + ' día(s)'}.</div></div>`;
    });
    return tarjeta('Notificaciones', avisos.join('') || '<p>No tienes avisos pendientes.</p>')
        + tarjeta('Mis préstamos e historial', tabla(['Libro', 'Fecha', 'Límite', 'Estado', 'Acción'], [...mios].reverse().map(p => {
            const e = estado(p);
            return `<tr><td>${esc(getL(p.libroId).titulo)}</td><td>${p.fecha}</td><td>${p.limite}</td><td><span class="badge ${e[1]}">${e[0]}</span></td><td>${p.devuelto ? '' : botonRenovar(p) + `<button class="btn btn-sm btn-exito" data-acc="devolver" data-id="${p.id}">Devolver</button>`}</td></tr>`;
        })));
};

// ----- Pantalla: usuarios del sistema (solo admin): formulario + tabla -----
const vUsuarios = () => tarjeta('Solicitudes de contraseña (' + solicitudes.length + ')', tabla(['Nombre', 'Correo', 'Fecha', 'Acción'], solicitudes.map(s =>
        `<tr><td>${esc(s.nombre)}</td><td>${esc(s.email)}</td><td>${esc(String(s.fecha).slice(0, 16))}</td>
        <td><button class="btn btn-sm btn-exito" data-acc="restablecer" data-id="${s.usuarioId}">Restablecer</button>
        <button class="btn btn-sm btn-outline" data-acc="descartar" data-id="${s.id}">Descartar</button></td></tr>`)))
    + tarjeta('Crear usuario', `<form data-form="usuario" novalidate><div class="busqueda-grid">
    ${campo('nombre', 'Nombre completo')}${campo('usuario', 'Nombre de usuario')}${campo('email', 'Correo electrónico', '', 'email')}${campo('pass', 'Contraseña', '', 'password')}
    ${select('rol', 'Rol', '<option value="bibliotecario">Bibliotecario</option><option value="lector">Lector</option>')}
    </div><button class="btn btn-primario">Crear usuario</button></form>`)
    + tarjeta('Usuarios del sistema', tabla(['Nombre', 'Usuario', 'Rol', 'Acción'], usuarios.map(u =>
        `<tr><td>${esc(u.nombre)}</td><td>${esc(u.usuario)}</td><td><span class="badge badge-rol">${u.rol}</span></td>
        <td>${u.id === sesion ? '' : `<button class="btn btn-sm btn-outline" data-acc="restablecer" data-id="${u.id}">Restablecer contraseña</button> <button class="btn btn-sm btn-peligro" data-acc="eliminarU" data-id="${u.id}">Eliminar</button>`}</td></tr>`)));


/* ==============================================================================
   10. RESERVAS, GRÁFICOS, PERFIL Y PORTADA DE BIENVENIDA
   ============================================================================== */

// Botón que aparece en cada libro del catálogo (solo para lectores):
// "Pedir prestado" si hay ejemplares, "Reservar" si no, o la etiqueta "Reservado"
const accionLector = l => {
    if (usuario()?.rol !== 'lector') return '';
    if (disp(l) > 0) return `<button class="btn btn-sm btn-dorado" data-acc="pedir" data-id="${l.id}">Pedir prestado (7 días)</button>`;
    return reservas.some(x => x.libroId === l.id && x.lectorId === sesion) ? '<span class="badge badge-prestado">Reservado</span>' : `<button class="btn btn-sm btn-dorado" data-acc="reservar" data-id="${l.id}">Reservar</button>`;
};

// Tabla "Mis reservas" del lector: indica si el libro ya está disponible para pedirlo
const tarjetaReservas = () => {
    const mias = reservas.filter(x => x.lectorId === sesion);
    return tarjeta('Mis reservas', tabla(['Libro', 'Estado', 'Acción'], mias.map(x => {
        const l = getL(x.libroId), ya = l.id && disp(l) > 0;
        return `<tr><td>${esc(l.titulo)}</td><td><span class="badge ${ya ? 'badge-disponible' : 'badge-prestado'}">${ya ? 'Ya disponible' : 'En espera'}</span></td>
        <td>${ya ? `<button class="btn btn-sm btn-dorado" data-acc="pedir" data-id="${l.id}">Pedir prestado</button> ` : ''}<button class="btn btn-sm btn-outline" data-acc="cancelarR" data-id="${x.id}">Cancelar</button></td></tr>`;
    })));
};

// Pantalla completa del lector: notificaciones + préstamos + reservas
const vMis = () => vMisBase() + tarjetaReservas();

// Gráfico de barras horizontales: t = título, datos = lista de [nombre, valor]
const barras = (t, datos) => { const max = Math.max(1, ...datos.map(d => d[1])); return tarjeta(t, datos.map(([n, v]) => `<div class="barra-fila"><span class="barra-etq">${esc(n)}</span><div class="barra-fondo"><div class="barra" style="width:${v / max * 100}%"></div></div><strong>${v}</strong></div>`).join('')); };

// Panel completo del personal: métricas e informes + dos gráficos
const vPanel = () => {
    const act = prestamos.filter(p => !p.devuelto);
    return vPanelBase()
        + barras('Gráfico: libros por categoría', CATS.map(c => [c, libros.filter(l => l.categoria === c).length]))
        + barras('Gráfico: préstamos por estado', [['Activos al día', act.filter(p => dias(p.limite) >= 0).length], ['Vencidos', act.filter(p => dias(p.limite) < 0).length], ['Devueltos', prestamos.length - act.length], ['Reservas en espera', reservas.length]]);
};

// Pantalla: mi perfil (cambiar nombre, correo y contraseña)
const vPerfil = () => { const u = usuario(); return tarjeta('Mi perfil', `<form data-form="perfil" novalidate>
    ${campo('nombre', 'Nombre completo', u.nombre)}${campo('email', 'Correo electrónico', u.email, 'email')}
    <p class="texto-secundario">Tu usuario para entrar: <strong>${esc(u.usuario)}</strong> (también puedes entrar con tu correo).</p>
    <div class="form-grupo"><label class="form-label" for="actual">Contraseña actual (solo si vas a cambiarla)</label><input class="form-control" id="actual" name="actual" type="password"></div>
    <div class="form-grupo"><label class="form-label" for="nueva">Nueva contraseña</label><input class="form-control" id="nueva" name="nueva" type="password"></div>
    <button class="btn btn-primario">Guardar cambios</button></form>`); };

// Pantalla de bienvenida para visitantes: banner + 4 libros destacados + horarios
const vPortada = () => `<section class="hero-portada"><h1>Bienvenido al Sistema Biblioteca</h1>
    <p>Consulta el catálogo, reserva y pide prestados tus libros desde cualquier lugar.</p>
    <a href="#" class="btn btn-dorado" data-v="login">Ingresar</a> <a href="#" class="btn btn-outline" data-v="registro">Crear cuenta</a></section>`
    + tarjeta('Libros destacados', `<div class="grid-libros">${libros.slice(0, 4).map(l => `<article class="tarjeta-libro">${img(l)}<div class="libro-cuerpo"><h3 class="libro-titulo">${esc(l.titulo)}</h3><p class="libro-autor">${esc(l.autor)}</p></div></article>`).join('')}</div>`)
    + tarjeta('Horarios y contacto', '<p>Lunes a viernes: 8:00 a. m. – 5:00 p. m. · Sábados: 9:00 a. m. – 1:00 p. m.</p><p>Universidad de Panamá, CRUBO · Bocas del Toro, Panamá</p>');


/* ==============================================================================
   11. TABLAS CON FILTRO Y ORDEN POR COLUMNA
   ============================================================================== */

// A cada tabla con 2 o más filas le agrega un cuadro "Filtrar tabla..." y permite
// ordenar al hacer clic en el encabezado de una columna
function mejorarTablas() {
    document.querySelectorAll('.tabla-responsive').forEach(w => {
        const t = w.querySelector('table'), filas = [...t.tBodies[0].rows];
        if (filas.length < 2) return; // No vale la pena filtrar una tabla con 0 o 1 fila

        // Cuadro de filtro: oculta las filas que no contienen el texto escrito
        const b = document.createElement('input');
        b.className = 'form-control buscador-tabla'; b.placeholder = 'Filtrar tabla...'; b.setAttribute('aria-label', 'Filtrar tabla');
        b.oninput = () => filas.forEach(f => { f.hidden = !f.textContent.toLowerCase().includes(b.value.toLowerCase()); });
        w.before(b);

        // Ordenar por columna: clic alterna ascendente (▲) / descendente (▼)
        const ths = [...t.tHead.querySelectorAll('th')];
        ths.forEach((th, i) => {
            th.classList.add('ordenable'); th.title = 'Ordenar';
            th.onclick = () => {
                const asc = th.dataset.o !== 'a'; ths.forEach(x => delete x.dataset.o); th.dataset.o = asc ? 'a' : 'd';
                filas.sort((a, c) => a.cells[i].textContent.localeCompare(c.cells[i].textContent, 'es', { numeric: true }) * (asc ? 1 : -1)).forEach(f => t.tBodies[0].append(f));
            };
        });
    });
}


/* ==============================================================================
   12. MODO CLARO / OSCURO (se recuerda en el navegador)
   ============================================================================== */
function aplicarTema(t) { document.documentElement.dataset.tema = t; guardar('bib_tema', t); }
aplicarTema(leer('bib_tema', 'claro')); // Al abrir, aplica el último tema elegido


/* ==============================================================================
   13. RENDER Y NAVEGACIÓN
   ============================================================================== */

const STAFF_V = ['panel', 'libros', 'lectores', 'prestamos', 'usuarios']; // Pantallas exclusivas del personal

// Dibuja el menú y la pantalla actual. Se llama cada vez que cambia algo.
function render() {
    const u = usuario();

    // --- Control de acceso en pantalla (el servidor también lo valida) ---
    if (!u && !['portada', 'login', 'registro', 'olvide'].includes(vista)) vista = 'portada'; // Visitante: solo pantallas públicas
    if (u && ['portada', 'login', 'registro', 'olvide'].includes(vista)) vista = inicio(u);   // Con sesión: salta las públicas
    if (u && u.rol === 'lector' && STAFF_V.includes(vista)) vista = 'catalogo';     // Lector no entra al panel del personal
    if (u && u.rol === 'bibliotecario' && vista === 'usuarios') vista = 'panel';    // Solo el admin gestiona usuarios

    // --- Menú según el rol: visitante / personal / lector ---
    const menu = !u ? [['portada', 'Inicio'], ['login', 'Ingresar'], ['registro', 'Registrarse']] : u.rol !== 'lector'
        ? [['panel', 'Panel e informe'], ['catalogo', 'Catálogo'], ['libros', 'Libros'], ['lectores', 'Lectores'], ['prestamos', 'Préstamos'], ...(u.rol === 'admin' ? [['usuarios', 'Usuarios']] : [])]
        : [['catalogo', 'Catálogo'], ['mis', 'Mis préstamos']];
    $('#nav').innerHTML = menu.map(([v, t]) => `<li class="nav-item"><a href="#" data-v="${v}" class="${v === vista ? 'activo' : ''}">${t}</a></li>`).join('')
        + (u ? `<li class="nav-item"><a href="#" data-v="perfil" class="${vista === 'perfil' ? 'activo' : ''}">Mi perfil</a></li><li class="nav-item"><a href="#" data-acc="salir">Salir (${esc(u.nombre)})</a></li>` : '')
        + '<li class="nav-item"><a href="#" data-acc="tema" aria-label="Cambiar entre modo claro y oscuro">🌓</a></li>';
    $('#nav').classList.remove('abierto'); // Cierra el menú en móvil

    // --- Pantalla: busca la función de la vista actual y pega su HTML en <main> ---
    const vistas = { olvide: vOlvide, login: vLogin, registro: vRegistro, panel: vPanel, catalogo: vCatalogo, libros: vLibros, lectores: vLectores, prestamos: vPrestamos, mis: vMis, usuarios: vUsuarios, portada: vPortada, perfil: vPerfil };
    $('#app').innerHTML = vistas[vista]();
    mejorarTablas();
}


/* ==============================================================================
   14. FORMULARIOS
   Cada función recibe los datos del formulario (d) y los envía al servidor.
   Devuelve un texto si hubo error, o nada si salió bien.
   ============================================================================== */
const forms = {
    // Iniciar sesión (api/sesion.php, acción "login")
    async login(d) {
        const err = await enviar('api/sesion.php', { accion: 'login', usuario: d.usuario, pass: d.pass });
        if (err) return err;
        vista = inicio(usuario()); $('#aviso').innerHTML = ''; // Va a la pantalla inicial de su rol
    },

    // Pedir al administrador que restablezca la contraseña (acción "olvide"); al terminar vuelve al login
    async olvide(d) {
        const err = await enviar('api/sesion.php', { accion: 'olvide', usuario: d.usuario });
        if (!err) vista = 'login';
        return err;
    },

    // Registrar una cuenta de lector (acción "registro"); al terminar muestra el login
    async registro(d) {
        const err = await enviar('api/sesion.php', { accion: 'registro', nombre: d.nombre, email: d.email, pass: d.pass });
        if (!err) vista = 'login';
        return err;
    },

    // Actualizar nombre, correo y (opcional) contraseña del usuario actual
    perfil: d => enviar('api/sesion.php', { accion: 'perfil', nombre: d.nombre, email: d.email, actual: d.actual, nueva: d.nueva }),

    // Crear un usuario desde el panel del administrador (api/usuarios.php)
    usuario: d => enviar('api/usuarios.php', { accion: 'crear', nombre: d.nombre, usuario: d.usuario, email: d.email, pass: d.pass, rol: d.rol }),

    // Guardar un libro nuevo o editado (api/libros.php); incluye la portada si se eligió una
    async libro(d) {
        const err = await enviar('api/libros.php', { accion: 'guardar', id: editId, ...d, portada: portadaTmp });
        if (!err) { editId = null; portadaTmp = null; }
        return err;
    },

    // Registrar un préstamo desde el panel del personal (api/prestamos.php)
    prestamo: d => enviar('api/prestamos.php', { accion: 'crear', lector: d.lector, libro: d.libro, dias: d.dias })
};


/* ==============================================================================
   15. EVENTOS (lo que pasa cuando el usuario interactúa)
   ============================================================================== */

// Envío de cualquier formulario: se evita que la página se recargue y se llama a forms[nombre]
document.addEventListener('submit', async e => {
    e.preventDefault();
    const f = forms[e.target.dataset.form];
    if (!f) return;
    const err = await f(Object.fromEntries(new FormData(e.target)));
    if (err) return aviso(err, 'danger'); // Si hubo error se muestra y NO se redibuja (así no se pierde lo escrito)
    render();
});

// Buscador y filtro de categoría del catálogo: actualizan la lista mientras se escribe
document.addEventListener('input', e => { if (e.target.matches('#q, #cat')) $('#lista').innerHTML = tarjetas(); });
document.addEventListener('change', e => { if (e.target.matches('#cat')) $('#lista').innerHTML = tarjetas(); });

// Clic en cualquier elemento con data-v (cambiar de pantalla) o data-acc (ejecutar una acción)
document.addEventListener('click', async e => {
    const el = e.target.closest('[data-v],[data-acc]');
    if (!el) return;
    e.preventDefault();
    const { v, acc } = el.dataset, id = Number(el.dataset.id);

    // --- Navegación entre pantallas ---
    if (v) { vista = v === 'inicio' ? (usuario() ? inicio(usuario()) : 'portada') : v; editId = null; $('#aviso').innerHTML = ''; return render(); }

    // --- Acciones que no cambian datos ---
    if (acc === 'imprimir') return window.print();                       // Imprime el informe
    if (acc === 'cerrar') return $('#dlg').close();                      // Cierra la ventana de detalle
    if (acc === 'tema') return aplicarTema(document.documentElement.dataset.tema === 'oscuro' ? 'claro' : 'oscuro');
    if (acc === 'cancelar') editId = null;                               // Cancela la edición de un libro
    if (acc === 'editar') { editId = id; window.scrollTo(0, 0); }        // Carga un libro en el formulario

    // --- Detalle del libro en una ventana emergente ---
    if (acc === 'detalle') {
        const l = getL(id);
        $('#dlg').innerHTML = `${img(l)}<h2 class="tarjeta-titulo">${esc(l.titulo)}</h2><p><strong>${esc(l.autor)}</strong> · ${esc(l.categoria)} · ${l.anio}</p><p>Código: ${esc(l.isbn)} · Disponibles: ${disp(l)} de ${l.copias}</p><p>${esc(l.descripcion) || 'Sin descripción.'}</p><button class="btn btn-primario" data-acc="cerrar">Cerrar</button>`;
        return $('#dlg').showModal();
    }

    // --- Exportar el informe de préstamos a un archivo CSV (se abre en Excel) ---
    if (acc === 'csv') {
        const fila = f => f.map(x => '"' + String(x).replace(/"/g, '""') + '"').join(',');
        const datos = [['Libro', 'Lector', 'Fecha', 'Límite', 'Devuelto', 'Atraso (días)', 'Multa (B/.)'], ...prestamos.map(p => [getL(p.libroId).titulo, getU(p.lectorId).nombre, p.fecha, p.limite, p.devuelto || '', atraso(p), multa(p).toFixed(2)])];
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['\ufeff' + datos.map(fila).join('\n')], { type: 'text/csv;charset=utf-8' }));
        a.download = 'informe-prestamos.csv'; a.click(); return;
    }

    // --- Cerrar sesión ---
    if (acc === 'salir') {
        await api('api/sesion.php', { accion: 'logout' });
        await cargar(); vista = 'login'; $('#aviso').innerHTML = '';
    }

    // --- Eliminar un libro (se confirma antes) ---
    if (acc === 'eliminar') {
        if (getL(id).prestados) return aviso('No se puede eliminar: el libro tiene préstamos activos.', 'danger');
        if (!confirm('¿Eliminar este libro?')) return;
        const err = await enviar('api/libros.php', { accion: 'eliminar', id });
        if (err) return aviso(err, 'danger');
    }

    // --- Reservar un libro sin ejemplares disponibles ---
    if (acc === 'reservar') {
        if (disp(getL(id)) > 0) return aviso('Ese libro ya está disponible: puedes pedirlo prestado.', 'warning');
        const err = await enviar('api/reservas.php', { accion: 'crear', libro: id });
        if (err) return aviso(err, 'danger');
    }

    // --- Cancelar una reserva ---
    if (acc === 'cancelarR') {
        const err = await enviar('api/reservas.php', { accion: 'cancelar', id });
        if (err) return aviso(err, 'danger');
    }

    // --- Renovar un préstamo +7 días ---
    if (acc === 'renovar') {
        const err = await enviar('api/prestamos.php', { accion: 'renovar', id });
        if (err) return aviso(err, 'danger');
    }

    // --- El lector pide un libro prestado por 7 días ---
    if (acc === 'pedir') {
        const err = await enviar('api/prestamos.php', { accion: 'crear', libro: id });
        if (err) return aviso(err, 'danger');
    }

    // --- Devolver un libro ---
    if (acc === 'devolver') {
        const err = await enviar('api/prestamos.php', { accion: 'devolver', id });
        if (err) return aviso(err, 'danger');
    }

    // --- Restablecer la contraseña de un usuario (solo admin): se escribe una temporal ---
    if (acc === 'restablecer') {
        const nueva = prompt('Escribe una contraseña temporal para ' + getU(id).nombre + ' (mínimo 6 caracteres):');
        if (nueva === null) return; // Canceló
        const err = await enviar('api/usuarios.php', { accion: 'restablecer', id, pass: nueva });
        if (err) return aviso(err, 'danger');
    }

    // --- Descartar una solicitud de contraseña (solo admin) ---
    if (acc === 'descartar') {
        const err = await enviar('api/usuarios.php', { accion: 'descartar', id });
        if (err) return aviso(err, 'danger');
    }

    // --- Eliminar un usuario (solo admin; se confirma antes) ---
    if (acc === 'eliminarU') {
        if (id === sesion) return aviso('No puedes eliminar tu propia cuenta.', 'danger');
        if (prestamos.some(p => p.lectorId === id && !p.devuelto)) return aviso('El usuario tiene préstamos activos.', 'danger');
        if (!confirm('¿Eliminar este usuario?')) return;
        const err = await enviar('api/usuarios.php', { accion: 'eliminar', id });
        if (err) return aviso(err, 'danger');
    }

    render(); // Después de cualquier acción se redibuja la pantalla con los datos actualizados
});

// Botón ☰ del menú en pantallas pequeñas: abre y cierra el menú
$('#btnMenu').addEventListener('click', () => $('#nav').classList.toggle('abierto'));


/* ==============================================================================
   16. INICIO DE LA APLICACIÓN
   ============================================================================== */
// Al abrir la página: se piden los datos al servidor (y se detecta si ya hay una sesión
// abierta) y luego se dibuja la pantalla correspondiente
(async () => { await cargar(); render(); })();
