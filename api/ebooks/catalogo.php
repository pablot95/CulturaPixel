<?php
declare(strict_types=1);

// GET /api/ebooks/catalogo.php — precio y disponibilidad de cada ebook (público).
// Las páginas muestran el precio escrito en el HTML y lo actualizan con esto, así un
// cambio de precio desde el panel se ve sin tocar código.

require_once dirname(__DIR__) . '/lib/ebooks.php';

try {
    requireMethod('GET', 'HEAD');
    $ebooks = array_map('vistaPublicaEbook', listarEbooks());
    jsonResponse(['ebooks' => $ebooks], 200, ['Cache-Control' => 'public, max-age=30']);
} catch (Throwable $error) {
    responderError($error, 'catalogo');
}
