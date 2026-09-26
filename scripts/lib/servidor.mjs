// Servidor local: el sitio y la API en PHP con el servidor embebido (php -S) y el router
// scripts/router-local.php, que imita los .htaccess de Hostinger.
//
// En modo simulado además levanta el simulador de Mercado Pago y siembra los emuladores de
// Firebase (hay que correrlo dentro de `firebase emulators:exec`): el circuito completo
// sin tocar datos reales ni cobrar. Lo usan scripts/servidor-local.mjs y las pruebas.

import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { crearUsuario, guardar } from './emulador.mjs';
import { crearSimuladorMP } from './mp-simulado.mjs';
import { pdfDePrueba } from './pdf-prueba.mjs';

export const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
/** Contraseña del administrador en el emulador de Auth (solo existe en local). */
export const CONTRASENA_LOCAL = 'culturapixel-local';

const datosEbooks = () => JSON.parse(fs.readFileSync(path.join(RAIZ, 'scripts', 'datos-ebooks.json'), 'utf8'));

async function esperarServidor(url, proceso) {
  for (let intento = 0; intento < 100; intento += 1) {
    if (proceso.exitCode !== null) throw new Error('PHP se cerró al arrancar (¿el puerto está ocupado?).');
    try {
      await fetch(url);
      return;
    } catch {
      await new Promise((resolver) => setTimeout(resolver, 100));
    }
  }
  throw new Error(`El servidor PHP no respondió en ${url}`);
}

async function prepararSimulado(entorno, puertoMP) {
  if (!process.env.FIRESTORE_EMULATOR_HOST || !process.env.FIREBASE_AUTH_EMULATOR_HOST) {
    throw new Error('El modo simulado usa los emuladores de Firebase. Ejecutá:  npm run dev:simulado');
  }
  const baseMP = `http://127.0.0.1:${puertoMP}/__mp`;
  const carpetaTmp = path.join(RAIZ, 'tmp');
  Object.assign(entorno, {
    FIREBASE_PROJECT_ID: 'demo-culturapixel',
    MP_API_BASE: baseMP,
    MP_AUTH_URL: `${baseMP}/authorization`,
    MP_CLIENT_ID: 'SIM-CLIENT',
    MP_CLIENT_SECRET: 'sim-secret-culturapixel',
    MP_ACCESS_TOKEN: '',
    MP_WEBHOOK_SECRET: '',
    EBOOKS_DIR: path.join(carpetaTmp, 'ebooks-local'),
    SERVICE_ACCOUNT_PATH: path.join(carpetaTmp, 'sin-cuenta-de-servicio.json'),
    CULTURAPIXEL_TMP: path.join(carpetaTmp, 'php-tmp', crypto.randomBytes(4).toString('hex')),
    LIMITES_DESACTIVADOS: '1',
  });
  process.env.FIREBASE_PROJECT_ID = 'demo-culturapixel';

  // PDF de prueba (en tmp/, nunca en storage/).
  const { admin, ebooks } = datosEbooks();
  fs.mkdirSync(entorno.EBOOKS_DIR, { recursive: true });
  const originales = {};
  for (const ebook of ebooks) {
    originales[ebook.id] = pdfDePrueba({ titulo: `${ebook.titulo} (prueba)`, paginas: 4, rellenoBytes: 5 * 1024 * 1024 });
    fs.writeFileSync(path.join(entorno.EBOOKS_DIR, `${ebook.id}.pdf`), originales[ebook.id]);
  }

  await guardar(`admins/${admin}`, { email: admin, activo: true, rol: 'owner' });
  for (const { id, ...datos } of ebooks) await guardar(`ebooks/${id}`, { ...datos, actualizadoAt: new Date() });
  await crearUsuario(admin, CONTRASENA_LOCAL);

  const simulador = crearSimuladorMP({ base: baseMP, sitio: entorno.SITE_URL, secreto: entorno.MP_CLIENT_SECRET });
  const servidorMP = http.createServer((req, res) => {
    simulador.manejar(req, res, new URL(req.url, baseMP)).catch((error) => {
      console.error('[simulador MP]', error);
      if (!res.headersSent) res.writeHead(500).end();
    });
  });
  await new Promise((resolver) => servidorMP.listen(puertoMP, '127.0.0.1', resolver));
  return { simulador, servidorMP, originales };
}

export async function iniciarServidor({ simulado = false, puerto = 3000 } = {}) {
  const sitio = `http://127.0.0.1:${puerto}`;
  const entorno = { ...process.env, SITE_URL: sitio };
  const extra = simulado ? await prepararSimulado(entorno, puerto + 1) : {};
  // Límites de subida como los de Hostinger (el php.ini local trae 2 MB).
  const opciones = ['-d', 'upload_max_filesize=256M', '-d', 'post_max_size=260M', '-d', 'memory_limit=512M'];
  const php = spawn('php', [...opciones, '-S', `127.0.0.1:${puerto}`, 'scripts/router-local.php'], { cwd: RAIZ, env: entorno, stdio: ['ignore', 'ignore', 'pipe'] });
  php.stderr.on('data', (linea) => {
    const texto = String(linea);
    // El servidor embebido anota cada pedido en stderr: solo se muestran los errores de PHP.
    if (/PHP (Fatal|Warning|Parse|Notice|Deprecated)|\[error\]/i.test(texto)) process.stderr.write(texto);
  });
  await esperarServidor(`${sitio}/robots.txt`, php);
  return {
    sitio,
    simulador: extra.simulador || null,
    originales: extra.originales || {},
    carpetaEbooks: entorno.EBOOKS_DIR || path.join(RAIZ, 'storage', 'ebooks'),
    cerrar: async () => {
      php.kill();
      await new Promise((resolver) => (extra.servidorMP ? extra.servidorMP.close(() => resolver()) : resolver()));
    },
  };
}
