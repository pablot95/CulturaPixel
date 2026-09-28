// Versiona las URLs de los archivos estáticos: /ebooks/ebooks.css → /ebooks/ebooks.css?v=<hash>.
//
// Hostinger sirve CSS, JS e imágenes con caché de 7 días, y el HTML no se cachea. Si un
// archivo cambia sin cambiar de nombre, quien ya visitó la web recibe la página nueva con el
// CSS o las imágenes viejas. Con el hash del contenido en la URL, cada cambio es otra URL.
//
//   npm run versionar                      actualiza los HTML y los import entre módulos JS
//   node scripts/versionar.mjs --comprobar solo revisa (corre en npm test): falla si algo quedó viejo
//
// Las rutas que arma la API (tapa del libro en el panel y en la página de gracias) se
// versionan en PHP con urlConVersion() de api/lib/common.php.

import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DOMINIO = 'https://www.institutoculturapixel.com';
const CARPETAS_EXCLUIDAS = new Set(['.git', '.claude', '.vercel', 'node_modules', 'tests', 'scripts', 'tmp', 'storage', 'secretos']);
const ESTATICOS = /\.(css|m?js|webp|avif|png|jpe?g|gif|svg|ico)$/i;
const ATRIBUTOS = /(\s(?:href|src|data-grande|content)=)(["'])([^"']*)\2/g;
const IMPORTS = /(\bfrom\s*|\bimport\s*\(\s*|\bimport\s+)(["'])(\.{1,2}\/[^"'?#]+\.m?js)(?:\?v=[0-9a-f]+)?\2/g;

const comprobar = process.argv.includes('--comprobar');
const avisos = new Set();
const huellas = new Map();
const versionados = new Map();

function buscar(carpeta, extension) {
  return readdirSync(carpeta, { withFileTypes: true }).flatMap((entrada) => {
    const ruta = join(carpeta, entrada.name);
    if (entrada.isDirectory()) return CARPETAS_EXCLUIDAS.has(entrada.name) ? [] : buscar(ruta, extension);
    return entrada.name.endsWith(extension) ? [ruta] : [];
  });
}

/** Contenido final de un módulo JS: sus import relativos también llevan versión. */
function moduloVersionado(archivo) {
  if (!versionados.has(archivo)) {
    const original = readFileSync(archivo, 'utf8');
    versionados.set(archivo, original.replace(IMPORTS, (entero, antes, comilla, ruta) => {
      const destino = resolve(dirname(archivo), ruta);
      if (!existsSync(destino)) {
        avisos.add(`${relative(RAIZ, archivo)}: no existe ${ruta}`);
        return entero;
      }
      return `${antes}${comilla}${ruta}?v=${huella(destino)}${comilla}`;
    }));
  }
  return versionados.get(archivo);
}

function huella(archivo) {
  if (!huellas.has(archivo)) {
    const contenido = /\.m?js$/i.test(archivo) ? moduloVersionado(archivo) : readFileSync(archivo);
    huellas.set(archivo, createHash('sha1').update(contenido).digest('hex').slice(0, 10));
  }
  return huellas.get(archivo);
}

function paginaVersionada(pagina) {
  return readFileSync(pagina, 'utf8').replace(ATRIBUTOS, (entero, atributo, comilla, valor) => {
    const absoluta = valor.startsWith(`${DOMINIO}/`);
    const ruta = absoluta ? valor.slice(DOMINIO.length) : valor;
    const [camino, consulta = ''] = ruta.split('?');
    // Solo archivos propios, sin otros parámetros; content="" solo si es una URL (og:image).
    if (!camino || /^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/i.test(camino) || (consulta && !/^v=[0-9a-f]+$/.test(consulta))) return entero;
    if (!ESTATICOS.test(camino) || (atributo.includes('content') && !camino.startsWith('/'))) return entero;
    let archivo;
    try {
      archivo = camino.startsWith('/') ? join(RAIZ, decodeURIComponent(camino)) : resolve(dirname(pagina), decodeURIComponent(camino));
    } catch {
      return entero;
    }
    if (!existsSync(archivo) || !statSync(archivo).isFile()) {
      avisos.add(`${relative(RAIZ, pagina)}: no existe ${camino}`);
      return entero;
    }
    return `${atributo}${comilla}${absoluta ? DOMINIO : ''}${camino}?v=${huella(archivo)}${comilla}`;
  });
}

const cambios = [];
for (const archivo of buscar(RAIZ, '.js')) {
  if (moduloVersionado(archivo) !== readFileSync(archivo, 'utf8')) cambios.push([archivo, moduloVersionado(archivo)]);
}
for (const pagina of buscar(RAIZ, '.html')) {
  const nuevo = paginaVersionada(pagina);
  if (nuevo !== readFileSync(pagina, 'utf8')) cambios.push([pagina, nuevo]);
}

for (const aviso of avisos) console.warn(`Aviso: ${aviso}`);
if (comprobar) {
  if (cambios.length) {
    console.error(`✖ versiones desactualizadas en: ${cambios.map(([archivo]) => relative(RAIZ, archivo)).join(', ')}`);
    console.error('  Corré "npm run versionar" y commiteá los cambios.');
    process.exit(1);
  }
  console.log('✔ versiones de CSS, JS e imágenes al día');
} else {
  for (const [archivo, contenido] of cambios) {
    writeFileSync(archivo, contenido);
    console.log(`versionado: ${relative(RAIZ, archivo)}`);
  }
  if (!cambios.length) console.log('Todo al día.');
}
