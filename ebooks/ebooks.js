// Landing de un ebook: precio vigente, checkout, carrusel de páginas, barra de compra
// en el celular y aviso para quien ya lo compró desde este navegador.

import {
  cargarCatalogo, guardarCompra, leerCompras, postJson, sinMovimiento, trampaDeFoco,
} from './comun.js?v=27743da1fd';

const ebookDePagina = document.body.dataset.ebook || '';
const disponibles = {};

cargarCatalogo()
  .then((ebooks) => {
    for (const ebook of ebooks) disponibles[ebook.id] = ebook.disponible;
  })
  .catch(() => {});

/* ------------------------------------------------------------------ checkout */

const modal = document.getElementById('checkout');
const formulario = modal?.querySelector('[data-checkout]');
const errorGeneral = modal?.querySelector('[data-checkout-error]');
const botonEnviar = modal?.querySelector('[data-checkout-enviar]');
const textoBoton = botonEnviar?.innerHTML || '';
let ebookElegido = ebookDePagina;
let disparador = null;
let liberarTrampa = null;

function marcarError(campo, mensaje) {
  const error = document.getElementById(`${campo.id}-error`);
  campo.setAttribute('aria-invalid', 'true');
  if (error) {
    error.textContent = mensaje;
    error.hidden = false;
  }
}

function limpiarErrores() {
  formulario?.querySelectorAll('input').forEach((campo) => {
    campo.setAttribute('aria-invalid', 'false');
    const error = document.getElementById(`${campo.id}-error`);
    if (error) error.hidden = true;
  });
  if (errorGeneral) {
    errorGeneral.hidden = true;
    errorGeneral.replaceChildren();
  }
}

function mostrarErrorGeneral(mensaje) {
  errorGeneral.textContent = mensaje;
  errorGeneral.hidden = false;
}

function enviando(activo) {
  botonEnviar.disabled = activo;
  botonEnviar.setAttribute('aria-busy', String(activo));
  botonEnviar.innerHTML = activo ? 'Te llevamos a Mercado Pago…' : textoBoton;
}

function abrirCheckout(boton) {
  if (!modal) return;
  ebookElegido = boton?.dataset.comprar || ebookDePagina;
  disparador = boton || document.activeElement;
  limpiarErrores();
  if (disponibles[ebookElegido] === false) {
    mostrarErrorGeneral('Por ahora este libro no está a la venta. Probá de nuevo más tarde.');
  }
  modal.hidden = false;
  requestAnimationFrame(() => modal.classList.add('is-open'));
  document.body.style.overflow = 'hidden';
  liberarTrampa = trampaDeFoco(modal, cerrarCheckout);
  const primero = formulario.nombre.value ? formulario.email : formulario.nombre;
  setTimeout(() => primero.focus(), 60);
}

function cerrarCheckout() {
  if (!modal || modal.hidden) return;
  modal.classList.remove('is-open');
  liberarTrampa?.();
  liberarTrampa = null;
  document.body.style.overflow = '';
  setTimeout(() => {
    modal.hidden = true;
  }, sinMovimiento() ? 0 : 260);
  disparador?.focus?.();
}

document.querySelectorAll('[data-comprar]').forEach((boton) => {
  boton.addEventListener('click', () => abrirCheckout(boton));
});
modal?.querySelectorAll('[data-cerrar-modal]').forEach((elemento) => elemento.addEventListener('click', cerrarCheckout));

