<?php
declare(strict_types=1);

// POST /api/ebooks/webhook.php — notificaciones de pago de Mercado Pago.
// La URL viaja en cada preferencia (notification_url), así que no hace falta configurarla
// en la cuenta. El cuerpo de la notificación nunca se cree: con el id se vuelve a consultar
// el pago a la API y se comparan referencia, monto y moneda (como el webhook del template).

require_once dirname(__DIR__) . '/lib/registro.php';
require_once dirname(__DIR__) . '/lib/ventas.php';

function terminar(int $status, string $texto): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $texto;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') terminar(405, 'método no permitido');
$cuerpo = json_decode((string)file_get_contents('php://input'), true);
$cuerpo = is_array($cuerpo) ? $cuerpo : [];
$tipo = strtolower(cleanText($cuerpo['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '', 40));
// PHP convierte el parámetro data.id de la URL en data_id.
$pagoId = cleanText($_GET['data_id'] ?? $_GET['data.id'] ?? $cuerpo['data']['id'] ?? $_GET['id'] ?? '', 30);

// Solo pagos con id numérico: merchant_order y demás se confirman y se ignoran.
if (($tipo !== '' && !str_starts_with($tipo, 'payment')) || !preg_match('/^\d{1,20}$/', $pagoId)) terminar(200, 'ignorado');
try {
    rateLimit('webhook', 120, 60);
} catch (Throwable) {
    terminar(429, 'demasiadas solicitudes');
}
if (!verifyMercadoPagoSignature($pagoId)) {
    registrarWebhook('firma_invalida', '', ['pagoId' => $pagoId]);
    terminar(401, 'firma inválida');
}

$codigo = '';
try {
    $token = mercadoPagoAccessToken();
    if ($token === '') throw new RuntimeException('Mercado Pago no está conectado.');
    $pago = obtenerPago($pagoId, $token);
    if (!$pago) terminar(200, 'pago inexistente');
    $codigo = (string)($pago['external_reference'] ?? '');
    // Pagos de la cuenta que no son ventas de ebooks (otros cobros del instituto).
    if (!preg_match(CODIGO_VENTA, $codigo)) terminar(200, 'sin venta asociada');
    $venta = fsGet('ventas/' . $codigo);
    if (!$venta) {
        registrarWebhook('venta_inexistente', $codigo, ['pagoId' => $pagoId]);
        terminar(200, 'venta inexistente');
    }
    ['evento' => $evento] = aplicarPago($venta, $pago, 'webhook');
    registrarWebhook($evento, $codigo, ['pagoId' => $pagoId, 'estado' => (string)($pago['status'] ?? ''), 'detalle' => (string)($pago['status_detail'] ?? '')]);
    terminar(200, 'ok');
} catch (Throwable $error) {
    // 500: Mercado Pago reintenta la notificación más tarde.
    error_log('[webhook] ' . $error->getMessage());
    registrarWebhook('error', $codigo, ['pagoId' => $pagoId, 'mensaje' => utf8Slice($error->getMessage(), 300)]);
    terminar(500, 'error');
}
