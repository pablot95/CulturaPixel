// Sitio + API en PHP en http://127.0.0.1:3000
//
//   npm run dev            → Firestore y Mercado Pago reales (usa api/service-account.json
//                            y api/mercadopago.local.php, que no se versionan)
//   npm run dev:simulado   → emuladores de Firebase + simulador de Mercado Pago: el circuito
//                            completo sin tocar datos reales ni cobrar
//
// En modo simulado el panel (/admin/) entra con contacto@institutoculturapixel.com y la
// contraseña CONTRASENA_LOCAL de scripts/lib/servidor.mjs (solo existe en el emulador).

import { CONTRASENA_LOCAL, iniciarServidor } from './lib/servidor.mjs';

const argumentos = process.argv.slice(2);
const simulado = argumentos.includes('--simulado');
const indicePuerto = argumentos.indexOf('--puerto');
const puerto = (indicePuerto >= 0 && Number(argumentos[indicePuerto + 1])) || Number(process.env.PORT) || 3000;

try {
  const { sitio } = await iniciarServidor({ simulado, puerto });
  console.log(`\nCultura Pixel en ${sitio}`);
  if (simulado) {
    console.log('Modo simulado: emuladores de Firebase + simulador de Mercado Pago (no se cobra nada).');
    console.log(`Panel: ${sitio}/admin/  ·  contacto@institutoculturapixel.com / ${CONTRASENA_LOCAL}`);
  } else {
    console.log('Servicios reales: usa api/service-account.json y api/mercadopago.local.php.');
  }
} catch (error) {
  console.error(error.message || error);
  process.exit(1);
}
