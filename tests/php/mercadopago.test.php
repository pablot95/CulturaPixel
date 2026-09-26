<?php
declare(strict_types=1);

// Credenciales de prueba: van antes de config.php, que no pisa una constante ya definida.
define('SITE_URL', 'https://www.institutoculturapixel.com');
define('MP_CLIENT_ID', '5556318803749433');
define('MP_CLIENT_SECRET', 'secreto-de-prueba');
define('MP_WEBHOOK_SECRET', '');
define('MP_ACCESS_TOKEN', '');
define('MP_API_BASE', 'https://api.mercadopago.com');
define('MP_AUTH_URL', 'https://auth.mercadopago.com.ar/authorization');

require __DIR__ . '/_comprobar.php';
require_once dirname(__DIR__, 2) . '/api/lib/mercadopago.php';

// state: el formato que valida el puente de gokywebs.com/mp-conectar/
$state = mercadoPagoNewState();
[$hostB64, $nonce, $firma] = explode('.', $state);
comprobar(base64_decode(strtr($hostB64, '-_', '+/'), true), 'www.institutoculturapixel.com', 'lleva el host de la web');
comprobarQue((bool)preg_match('/^[0-9a-f]{32}$/', $nonce), 'nonce de 32 hex');
// Misma cuenta que hace el puente (Gokywebsweb/mp-conectar/index.php).
$esperada = rtrim(strtr(base64_encode(hash_hmac('sha256', $hostB64 . '.' . $nonce, 'secreto-de-prueba', true)), '+/', '-_'), '=');
comprobar($firma, $esperada, 'firma HMAC con el client secret');
comprobarQue(mercadoPagoNewState() !== $state, 'cada state es único');

$url = mercadoPagoAuthorizeUrl('abc.def.ghi');
parse_str((string)parse_url($url, PHP_URL_QUERY), $consulta);
comprobar(strtok($url, '?'), 'https://auth.mercadopago.com.ar/authorization', 'URL de autorización');
comprobar($consulta['client_id'], '5556318803749433', 'client_id');
comprobar($consulta['redirect_uri'], 'https://gokywebs.com/mp-conectar/', 'vuelve por el puente');
comprobar($consulta['state'], 'abc.def.ghi', 'state');

