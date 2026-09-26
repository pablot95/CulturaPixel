// Panel de ventas de ebooks. El ingreso es con Firebase Authentication; todos los
// datos pasan por /api/ebooks/admin.php, que valida el token y que el usuario esté en
// admins/{email} de Firestore. El navegador nunca ve tokens de Mercado Pago.

import { initializeApp } from 'https://www.gstatic.com/firebasejs/12.4.0/firebase-app.js';
import {
  connectAuthEmulator, getAuth, onAuthStateChanged, sendPasswordResetEmail, signInWithEmailAndPassword, signOut,
} from 'https://www.gstatic.com/firebasejs/12.4.0/firebase-auth.js';

const firebaseConfig = {
  apiKey: 'AIzaSyBZ1em4R7YqzBrzN1KGZoOeambrMlbbHAI',
  authDomain: 'institutoculturapixel-ef52a.firebaseapp.com',
  projectId: 'institutoculturapixel-ef52a',
  storageBucket: 'institutoculturapixel-ef52a.firebasestorage.app',
  messagingSenderId: '980142106247',
  appId: '1:980142106247:web:f9f10da0713e3f5d61fa22',
};

const auth = getAuth(initializeApp(firebaseConfig));
auth.languageCode = 'es';

const $ = (selector, raiz = document) => raiz.querySelector(selector);
const $$ = (selector, raiz = document) => [...raiz.querySelectorAll(selector)];

const ZONA = 'America/Argentina/Mendoza';
const dinero = (monto) => `$${new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 }).format(Number(monto) || 0)}`;
const fechaCorta = (iso) => (iso ? new Intl.DateTimeFormat('es-AR', { dateStyle: 'short', timeStyle: 'short', timeZone: ZONA }).format(new Date(iso)) : '—');
const fechaLarga = (iso) => (iso ? new Intl.DateTimeFormat('es-AR', { dateStyle: 'long', timeZone: ZONA }).format(new Date(iso)) : '');
const nombreDelMes = (clave) => {
  const [anio, mes] = String(clave || '').split('-').map(Number);
  return anio ? new Intl.DateTimeFormat('es-AR', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(anio, mes - 1, 15))) : 'este mes';
};

const ESTADOS = { aprobado: 'Aprobada', pendiente: 'Pendiente', rechazado: 'Rechazada', cancelado: 'Cancelada', reembolsado: 'Reembolsada' };
const INCIDENTES = {
  monto_incorrecto: 'El monto pagado no coincide con el precio',
  pago_duplicado: 'Pagó dos veces: devolvé uno desde Mercado Pago',
  error_preferencia: 'No se pudo crear el pago en Mercado Pago',
};
const RESULTADOS_MP = {
  conectado: ['¡Listo! Mercado Pago quedó conectado y ya podés vender.', 'ok'],
  cancelado: ['Cancelaste la conexión con Mercado Pago.', 'error'],
  vencido: ['La conexión venció o se abrió en otro navegador. Probá de nuevo desde este.', 'error'],
  rechazado: ['Mercado Pago rechazó la conexión. Probá de nuevo.', 'error'],
  error: ['No se pudo completar la conexión con Mercado Pago. Probá de nuevo.', 'error'],
};

const estado = { sesion: null, ventas: [], siguiente: null, filtro: '', busqueda: '' };

/* ------------------------------------------------------------------ utilidades */

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

/** replaceChildren sin los opcionales vacíos (null se convertiría en el texto "null"). */
function reemplazar(contenedor, ...hijos) {
  contenedor.replaceChildren(...hijos.flat().filter((hijo) => hijo !== null && hijo !== undefined && hijo !== false));
}

function icono(nombre) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('aria-hidden', 'true');
  const uso = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  uso.setAttribute('href', `#i-${nombre}`);
  svg.append(uso);
  return svg;
}

let temporizadorAviso;
function avisar(texto, tipo = 'ok') {
  const toast = $('[data-toast]');
  toast.textContent = texto;
  toast.classList.toggle('panel-toast--error', tipo === 'error');
  toast.hidden = false;
  clearTimeout(temporizadorAviso);
  temporizadorAviso = setTimeout(() => {
    toast.hidden = true;
  }, 5200);
}

