<?php
declare(strict_types=1);

/*
 * Mercado Pago. "Conectar con Mercado Pago" (OAuth) es el de api/lib/mercadopago-oauth.php
 * del Template-Ecommerce: la app es la de Gokywebs y su única URL de vuelta es el puente
 * https://gokywebs.com/mp-conectar/, que reenvía a SITE_URL/api/mp-conectar.php de la web
 * que firmó el state. El Access Token del instituto vive en config/mercadopago (Firestore),
 * se renueva solo cuando faltan menos de 30 días (vence a los 180) y nunca va al navegador.
 * Además: preferencias de Checkout Pro, consulta de pagos y firma de webhooks.
 */

require_once __DIR__ . '/firestore.php';

const MP_RUTA_CONFIG = 'config/mercadopago';
const MP_OAUTH_STATE_SECONDS = 900;
const MP_OAUTH_REFRESH_MARGIN_SECONDS = 30 * 86400;
const MP_OAUTH_RETRY_SECONDS = 6 * 3600;
// Cookie del navegador que inició la conexión: un enlace de vuelta robado no sirve en otro.
const MP_COOKIE_STATE = 'mp_oauth_state';

/** Campos de config/mercadopago que escribe la conexión (y que se vacían al desconectar). */
const MP_OAUTH_FIELDS = ['accessToken', 'publicKey', 'refreshToken', 'mpUserId', 'liveMode', 'expiresAt', 'conexion', 'cuentaNombre', 'cuentaEmail', 'oauthError', 'refreshIntentoAt'];

function mercadoPagoOAuthAvailable(): bool {
    return MP_CLIENT_ID !== '' && MP_CLIENT_SECRET !== '' && !str_contains(MP_CLIENT_ID . MP_CLIENT_SECRET, 'CHANGE_ME');
}

function mercadoPagoRedirectUri(): string {
    return MP_OAUTH_REDIRECT_URL;
}

function mercadoPagoBase64Url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * state = base64url(host de la web) . nonce . firma HMAC con el Client Secret.
 * El puente solo reenvía a la web si la firma es válida.
 */
function mercadoPagoNewState(string $sitio = SITE_URL, string $secreto = MP_CLIENT_SECRET): string {
    $host = strtolower((string)parse_url($sitio, PHP_URL_HOST));
    if ($host === '') throw new RuntimeException('SITE_URL no es válida.');
    $payload = mercadoPagoBase64Url($host) . '.' . randomId(16);
    return $payload . '.' . mercadoPagoBase64Url(hash_hmac('sha256', $payload, $secreto, true));
}

/** HTTP hacia Mercado Pago con doble de pruebas; nunca se define desde una request. */
function mercadoPagoHttp(string $method, string $ruta, ?array $body, array $headers = []): array {
    $fake = $GLOBALS['MP_HTTP_FAKE'] ?? null;
    $url = MP_API_BASE . $ruta;
    return is_callable($fake) ? $fake($method, $url, $body, $headers) : httpJson($method, $url, $body, $headers, 20);
}