formulario?.addEventListener('submit', async (evento) => {
  evento.preventDefault();
  if (botonEnviar.disabled) return;
  limpiarErrores();
  const nombre = formulario.nombre.value.trim().replace(/\s+/g, ' ');
  const email = formulario.email.value.trim();
  let primerError = null;
  if (nombre.length < 2) {
    marcarError(formulario.nombre, 'Escribí tu nombre y apellido.');
    primerError ??= formulario.nombre;
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
    marcarError(formulario.email, email ? 'Revisá el email: parece incompleto.' : 'Escribí tu email.');
    primerError ??= formulario.email;
  }
  if (primerError) {
    primerError.focus();
    return;
  }

  enviando(true);
  try {
    const compra = await postJson('/api/ebooks/comprar.php', { ebookId: ebookElegido, nombre, email });
    guardarCompra({ codigo: compra.codigo, clave: compra.clave, ebookId: ebookElegido });
    window.location.assign(compra.url);
  } catch (error) {
    enviando(false);
    if (error.codigo === 'nombre') {
      marcarError(formulario.nombre, error.message);
      formulario.nombre.focus();
    } else if (error.codigo === 'email') {
      marcarError(formulario.email, error.message);
      formulario.email.focus();
    } else {
      // Cualquier otro problema (venta pausada, Mercado Pago sin conectar, sin conexión).
      mostrarErrorGeneral(error.message);
    }
  }
});

// Al volver con "atrás" desde Mercado Pago el navegador restaura la página tal cual.
window.addEventListener('pageshow', (evento) => {
  if (!evento.persisted || !botonEnviar) return;
  enviando(false);
  cerrarCheckout();
});

/* ------------------------------------------------------------- ya compraste */

async function revisarCompras() {
  const aviso = document.querySelector('[data-ya-comprado]');
  if (!aviso || !ebookDePagina) return;
  const sesenta = 60 * 86400_000;
  const propias = leerCompras().filter((compra) => compra.ebookId === ebookDePagina && Date.now() - (compra.fecha || 0) < sesenta).slice(0, 3);
  for (const compra of propias) {
    try {
      const estado = await postJson('/api/ebooks/estado.php', { orden: compra.codigo, clave: compra.clave });
      if (estado.estado === 'aprobado') {
        aviso.querySelector('[data-ya-comprado-link]').href = `/ebooks/gracias/?orden=${encodeURIComponent(compra.codigo)}&clave=${encodeURIComponent(compra.clave)}`;
        aviso.hidden = false;
        return;
      }
    } catch {
      // Compra vieja o de otro entorno: se ignora.
    }
  }
}
revisarCompras();

/* ------------------------------------------------------- páginas de muestra */

const pista = document.querySelector('[data-paginas]');
const flechaAnterior = document.querySelector('[data-paginas-anterior]');
const flechaSiguiente = document.querySelector('[data-paginas-siguiente]');

function actualizarFlechas() {
  if (!pista || !flechaAnterior) return;
  flechaAnterior.disabled = pista.scrollLeft <= 4;
  flechaSiguiente.disabled = pista.scrollLeft + pista.clientWidth >= pista.scrollWidth - 4;
}

if (pista) {
  const desplazar = (sentido) => pista.scrollBy({ left: sentido * pista.clientWidth * 0.9, behavior: sinMovimiento() ? 'auto' : 'smooth' });
  flechaAnterior?.addEventListener('click', () => desplazar(-1));
  flechaSiguiente?.addEventListener('click', () => desplazar(1));
  let pendiente = false;
  pista.addEventListener('scroll', () => {
    if (pendiente) return;
    pendiente = true;
    requestAnimationFrame(() => {
      pendiente = false;
      actualizarFlechas();
    });
  }, { passive: true });
  window.addEventListener('resize', actualizarFlechas, { passive: true });
  actualizarFlechas();
}

// Todo lo que se amplía (presentación del libro + ideas del carrusel), en el orden de data-pagina.
const visor = document.querySelector('[data-visor]');
const imagenVisor = visor?.querySelector('[data-visor-imagen]');
const contadorVisor = visor?.querySelector('[data-visor-contador]');
const botonesPagina = [...document.querySelectorAll('[data-pagina]')].sort((a, b) => Number(a.dataset.pagina) - Number(b.dataset.pagina));
const paginas = botonesPagina.map((boton) => {
  const imagen = boton.querySelector('img');
  const chica = imagen.getAttribute('src');
  return {
    chica,
    grande: boton.dataset.grande || chica,
    alt: imagen.alt,
    nombre: boton.dataset.nombre || '',
    proporcion: Number(imagen.getAttribute('width')) / Number(imagen.getAttribute('height')) || 1,
  };
});
let paginaActual = 0;
let disparadorVisor = null;
let liberarVisor = null;