function mostrarVista(nombre) {
  $$('[data-vista]').forEach((vista) => {
    vista.hidden = vista.dataset.vista !== nombre;
  });
}

async function ocupado(boton, tarea) {
  boton.disabled = true;
  boton.setAttribute('aria-busy', 'true');
  try {
    return await tarea();
  } finally {
    boton.disabled = false;
    boton.removeAttribute('aria-busy');
  }
}

async function api(accion, datos = {}) {
  const pedir = async (forzar) => fetch('/api/ebooks/admin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await auth.currentUser.getIdToken(forzar)}` },
    body: JSON.stringify({ accion, ...datos }),
  });
  let respuesta;
  try {
    respuesta = await pedir(false);
    if (respuesta.status === 401) respuesta = await pedir(true);
  } catch {
    throw Object.assign(new Error('No hay conexión con el servidor. Revisá internet e intentá de nuevo.'), { status: 0 });
  }
  const cuerpo = await respuesta.json().catch(() => ({}));
  if (!respuesta.ok) throw Object.assign(new Error(cuerpo.error || 'Algo salió mal. Probá de nuevo.'), { status: respuesta.status });
  return cuerpo;
}

/* ------------------------------------------------------------------ pestañas */

const PESTANAS = ['resumen', 'ventas', 'ebooks', 'mp'];

function activarPestana(nombre, { foco = false } = {}) {
  if (!PESTANAS.includes(nombre)) nombre = 'resumen';
  $$('[data-tab]').forEach((tab) => {
    const activa = tab.dataset.tab === nombre;
    tab.setAttribute('aria-selected', String(activa));
    tab.tabIndex = activa ? 0 : -1;
    if (activa && foco) tab.focus();
  });
  $$('[data-panel]').forEach((panel) => {
    panel.hidden = panel.dataset.panel !== nombre;
  });
  history.replaceState(null, '', `${location.pathname}#${nombre}`);
}

$$('[data-tab]').forEach((tab) => tab.addEventListener('click', () => activarPestana(tab.dataset.tab)));
$('.panel-tabs').addEventListener('keydown', (evento) => {
  const indice = PESTANAS.indexOf($('[data-tab][aria-selected="true"]').dataset.tab);
  const movimientos = { ArrowRight: 1, ArrowLeft: -1 };
  if (evento.key in movimientos) {
    evento.preventDefault();
    activarPestana(PESTANAS[(indice + movimientos[evento.key] + PESTANAS.length) % PESTANAS.length], { foco: true });
  }
});
$('[data-ir-ventas]').addEventListener('click', () => activarPestana('ventas'));
$('[data-ir-mp]').addEventListener('click', () => activarPestana('mp'));

/* ------------------------------------------------------------------ resumen */

function estadoBadge(valor) {
  return h('span', { class: `estado estado--${valor}` }, ESTADOS[valor] || valor);
}

function pintarResumen() {
  const { resumen } = estado.sesion;
  const kpi = (etiqueta, valor, detalle, clase = '') => h('article', { class: `panel-kpi ${clase}` },
    h('p', { class: 'panel-kpi__etiqueta' }, etiqueta),
    h('p', { class: 'panel-kpi__valor' }, valor),
    h('p', { class: 'panel-kpi__detalle' }, detalle));
  const aprobadas = resumen.aprobadas.cantidad;
  reemplazar($('[data-kpis]'),
    kpi('Recaudado en total', dinero(resumen.aprobadas.total), `${aprobadas} ${aprobadas === 1 ? 'venta aprobada' : 'ventas aprobadas'}`, 'panel-kpi--destacado'),
    kpi(`Vendido en ${nombreDelMes(resumen.mesActual)}`, dinero(resumen.mes.total), `${resumen.mes.cantidad} ${resumen.mes.cantidad === 1 ? 'venta' : 'ventas'}`),
    kpi('Pagos sin terminar', String(resumen.pendientes), 'abrieron el pago y no lo completaron'),
    kpi('Para revisar', String(resumen.revision), resumen.revision ? 'ventas con algún problema' : 'todo en orden', resumen.revision ? 'panel-kpi--alerta' : ''),
  );
  pintarResumenMP();
}

