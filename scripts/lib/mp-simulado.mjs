// Simulador local de Mercado Pago para probar el circuito completo sin dinero real:
// preferencias, página de checkout (aprobar / rechazar), pagos, búsqueda, webhook,
// OAuth ("Conectar con Mercado Pago") y /users/me. Imita las respuestas de la API real
// en lo que usan las funciones de /api. Solo lo monta scripts/servidor-local.mjs --simulado.

import crypto from 'node:crypto';

const escapar = (texto) => String(texto ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

async function leerCuerpo(req) {
  const partes = [];
  for await (const parte of req) partes.push(parte);
  return Buffer.concat(partes).toString('utf8');
}

function json(res, status, datos) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(JSON.stringify(datos));
}

function pagina(res, titulo, cuerpo) {
  res.statusCode = 200;
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.end(`<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${escapar(titulo)}</title><style>
body{margin:0;font-family:system-ui,sans-serif;background:#ededed;color:#333}
header{background:#ffe600;padding:14px 20px;font-weight:700}
main{max-width:460px;margin:28px auto;background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.aviso{font-size:13px;background:#fff7d6;border:1px solid #f0d77a;border-radius:6px;padding:8px 10px;margin-bottom:18px}
.total{font-size:28px;font-weight:700;margin:6px 0 20px}
button{width:100%;padding:14px;border:0;border-radius:6px;font-size:16px;font-weight:600;cursor:pointer;margin-top:10px}
.ok{background:#009ee3;color:#fff}.mal{background:#fff;color:#c0392b;border:1px solid #c0392b}.volver{background:#fff;color:#009ee3}
label{display:flex;gap:8px;align-items:center;font-size:14px;margin-top:12px}
</style></head><body><header>mercado pago · simulador local</header><main>${cuerpo}</main></body></html>`);
}

