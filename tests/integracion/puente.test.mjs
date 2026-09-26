// El state que arma api/lib/mercadopago.php lo acepta el puente real de Gokywebs
// (Gokywebsweb/mp-conectar/index.php) y reenvía a /api/mp-conectar.php de esta web.

import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, describe, it } from 'node:test';
import { RAIZ } from '../../scripts/lib/servidor.mjs';

const PUENTE = path.resolve(RAIZ, '..', '..', 'Gokywebsweb', 'mp-conectar', 'index.php');

function stateDesdePhp(secreto) {
  const codigo = `define('MP_CLIENT_SECRET', '${secreto}'); define('SITE_URL', 'https://www.institutoculturapixel.com');
    require '${path.join(RAIZ, 'api', 'lib', 'mercadopago.php').replace(/\\/g, '/')}'; echo mercadoPagoNewState();`;
  return execFileSync('php', ['-r', codigo], { encoding: 'utf8' }).trim();
}

describe('puente "Conectar con Mercado Pago" de gokywebs.com', { skip: !fs.existsSync(PUENTE) && 'no está Gokywebsweb/mp-conectar' }, () => {
  const carpeta = fs.mkdtempSync(path.join(os.tmpdir(), 'puente-'));
  fs.mkdirSync(path.join(carpeta, 'mp-conectar'));
  fs.mkdirSync(path.join(carpeta, 'config'));
  fs.copyFileSync(PUENTE, path.join(carpeta, 'mp-conectar', 'index.php'));
  fs.writeFileSync(path.join(carpeta, 'config', 'mp-oauth-config.php'), "<?php define('MP_OAUTH_CLIENT_SECRET', 'secreto-de-prueba');\n");
  const puerto = 18000 + Math.floor(Math.random() * 1000);
  const servidor = spawn('php', ['-S', `127.0.0.1:${puerto}`, '-t', carpeta], { stdio: 'ignore' });
  after(() => {
    servidor.kill();
    fs.rmSync(carpeta, { recursive: true, force: true });
  });

  async function pedir(consulta) {
    for (let intento = 0; intento < 50; intento += 1) {
      try {
        return await fetch(`http://127.0.0.1:${puerto}/mp-conectar/?${consulta}`, { redirect: 'manual' });
      } catch {
        await new Promise((resolver) => setTimeout(resolver, 100));
      }
    }
    throw new Error('el servidor PHP del puente no arrancó');
  }

  it('reenvía el código a /api/mp-conectar.php de esta web', async () => {
    const state = stateDesdePhp('secreto-de-prueba');
    const respuesta = await pedir(new URLSearchParams({ code: 'TG-123', state }));
    assert.equal(respuesta.status, 303);
    const destino = new URL(respuesta.headers.get('location'));
    assert.equal(destino.origin + destino.pathname, 'https://www.institutoculturapixel.com/api/mp-conectar.php');
    assert.equal(destino.searchParams.get('code'), 'TG-123');
    assert.equal(destino.searchParams.get('state'), state);
  });

  it('rechaza un state firmado con otro secreto', async () => {
    const respuesta = await pedir(new URLSearchParams({ code: 'TG-123', state: stateDesdePhp('otro-secreto') }));
    assert.equal(respuesta.status, 400);
  });
});