function pintarUltimas() {
  const ultimas = estado.ventas.slice(0, 5);
  reemplazar($('[data-ultimas]'), ...(ultimas.length
    ? ultimas.map((venta) => h('li', {},
      h('div', { class: 'panel-ultimas__quien' }, h('strong', {}, venta.comprador.nombre || venta.comprador.email), h('span', {}, `${fechaCorta(venta.creadaAt)} · ${venta.ebookTitulo || venta.ebookId}`)),
      h('div', {}, estadoBadge(venta.estado))))
    : [h('li', { class: 'panel-vacio' }, 'Todavía no hay ventas.')]));
}

function pintarResumenMP() {
  const mp = estado.sesion.mp;
  reemplazar($('[data-resumen-mp]'),
    h('h3', {}, 'Mercado Pago'),
    mp.conectado
      ? h('p', {}, estadoBadge('aprobado'), ' ', mp.cuentaNombre ? `Cobrando en la cuenta ${mp.cuentaNombre}.` : 'Cuenta conectada.')
      : h('p', {}, 'Todavía no está conectado: sin esto no se pueden cobrar los ebooks.'),
    h('p', { style: 'margin-top:14px' }, h('button', { type: 'button', class: 'panel-link', onclick: () => activarPestana('mp') }, mp.conectado ? 'Ver la conexión' : 'Conectar Mercado Pago')),
  );
  $('[data-mp-aviso]').hidden = mp.conectado;
}

/* ------------------------------------------------------------------ ventas */

async function cargarVentas({ reiniciar = false } = {}) {
  const contenedor = $('[data-ventas]');
  if (reiniciar) {
    estado.ventas = [];
    estado.siguiente = null;
    reemplazar(contenedor, h('p', { class: 'panel-vacio' }, 'Cargando ventas…'));
  }
  const botonMas = $('[data-mas]');
  try {
    const datos = await api('ventas', {
      estado: estado.filtro,
      buscar: estado.busqueda,
      despuesDe: reiniciar ? null : estado.siguiente,
    });
    estado.ventas = reiniciar ? datos.ventas : [...estado.ventas, ...datos.ventas];
    estado.siguiente = datos.siguiente;
    pintarVentas();
    if (!estado.filtro && !estado.busqueda) pintarUltimas();
  } catch (error) {
    reemplazar(contenedor, h('p', { class: 'panel-vacio' }, error.message));
  }
  botonMas.hidden = !estado.siguiente;
}

function accionesDeVenta(venta) {
  const acciones = [];
  if (venta.estado === 'aprobado') {
    acciones.push(h('button', { type: 'button', class: 'panel-btn panel-btn--suave panel-btn--chico', onclick: (evento) => nuevoLink(venta, evento.currentTarget) }, 'Nuevo link de descarga'));
    if (venta.descargas.usadas > 0) {
      acciones.push(h('button', { type: 'button', class: 'panel-btn panel-btn--suave panel-btn--chico', onclick: (evento) => reiniciarDescargas(venta, evento.currentTarget) }, 'Reiniciar descargas'));
    }
  } else if (venta.estado !== 'reembolsado') {
    acciones.push(h('button', { type: 'button', class: 'panel-btn panel-btn--suave panel-btn--chico', onclick: (evento) => verificar(venta, evento.currentTarget) }, 'Verificar pago'));
  }
  return acciones;
}

