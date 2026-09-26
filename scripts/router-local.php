<?php
declare(strict_types=1);

// Router del servidor embebido de PHP (php -S) para desarrollo local: imita lo que en
// Hostinger hacen los .htaccess (bloqueos, redirecciones y carpetas con barra final).

$ruta = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$raiz = dirname(__DIR__);

$bloqueadas = [
    '#^/(tests|scripts|secretos|storage|node_modules|tmp)(/|$)#i',
    '#^/api/lib(/|$)#i',
    '#^/api/(config\.php|secrets\.local(\.example)?\.php|mercadopago\.local\.php|service-account\.json)#i',
    '#^/(package(-lock)?\.json|firebase\.json|firestore\.rules|firestore\.indexes\.json|EBOOKS\.md|\.vercelignore)$#i',
    '#(^|/)\.(git|env|claude|htaccess)#i',
];
foreach ($bloqueadas as $patron) {
    if (preg_match($patron, $ruta)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No encontrado';
        return true;
    }
}

$redirecciones = ['/ebooks' => '/#ebooks', '/ebooks/' => '/#ebooks', '/quinceaneras' => '/ebooks/quinceaneras/', '/quinceaneras/' => '/ebooks/quinceaneras/'];
if (isset($redirecciones[$ruta])) {
    header('Location: ' . $redirecciones[$ruta], true, 302);
    return true;
}

// Modo simulado: el panel se conecta al emulador de Auth (ver admin/admin.js).
if ($ruta === '/__dev/config') {
    $auth = getenv('FIREBASE_AUTH_EMULATOR_HOST');
    if (!$auth) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: application/json');
    echo json_encode(['authEmulator' => 'http://' . $auth]);
    return true;
}

if ($ruta !== '/' && is_dir($raiz . $ruta) && !str_ends_with($ruta, '/')) {
    header('Location: ' . $ruta . '/', true, 301);
    return true;
}
if (!file_exists($raiz . $ruta) && !is_dir($raiz . $ruta)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No encontrado';
    return true;
}
return false;
