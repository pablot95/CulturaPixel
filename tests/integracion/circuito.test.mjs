// Circuito completo del backend PHP contra los emuladores de Firebase y el simulador de
// Mercado Pago:  npm run test:integracion
// Cubre: conexión OAuth, compra, vuelta del checkout, webhook, descarga, límite de
// descargas, panel (listados, links nuevos, precios, subida del PDF) y desconexión.

import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';
import { crearUsuario, guardar, ingresar, leer } from '../../scripts/lib/emulador.mjs';
import { pdfDePrueba } from '../../scripts/lib/pdf-prueba.mjs';
import { CONTRASENA_LOCAL, iniciarServidor } from '../../scripts/lib/servidor.mjs';

const PUERTO = 3900 + Math.floor(Math.random() * 90) * 2;
const ADMIN = 'contacto@institutoculturapixel.com';
const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
let entorno;
let tokenAdmin;

async function api(ruta, { metodo = 'POST', cuerpo, token, headers = {} } = {}) {
  const respuesta = await fetch(`${entorno.sitio}${ruta}`, {
    method: metodo,
    redirect: 'manual',
    headers: { ...(cuerpo ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...headers },
    body: cuerpo ? JSON.stringify(cuerpo) : undefined,
  });
  const texto = await respuesta.text();
  let datos;
  try {
    datos = JSON.parse(texto);
  } catch {
    datos = texto;
  }
  return { status: respuesta.status, datos, headers: respuesta.headers };
}

const admin = (accion, extra = {}) => api('/api/ebooks/admin.php', { cuerpo: { accion, ...extra }, token: tokenAdmin });
const comprarConDatos = (datos) => api('/api/ebooks/comprar.php', { cuerpo: { ebookId: 'quinceaneras', nombre: 'Sofía Pérez', email: 'sofia@correo.test', ...datos } });

async function comprar(email = 'sofia@correo.test') {
  const { status, datos } = await comprarConDatos({ email });
  assert.equal(status, 201, JSON.stringify(datos));
  return datos;
}

/** Paga en el checkout simulado y devuelve la URL de vuelta (con los parámetros de MP). */
async function pagar(urlPago, resultado, { webhook = false } = {}) {
  const url = new URL(urlPago);
  const cuerpo = new URLSearchParams({ pref: url.searchParams.get('pref'), resultado, ...(webhook ? { webhook: '1' } : {}) });
  const respuesta = await fetch(`${url.origin}/__mp/checkout/pagar`, { method: 'POST', body: cuerpo, redirect: 'manual' });
  assert.equal(respuesta.status, 302);
  return new URL(respuesta.headers.get('location'));
}

async function esperar(condicion, intentos = 60) {
  for (let i = 0; i < intentos; i += 1) {
    if (await condicion()) return true;
    await new Promise((resolver) => setTimeout(resolver, 100));
  }
  return false;
}

async function subirPdf(contenido, { token = tokenAdmin, id = 'quinceaneras', nombre = 'libro.pdf' } = {}) {
  const formulario = new FormData();
  formulario.append('id', id);
  formulario.append('archivo', new Blob([contenido], { type: 'application/pdf' }), nombre);
  const respuesta = await fetch(`${entorno.sitio}/api/ebooks/admin-subir.php`, {
    method: 'POST',
    headers: token ? { Authorization: `Bearer ${token}` } : {},
    body: formulario,
  });
  return { status: respuesta.status, datos: await respuesta.json().catch(() => ({})) };
}

before(async () => {
  entorno = await iniciarServidor({ simulado: true, puerto: PUERTO });
  tokenAdmin = await ingresar(ADMIN, CONTRASENA_LOCAL);
});

after(async () => {
  await entorno?.cerrar();
});

describe('circuito de venta de ebooks (PHP)', () => {
  let compra;

  it('el catálogo informa precio y disponibilidad', async () => {
    const { status, datos } = await api('/api/ebooks/catalogo.php', { metodo: 'GET' });
    assert.equal(status, 200);
    const ebook = datos.ebooks.find((item) => item.id === 'quinceaneras');
    assert.equal(ebook.precio, 14900);
    assert.equal(ebook.disponible, true);
  });

  it('sin Mercado Pago conectado no deja comprar', async () => {
    const { status, datos } = await comprarConDatos({});
    assert.equal(status, 503);
    assert.equal(datos.codigo, 'sin_mercadopago');
  });

  it('el panel exige un administrador', async () => {
    assert.equal((await api('/api/ebooks/admin.php', { cuerpo: { accion: 'sesion' } })).status, 401);
    assert.equal((await api('/api/ebooks/admin.php', { cuerpo: { accion: 'sesion' }, token: 'token-falso' })).status, 401);
    await crearUsuario('curioso@correo.test', 'otra-clave-123');
    const ajeno = await ingresar('curioso@correo.test', 'otra-clave-123');
    assert.equal((await api('/api/ebooks/admin.php', { cuerpo: { accion: 'sesion' }, token: ajeno })).status, 403);
    assert.equal((await subirPdf(pdfDePrueba(), { token: ajeno })).status, 403);
    const { status, datos } = await admin('sesion');
    assert.equal(status, 200);
    assert.equal(datos.admin.email, ADMIN);
    assert.equal(datos.mp.conectado, false);
    assert.equal(datos.ebooks[0].archivo.bytes, entorno.originales.quinceaneras.length);
    assert.equal((await admin('inventada')).status, 400);
  });

  it('la vuelta de OAuth sin la cookie del navegador que la inició se rechaza', async () => {
    const { datos } = await admin('mp-conectar');
    const state = new URL(datos.url).searchParams.get('state');
    const { status, headers } = await api(`/api/mp-conectar.php?code=X&state=${encodeURIComponent(state)}`, { metodo: 'GET' });
    assert.equal(status, 303);
    assert.match(headers.get('location'), /\/admin\/\?mp=vencido$/);
  });

  it('conecta la cuenta de Mercado Pago por OAuth (puente simulado)', async () => {
    const { status, datos, headers } = await admin('mp-conectar');
    assert.equal(status, 200);
    const cookie = headers.getSetCookie().find((valor) => valor.startsWith('mp_oauth_state='));
    assert.match(cookie, /httponly/i);
    assert.match(cookie, /path=\/api\//i);
    assert.match(cookie, /samesite=lax/i);
    const autorizar = new URL(datos.url);
    const state = autorizar.searchParams.get('state');
    // El vendedor autoriza en Mercado Pago y el puente lo manda de vuelta a la web.
    const autorizacion = await fetch(`${autorizar.origin}/__mp/authorization`, { method: 'POST', body: new URLSearchParams({ state, decision: 'si' }), redirect: 'manual' });
    const vuelta = new URL(autorizacion.headers.get('location'));
    assert.equal(vuelta.pathname, '/api/mp-conectar.php');
    const final = await api(`${vuelta.pathname}${vuelta.search}`, { metodo: 'GET', headers: { Cookie: cookie.split(';')[0] } });
    assert.equal(final.status, 303);
    assert.match(final.headers.get('location'), /\/admin\/\?mp=conectado$/);
    const config = await leer('config/mercadopago');
    assert.match(config.accessToken, /^APP_USR-SIM-/);
    assert.equal(config.oauthState, '');
    const repetido = await api(`${vuelta.pathname}${vuelta.search}`, { metodo: 'GET', headers: { Cookie: cookie.split(';')[0] } });
    assert.match(repetido.headers.get('location'), /mp=vencido$/, 'el state es de un solo uso');
    const sesion = await admin('sesion');
    assert.equal(sesion.datos.mp.conectado, true);
    assert.equal(sesion.datos.mp.cuentaNombre, 'CULTURAPIXEL.SIMULADO');
    assert.equal(JSON.stringify(sesion.datos).includes('APP_USR-SIM-'), false, 'el token no sale del servidor');
  });

  it('valida los datos del comprador', async () => {
    const corto = await comprarConDatos({ nombre: 'S' });
    assert.equal(corto.status, 422);
    assert.equal(corto.datos.codigo, 'nombre');
    assert.equal((await comprarConDatos({ email: 'sofia@correo' })).datos.codigo, 'email');
    assert.equal((await comprarConDatos({ ebookId: 'no-existe' })).status, 404);
    assert.equal((await api('/api/ebooks/comprar.php', { metodo: 'POST', headers: { 'Content-Type': 'application/json' } })).status, 422);
  });

  it('crea la venta pendiente y la preferencia de pago', async () => {
    compra = await comprar();
    assert.match(compra.codigo, /^[A-HJ-NP-Z2-9]{8}$/);
    assert.match(compra.url, /\/__mp\/checkout\?pref=SIM-/);
    const venta = await leer(`ventas/${compra.codigo}`);
    assert.equal(venta.estado, 'pendiente');
    assert.equal(venta.monto, 14900);
    assert.equal(venta.compradorEmail, 'sofia@correo.test');
    assert.equal(JSON.stringify(venta).includes(compra.clave), false, 'la clave no se guarda en claro');
    const preferencia = entorno.simulador.preferencias.get(new URL(compra.url).searchParams.get('pref'));
    assert.equal(preferencia.items[0].unit_price, 14900);
    assert.equal(preferencia.external_reference, compra.codigo);
    assert.equal(preferencia.notification_url, `${entorno.sitio}/api/ebooks/webhook.php?source_news=webhooks`);
  });

  it('antes de pagar no hay descarga', async () => {
    const estado = await api('/api/ebooks/estado.php', { cuerpo: { orden: compra.codigo, clave: compra.clave } });
    assert.equal(estado.datos.estado, 'pendiente');
    assert.equal(estado.datos.descarga, '');
    const descarga = await fetch(`${entorno.sitio}/api/ebooks/descargar.php?orden=${compra.codigo}&clave=${compra.clave}`);
    assert.equal(descarga.status, 403);
    assert.match(await descarga.text(), /todavía no está confirmado/);
  });

  it('una clave equivocada no revela la compra', async () => {
    assert.equal((await api('/api/ebooks/estado.php', { cuerpo: { orden: compra.codigo, clave: 'x'.repeat(32) } })).status, 404);
    assert.equal((await fetch(`${entorno.sitio}/api/ebooks/descargar.php?orden=${compra.codigo}&clave=${'x'.repeat(32)}`)).status, 404);
  });

  it('al volver del checkout aprobado se confirma aunque el webhook no haya llegado', async () => {
    const vuelta = await pagar(compra.url, 'approved', { webhook: false });
    assert.equal(vuelta.pathname, '/ebooks/gracias/');
    assert.equal(vuelta.searchParams.get('orden'), compra.codigo);
    assert.equal(vuelta.searchParams.get('clave'), compra.clave);
    const { datos } = await api('/api/ebooks/estado.php', { cuerpo: { orden: compra.codigo, clave: compra.clave, pagoId: vuelta.searchParams.get('payment_id') } });
    assert.equal(datos.estado, 'aprobado');
    assert.equal(datos.nombre, 'Sofía');
    assert.equal(datos.descargas.restantes, 10);
    assert.match(datos.descarga, /^\/api\/ebooks\/descargar\.php\?orden=/);
    const venta = await leer(`ventas/${compra.codigo}`);
    assert.equal(venta.ultimoOrigen, 'retorno');
    assert.equal(venta.mesAprobacion.length, 7);
  });

  it('descarga el PDF completo, con nombre y sin cache', async () => {
    const respuesta = await fetch(`${entorno.sitio}/api/ebooks/descargar.php?orden=${compra.codigo}&clave=${encodeURIComponent(compra.clave)}`);
    assert.equal(respuesta.status, 200);
    assert.equal(respuesta.headers.get('content-type'), 'application/pdf');
    assert.match(respuesta.headers.get('content-disposition'), /^attachment; filename="Quinceaneras - 100 ideas para tu sesion de fotos - Cultura Pixel\.pdf"/);
    assert.equal(respuesta.headers.get('cache-control'), 'private, no-store');
    const pdf = Buffer.from(await respuesta.arrayBuffer());
    assert.ok(pdf.length > 5 * 1024 * 1024, 'archivo de más de 5 MB');
    assert.equal(crypto.createHash('sha256').update(pdf).digest('hex'), crypto.createHash('sha256').update(entorno.originales.quinceaneras).digest('hex'));
    assert.equal((await leer(`ventas/${compra.codigo}`)).descargas, 1);
  });

  it('un segundo pedido inmediato no consume otra descarga; ver=1 lo abre en el navegador', async () => {
    const respuesta = await fetch(`${entorno.sitio}/api/ebooks/descargar.php?orden=${compra.codigo}&clave=${encodeURIComponent(compra.clave)}&ver=1`);
    assert.equal(respuesta.status, 200);
    assert.match(respuesta.headers.get('content-disposition'), /^inline;/);
    await respuesta.arrayBuffer();
    assert.equal((await leer(`ventas/${compra.codigo}`)).descargas, 1);
  });

  it('corta al llegar al límite de descargas', async () => {
    await guardar(`ventas/${compra.codigo}`, { descargas: 10, ultimaDescargaAt: new Date(Date.now() - 3600_000) });
    const respuesta = await fetch(`${entorno.sitio}/api/ebooks/descargar.php?orden=${compra.codigo}&clave=${encodeURIComponent(compra.clave)}`);
    assert.equal(respuesta.status, 403);
    assert.match(await respuesta.text(), /límite de descargas/);
  });

  it('el panel reinicia las descargas y genera un link nuevo (el viejo deja de andar)', async () => {
    const reinicio = await admin('venta-reiniciar', { codigo: compra.codigo });
    assert.equal(reinicio.datos.venta.descargas.usadas, 0);
    const link = await admin('venta-link', { codigo: compra.codigo });
    const nuevo = new URL(link.datos.url);
    assert.equal(nuevo.searchParams.get('orden'), compra.codigo);
    assert.equal((await api('/api/ebooks/estado.php', { cuerpo: { orden: compra.codigo, clave: compra.clave } })).status, 404);
    const conNueva = await api('/api/ebooks/estado.php', { cuerpo: { orden: compra.codigo, clave: nuevo.searchParams.get('clave') } });
    assert.equal(conNueva.datos.estado, 'aprobado');
  });

  it('el webhook aprueba sin que el comprador vuelva a la web', async () => {
    const segunda = await comprar('lucia@correo.test');
    await pagar(segunda.url, 'approved', { webhook: true });
    assert.equal(await esperar(async () => (await leer(`ventas/${segunda.codigo}`)).estado === 'aprobado'), true);
    assert.equal((await leer(`ventas/${segunda.codigo}`)).ultimoOrigen, 'webhook');
  });

  it('un pago rechazado deja la venta rechazada y sin descarga', async () => {
    const tercera = await comprar('rechazo@correo.test');
    const vuelta = await pagar(tercera.url, 'rejected');
    const { datos } = await api('/api/ebooks/estado.php', { cuerpo: { orden: tercera.codigo, clave: tercera.clave, pagoId: vuelta.searchParams.get('payment_id') } });
    assert.equal(datos.estado, 'rechazado');
    assert.equal(datos.descarga, '');
  });

  it('el webhook ignora lo que no es un pago de una venta', async () => {
    assert.equal((await api('/api/ebooks/webhook.php?type=merchant_order&data.id=123', { cuerpo: { type: 'merchant_order' } })).datos, 'ignorado');
    assert.equal((await api('/api/ebooks/webhook.php?type=payment&data.id=987654321', { cuerpo: { type: 'payment', data: { id: '987654321' } } })).datos, 'pago inexistente');
    assert.equal((await api('/api/ebooks/webhook.php', { metodo: 'GET' })).status, 405);
  });

  it('el panel lista, filtra y busca ventas, y resume lo vendido', async () => {
    const todas = await admin('ventas');
    assert.equal(todas.status, 200);
    assert.ok(todas.datos.ventas.length >= 3);
    const aprobadas = await admin('ventas', { estado: 'aprobado' });
    assert.ok(aprobadas.datos.ventas.length >= 2);
    assert.ok(aprobadas.datos.ventas.every((venta) => venta.estado === 'aprobado'));
    assert.equal((await admin('ventas', { buscar: 'LUCIA@correo.test' })).datos.ventas.length, 1);
    assert.equal((await admin('ventas', { buscar: compra.codigo.toLowerCase() })).datos.ventas[0].codigo, compra.codigo);
    const { datos } = await admin('resumen');
    assert.equal(datos.resumen.aprobadas.cantidad, 2);
    assert.equal(datos.resumen.aprobadas.total, 29800);
    assert.equal(datos.resumen.mes.cantidad, 2);
  });

  it('pagina el listado de ventas sin repetir', async () => {
    const base = Date.now();
    for (let i = 0; i < 26; i += 1) {
      const codigo = [...crypto.randomBytes(8)].map((byte) => ALFABETO[byte % 32]).join('');
      // Dos ventas por minuto con la misma hora: la paginación desempata por código.
      await guardar(`ventas/${codigo}`, { estado: 'cancelado', monto: 1, moneda: 'ARS', creadaAt: new Date(base - (Math.floor(i / 2) + 1) * 60_000), ebookId: 'quinceaneras' });
    }
    const primera = await admin('ventas');
    assert.equal(primera.datos.ventas.length, 25);
    assert.ok(primera.datos.siguiente);
    const segunda = await admin('ventas', { despuesDe: primera.datos.siguiente });
    const vistos = new Set(primera.datos.ventas.map((venta) => venta.codigo));
    assert.ok(segunda.datos.ventas.length > 0);
    assert.ok(segunda.datos.ventas.every((venta) => !vistos.has(venta.codigo)));
  });

  it('un cambio de precio en el panel se cobra en la próxima compra', async () => {
    assert.equal((await admin('ebook-guardar', { id: 'quinceaneras', precio: 16500 })).datos.ebook.precio, 16500);
    assert.equal((await admin('ebook-guardar', { id: 'quinceaneras', precio: 99.5 })).status, 422);
    const nueva = await comprar('precio@correo.test');
    const preferencia = entorno.simulador.preferencias.get(new URL(nueva.url).searchParams.get('pref'));
    assert.equal(preferencia.items[0].unit_price, 16500);
    assert.equal((await api('/api/ebooks/catalogo.php', { metodo: 'GET' })).datos.ebooks[0].precio, 16500);
  });

  it('pausar la venta desde el panel bloquea la compra', async () => {
    await admin('ebook-guardar', { id: 'quinceaneras', activo: false });
    const { status, datos } = await comprarConDatos({});
    assert.equal(status, 409);
    assert.equal(datos.codigo, 'no_disponible');
    await admin('ebook-guardar', { id: 'quinceaneras', activo: true, precio: 14900 });
  });

  it('el panel sube y reemplaza el PDF (solo PDFs)', async () => {
    assert.equal((await subirPdf(Buffer.from('esto no es un pdf, es texto plano con más de un kilobyte '.repeat(40)))).status, 422);
    const nuevo = pdfDePrueba({ titulo: 'Versión corregida', paginas: 2, rellenoBytes: 2048 });
    const { status, datos } = await subirPdf(nuevo);
    assert.equal(status, 200, JSON.stringify(datos));
    assert.equal(datos.ebook.archivo.bytes, nuevo.length);
    assert.deepEqual(fs.readFileSync(path.join(entorno.carpetaEbooks, 'quinceaneras.pdf')), nuevo);
    assert.equal((await leer('ebooks/quinceaneras')).archivoSha256, crypto.createHash('sha256').update(nuevo).digest('hex'));
    assert.equal((await subirPdf(entorno.originales.quinceaneras)).status, 200);
  });

  it('desconectar Mercado Pago corta las compras', async () => {
    assert.equal((await admin('mp-desconectar')).datos.mp.conectado, false);
    assert.equal((await leer('config/mercadopago')).accessToken, '');
    assert.equal((await comprarConDatos({})).status, 503);
  });

  it('los archivos privados y de desarrollo no se sirven', async () => {
    const rutas = ['/api/lib/common.php', '/api/config.php', '/api/service-account.json', '/api/mercadopago.local.php', '/storage/ebooks/quinceaneras.pdf', '/scripts/router-local.php', '/tests/php/ventas.test.php', '/package.json', '/firebase.json'];
    for (const ruta of rutas) assert.equal((await fetch(`${entorno.sitio}${ruta}`)).status, 404, ruta);
    assert.equal((await fetch(`${entorno.sitio}/quinceaneras`, { redirect: 'manual' })).headers.get('location'), '/ebooks/quinceaneras/');
  });
});
