<?php
declare(strict_types=1);

/*
 * Vuelta de "Conectar con Mercado Pago" (mismo circuito que api/mp-conectar.php del
 * Template-Ecommerce): Mercado Pago vuelve al puente de Gokywebs (https://gokywebs.com/mp-conectar/)
 * y el puente redirige acá con ?code&state. Se valida el state (hash guardado + cookie de este
 * navegador, un solo uso, 15 minutos), se canjea el código por las claves de la cuenta del
 * instituto y se vuelve al panel.
 */

require_once __DIR__ . '/lib/mercadopago.php';
require_once __DIR__ . '/lib/registro.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function volverAlPanel(string $resultado): never {
    setcookie(MP_COOKIE_STATE, '', ['expires' => time() - 3600, 'path' => '/api/', 'secure' => str_starts_with(SITE_URL, 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . SITE_URL . '/admin/?mp=' . rawurlencode($resultado), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') volverAlPanel('error');

try {
    rateLimit('mp-conectar', 20, 300);
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    $cookie = (string)($_COOKIE[MP_COOKIE_STATE] ?? '');
    $guardado = mercadoPagoConfig();
    $esperado = (string)($guardado['oauthState'] ?? '');
    $vence = strtotime((string)($guardado['oauthStateExpira'] ?? ''));
    $valido = $state !== '' && $esperado !== '' && $cookie !== ''
        && hash_equals($esperado, hash('sha256', $state)) && hash_equals($cookie, $state)
        && $vence !== false && $vence >= time();
    if (!$valido) volverAlPanel('vencido');

    // El state es de un solo uso: se borra antes de canjear.
    fsPatch(MP_RUTA_CONFIG, ['oauthState' => '', 'oauthStateExpira' => '']);
    if ($code === '' || isset($_GET['error'])) volverAlPanel('cancelado');

    try {
        $campos = mercadoPagoExchangeCode($code, time());
    } catch (RuntimeException $error) {
        fsPatch(MP_RUTA_CONFIG, ['oauthError' => cleanText($error->getMessage(), 240), 'updatedAt' => fsTimestamp()]);
        volverAlPanel('rechazado');
    }
    fsPatch(MP_RUTA_CONFIG, $campos + ['updatedAt' => fsTimestamp()]);
    auditar('mp_conectar', 'mercadopago:' . ($campos['mpUserId'] ?? ''), ['cuenta' => $campos['cuentaNombre'] ?? '']);
    volverAlPanel('conectado');
} catch (Throwable $error) {
    error_log('[mp-conectar] ' . $error->getMessage());
    volverAlPanel('error');
}