// Tokens
$campos = mercadoPagoTokenFields(['access_token' => 'APP_USR-1', 'refresh_token' => 'TG-1', 'public_key' => 'APP_USR-PUB', 'user_id' => 42, 'expires_in' => 15552000, 'live_mode' => true], 1800000000);
comprobar($campos['accessToken'], 'APP_USR-1', 'access token');
comprobar($campos['mpUserId'], '42', 'usuario');
comprobar($campos['conexion'], 'oauth', 'conexión oauth');
comprobar($campos['expiresAt'], gmdate('Y-m-d\TH:i:s\Z', 1800000000 + 15552000), 'vencimiento');
comprobarError(static fn() => mercadoPagoTokenFields([], 1), '/Access Token/', 'sin token falla');
$vence = static fn(int $dias): array => ['conexion' => 'oauth', 'refreshToken' => 'TG', 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', 1800000000 + $dias * 86400)];
comprobar(mercadoPagoNeedsRefresh($vence(10), 1800000000), true, 'renueva si faltan menos de 30 días');
comprobar(mercadoPagoNeedsRefresh($vence(90), 1800000000), false, 'no renueva si falta mucho');

// Renovación con la API simulada
$llamadas = [];
$GLOBALS['MP_HTTP_FAKE'] = static function (string $metodo, string $url, ?array $cuerpo, array $headers) use (&$llamadas): array {
    $llamadas[] = [$metodo, $url, $cuerpo];
    if ($url === 'https://api.mercadopago.com/oauth/token' && ($cuerpo['grant_type'] ?? '') === 'refresh_token' && ($cuerpo['refresh_token'] ?? '') === 'TG') {
        return ['ok' => true, 'status' => 200, 'data' => ['access_token' => 'APP_USR-2', 'refresh_token' => 'TG-2', 'expires_in' => 15552000, 'user_id' => 42], 'raw' => '', 'error' => ''];
    }
    return ['ok' => false, 'status' => 400, 'data' => ['message' => 'invalid_grant'], 'raw' => '', 'error' => ''];
};
$guardado = null;
$renovado = mercadoPagoRefreshIfNeeded($vence(10), 1800000000, static fn() => null, static function (array $patch) use (&$guardado): void { $guardado = $patch; });
comprobar($renovado['accessToken'], 'APP_USR-2', 'renueva el token');
comprobar($guardado['refreshToken'], 'TG-2', 'guarda el refresh token nuevo');
$fallido = mercadoPagoRefreshIfNeeded(['refreshToken' => 'OTRO'] + $vence(10), 1800000000, static fn() => null, static function (array $patch) use (&$guardado): void { $guardado = $patch; });
comprobarQue(str_contains($fallido['oauthError'], 'rechazó'), 'si Mercado Pago rechaza, queda el error a la vista');
comprobar($fallido['refreshToken'], 'OTRO', 'y conserva el token actual');
unset($GLOBALS['MP_HTTP_FAKE']);

// Vista del panel: nunca tokens
$vista = mercadoPagoAdminView(['accessToken' => 'APP_USR-SECRETO', 'refreshToken' => 'TG-SECRETO', 'conexion' => 'oauth', 'cuentaNombre' => 'CULTURAPIXEL']);
comprobar($vista['conectado'], true, 'conectado');
comprobar($vista['cuentaNombre'], 'CULTURAPIXEL', 'cuenta');
comprobarQue(!str_contains((string)json_encode($vista), 'SECRETO'), 'el panel no recibe tokens');
comprobar(mercadoPagoAdminView([])['conectado'], false, 'sin token no está conectado');

// Preferencia de Checkout Pro
$cuerpo = cuerpoPreferencia(
    ['id' => 'K7F2QX9M', 'monto' => 14900, 'compradorNombre' => 'Sofía Pérez', 'compradorEmail' => 'sofia@mail.com'],
    ['id' => 'quinceaneras', 'titulo' => 'Quinceañeras', 'portada' => '/ebooks/quinceaneras/img/portada.webp'],
    'CLAVE123',
    strtotime('2026-09-26T15:00:00Z'),
);
comprobar($cuerpo['items'][0]['unit_price'], 14900.0, 'cobra el monto de la venta');
comprobar($cuerpo['items'][0]['currency_id'], 'ARS', 'en pesos');
comprobar($cuerpo['items'][0]['picture_url'], 'https://www.institutoculturapixel.com/ebooks/quinceaneras/img/portada.webp', 'con la tapa');
comprobar($cuerpo['external_reference'], 'K7F2QX9M', 'referencia = código de venta');
comprobar($cuerpo['back_urls']['success'], 'https://www.institutoculturapixel.com/ebooks/gracias/?orden=K7F2QX9M&clave=CLAVE123', 'vuelve a gracias con la clave');
comprobar($cuerpo['notification_url'], 'https://www.institutoculturapixel.com/api/ebooks/webhook.php?source_news=webhooks', 'webhook');
comprobar($cuerpo['binary_mode'], true, 'sin pagos en revisión');
comprobar($cuerpo['payment_methods']['excluded_payment_types'], [['id' => 'ticket'], ['id' => 'atm']], 'sin efectivo');
comprobar($cuerpo['payer'], ['name' => 'Sofía', 'surname' => 'Pérez', 'email' => 'sofia@mail.com'], 'comprador');
comprobar($cuerpo['expiration_date_to'], '2026-09-27T15:00:00.000Z', 'vence en 24 horas');

// Firma de webhooks
$ts = '1742505638683';
$v1 = hash_hmac('sha256', 'id:123456;request-id:req-1;ts:' . $ts . ';', 'secreto-webhook');
$firmaWebhook = parseSignature('ts=' . $ts . ',v1=' . $v1);
comprobar(verifyMercadoPagoSignatureWith('123456', 'req-1', $firmaWebhook, 'secreto-webhook'), true, 'firma válida');
comprobar(verifyMercadoPagoSignatureWith('123457', 'req-1', $firmaWebhook, 'secreto-webhook'), false, 'otro pago');
comprobar(verifyMercadoPagoSignatureWith('123456', 'req-1', $firmaWebhook, 'otro'), false, 'otro secreto');
comprobar(verifyMercadoPagoSignature('123'), true, 'sin secreto configurado no se exige firma');

terminarPruebas('mercadopago');
