// Página de gracias: confirma el pago con el servidor (que a su vez le pregunta a
// Mercado Pago), arranca la descarga sola la primera vez y deja el botón a mano.
// El enlace de esta página es el acceso personal a la compra: sirve para volver a
// descargar el libro.

import { enlaceWhatsapp, guardarCompra, leerCompras, postJson } from '../comun.js';

const tarjeta = document.querySelector('[data-gracias]');
const consulta = new URLSearchParams(location.search);
let orden = (consulta.get('orden') || '').trim().toUpperCase();
let clave = (consulta.get('clave') || '').trim();
const valorMP = (nombre) => {
  const valor = consulta.get(nombre);
  return valor && valor !== 'null' ? valor : '';
};
const pagoId = valorMP('payment_id') || valorMP('collection_id');
const estadoMP = valorMP('status') || valorMP('collection_status');
const volvioSinPagar = consulta.has('status') && !estadoMP;
const esNavegadorDeApp = /Instagram|FBAN|FBAV|FB_IAB|TikTok|musical_ly|Snapchat|Line\//i.test(navigator.userAgent);

if (!orden || !clave) {
  const ultima = leerCompras()[0];
  if (ultima) ({ codigo: orden, clave } = ultima);
}
if (orden && clave) {
  // La URL queda limpia (sin los parámetros de Mercado Pago): es el link para volver.
  history.replaceState(null, '', `${location.pathname}?orden=${encodeURIComponent(orden)}&clave=${encodeURIComponent(clave)}`);
}

/** Crea elementos sin innerHTML: los datos del comprador nunca se interpretan como HTML. */
function h(etiqueta, atributos = {}, ...hijos) {
  const elemento = document.createElement(etiqueta);
  for (const [clave, valor] of Object.entries(atributos)) {
    if (valor === false || valor === null || valor === undefined) continue;
    if (clave === 'class') elemento.className = valor;
    else if (clave.startsWith('on')) elemento.addEventListener(clave.slice(2), valor);
    else elemento.setAttribute(clave, valor === true ? '' : valor);
  }
  elemento.append(...hijos.flat().filter((hijo) => hijo !== null && hijo !== undefined && hijo !== false));
  return elemento;
}

function icono(nombre) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('aria-hidden', 'true');
  const uso = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  uso.setAttribute('href', `#i-${nombre}`);
  svg.append(uso);
  return svg;
}

function pintar(...contenido) {
  tarjeta.replaceChildren(...contenido.flat().filter(Boolean));
}

const ayuda = (texto) => h('p', { class: 'gracias__ayuda' },
  '¿Algún problema? ',
  h('a', { href: enlaceWhatsapp(texto), target: '_blank', rel: 'noopener noreferrer' }, 'Escribinos por WhatsApp'),
  ' y lo resolvemos.');

const textoAyuda = () => `Hola, compré un ebook${orden ? ` (compra ${orden})` : ''} y necesito ayuda con la descarga.`;

/* ---------------------------------------------------------------- estados */

