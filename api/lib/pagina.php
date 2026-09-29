<?php
declare(strict_types=1);

// Página HTML mínima para errores de la descarga (se abre como enlace, así que un error
// tiene que verse como página y no como JSON).

require_once __DIR__ . '/common.php';

function responderPagina(int $status, string $titulo, string $mensaje, string $volver = '', string $codigo = ''): never {
    // Sin marca ni medios de contacto: las páginas de los ebooks son independientes del instituto.
    $acciones = $volver !== '' ? '<div class="acciones"><a class="boton" href="' . escaparHtml($volver) . '">Volver a mi compra</a></div>' : '';
    if (!headers_sent()) {
        header_remove('Content-Length');
        header_remove('Content-Disposition');
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: no-referrer');
    }
    echo '<!doctype html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>' . escaparHtml($titulo) . '</title>
<link rel="icon" type="image/png" href="/images/favicon.png">
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; box-sizing: border-box;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f1035;
    background: radial-gradient(circle at 100% 0%, #efe9ff 0%, #ffffff 60%); }
  main { width: min(480px, 100%); background: #fff; border: 1px solid #d9defa; border-radius: 20px; padding: 32px 26px;
    box-shadow: 0 26px 54px rgba(15, 16, 53, .12); text-align: center; }
  h1 { font-size: 1.45rem; line-height: 1.2; margin: 0 0 12px; }
  p { color: #4a4a7a; line-height: 1.6; margin: 0 0 24px; }
  .acciones { display: grid; gap: 10px; }
  .boton { display: inline-flex; justify-content: center; align-items: center; min-height: 48px; border-radius: 999px; padding: 0 22px;
    font-weight: 700; text-decoration: none; color: #fff; background: linear-gradient(135deg, #6d3fe0, #5e3cc8); }
  .boton:focus-visible { outline: 3px solid #b9a7ff; outline-offset: 2px; }
</style>
</head>
<body>
<main>
  <h1>' . escaparHtml($titulo) . '</h1>
  <p>' . escaparHtml($mensaje) . '</p>
  ' . $acciones . '
</main>
</body>
</html>';
    exit;
}
