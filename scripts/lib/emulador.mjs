// Acceso directo a los emuladores de Firebase (Firestore y Auth) para el servidor local
// simulado y las pruebas. No se usa en producción.

const proyecto = () => process.env.FIREBASE_PROJECT_ID || 'demo-culturapixel';
const baseFirestore = () => `http://${process.env.FIRESTORE_EMULATOR_HOST}/v1/projects/${proyecto()}/databases/(default)/documents`;
const baseAuth = () => `http://${process.env.FIREBASE_AUTH_EMULATOR_HOST}/identitytoolkit.googleapis.com/v1`;
const rutaUrl = (ruta) => ruta.split('/').map(encodeURIComponent).join('/');

export function valorFs(valor) {
  if (valor instanceof Date) return { timestampValue: valor.toISOString() };
  if (valor === null || valor === undefined) return { nullValue: null };
  if (typeof valor === 'boolean') return { booleanValue: valor };
  if (typeof valor === 'number') return Number.isInteger(valor) ? { integerValue: String(valor) } : { doubleValue: valor };
  if (typeof valor === 'string') return { stringValue: valor };
  if (Array.isArray(valor)) return { arrayValue: { values: valor.map(valorFs) } };
  return { mapValue: { fields: camposFs(valor) } };
}

export const camposFs = (objeto) => Object.fromEntries(Object.entries(objeto).map(([clave, valor]) => [clave, valorFs(valor)]));

function desdeValorFs(valor) {
  if ('nullValue' in valor) return null;
  if ('booleanValue' in valor) return valor.booleanValue;
  if ('integerValue' in valor) return Number(valor.integerValue);
  if ('doubleValue' in valor) return Number(valor.doubleValue);
  if ('stringValue' in valor) return valor.stringValue;
  if ('timestampValue' in valor) return valor.timestampValue;
  if ('arrayValue' in valor) return (valor.arrayValue.values || []).map(desdeValorFs);
  if ('mapValue' in valor) return Object.fromEntries(Object.entries(valor.mapValue.fields || {}).map(([k, v]) => [k, desdeValorFs(v)]));
  return null;
}

async function pedir(metodo, url, cuerpo) {
  const respuesta = await fetch(url, {
    method: metodo,
    headers: { Authorization: 'Bearer owner', ...(cuerpo ? { 'Content-Type': 'application/json' } : {}) },
    body: cuerpo ? JSON.stringify(cuerpo) : undefined,
  });
  const datos = await respuesta.json().catch(() => ({}));
  return { ok: respuesta.ok, status: respuesta.status, datos };
}

export async function leer(ruta) {
  const { ok, status, datos } = await pedir('GET', `${baseFirestore()}/${rutaUrl(ruta)}`);
  if (status === 404) return null;
  if (!ok) throw new Error(`Emulador: no se pudo leer ${ruta}`);
  return Object.fromEntries(Object.entries(datos.fields || {}).map(([clave, valor]) => [clave, desdeValorFs(valor)]));
}

/** Crea o actualiza los campos dados (upsert). */
export async function guardar(ruta, campos) {
  const mascara = Object.keys(campos).map((campo) => `updateMask.fieldPaths=${encodeURIComponent(campo)}`).join('&');
  const { ok } = await pedir('PATCH', `${baseFirestore()}/${rutaUrl(ruta)}?${mascara}`, { fields: camposFs(campos) });
  if (!ok) throw new Error(`Emulador: no se pudo guardar ${ruta}`);
}

export async function crearUsuario(email, password) {
  const respuesta = await fetch(`${baseAuth()}/accounts:signUp?key=local`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password, returnSecureToken: false }),
  });
  if (!respuesta.ok && !(await respuesta.text()).includes('EMAIL_EXISTS')) throw new Error(`No se pudo crear ${email} en el emulador de Auth.`);
}

export async function ingresar(email, password) {
  const respuesta = await fetch(`${baseAuth()}/accounts:signInWithPassword?key=local`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password, returnSecureToken: true }),
  });
  return (await respuesta.json()).idToken;
}
