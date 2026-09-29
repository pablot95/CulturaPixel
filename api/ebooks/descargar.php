<?php
declare(strict_types=1);

// GET /api/ebooks/descargar.php?orden=CODIGO&clave=...[&ver=1]
// Entrega el PDF de una compra aprobada desde storage/ebooks/ (que no es accesible desde
// la web). Con ver=1 se abre en el navegador en lugar de descargarse.

require_once dirname(__DIR__) . '/lib/ebooks.php';
require_once dirname(__DIR__) . '/lib/pagina.php';
require_once dirname(__DIR__) . '/lib/ventas.php';

$codigo = strtoupper(substr(trim((string)($_GET['orden'] ?? '')), 0, 20));
$clave = substr(trim((string)($_GET['clave'] ?? '')), 0, 100);
$ver = ($_GET['ver'] ?? '') === '1';
$volver = '/ebooks/gracias/?orden=' . rawurlencode($codigo) . '&clave=' . rawurlencode($clave);
$tituloNoEncontrada = 'No encontramos tu compra';
$mensajeNoEncontrada = 'Revisá que el enlace esté completo. Si lo copiaste de un mensaje, puede haberse cortado.';

try {
    $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($metodo, ['GET', 'HEAD'], true)) responderPagina(405, 'Método no permitido', 'Abrí el enlace de descarga desde tu página de compra.');
    rateLimit('descargar', 30, 600);
    if (!preg_match(CODIGO_VENTA, $codigo)) responderPagina(404, $tituloNoEncontrada, $mensajeNoEncontrada);
    $venta = fsGet('ventas/' . $codigo);
    if (!$venta || !claveCorrecta($venta, $clave)) responderPagina(404, $tituloNoEncontrada, $mensajeNoEncontrada);
    if (!in_array($venta['estado'] ?? '', ['aprobado', 'reembolsado'], true)) {
        try {
            ['venta' => $venta] = reconciliar($venta);
        } catch (Throwable $error) {
            error_log('[descargar] reconciliar: ' . $error->getMessage());
        }
    }

    $decision = evaluarDescarga($venta);
    if (!$decision['permitido']) {
        match ($decision['motivo']) {
            'limite' => responderPagina(403, 'Llegaste al límite de descargas',
                'Este enlace ya se usó ' . ((int)($venta['descargasMax'] ?? 0) ?: DESCARGAS_MAX) . ' veces, que es el máximo por compra.',
                $volver, $codigo),
            'reembolsada' => responderPagina(403, 'Esta compra fue reembolsada', 'El pago se devolvió, así que la descarga ya no está disponible.', $volver, $codigo),
            default => responderPagina(403, 'Tu pago todavía no está confirmado', 'Apenas Mercado Pago lo apruebe vas a poder descargar el libro desde tu página de compra. Suele tardar unos segundos.', $volver, $codigo),
        };
    }

    $ruta = rutaPdf((string)$venta['ebookId']);
    if (!is_file($ruta) || !is_readable($ruta)) throw new RuntimeException('Falta el PDF de ' . $venta['ebookId'] . ' en storage/ebooks/.');
    $ebook = null;
    try {
        $ebook = leerEbook((string)$venta['ebookId']);
    } catch (Throwable) {
    }

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)filesize($ruta));
    header('Content-Disposition: ' . disposicion($ver ? 'inline' : 'attachment', nombreDeDescarga($ebook, $venta)));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    header('Referrer-Policy: no-referrer');
    if ($metodo === 'HEAD') exit;

    // Si el contador no se puede actualizar, la descarga sigue igual: ya se validó la compra.
    if ($decision['contar']) {
        try {
            registrarDescarga($venta);
        } catch (Throwable $error) {
            error_log('[descargar] contador: ' . $error->getMessage());
        }
    }
    @set_time_limit(0);
    $archivo = fopen($ruta, 'rb');
    while ($archivo && !feof($archivo) && !connection_aborted()) {
        echo fread($archivo, 1048576);
        flush();
    }
    if ($archivo) fclose($archivo);
    exit;
} catch (Throwable $error) {
    error_log('[descargar] ' . $error->getMessage());
    responderPagina(500, 'No pudimos preparar tu descarga', 'Probá de nuevo en unos minutos.', $volver, $codigo);
}