function pintarAprobado(datos) {
  guardarCompra({ codigo: datos.codigo, clave, ebookId: datos.ebook.id });
  const titulo = datos.nombre ? `¡Listo, ${datos.nombre}! Tu libro ya es tuyo` : '¡Listo! Tu libro ya es tuyo';
  const restantes = datos.descargas?.restantes ?? 0;
  const botonDescarga = h('a', { class: 'ebook-btn ebook-btn--xl', href: datos.descarga, onclick: marcarDescargado },
    icono('descarga'), 'Descargar el PDF');
  const copiar = h('button', { type: 'button', class: 'gracias__enlace', onclick: copiarEnlace }, 'Copiar el enlace de esta página');

  pintar(
    h('div', { class: 'gracias__icono gracias__icono--ok' }, icono('check')),
    h('p', { class: 'pixel-kicker' }, 'Compra confirmada'),
    h('h1', { tabindex: '-1' }, titulo),
    h('p', { class: 'gracias__texto' }, esNavegadorDeApp
      ? 'Tocá el botón para descargar el PDF.'
      : 'La descarga empieza sola. Si no arranca, tocá el botón.'),
    h('div', { class: 'gracias__libro' },
      h('img', { src: `/ebooks/${encodeURIComponent(datos.ebook.id)}/img/portada-chica.webp`, alt: '', width: '480', height: '678' }),
      h('div', {}, h('strong', {}, datos.ebook.titulo), h('span', {}, 'PDF · compra ', h('span', { class: 'gracias__codigo' }, datos.codigo)))),
    h('div', { class: 'gracias__acciones' },
      restantes > 0 ? botonDescarga : null,
      h('p', { class: 'gracias__secundaria' },
        restantes > 0 ? h('a', { class: 'gracias__enlace', href: datos.ver, target: '_blank', rel: 'noopener', onclick: marcarDescargado }, 'Abrir en el navegador') : null,
        copiar)),
    esNavegadorDeApp ? h('p', { class: 'gracias__app' },
      'Estás navegando dentro de una app (Instagram, Facebook o TikTok). Si la descarga no arranca, tocá el menú ⋯ y elegí «Abrir en el navegador», o copiá el enlace y pegalo en Chrome o Safari.') : null,
    h('p', { class: 'gracias__nota' },
      h('strong', {}, 'Guardá esta página: '),
      restantes > 0
        ? `con este enlace podés volver a descargar el libro cuando quieras (te quedan ${restantes} ${restantes === 1 ? 'descarga' : 'descargas'}).`
        : 'ya usaste todas las descargas de esta compra. Escribinos y te habilitamos más.'),
    ayuda(textoAyuda()),
  );
  tarjeta.querySelector('h1').focus({ preventScroll: true });

  if (restantes > 0 && !esNavegadorDeApp && !yaDescargado()) {
    setTimeout(() => {
      marcarDescargado();
      window.location.assign(datos.descarga);
    }, 900);
  }
}

function pintarPendiente({ agotado = false } = {}) {
  pintar(
    h('div', { class: 'gracias__icono' }, agotado ? icono('reloj') : h('span', { class: 'gracias__giro', 'aria-hidden': 'true' })),
    h('h1', {}, 'Tu pago se está procesando'),
    h('p', { class: 'gracias__texto' }, agotado
      ? 'Mercado Pago todavía no lo confirmó. Guardá este enlace y volvé a entrar en un rato: apenas se acredite vas a poder descargar el libro.'
      : 'Mercado Pago todavía no lo confirmó. Esta página se actualiza sola: no hace falta que pagues de nuevo.'),
    agotado ? h('div', { class: 'gracias__acciones' },
      h('button', { type: 'button', class: 'ebook-btn', onclick: () => { intentos = 0; limite = 20; consultar(); } }, 'Consultar de nuevo'),
      h('p', { class: 'gracias__secundaria' }, h('button', { type: 'button', class: 'gracias__enlace', onclick: copiarEnlace }, 'Copiar el enlace de esta página'))) : null,
    ayuda(textoAyuda()),
  );
}

function pintarSinPagar(datos, { rechazado = false } = {}) {
  const url = `/ebooks/${encodeURIComponent(datos.ebook.id)}/#comprar`;
  pintar(
    h('div', { class: `gracias__icono ${rechazado ? 'gracias__icono--error' : ''}` }, icono(rechazado ? 'cruz' : 'reloj')),
    h('h1', {}, rechazado ? 'El pago no se completó' : 'Todavía no pagaste tu libro'),
    h('p', { class: 'gracias__texto' }, rechazado
      ? 'Mercado Pago no aprobó el pago o se canceló antes de terminar. Podés intentarlo de nuevo con otro medio de pago.'
      : 'Volviste antes de terminar el pago. Cuando quieras, lo retomás desde la página del libro.'),
    h('div', { class: 'gracias__acciones' }, h('a', { class: 'ebook-btn ebook-btn--xl', href: url }, rechazado ? 'Intentar de nuevo' : 'Terminar la compra')),
    ayuda(`Hola, quise comprar el ebook ${datos.ebook.titulo} y tuve un problema con el pago.`),
  );
}