/** Baja la versión grande de una página; al terminar llama a alListo (si todavía corresponde). */
function precargar(pagina, alListo) {
  if (pagina.grande === pagina.chica) return;
  const imagen = document.createElement('img');
  imagen.decoding = 'async';
  if (alListo) imagen.addEventListener('load', alListo, { once: true });
  imagen.src = pagina.grande;
}

function mostrarPagina(indice) {
  paginaActual = (indice + paginas.length) % paginas.length;
  const pagina = paginas[paginaActual];
  // Primero la imagen del carrusel (ya está en caché) y, cuando llega, la grande: se lee el texto chico.
  imagenVisor.style.setProperty('--proporcion', String(pagina.proporcion));
  imagenVisor.src = pagina.chica;
  imagenVisor.alt = pagina.alt;
  contadorVisor.textContent = `${pagina.nombre ? `${pagina.nombre} · ` : ''}${paginaActual + 1} de ${paginas.length}`;
  precargar(pagina, () => {
    if (paginas[paginaActual] !== pagina || visor.hidden) return;
    imagenVisor.src = pagina.grande;
    precargar(paginas[(paginaActual + 1) % paginas.length]);
  });
}

function cerrarVisor() {
  visor.hidden = true;
  liberarVisor?.();
  document.body.style.overflow = '';
  disparadorVisor?.focus();
}

function abrirVisor(indice, boton) {
  if (!visor || !paginas.length) return;
  disparadorVisor = boton;
  mostrarPagina(indice);
  visor.hidden = false;
  document.body.style.overflow = 'hidden';
  liberarVisor = trampaDeFoco(visor, cerrarVisor, {
    ArrowLeft: () => mostrarPagina(paginaActual - 1),
    ArrowRight: () => mostrarPagina(paginaActual + 1),
  });
  visor.querySelector('[data-visor-cerrar]').focus();
}

botonesPagina.forEach((boton, indice) => {
  boton.addEventListener('click', () => abrirVisor(indice, boton));
});
visor?.querySelector('[data-visor-cerrar]').addEventListener('click', cerrarVisor);
visor?.querySelector('[data-visor-anterior]').addEventListener('click', () => mostrarPagina(paginaActual - 1));
visor?.querySelector('[data-visor-siguiente]').addEventListener('click', () => mostrarPagina(paginaActual + 1));
visor?.addEventListener('click', (evento) => {
  if (evento.target === visor) cerrarVisor();
});

let inicioToque = null;
visor?.addEventListener('touchstart', (evento) => {
  inicioToque = evento.touches[0].clientX;
}, { passive: true });
visor?.addEventListener('touchend', (evento) => {
  if (inicioToque === null) return;
  const recorrido = evento.changedTouches[0].clientX - inicioToque;
  inicioToque = null;
  if (Math.abs(recorrido) > 45) mostrarPagina(paginaActual + (recorrido < 0 ? 1 : -1));
});

/* ------------------------------------------------ barra de compra (celular) */

const barra = document.querySelector('[data-barra]');
const vigilados = [document.querySelector('[data-hero-acciones]'), document.getElementById('comprar'), document.querySelector('.ebook-cierre'), document.querySelector('.pixel-footer')].filter(Boolean);

if (barra && vigilados.length && 'IntersectionObserver' in window) {
  const visibles = new Set();
  let heroVisto = false;
  const observador = new IntersectionObserver((entradas) => {
    for (const entrada of entradas) {
      if (entrada.isIntersecting) visibles.add(entrada.target);
      else visibles.delete(entrada.target);
      if (entrada.target === vigilados[0] && !entrada.isIntersecting && entrada.boundingClientRect.top < 0) heroVisto = true;
      if (entrada.target === vigilados[0] && entrada.isIntersecting) heroVisto = false;
    }
    const mostrar = heroVisto && visibles.size === 0;
    barra.classList.toggle('is-visible', mostrar);
    barra.setAttribute('aria-hidden', String(!mostrar));
    barra.querySelector('button').tabIndex = mostrar ? 0 : -1;
  });
  vigilados.forEach((elemento) => observador.observe(elemento));
}