function pintarVentas() {
  const contenedor = $('[data-ventas]');
  if (!estado.ventas.length) {
    reemplazar(contenedor, h('p', { class: 'panel-vacio' }, estado.busqueda ? 'No hay ventas para esa búsqueda.' : 'Todavía no hay ventas con este filtro.'));
    return;
  }
  const filas = estado.ventas.map((venta) => h('tr', {},
    h('td', { 'data-etiqueta': 'Fecha', class: 'numero' }, fechaCorta(venta.creadaAt)),
    h('td', { 'data-etiqueta': 'Compra' }, h('span', { class: 'codigo' }, venta.codigo)),
    h('td', { 'data-etiqueta': 'Comprador' }, h('div', {},
      h('strong', {}, venta.comprador.nombre || '—'),
      h('span', { class: 'secundario' }, venta.comprador.email || ''))),
    h('td', { 'data-etiqueta': 'Ebook' }, venta.ebookTitulo || venta.ebookId),
    h('td', { 'data-etiqueta': 'Monto', class: 'numero' }, dinero(venta.monto)),
    h('td', { 'data-etiqueta': 'Estado' }, h('div', {},
      estadoBadge(venta.estado),
      venta.requiereRevision ? h('span', { class: 'revision' }, icono('alerta'), INCIDENTES[venta.incidente] || 'Revisar') : null)),
    h('td', { 'data-etiqueta': 'Descargas', class: 'numero' }, venta.estado === 'aprobado' ? `${venta.descargas.usadas} de ${venta.descargas.max}` : '—'),
    h('td', { 'data-etiqueta': 'Acciones' }, h('div', { class: 'panel-acciones' }, accionesDeVenta(venta))),
  ));
  reemplazar(contenedor, h('table', {},
    h('thead', {}, h('tr', {}, ...['Fecha', 'Compra', 'Comprador', 'Ebook', 'Monto', 'Estado', 'Descargas', 'Acciones'].map((titulo) => h('th', { scope: 'col' }, titulo)))),
    h('tbody', {}, filas)));
}

function reemplazarVenta(actualizada) {
  estado.ventas = estado.ventas.map((venta) => (venta.codigo === actualizada.codigo ? actualizada : venta));
  pintarVentas();
  pintarUltimas();
}

async function verificar(venta, boton) {
  try {
    const { venta: actualizada, evento } = await ocupado(boton, () => api('venta-verificar', { codigo: venta.codigo }));
    reemplazarVenta(actualizada);
    const mensajes = {
      aprobado: ['Pago confirmado: la venta quedó aprobada y el comprador ya puede descargar.', 'ok'],
      sin_pagos: ['Mercado Pago no tiene ningún pago para esta compra.', 'error'],
      sin_token: ['Conectá Mercado Pago para poder verificar pagos.', 'error'],
      rechazado: ['Mercado Pago informa el pago como rechazado.', 'error'],
      monto_incorrecto: ['El pago existe pero el monto no coincide: revisalo en Mercado Pago.', 'error'],
    };
    const [texto, tipo] = mensajes[evento] || ['Sin cambios: el estado sigue igual en Mercado Pago.', 'ok'];
    avisar(texto, tipo);
    if (evento === 'aprobado') refrescarResumen();
  } catch (error) {
    avisar(error.message, 'error');
  }
}

async function nuevoLink(venta, boton) {
  if (!window.confirm(`Se va a generar un enlace nuevo para ${venta.comprador.nombre || venta.comprador.email} y el anterior deja de funcionar. ¿Seguimos?`)) return;
  try {
    const { url } = await ocupado(boton, () => api('venta-link', { codigo: venta.codigo }));
    const dialogo = $('[data-dialogo-link]');
    $('[data-dialogo-comprador]', dialogo).textContent = venta.comprador.nombre || venta.comprador.email;
    $('[data-dialogo-url]', dialogo).value = url;
    $('[data-dialogo-whatsapp]', dialogo).href = `https://wa.me/?text=${encodeURIComponent(`¡Hola! Te paso el enlace para descargar tu ebook «${venta.ebookTitulo}»: ${url}`)}`;
    $('[data-dialogo-copiar]', dialogo).textContent = 'Copiar enlace';
    dialogo.showModal();
  } catch (error) {
    avisar(error.message, 'error');
  }
}

async function reiniciarDescargas(venta, boton) {
  if (!window.confirm(`¿Reiniciar el contador de descargas de ${venta.comprador.nombre || venta.comprador.email}? Va a poder descargar el libro ${venta.descargas.max} veces más.`)) return;
  try {
    const { venta: actualizada } = await ocupado(boton, () => api('venta-reiniciar', { codigo: venta.codigo }));
    reemplazarVenta(actualizada);
    avisar('Listo: las descargas quedaron en cero.');
  } catch (error) {
    avisar(error.message, 'error');
  }
}

$('[data-dialogo-copiar]').addEventListener('click', async (evento) => {
  const campo = $('[data-dialogo-url]');
  try {
    await navigator.clipboard.writeText(campo.value);
  } catch {
    campo.select();
    document.execCommand?.('copy');
  }
  evento.currentTarget.textContent = '¡Copiado!';
});
$('[data-dialogo-cerrar]').addEventListener('click', () => $('[data-dialogo-link]').close());