function mercadoPagoAuthorizeUrl(string $state): string {
    return MP_AUTH_URL . '?' . http_build_query([
        'client_id' => MP_CLIENT_ID,
        'response_type' => 'code',
        'platform_id' => 'mp',
        'state' => $state,
        'redirect_uri' => mercadoPagoRedirectUri(),
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Respuesta de POST /oauth/token → campos de config/mercadopago. */
function mercadoPagoTokenFields(array $data, int $now): array {
    $token = trim((string)($data['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('Mercado Pago no devolvió el Access Token.');
    $expiresIn = (int)($data['expires_in'] ?? 0);
    $fields = [
        'accessToken' => $token,
        'refreshToken' => trim((string)($data['refresh_token'] ?? '')),
        'liveMode' => ($data['live_mode'] ?? true) !== false,
        'expiresAt' => $expiresIn > 0 ? gmdate('Y-m-d\TH:i:s\Z', $now + $expiresIn) : '',
        'conexion' => 'oauth',
        'oauthError' => '',
        'refreshIntentoAt' => '',
    ];
    // Una renovación que no repite la Public Key o el usuario no borra los que ya estaban.
    $publicKey = cleanText($data['public_key'] ?? '', 200);
    if ($publicKey !== '') $fields['publicKey'] = $publicKey;
    $userId = cleanText((string)($data['user_id'] ?? ''), 40);
    if ($userId !== '') $fields['mpUserId'] = $userId;
    return $fields;
}

function mercadoPagoTokenRequest(array $grant): array {
    $response = mercadoPagoHttp('POST', '/oauth/token', array_merge(['client_id' => MP_CLIENT_ID, 'client_secret' => MP_CLIENT_SECRET], $grant));
    if (!$response['ok']) {
        $detail = cleanText($response['data']['message'] ?? $response['data']['error'] ?? '', 200);
        throw new RuntimeException('Mercado Pago rechazó la conexión' . ($detail !== '' ? ' (' . $detail . ')' : '') . '.');
    }
    return $response['data'];
}

/** Canjea el código de un solo uso por las claves de la cuenta que autorizó. */
function mercadoPagoExchangeCode(string $code, int $now): array {
    $fields = mercadoPagoTokenFields(mercadoPagoTokenRequest([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => mercadoPagoRedirectUri(),
    ]), $now);
    // Nombre de la cuenta para el "Conectado como…" del panel; si falla, la conexión vale igual.
    $me = mercadoPagoHttp('GET', '/users/me', null, ['Authorization: Bearer ' . $fields['accessToken']]);
    $fields['cuentaNombre'] = $me['ok'] ? cleanText($me['data']['nickname'] ?? '', 120) : '';
    $fields['cuentaEmail'] = $me['ok'] ? cleanText($me['data']['email'] ?? '', 160) : '';
    return $fields;
}

function mercadoPagoNeedsRefresh(array $stored, int $now): bool {
    if (($stored['conexion'] ?? '') !== 'oauth' || trim((string)($stored['refreshToken'] ?? '')) === '') return false;
    $expires = strtotime((string)($stored['expiresAt'] ?? ''));
    return $expires !== false && $expires - $now < MP_OAUTH_REFRESH_MARGIN_SECONDS;
}

/**
 * Renueva el Access Token si está por vencer. Nunca tira excepción: si Mercado Pago
 * rechaza la renovación, el token actual sigue sirviendo hasta que vence, el error queda
 * a la vista en el panel y se reintenta cada 6 horas. $load/$save existen para las pruebas.
 */
function mercadoPagoRefreshIfNeeded(array $stored, ?int $now = null, ?callable $load = null, ?callable $save = null): array {
    $now ??= time();
    if (!mercadoPagoOAuthAvailable() || !mercadoPagoNeedsRefresh($stored, $now)) return $stored;
    $lastTry = strtotime((string)($stored['refreshIntentoAt'] ?? ''));
    if ($lastTry !== false && $now - $lastTry < MP_OAUTH_RETRY_SECONDS) return $stored;
    $load ??= static fn(): ?array => fsGet(MP_RUTA_CONFIG);
    $save ??= static function (array $patch): void { fsPatch(MP_RUTA_CONFIG, $patch + ['updatedAt' => fsTimestamp()]); };

    // Un solo proceso renueva: el refresh_token se consume al usarse.
    $lock = @fopen(carpetaTemporal('mp') . '/refresh-' . hash('sha256', SITE_URL) . '.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $stored; }
    try {
        $fresh = $load() ?? $stored;
        if (!mercadoPagoNeedsRefresh($fresh, $now)) return $fresh;
        try {
            $patch = mercadoPagoTokenFields(mercadoPagoTokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => (string)$fresh['refreshToken'],
            ]), $now);
        } catch (Throwable $error) {
            $patch = ['oauthError' => cleanText($error->getMessage(), 240), 'refreshIntentoAt' => gmdate('Y-m-d\TH:i:s\Z', $now)];
        }
        try { $save($patch); } catch (Throwable) { /* se reintenta en el próximo uso */ }
        return array_merge($fresh, $patch);
    } catch (Throwable) {
        return $stored;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Lo que ve el panel: el estado de la conexión, nunca tokens. */
function mercadoPagoAdminView(array $stored): array {
    $conexion = '';
    if (!empty($stored['accessToken'])) $conexion = ($stored['conexion'] ?? '') === 'oauth' ? 'oauth' : 'manual';
    elseif (MP_ACCESS_TOKEN !== '') $conexion = 'entorno';
    return [
        'conectado' => $conexion !== '',
        'conexion' => $conexion,
        'cuentaNombre' => (string)($stored['cuentaNombre'] ?? ''),
        'cuentaEmail' => (string)($stored['cuentaEmail'] ?? ''),
        'expiresAt' => (string)($stored['expiresAt'] ?? ''),
        'liveMode' => ($stored['liveMode'] ?? true) !== false,
        'oauthError' => (string)($stored['oauthError'] ?? ''),
        'oauthDisponible' => mercadoPagoOAuthAvailable(),
    ];
}

function mercadoPagoConfig(): array {
    return fsGet(MP_RUTA_CONFIG) ?? [];
}

/** Access Token de la cuenta del instituto (renovado si hace falta). */
function mercadoPagoAccessToken(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stored = mercadoPagoRefreshIfNeeded(mercadoPagoConfig());
        $token = trim((string)($stored['accessToken'] ?? ''));
    } catch (Throwable $error) {
        error_log('[mercadopago] ' . $error->getMessage());
        $token = '';
    }
    return $cached = ($token !== '' ? $token : MP_ACCESS_TOKEN);
}

/** URL de vuelta: la misma para aprobado, pendiente y rechazado; la página consulta el estado real. */
function urlGracias(string $ventaId, string $clave): string {
    return SITE_URL . '/ebooks/gracias/?orden=' . rawurlencode($ventaId) . '&clave=' . rawurlencode($clave);
}

function cuerpoPreferencia(array $venta, array $ebook, string $clave, ?int $ahora = null): array {
    $ahora ??= time();
    $partes = explode(' ', (string)($venta['compradorNombre'] ?? ''), 2);
    $vuelta = urlGracias((string)$venta['id'], $clave);
    $portada = str_starts_with((string)($ebook['portada'] ?? ''), '/') ? SITE_URL . $ebook['portada'] : '';
    $item = [
        'id' => (string)$ebook['id'],
        'title' => utf8Slice(($ebook['titulo'] ?? 'Ebook') . ' (PDF)', 250),
        'description' => 'Ebook en PDF de descarga inmediata',
        'category_id' => 'virtual_goods',
        'quantity' => 1,
        'currency_id' => MONEDA,
        'unit_price' => (float)$venta['monto'],
    ];
    if (str_starts_with($portada, 'https://')) $item['picture_url'] = $portada;
    return [
        'items' => [$item],
        'payer' => ['name' => $partes[0] ?? '', 'surname' => $partes[1] ?? '', 'email' => (string)$venta['compradorEmail']],
        'external_reference' => (string)$venta['id'],
        'notification_url' => SITE_URL . '/api/ebooks/webhook.php?source_news=webhooks',
        'back_urls' => ['success' => $vuelta, 'pending' => $vuelta, 'failure' => $vuelta],
        'auto_return' => 'approved',
        // Aprobado o rechazado al instante: sin pagos "en revisión" que demoren la descarga.
        'binary_mode' => true,
        // Sin efectivo (Rapipago / Pago Fácil): el PDF se entrega apenas se acredita.
        'payment_methods' => ['excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']]],
        'statement_descriptor' => 'CULTURA PIXEL',
        'expires' => true,
        'expiration_date_from' => gmdate('Y-m-d\TH:i:s.000\Z', $ahora - 60),
        'expiration_date_to' => gmdate('Y-m-d\TH:i:s.000\Z', $ahora + PREFERENCIA_HORAS * 3600),
        'metadata' => ['venta_id' => (string)$venta['id'], 'ebook_id' => (string)$ebook['id']],
    ];
}

function crearPreferencia(array $venta, array $ebook, string $clave, string $token): array {
    $respuesta = mercadoPagoHttp('POST', '/checkout/preferences', cuerpoPreferencia($venta, $ebook, $clave), [
        'Authorization: Bearer ' . $token,
        'X-Idempotency-Key: preferencia-' . $venta['id'],
    ]);
    if (!$respuesta['ok'] || empty($respuesta['data']['id']) || empty($respuesta['data']['init_point'])) {
        throw new RuntimeException('Mercado Pago rechazó la preferencia (HTTP ' . $respuesta['status'] . '): ' . (string)($respuesta['data']['message'] ?? 'sin detalle'));
    }
    return $respuesta['data'];
}

function obtenerPago(string $pagoId, string $token): ?array {
    $respuesta = mercadoPagoHttp('GET', '/v1/payments/' . rawurlencode($pagoId), null, ['Authorization: Bearer ' . $token]);
    if ($respuesta['status'] === 404) return null;
    if (!$respuesta['ok']) throw new RuntimeException('No se pudo consultar el pago ' . $pagoId . ' (HTTP ' . $respuesta['status'] . ').');
    return $respuesta['data'];
}

/** Pagos de una venta, del más nuevo al más viejo. */
function buscarPagos(string $ventaId, string $token): array {
    $consulta = http_build_query(['external_reference' => $ventaId, 'sort' => 'date_created', 'criteria' => 'desc', 'limit' => 10]);
    $respuesta = mercadoPagoHttp('GET', '/v1/payments/search?' . $consulta, null, ['Authorization: Bearer ' . $token]);
    if (!$respuesta['ok']) throw new RuntimeException('No se pudieron buscar los pagos de ' . $ventaId . ' (HTTP ' . $respuesta['status'] . ').');
    return is_array($respuesta['data']['results'] ?? null) ? $respuesta['data']['results'] : [];
}

function parseSignature(string $signature): array {
    $parts = [];
    foreach (explode(',', $signature) as $piece) {
        [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
        if ($key !== '') $parts[trim($key)] = trim($value);
    }
    return $parts;
}

/** Verificación de x-signature según la documentación de Mercado Pago. */
function verifyMercadoPagoSignatureWith(string $dataId, string $requestId, array $signature, string $secret): bool {
    if ($secret === '' || empty($signature['ts']) || empty($signature['v1']) || $requestId === '' || $dataId === '') return false;
    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $signature['ts'] . ';';
    return hash_equals(hash_hmac('sha256', $manifest, $secret), (string)$signature['v1']);
}

/**
 * Sin MP_WEBHOOK_SECRET la firma no se exige: el estado del pago SIEMPRE se vuelve a
 * consultar a la API de Mercado Pago y jamás se cree el cuerpo de la notificación.
 */
function verifyMercadoPagoSignature(string $dataId): bool {
    if (MP_WEBHOOK_SECRET === '') return true;
    return verifyMercadoPagoSignatureWith($dataId, requestHeader('X-Request-Id'), parseSignature(requestHeader('X-Signature')), MP_WEBHOOK_SECRET);
}