export function crearSimuladorMP({ base, sitio, secreto }) {
  const preferencias = new Map();
  const pagos = new Map();
  const codigosOAuth = new Map();
  let proximoPago = 1_000_000_001;

  function crearPago(preferencia, status, { monto } = {}) {
    const id = proximoPago++;
    const total = preferencia.items.reduce((suma, item) => suma + Number(item.unit_price) * Number(item.quantity || 1), 0);
    const pago = {
      id,
      status,
      status_detail: status === 'approved' ? 'accredited' : 'cc_rejected_other_reason',
      external_reference: preferencia.external_reference,
      transaction_amount: monto ?? total,
      currency_id: preferencia.items[0]?.currency_id || 'ARS',
      payment_method_id: 'visa',
      payment_type_id: 'credit_card',
      date_created: new Date().toISOString(),
      preference_id: preferencia.id,
      token: preferencia.token,
    };
    pagos.set(String(id), pago);
    return pago;
  }

  async function notificar(preferencia, pago) {
    if (!preferencia.notification_url) return;
    const url = new URL(preferencia.notification_url);
    url.searchParams.set('data.id', String(pago.id));
    url.searchParams.set('type', 'payment');
    await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'payment.created', api_version: 'v1', data: { id: String(pago.id) }, type: 'payment', live_mode: true, date_created: pago.date_created }),
    }).catch((error) => console.error('[simulador] webhook', error.message));
  }

  function vistaPago(pago) {
    const { token: _token, ...publico } = pago;
    return publico;
  }

  async function manejar(req, res, url) {
    const ruta = url.pathname.replace(/^\/__mp/, '') || '/';
    const conToken = /^Bearer\s+\S+/.test(req.headers.authorization || '');

    if (req.method === 'POST' && ruta === '/checkout/preferences') {
      if (!conToken) return json(res, 401, { message: 'invalid_token' });
      const cuerpo = JSON.parse(await leerCuerpo(req));
      if (!cuerpo.items?.length || !cuerpo.external_reference) return json(res, 400, { message: 'invalid preference' });
      const id = `SIM-${crypto.randomBytes(6).toString('hex')}`;
      preferencias.set(id, { ...cuerpo, id, token: req.headers.authorization });
      return json(res, 201, { id, init_point: `${base}/checkout?pref=${id}` });
    }
    if (req.method === 'GET' && ruta === '/v1/payments/search') {
      if (!conToken) return json(res, 401, { message: 'invalid_token' });
      const referencia = url.searchParams.get('external_reference');
      const resultados = [...pagos.values()].filter((pago) => pago.external_reference === referencia).sort((a, b) => b.id - a.id);
      return json(res, 200, { results: resultados.map(vistaPago), paging: { total: resultados.length } });
    }
    const pagoRuta = /^\/v1\/payments\/(\d+)$/.exec(ruta);
    if (req.method === 'GET' && pagoRuta) {
      if (!conToken) return json(res, 401, { message: 'invalid_token' });
      const pago = pagos.get(pagoRuta[1]);
      return pago ? json(res, 200, vistaPago(pago)) : json(res, 404, { message: 'Payment not found' });
    }
    if (req.method === 'POST' && ruta === '/oauth/token') {
      const cuerpo = JSON.parse(await leerCuerpo(req));
      if (cuerpo.grant_type === 'authorization_code' && !codigosOAuth.delete(cuerpo.code)) return json(res, 400, { message: 'invalid_grant' });
      return json(res, 200, {
        access_token: `APP_USR-SIM-${crypto.randomBytes(8).toString('hex')}`,
        refresh_token: `TG-SIM-${crypto.randomBytes(8).toString('hex')}`,
        public_key: 'APP_USR-SIM-PUBLIC',
        user_id: 123456789,
        expires_in: 15_552_000,
        live_mode: true,
      });
    }
    if (req.method === 'GET' && ruta === '/users/me') {
      return json(res, 200, { id: 123456789, nickname: 'CULTURAPIXEL.SIMULADO', email: 'ventas@simulado.test' });
    }

    if (req.method === 'GET' && ruta === '/checkout') {
      const preferencia = preferencias.get(url.searchParams.get('pref') || '');
      if (!preferencia) return pagina(res, 'Link vencido', '<p>Este link de pago no existe o venció.</p>');
      const item = preferencia.items[0];
      return pagina(res, 'Pagar', `
        <p class="aviso">Simulador local: acá no se cobra nada.</p>
        <p>${escapar(item.title)}</p>
        <p class="total">$ ${Number(item.unit_price).toLocaleString('es-AR')}</p>
        <p>Comprador: ${escapar(preferencia.payer?.email)}</p>
        <form method="post" action="${base}/checkout/pagar">
          <input type="hidden" name="pref" value="${escapar(preferencia.id)}">
          <label><input type="checkbox" name="webhook" value="1" checked> Enviar la notificación (webhook)</label>
          <button class="ok" name="resultado" value="approved">Pagar (se aprueba)</button>
          <button class="mal" name="resultado" value="rejected">Pagar (se rechaza)</button>
          <button class="volver" name="resultado" value="volver">Volver al sitio sin pagar</button>
        </form>`);
    }
    if (req.method === 'POST' && ruta === '/checkout/pagar') {
      const datos = new URLSearchParams(await leerCuerpo(req));
      const preferencia = preferencias.get(datos.get('pref') || '');
      if (!preferencia) return pagina(res, 'Link vencido', '<p>Este link de pago no existe o venció.</p>');
      const resultado = datos.get('resultado');
      const vuelta = new URL(resultado === 'approved' ? preferencia.back_urls.success : preferencia.back_urls.failure);
      if (resultado === 'volver') {
        for (const [clave, valor] of Object.entries({ collection_id: 'null', collection_status: 'null', payment_id: 'null', status: 'null', external_reference: preferencia.external_reference, preference_id: preferencia.id })) vuelta.searchParams.set(clave, valor);
      } else {
        const pago = crearPago(preferencia, resultado === 'approved' ? 'approved' : 'rejected');
        if (datos.get('webhook') === '1') setTimeout(() => notificar(preferencia, pago), 150);
        for (const [clave, valor] of Object.entries({
          collection_id: pago.id, collection_status: pago.status, payment_id: pago.id, status: pago.status,
          external_reference: pago.external_reference, payment_type: 'credit_card', merchant_order_id: '99' + pago.id,
          preference_id: preferencia.id, site_id: 'MLA', processing_mode: 'aggregator', merchant_account_id: 'null',
        })) vuelta.searchParams.set(clave, String(valor));
      }
      res.statusCode = 302;
      res.setHeader('Location', vuelta.href);
      return res.end();
    }

    if (req.method === 'GET' && ruta === '/authorization') {
      const state = url.searchParams.get('state') || '';
      return pagina(res, 'Autorizar', `
        <p class="aviso">Simulador local del "Conectar con Mercado Pago".</p>
        <p><strong>Gokywebs</strong> quiere cobrar en tu cuenta de Mercado Pago.</p>
        <form method="post" action="${base}/authorization">
          <input type="hidden" name="state" value="${escapar(state)}">
          <button class="ok" name="decision" value="si">Autorizar</button>
          <button class="volver" name="decision" value="no">Cancelar</button>
        </form>`);
    }
    if (req.method === 'POST' && ruta === '/authorization') {
      // Hace lo mismo que el puente de gokywebs.com/mp-conectar/: valida la firma del state.
      const datos = new URLSearchParams(await leerCuerpo(req));
      const state = datos.get('state') || '';
      const [hostB64, nonce, firma] = state.split('.');
      const esperada = crypto.createHmac('sha256', secreto).update(`${hostB64}.${nonce}`).digest('base64url');
      if (!firma || firma !== esperada) return pagina(res, 'Error', '<p>El enlace de vuelta de Mercado Pago no es válido.</p>');
      const destino = new URL(`${sitio}/api/mp-conectar.php`);
      destino.searchParams.set('state', state);
      if (datos.get('decision') === 'si') {
        const codigo = `SIMCODE-${crypto.randomBytes(6).toString('hex')}`;
        codigosOAuth.set(codigo, true);
        destino.searchParams.set('code', codigo);
      } else {
        destino.searchParams.set('error', 'access_denied');
      }
      res.statusCode = 303;
      res.setHeader('Location', destino.href);
      return res.end();
    }
    return json(res, 404, { message: 'ruta del simulador inexistente' });
  }

  return { manejar, crearPago, notificar, pagos, preferencias };
}