$$('[data-filtros] button').forEach((boton) => boton.addEventListener('click', () => {
  $$('[data-filtros] button').forEach((otro) => otro.setAttribute('aria-pressed', String(otro === boton)));
  estado.filtro = boton.dataset.estado;
  estado.busqueda = '';
  $('#buscar-venta').value = '';
  cargarVentas({ reiniciar: true });
}));

$('[data-buscador]').addEventListener('submit', (evento) => {
  evento.preventDefault();
  estado.busqueda = $('#buscar-venta').value.trim();
  if (estado.busqueda) {
    estado.filtro = '';
    $$('[data-filtros] button').forEach((boton) => boton.setAttribute('aria-pressed', String(boton.dataset.estado === '')));
  }
  cargarVentas({ reiniciar: true });
});
$('#buscar-venta').addEventListener('search', (evento) => {
  if (!evento.target.value && estado.busqueda) {
    estado.busqueda = '';
    cargarVentas({ reiniciar: true });
  }
});
$('[data-mas]').addEventListener('click', (evento) => ocupado(evento.currentTarget, () => cargarVentas()));

/* ------------------------------------------------------------------ ebooks */

const PDF_MAX_MB = 200;

/** Sube el PDF con XMLHttpRequest para poder mostrar el avance (fetch no lo informa). */
async function subirPdf(ebook, archivo, alAvanzar) {
  const token = await auth.currentUser.getIdToken();
  return new Promise((resolver, rechazar) => {
    const pedido = new XMLHttpRequest();
    pedido.open('POST', '/api/ebooks/admin-subir.php');
    pedido.setRequestHeader('Authorization', `Bearer ${token}`);
    pedido.upload.addEventListener('progress', (evento) => {
      if (evento.lengthComputable) alAvanzar(evento.loaded / evento.total);
    });
    pedido.addEventListener('load', () => {
      let datos;
      try {
        datos = JSON.parse(pedido.responseText);
      } catch {
        datos = {};
      }
      if (pedido.status >= 200 && pedido.status < 300) resolver(datos);
      else rechazar(new Error(datos.error || 'No se pudo subir el PDF. Probá de nuevo.'));
    });
    pedido.addEventListener('error', () => rechazar(new Error('Se cortó la conexión mientras se subía el PDF. Probá de nuevo.')));
    const formulario = new FormData();
    formulario.append('id', ebook.id);
    formulario.append('archivo', archivo);
    pedido.send(formulario);
  });
}

function bloquePdf(ebook) {
  const megas = ebook.archivo ? (ebook.archivo.bytes / 1048576).toFixed(1).replace('.', ',') : '';
  const estadoArchivo = h('p', { class: `panel-ebook__archivo${ebook.archivo ? '' : ' panel-ebook__archivo--falta'}` },
    ebook.archivo
      ? `PDF subido · ${megas} MB · ${fechaCorta(ebook.archivo.actualizado)}`
      : 'Falta subir el PDF: hasta que no esté, el libro no se puede comprar.');
  const avance = h('progress', { max: '100', value: '0', hidden: true, 'aria-label': 'Avance de la subida' });
  const entrada = h('input', { type: 'file', accept: 'application/pdf,.pdf', class: 'sr-only' });
  const etiqueta = h('label', { class: 'panel-btn panel-btn--suave panel-archivo' }, entrada, ebook.archivo ? 'Reemplazar el PDF' : 'Subir el PDF');
  entrada.addEventListener('change', async () => {
    const archivo = entrada.files?.[0];
    entrada.value = '';
    if (!archivo) return;
    if (!/\.pdf$/i.test(archivo.name) && archivo.type !== 'application/pdf') return avisar('Elegí un archivo PDF.', 'error');
    if (archivo.size > PDF_MAX_MB * 1048576) return avisar(`El PDF pesa más de ${PDF_MAX_MB} MB. Achicalo antes de subirlo.`, 'error');
    if (ebook.archivo && !window.confirm('¿Reemplazar el PDF actual? Las próximas descargas van a entregar el archivo nuevo.')) return;
    etiqueta.classList.add('is-ocupado');
    entrada.disabled = true;
    avance.hidden = false;
    avance.value = 0;
    try {
      const { ebook: actualizado } = await subirPdf(ebook, archivo, (fraccion) => {
        avance.value = Math.round(fraccion * 100);
      });
      estado.sesion.ebooks = estado.sesion.ebooks.map((otro) => (otro.id === actualizado.id ? actualizado : otro));
      avisar(actualizado.disponible ? 'PDF subido: el libro ya se puede comprar.' : 'PDF subido.');
      pintarEbooks();
    } catch (error) {
      avisar(error.message, 'error');
      etiqueta.classList.remove('is-ocupado');
      entrada.disabled = false;
      avance.hidden = true;
    }
  });
  return h('div', { class: 'panel-ebook__pdf' }, estadoArchivo, h('div', { class: 'panel-ebook__subida' }, etiqueta, avance));
}

