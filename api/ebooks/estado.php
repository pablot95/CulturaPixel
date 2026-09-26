<?php
declare(strict_types=1);

// POST /api/ebooks/estado.php  { orden, clave, pagoId? }
// Estado de una compra para la página de gracias. Si todavía no figura aprobada, le
// pregunta a Mercado Pago (rescate por si el webhook se demora).

require_once dirname(__DIR__) . '/lib/ventas.php';

try {
    requireMethod('POST');
    rateLimit('estado', 90, 300);
    $entrada = readJsonBody();
    $codigo = strtoupper(cleanText($entrada['orden'] ?? '', 20));
    $clave = cleanText($entrada['clave'] ?? '', 100);
    $pagoId = cleanText((string)($entrada['pagoId'] ?? ''), 24);
    if (!preg_match(CODIGO_VENTA, $codigo) || $clave === '') throw new ErrorPublico('No encontramos tu compra.', 404);
    $venta = fsGet('ventas/' . $codigo);
    if (!$venta || !claveCorrecta($venta, $clave)) throw new ErrorPublico('No encontramos tu compra.', 404);

    if (!in_array($venta['estado'] ?? '', ['aprobado', 'reembolsado'], true)) {
        // Una consulta a Mercado Pago cada pocos segundos por compra, aunque la página sondee;
        // el pago que informa la vuelta del checkout se consulta siempre la primera vez.
        $pagoNuevo = $pagoId !== '' && pasoElTiempo('estado-pago|' . $codigo . '|' . $pagoId, 3600);
        if ($pagoNuevo || pasoElTiempo('estado|' . $codigo, 4)) {
            try {
                ['venta' => $venta] = reconciliar($venta, $pagoId);
            } catch (Throwable $error) {
                error_log('[estado] reconciliar: ' . $error->getMessage());
            }
        }
    }
    jsonResponse(vistaComprador($venta, $clave));
} catch (Throwable $error) {
    responderError($error, 'estado');
}