function pintarReembolsado() {
  pintar(
    h('div', { class: 'gracias__icono gracias__icono--error' }, icono('cruz')),
    h('h1', {}, 'Esta compra fue reembolsada'),
    h('p', { class: 'gracias__texto' }, 'El pago se devolvió, así que la descarga ya no está disponible.'),
    ayuda(textoAyuda()),
  );
}

function pintarNoEncontrada() {
  pintar(
    h('div', { class: 'gracias__icono' }, icono('busqueda')),
    h('h1', {}, 'No encontramos tu compra'),
    h('p', { class: 'gracias__texto' }, 'Revisá que el enlace esté completo. Si pagaste y no ves tu libro, escribinos por WhatsApp y te lo mandamos.'),
    h('div', { class: 'gracias__acciones' }, h('a', { class: 'ebook-btn', href: enlaceWhatsapp('Hola, compré un ebook y no encuentro la descarga.'), target: '_blank', rel: 'noopener noreferrer' }, 'Escribinos por WhatsApp')),
  );
}

function pintarErrorDeRed(mensaje) {
  pintar(
    h('div', { class: 'gracias__icono gracias__icono--error' }, icono('cruz')),
    h('h1', {}, 'No pudimos consultar tu compra'),
    h('p', { class: 'gracias__texto' }, mensaje),
    h('div', { class: 'gracias__acciones' }, h('button', { type: 'button', class: 'ebook-btn', onclick: () => { intentos = 0; consultar(); } }, 'Reintentar')),
    ayuda(textoAyuda()),
  );
}

/* ---------------------------------------------------------------- acciones */

function yaDescargado() {
  try {
    return localStorage.getItem(`culturapixel:descargado:${orden}`) === '1';
  } catch {
    return false;
  }
}

function marcarDescargado() {
  try {
    localStorage.setItem(`culturapixel:descargado:${orden}`, '1');
  } catch {
    // Sin almacenamiento: a lo sumo la descarga automática se repite al recargar.
  }
}

async function copiarEnlace(evento) {
  const boton = evento.currentTarget;
  try {
    await navigator.clipboard.writeText(location.href);
    boton.textContent = '¡Enlace copiado!';
  } catch {
    window.prompt('Copiá este enlace:', location.href);
  }
}

/* ---------------------------------------------------------------- consulta */

let intentos = 0;
// Si viene de Mercado Pago con el pago en curso se espera más; si es un link guardado, poco.
let limite = ['approved', 'in_process', 'pending', 'authorized'].includes(estadoMP) ? 45 : 3;
let pagoPendienteDeInformar = pagoId;

async function consultar() {
  if (!orden || !clave) return pintarNoEncontrada();
  try {
    const datos = await postJson('/api/ebooks/estado.php', { orden, clave, pagoId: pagoPendienteDeInformar });
    pagoPendienteDeInformar = '';
    if (datos.estado === 'aprobado') return pintarAprobado(datos);
    if (datos.estado === 'reembolsado') return pintarReembolsado();
    if (datos.estado === 'rechazado' || datos.estado === 'cancelado') return pintarSinPagar(datos, { rechazado: true });
    if (volvioSinPagar && intentos === 0) return pintarSinPagar(datos);
    intentos += 1;
    if (intentos >= limite) return pintarPendiente({ agotado: true });
    pintarPendiente();
    setTimeout(consultar, 4000);
  } catch (error) {
    if (error.status === 404) return pintarNoEncontrada();
    intentos += 1;
    if (intentos < 3) return setTimeout(consultar, 3000);
    pintarErrorDeRed(error.message);
  }
}

consultar();