function pintarEbooks() {
  const { ebooks } = estado.sesion;
  if (!ebooks.length) {
    reemplazar($('[data-ebooks]'), h('p', { class: 'panel-vacio' }, 'Todavía no hay ebooks cargados.'));
    return;
  }
  reemplazar($('[data-ebooks]'), ...ebooks.map((ebook) => {
    const precio = h('input', { type: 'text', inputmode: 'numeric', id: `precio-${ebook.id}`, value: new Intl.NumberFormat('es-AR').format(ebook.precio), autocomplete: 'off' });
    const activo = h('input', { type: 'checkbox', role: 'switch', id: `activo-${ebook.id}` });
    activo.checked = ebook.activo;
    const guardar = h('button', { type: 'submit', class: 'panel-btn panel-btn--primario' }, 'Guardar cambios');
    precio.addEventListener('blur', () => {
      const numero = Number(precio.value.replace(/\D/g, ''));
      if (numero) precio.value = new Intl.NumberFormat('es-AR').format(numero);
    });
    const formulario = h('form', { class: 'panel-ebook__form', onsubmit: async (evento) => {
      evento.preventDefault();
      const numero = Number(precio.value.replace(/\D/g, ''));
      if (!Number.isInteger(numero) || numero < 100) {
        avisar('Revisá el precio: tiene que ser un número en pesos, de $100 para arriba.', 'error');
        precio.focus();
        return;
      }
      try {
        const { ebook: actualizado } = await ocupado(guardar, () => api('ebook-guardar', { id: ebook.id, precio: numero, activo: activo.checked }));
        estado.sesion.ebooks = estado.sesion.ebooks.map((otro) => (otro.id === actualizado.id ? actualizado : otro));
        avisar(`Guardado. ${actualizado.activo ? `La web muestra ${dinero(actualizado.precio)} en menos de un minuto.` : 'La venta quedó pausada.'}`);
        pintarEbooks();
      } catch (error) {
        avisar(error.message, 'error');
      }
    } },
    h('label', { class: 'panel-ebook__precio', for: `precio-${ebook.id}` }, 'Precio', h('div', {}, h('span', { 'aria-hidden': 'true' }, '$'), precio)),
    h('label', { class: 'panel-interruptor', for: `activo-${ebook.id}` }, activo, 'A la venta'),
    guardar);

    return h('article', { class: 'panel-tarjeta panel-ebook' },
      h('img', { src: ebook.portada || '/images/favicon.png', alt: '', width: '110', height: '155' }),
      h('div', {},
        h('h3', {}, ebook.titulo),
        bloquePdf(ebook),
        formulario,
        h('p', { class: 'panel-ebook__enlaces' },
          h('a', { href: ebook.url || '/', target: '_blank', rel: 'noopener' }, 'Ver la página del libro', icono('externo')))));
  }));
}

/* ------------------------------------------------------------------ mercado pago */

async function conectarMP(boton) {
  try {
    const { url } = await ocupado(boton, () => api('mp-conectar'));
    window.location.assign(url);
  } catch (error) {
    avisar(error.message, 'error');
  }
}

async function desconectarMP(boton) {
  if (!window.confirm('Si desconectás Mercado Pago, la web deja de cobrar los ebooks hasta que la vuelvas a conectar. ¿Desconectar?')) return;
  try {
    const { mp } = await ocupado(boton, () => api('mp-desconectar'));
    estado.sesion.mp = mp;
    pintarMP();
    pintarResumenMP();
    avisar('Mercado Pago quedó desconectado.');
  } catch (error) {
    avisar(error.message, 'error');
  }
}

