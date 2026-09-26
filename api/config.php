<?php
declare(strict_types=1);

/*
 * Configuración de la venta de ebooks. Este archivo está en el repo (público): los
 * secretos NO van acá. Se suben a mano a api/ en Hostinger (Administrador de archivos),
 * no se versionan y api/.htaccess bloquea su descarga:
 *   - mercadopago.local.php → MP_CLIENT_ID / MP_CLIENT_SECRET de la app de Gokywebs
 *                             (el mismo archivo de todas las webs)
 *   - service-account.json  → cuenta de servicio de Firebase
 *   - secrets.local.php     → opcional: MP_WEBHOOK_SECRET, MP_ACCESS_TOKEN
 * En local y en las pruebas cualquier valor se puede pisar con variables de entorno.
 */

function configEntorno(string $nombre, string $porDefecto = ''): string {
    $valor = getenv($nombre);
    return is_string($valor) && trim($valor) !== '' ? trim($valor) : $porDefecto;
}

// 1) Variables de entorno: solo existen en local y en las pruebas, y mandan sobre los
//    archivos (así el modo simulado no usa las credenciales reales aunque estén en api/).
foreach (['SITE_URL', 'FIREBASE_PROJECT_ID', 'SERVICE_ACCOUNT_PATH', 'MP_CLIENT_ID', 'MP_CLIENT_SECRET', 'MP_ACCESS_TOKEN', 'MP_WEBHOOK_SECRET', 'MP_API_BASE', 'MP_AUTH_URL', 'EBOOKS_DIR'] as $nombreEntorno) {
    if (configEntorno($nombreEntorno) !== '' && !defined($nombreEntorno)) define($nombreEntorno, configEntorno($nombreEntorno));
}

// 2) Archivos del servidor (usan `defined('X') || define('X', …)`, como mercadopago.local.php).
foreach (['secrets.local.php', 'mercadopago.local.php'] as $archivoSecreto) {
    if (is_file(__DIR__ . '/' . $archivoSecreto)) require __DIR__ . '/' . $archivoSecreto;
}

// 3) Valores por defecto.

defined('SITE_URL') || define('SITE_URL', rtrim(configEntorno('SITE_URL', 'https://www.institutoculturapixel.com'), '/'));
defined('WHATSAPP') || define('WHATSAPP', '5492615547922');

defined('FIREBASE_PROJECT_ID') || define('FIREBASE_PROJECT_ID', configEntorno('FIREBASE_PROJECT_ID', 'institutoculturapixel-ef52a'));
// Clave web de Firebase: es pública (la usa el panel). Sirve para validar el token del admin.
defined('FIREBASE_WEB_API_KEY') || define('FIREBASE_WEB_API_KEY', configEntorno('FIREBASE_WEB_API_KEY', 'AIzaSyBZ1em4R7YqzBrzN1KGZoOeambrMlbbHAI'));
defined('SERVICE_ACCOUNT_PATH') || define('SERVICE_ACCOUNT_PATH', configEntorno('SERVICE_ACCOUNT_PATH', __DIR__ . '/service-account.json'));

// "Conectar con Mercado Pago": la app de Gokywebs. Su URL de vuelta es el puente de
// gokywebs.com, que reenvía a SITE_URL/api/mp-conectar.php.
defined('MP_CLIENT_ID') || define('MP_CLIENT_ID', configEntorno('MP_CLIENT_ID'));
defined('MP_CLIENT_SECRET') || define('MP_CLIENT_SECRET', configEntorno('MP_CLIENT_SECRET'));
defined('MP_OAUTH_REDIRECT_URL') || define('MP_OAUTH_REDIRECT_URL', configEntorno('MP_OAUTH_REDIRECT_URL', 'https://gokywebs.com/mp-conectar/'));
// Respaldo si no se usa "Conectar con Mercado Pago" (Access Token cargado a mano).
defined('MP_ACCESS_TOKEN') || define('MP_ACCESS_TOKEN', configEntorno('MP_ACCESS_TOKEN'));
// Opcional: con el secreto de webhooks cargado, la firma x-signature se exige.
defined('MP_WEBHOOK_SECRET') || define('MP_WEBHOOK_SECRET', configEntorno('MP_WEBHOOK_SECRET'));
// Solo para pruebas locales con el simulador de Mercado Pago.
defined('MP_API_BASE') || define('MP_API_BASE', rtrim(configEntorno('MP_API_BASE', 'https://api.mercadopago.com'), '/'));
defined('MP_AUTH_URL') || define('MP_AUTH_URL', configEntorno('MP_AUTH_URL', 'https://auth.mercadopago.com.ar/authorization'));

// PDFs de los ebooks (fuera del alcance web: storage/.htaccess niega todo).
defined('EBOOKS_DIR') || define('EBOOKS_DIR', rtrim(configEntorno('EBOOKS_DIR', dirname(__DIR__) . '/storage/ebooks'), '/\\'));
defined('PDF_MAX_BYTES') || define('PDF_MAX_BYTES', 200 * 1048576);

const MONEDA = 'ARS';
const ZONA_HORARIA = 'America/Argentina/Mendoza';
// Descargas por compra: alcanza para celular y compu, y corta la difusión del enlace.
const DESCARGAS_MAX = 10;
// Dos pedidos del mismo enlace dentro de esta ventana cuentan como una descarga.
const VENTANA_DESCARGA_SEGUNDOS = 120;
// Vigencia del link de pago de Mercado Pago.
const PREFERENCIA_HORAS = 24;
const MAX_REQUEST_BYTES = 65536;
const TRUST_CF_CONNECTING_IP = false;
