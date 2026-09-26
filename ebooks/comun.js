// Utilidades compartidas por la landing de cada ebook y la página de gracias.

export const WHATSAPP = '5492615547922';
const CLAVE_COMPRAS = 'culturapixel:compras';
const FOCUSABLES = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export const formatearNumero = (numero) => new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 }).format(numero);
export const formatearPrecio = (numero) => `$${formatearNumero(numero)}`;
export const enlaceWhatsapp = (texto) => `https://wa.me/${WHATSAPP}?text=${encodeURIComponent(texto)}`;

/** Compras hechas desde este navegador (para volver a la descarga sin buscar el link). */
export function leerCompras() {
  try {
    const lista = JSON.parse(localStorage.getItem(CLAVE_COMPRAS) || '[]');
    return Array.isArray(lista) ? lista.filter((compra) => compra && compra.codigo && compra.clave) : [];
  } catch {
    return [];
  }
}

export function guardarCompra({ codigo, clave, ebookId }) {
  try {
    const lista = leerCompras().filter((compra) => compra.codigo !== codigo);
    lista.unshift({ codigo, clave, ebookId, fecha: Date.now() });
    localStorage.setItem(CLAVE_COMPRAS, JSON.stringify(lista.slice(0, 12)));
  } catch {
    // Navegación privada o almacenamiento bloqueado: el link de la página de gracias sigue sirviendo.
  }
}

export async function postJson(url, cuerpo) {
  let respuesta;
  try {
    respuesta = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(cuerpo),
    });
  } catch {
    const error = new Error('No pudimos conectarnos. Revisá tu conexión e intentá de nuevo.');
    error.status = 0;
    throw error;
  }
  const datos = await respuesta.json().catch(() => ({}));
  if (!respuesta.ok) {
    const error = new Error(datos.error || 'Tuvimos un problema. Intentá de nuevo en unos minutos.');
    error.status = respuesta.status;
    error.codigo = datos.codigo || '';
    throw error;
  }
  return datos;
}

/** Pone el precio vigente (el que se edita en el panel) en cada [data-precio-ebook]. */
export async function cargarCatalogo() {
  const respuesta = await fetch('/api/ebooks/catalogo.php', { headers: { Accept: 'application/json' } });
  if (!respuesta.ok) throw new Error('catálogo no disponible');
  const { ebooks = [] } = await respuesta.json();
  for (const ebook of ebooks) {
    if (!(ebook.precio > 0)) continue;
    document.querySelectorAll(`[data-precio-ebook="${CSS.escape(ebook.id)}"]`).forEach((elemento) => {
      elemento.textContent = elemento.dataset.formato === 'numero' ? formatearNumero(ebook.precio) : formatearPrecio(ebook.precio);
    });
  }
  return ebooks;
}

/**
 * Mantiene el foco dentro de un diálogo, cierra con Esc y atiende teclas extra
 * (por ejemplo, flechas en el visor). Devuelve la función que la desactiva.
 */
export function trampaDeFoco(contenedor, alCerrar, teclas = {}) {
  function alTeclear(evento) {
    if (evento.key === 'Escape') {
      evento.preventDefault();
      alCerrar();
      return;
    }
    if (teclas[evento.key]) {
      evento.preventDefault();
      teclas[evento.key]();
      return;
    }
    if (evento.key !== 'Tab') return;
    const elementos = [...contenedor.querySelectorAll(FOCUSABLES)].filter((elemento) => elemento.getClientRects().length > 0);
    if (!elementos.length) return;
    const primero = elementos[0];
    const ultimo = elementos[elementos.length - 1];
    if (evento.shiftKey && (document.activeElement === primero || !contenedor.contains(document.activeElement))) {
      ultimo.focus();
      evento.preventDefault();
    } else if (!evento.shiftKey && (document.activeElement === ultimo || !contenedor.contains(document.activeElement))) {
      primero.focus();
      evento.preventDefault();
    }
  }
  document.addEventListener('keydown', alTeclear);
  return () => document.removeEventListener('keydown', alTeclear);
}

export const sinMovimiento = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
