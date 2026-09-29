<?php
declare(strict_types=1);

// POST /api/ebooks/comprar.php  { ebookId, nombre, email }
// Registra la venta como pendiente, crea la preferencia de Checkout Pro y devuelve la URL
// de pago. El precio sale de Firestore, nunca del navegador.

require_once dirname(__DIR__) . '/lib/ebooks.php';
require_once dirname(__DIR__) . '/lib/ventas.php';

try {
    requireMethod('POST');
    // Holgado: en celulares muchas personas comparten IP (CGNAT de las operadoras).
    rateLimit('comprar', 20, 600);
    $entrada = readJsonBody();
    $comprador = validarComprador($entrada);
    $ebook = leerEbook(cleanText($entrada['ebookId'] ?? '', 60));
    if (!$ebook) throw new ErrorPublico('No encontramos ese ebook.', 404);
    if (!sePuedeComprar($ebook)) {
        throw new ErrorPublico('Este ebook no está a la venta en este momento. Probá de nuevo más tarde.', 409, 'no_disponible');
    }
    $token = mercadoPagoAccessToken();
    if ($token === '') {
        throw new ErrorPublico('Las compras todavía no están habilitadas. Probá de nuevo más tarde.', 503, 'sin_mercadopago');
    }

    ['venta' => $venta, 'clave' => $clave] = crearVenta($ebook, $comprador, (int)($ebook['descargasMax'] ?? 0) ?: DESCARGAS_MAX);
    try {
        $preferencia = crearPreferencia($venta, $ebook, $clave, $token);
    } catch (Throwable $error) {
        error_log('[comprar] preferencia: ' . $error->getMessage());
        try {
            fsPatch('ventas/' . $venta['id'], ['estado' => 'cancelado', 'incidente' => 'error_preferencia', 'actualizadaAt' => fsTimestamp()]);
        } catch (Throwable) {
        }
        throw new ErrorPublico('No pudimos conectar con Mercado Pago. Probá de nuevo en unos minutos.', 502, 'mercadopago');
    }
    fsPatch('ventas/' . $venta['id'], ['mpPreferenciaId' => (string)$preferencia['id'], 'actualizadaAt' => fsTimestamp()]);
    jsonResponse(['codigo' => $venta['id'], 'clave' => $clave, 'url' => $preferencia['init_point']], 201);
} catch (Throwable $error) {
    responderError($error, 'comprar');
}
