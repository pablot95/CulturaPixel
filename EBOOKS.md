# Venta de ebooks · Instituto Cultura Pixel

Venta de ebooks en PDF con Mercado Pago y descarga inmediata. Corre en **Hostinger** (PHP 8.3)
con deploy automático desde GitHub, como los demás sitios de Gokywebs. No es el
Template-Ecommerce completo: reutiliza sus piezas (Firestore por REST con cuenta de servicio,
"Conectar con Mercado Pago" por el puente de gokywebs.com, webhook que vuelve a consultar
el pago, límites por IP) en una versión chica pensada para productos digitales.

## Qué hay

| Ruta | Qué es |
| --- | --- |
| `/#ebooks` | Sección de ebooks en la home (una tarjeta por ebook) |
| `/ebooks/quinceaneras/` | Landing del ebook (también `/quinceaneras`) |
| `/ebooks/gracias/?orden=…&clave=…` | Página de éxito: confirma el pago, descarga sola y deja el botón. Ese link es el acceso personal del comprador |
| `/admin/` | Panel del instituto: resumen, ventas, precio / pausa, **enlace para compartir** cada libro, **subida del PDF** y conexión con Mercado Pago |
| `api/ebooks/*.php`, `api/mp-conectar.php` | API en PHP (`api/lib/` tiene la lógica) |
| `storage/ebooks/<id>.pdf` | Los PDF. Se suben desde el panel; `storage/.htaccess` bloquea el acceso directo |

## Qué NO está en Git (el repo es público)

Se suben a mano a `public_html/api/` con el Administrador de archivos de Hostinger. El deploy
por Git no los toca y `api/.htaccess` bloquea su descarga:

| Archivo | Qué es |
| --- | --- |
| `api/mercadopago.local.php` | App de Mercado Pago de Gokywebs (el mismo archivo de todas las webs) |
| `api/service-account.json` | Cuenta de servicio de Firebase (copia local en `secretos/firebase-service-account.json`) |
| `api/secrets.local.php` | Opcional (ver `api/secrets.local.example.php`) |

Los PDF tampoco van al repo: se suben desde **/admin/ → Ebooks → Subir el PDF**.

## Cómo funciona una compra

1. El comprador toca **Comprar**, deja nombre y email → `comprar.php` crea `ventas/{código}` (pendiente) y la preferencia de Checkout Pro. El precio sale de Firestore, nunca del navegador.
2. Paga en Mercado Pago (sin efectivo y en `binary_mode`: se aprueba o rechaza al instante).
3. Mercado Pago vuelve a `/ebooks/gracias/`. La página consulta `estado.php`; si el webhook todavía no llegó, el servidor pregunta el pago a Mercado Pago con el `payment_id` de la vuelta (rescate). En paralelo llega `webhook.php`, que hace lo mismo. Las dos vías son idempotentes.
4. Aprobado → la descarga empieza sola una vez y queda el botón. `descargar.php` valida la clave y entrega el PDF desde `storage/ebooks/`.
5. Cada compra tiene **10 descargas** (dos pedidos dentro de 2 minutos cuentan como uno). Desde el panel se reinician o se genera un link nuevo (el viejo deja de andar).

Una venta aprobada solo cambia si se reembolsa o contracarga **ese mismo pago**. Si el monto no coincide, no se aprueba y queda marcada "para revisar".

## Puesta en marcha en Hostinger