function pintarMP() {
  const mp = estado.sesion.mp;
  const contenedor = $('[data-mp]');
  if (mp.conectado) {
    const cuenta = mp.cuentaNombre ? `${mp.cuentaNombre}${mp.cuentaEmail ? ` (${mp.cuentaEmail})` : ''}` : 'la cuenta del instituto';
    reemplazar(contenedor,
      h('div', { class: 'panel-mp__estado' },
        h('span', { class: 'panel-mp__icono panel-mp__icono--ok' }, icono('check')),
        h('div', {}, h('h3', {}, 'Conectado'), h('p', {}, mp.conexion === 'entorno' ? 'Con las credenciales cargadas en el servidor.' : `Cobrando en ${cuenta}.`))),
      mp.oauthError ? h('p', { class: 'panel-mensaje panel-mensaje--error' }, `Mercado Pago no pudo renovar la conexión (${mp.oauthError}). Volvé a conectar para que no se corte.`) : null,
      h('ul', { class: 'panel-mp__lista' },
        h('li', {}, 'El dinero de cada venta entra directo en esa cuenta de Mercado Pago.'),
        mp.expiresAt ? h('li', {}, `La autorización se renueva sola (vence el ${fechaLarga(mp.expiresAt)}).`) : null,
        !mp.liveMode ? h('li', {}, 'Atención: es una cuenta de prueba, los pagos no son reales.') : null),
      mp.conexion === 'entorno' ? null : h('div', { class: 'panel-mp__botones' },
        h('button', { type: 'button', class: 'panel-btn panel-btn--suave', onclick: (evento) => conectarMP(evento.currentTarget) }, 'Conectar otra cuenta'),
        h('button', { type: 'button', class: 'panel-btn panel-btn--peligro', onclick: (evento) => desconectarMP(evento.currentTarget) }, 'Desconectar')),
    );
    return;
  }
  reemplazar(contenedor,
    h('div', { class: 'panel-mp__estado' },
      h('span', { class: 'panel-mp__icono' }, icono('mp')),
      h('div', {}, h('h3', {}, 'Sin conectar'), h('p', {}, 'Conectá la cuenta de Mercado Pago del instituto para cobrar los ebooks.'))),
    mp.oauthError ? h('p', { class: 'panel-mensaje panel-mensaje--error' }, mp.oauthError) : null,
    h('ol', { class: 'panel-mp__lista' },
      h('li', {}, 'Tocá «Conectar con Mercado Pago» e iniciá sesión con la cuenta del instituto.'),
      h('li', {}, 'Autorizá a Gokywebs a cobrar en tu nombre.'),
      h('li', {}, 'Volvés acá solo, con la cuenta conectada. El dinero de cada venta entra directo en tu cuenta.')),
    mp.oauthDisponible
      ? h('button', { type: 'button', class: 'panel-btn panel-btn--mp', onclick: (evento) => conectarMP(evento.currentTarget) }, 'Conectar con Mercado Pago')
      : h('p', { class: 'panel-mensaje panel-mensaje--error' }, 'La conexión no está configurada en el servidor todavía. Avisale a Gokywebs.'),
  );
}

/* ------------------------------------------------------------------ carga */

async function refrescarResumen() {
  try {
    const { resumen } = await api('resumen');
    estado.sesion.resumen = resumen;
    pintarResumen();
  } catch {
    // El resumen se vuelve a pedir con el botón Actualizar.
  }
}

$('[data-refrescar]').addEventListener('click', (evento) => ocupado(evento.currentTarget, async () => {
  await cargarSesion();
  await cargarVentas({ reiniciar: true });
}));

async function cargarSesion() {
  estado.sesion = await api('sesion');
  $('[data-admin-email]').textContent = estado.sesion.admin.email;
  pintarResumen();
  pintarEbooks();
  pintarMP();
}

// onAuthStateChanged y el formulario de ingreso pueden pedirlo a la vez: una sola carga.
let entrando = null;
function entrar() {
  entrando ??= entrarAhora().finally(() => {
    entrando = null;
  });
  return entrando;
}