1. **Git**: hPanel → institutoculturapixel.com → Avanzado → Git → repo `pablot95/CulturaPixel`, rama `main`, carpeta raíz, con deploy automático. (La carpeta tiene que estar vacía: borrar `default.php`.)
2. **Secretos**: subir `api/mercadopago.local.php` y `api/service-account.json` a `public_html/api/`.
3. **Probar** en el dominio temporal de Hostinger: la web, el panel y la subida del PDF andan; Mercado Pago todavía no (el puente vuelve al dominio real).
4. **DNS**: el dominio está registrado en Hostinger pero usa los nameservers de Vercel. Cambiarlos a los de Hostinger (`ns1.dns-parking.com` / `ns2.dns-parking.com`), esperar el SSL y activar "Forzar HTTPS".
5. **/admin/** con `contacto@institutoculturapixel.com` → **Ebooks → Subir el PDF** y **Mercado Pago → Conectar con Mercado Pago** con la cuenta del instituto.
6. Compra real de prueba (se puede bajar el precio a $100 desde el panel y después devolver el pago desde Mercado Pago).
7. Borrar el proyecto de Vercel (o quitarle el dominio). Hasta entonces, `.vercelignore` hace que Vercel publique solo lo estático.

## Agregar otro ebook

1. Sumarlo a `scripts/datos-ebooks.json` y correr `npm run firestore:sembrar` (crea `ebooks/{id}`; el precio y la pausa después se manejan desde el panel).
2. Imágenes (tapa, presentación, ideas de muestra e imagen para compartir). Los originales se
   dejan en `ebooks/` (no se suben a Git) y el script genera las versiones livianas en `ebooks/<id>/img/`:
   `npm run ebook:imagenes -- --id <id> --tapa ebooks/tapa.jpg --resumen ebooks/resumen.jpg --ideas ebooks/idea1.jpg ebooks/idea2.jpg --titulo "…" --subtitulo "…"`
   (o `--pdf "C:\ruta\libro.pdf" --paginas 1,4,9,15` para sacarlas del PDF). Cada idea queda
   como `pagina-NN.webp` para el carrusel y `pagina-NN-grande.webp` para verla ampliada.
3. Copiar `ebooks/quinceaneras/` como base de la landing (cambiar `data-ebook`, textos, nombres y `alt` de las ideas, JSON-LD y rutas de imágenes) y sumar la tarjeta en la sección `#ebooks` de la home y en `sitemap.xml`.
4. `npm run versionar` (ver abajo), subir el PDF desde el panel y copiar el enlace del libro (**Ebooks → Copiar enlace**) para compartirlo.

La tapa de Quinceañeras es cuadrada. Si otro libro tiene tapa vertical, poner
`style="--ebook-tapa: 900 / 1272"` (sus medidas) en el `<body>` de su landing y ajustar
`width`/`height` de sus imágenes.

## Caché: correr `npm run versionar` después de cada cambio

Hostinger sirve CSS, JS e imágenes con caché de 7 días; las páginas HTML se revalidan
siempre (`.htaccess`). Por eso cada archivo se pide con su versión (`/ebooks/ebooks.css?v=<hash>`):
**después de cambiar un CSS, JS o imagen, correr `npm run versionar`** y commitear. `npm test`
falla si alguna quedó vieja. Las tapas que arma la API (panel, página de gracias) se versionan
solas con `urlConVersion()`.

## Probar en local

Requiere PHP 8.1+, Node 20+ y Firebase CLI (con Java para los emuladores).

```
npm test                  # lógica en PHP: pagos, descargas, OAuth, Firestore, límites
npm run test:integracion  # circuito completo con emuladores de Firebase y Mercado Pago simulado
npm run dev:simulado      # sitio + panel en http://127.0.0.1:3000 sin cobrar ni tocar datos reales
npm run dev               # contra Firestore y Mercado Pago reales (usa los archivos de api/)
```

En `dev:simulado` el panel entra con `contacto@institutoculturapixel.com` y la contraseña `CONTRASENA_LOCAL` de `scripts/lib/servidor.mjs` (solo existe en el emulador). `scripts/router-local.php` imita los `.htaccess`.

## Firestore

- Reglas: todo cerrado para el navegador (`firestore.rules`). La API usa la cuenta de servicio y el panel pasa por `api/ebooks/admin.php`.
- Colecciones: `ebooks`, `ventas`, `config/mercadopago` (tokens, nunca salen del servidor), `admins`, `auditoria`, `logs_webhooks`.
- Índices: `firestore.indexes.json` (`npm run firestore:reglas` publica reglas e índices).
  El emulador de las pruebas **no exige índices compuestos**: después de tocar consultas o
  índices, correr `npm run firestore:verificar`, que prueba contra el Firestore real (solo
  lectura) todas las consultas de la API y el panel.
- Acceso al panel: documento `admins/{email}` con `activo: true` + usuario en Firebase Authentication.