async function entrarAhora() {
  mostrarVista('cargando');
  try {
    await cargarSesion();
  } catch (error) {
    if (error.status === 403 || error.status === 401) {
      await signOut(auth);
      mostrarErrorLogin(error.message);
      return;
    }
    mostrarVista('login');
    mostrarErrorLogin(error.message);
    return;
  }
  mostrarVista('app');
  const consulta = new URLSearchParams(location.search);
  const resultadoMP = consulta.get('mp');
  if (resultadoMP) {
    const [texto, tipo] = RESULTADOS_MP[resultadoMP] || RESULTADOS_MP.error;
    avisar(texto, tipo);
    history.replaceState(null, '', `${location.pathname}#mp`);
    activarPestana('mp');
  } else {
    activarPestana(location.hash.slice(1));
  }
  cargarVentas({ reiniciar: true });
}

/* ------------------------------------------------------------------ ingreso */

const formularioLogin = $('[data-login]');

function mostrarErrorLogin(texto) {
  mostrarVista('login');
  const error = $('[data-login-error]');
  error.textContent = texto;
  error.hidden = !texto;
  $('[data-login-ok]').hidden = true;
}

const ERRORES_AUTH = {
  'auth/invalid-credential': 'El email o la contraseña no son correctos.',
  'auth/wrong-password': 'El email o la contraseña no son correctos.',
  'auth/user-not-found': 'El email o la contraseña no son correctos.',
  'auth/invalid-email': 'Revisá el email.',
  'auth/too-many-requests': 'Hubo demasiados intentos. Esperá unos minutos o creá una contraseña nueva.',
  'auth/network-request-failed': 'No hay conexión. Revisá internet e intentá de nuevo.',
  'auth/user-disabled': 'Este usuario está deshabilitado.',
};

formularioLogin.addEventListener('submit', async (evento) => {
  evento.preventDefault();
  const email = formularioLogin.email.value.trim();
  const clave = formularioLogin.clave.value;
  if (!email || !clave) {
    mostrarErrorLogin('Completá el email y la contraseña.');
    return;
  }
  try {
    await ocupado($('[data-login-enviar]'), () => signInWithEmailAndPassword(auth, email, clave));
    // Si ya había una sesión abierta, onAuthStateChanged no vuelve a dispararse.
    if (!estado.sesion) entrar();
  } catch (error) {
    mostrarErrorLogin(ERRORES_AUTH[error.code] || 'No pudimos ingresar. Probá de nuevo.');
  }
});

$('[data-olvide]').addEventListener('click', async () => {
  const email = formularioLogin.email.value.trim();
  if (!email) {
    mostrarErrorLogin('Escribí tu email arriba y volvé a tocar «¿Olvidaste tu contraseña?».');
    formularioLogin.email.focus();
    return;
  }
  try {
    await sendPasswordResetEmail(auth, email);
  } catch (error) {
    if (error.code === 'auth/invalid-email') return mostrarErrorLogin('Revisá el email.');
  }
  // Mismo mensaje exista o no la cuenta: no se revela qué emails están registrados.
  $('[data-login-error]').hidden = true;
  const ok = $('[data-login-ok]');
  ok.textContent = 'Si ese email tiene acceso, te llegó un correo para crear una contraseña nueva. Revisá también el spam.';
  ok.hidden = false;
});

$('[data-salir]').addEventListener('click', () => signOut(auth));

/* ------------------------------------------------------------------ inicio */

async function iniciar() {
  // En el servidor local simulado (npm run dev:simulado) el ingreso usa el emulador de Auth.
  if (['localhost', '127.0.0.1'].includes(location.hostname)) {
    try {
      const respuesta = await fetch('/__dev/config');
      if (respuesta.ok) {
        const { authEmulator } = await respuesta.json();
        if (authEmulator) connectAuthEmulator(auth, authEmulator, { disableWarnings: true });
      }
    } catch {
      // Sin servidor local simulado: Firebase real.
    }
  }
  onAuthStateChanged(auth, (usuario) => {
    if (usuario) entrar();
    else {
      estado.sesion = null;
      mostrarVista('login');
    }
  });
}

iniciar();
