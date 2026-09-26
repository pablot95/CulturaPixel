<?php
declare(strict_types=1);

// POST /api/ebooks/admin.php  { accion, ... }  (Authorization: Bearer <ID token de Firebase>)
// Cada acción del panel pasa por acá: las reglas de Firestore niegan el acceso directo
// desde el navegador, así los tokens de Mercado Pago nunca salen del servidor.

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/ebooks.php';
require_once dirname(__DIR__) . '/lib/registro.php';
require_once dirname(__DIR__) . '/lib/ventas.php';

const ESTADOS_VENTA = ['aprobado', 'pendiente', 'rechazado', 'cancelado', 'reembolsado'];
const VENTAS_POR_PAGINA = 25;

function resumenVentas(): array {
    $mes = mesArgentina();
    return [
        'aprobadas' => fsCountSum('ventas', [['estado', '==', 'aprobado']], 'monto'),
        'mes' => fsCountSum('ventas', [['estado', '==', 'aprobado'], ['mesAprobacion', '==', $mes]], 'monto'),
        'pendientes' => fsCountSum('ventas', [['estado', '==', 'pendiente']])['cantidad'],
        'revision' => fsCountSum('ventas', [['requiereRevision', '==', true]])['cantidad'],
        'mesActual' => $mes,
    ];
}

function ventaDeEntrada(array $entrada): array {
    $codigo = strtoupper(cleanText($entrada['codigo'] ?? '', 20));
    if (!preg_match(CODIGO_VENTA, $codigo)) throw new ErrorPublico('Código de venta inválido.', 422);
    $venta = fsGet('ventas/' . $codigo);
    if (!$venta) throw new ErrorPublico('No existe esa venta.', 404);
    return $venta;
}

function listarVentas(array $entrada): array {
    $busqueda = cleanText($entrada['buscar'] ?? '', 160);
    if ($busqueda !== '') {
        $posibleCodigo = (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($busqueda));
        if (preg_match(CODIGO_VENTA, $posibleCodigo)) {
            $venta = fsGet('ventas/' . $posibleCodigo);
            if ($venta) return ['ventas' => [vistaAdminVenta($venta)], 'siguiente' => null];
        }
        $porEmail = fsQuery('ventas', [['compradorEmail', '==', strtolower($busqueda)]], [], 50);
        usort($porEmail, static fn(array $a, array $b): int => strcmp((string)($b['creadaAt'] ?? ''), (string)($a['creadaAt'] ?? '')));
        return ['ventas' => array_map('vistaAdminVenta', $porEmail), 'siguiente' => null];
    }

    $estado = in_array($entrada['estado'] ?? '', ESTADOS_VENTA, true) ? $entrada['estado'] : '';
    $cursor = is_array($entrada['despuesDe'] ?? null) ? $entrada['despuesDe'] : null;
    $cursorValido = $cursor && preg_match(CODIGO_VENTA, (string)($cursor['codigo'] ?? '')) && strtotime((string)($cursor['creadaAt'] ?? '')) !== false;
    $documentos = fsQuery(
        'ventas',
        $estado !== '' ? [['estado', '==', $estado]] : [],
        [['creadaAt', 'desc'], ['__name__', 'desc']],
        VENTAS_POR_PAGINA + 1,
        $cursorValido ? [['timestampValue' => (string)$cursor['creadaAt']], ['referenceValue' => fsDocumentName('ventas/' . $cursor['codigo'])]] : null,
    );
    $pagina = array_slice($documentos, 0, VENTAS_POR_PAGINA);
    $ultima = $pagina ? $pagina[count($pagina) - 1] : null;
    return [
        'ventas' => array_map('vistaAdminVenta', $pagina),
        'siguiente' => count($documentos) > VENTAS_POR_PAGINA && $ultima ? ['codigo' => $ultima['id'], 'creadaAt' => $ultima['creadaAt']] : null,
    ];
}

function guardarEbook(array $admin, array $entrada): array {
    $ebook = leerEbook(cleanText($entrada['id'] ?? '', 60));
    if (!$ebook) throw new ErrorPublico('No existe ese ebook.', 404);
    $cambios = [];
    if (array_key_exists('precio', $entrada)) {
        $precio = $entrada['precio'];
        if (!is_int($precio) || $precio < 100 || $precio > 10000000) throw new ErrorPublico('El precio tiene que ser un número entero en pesos, de $100 para arriba.', 422);
        $cambios['precio'] = $precio;
    }
    if (array_key_exists('activo', $entrada)) $cambios['activo'] = $entrada['activo'] === true;
    if (!$cambios) throw new ErrorPublico('No hay cambios para guardar.', 422);
    $actualizado = fsPatch('ebooks/' . $ebook['id'], $cambios + ['actualizadoAt' => fsTimestamp()]);
    auditar('ebook_guardar', $admin['email'], ['ebook' => $ebook['id'], 'antes' => ['precio' => $ebook['precio'] ?? null, 'activo' => $ebook['activo'] ?? null], 'despues' => $cambios]);
    return ['ebook' => vistaAdminEbook($actualizado)];
}

function iniciarConexionMP(array $admin): array {
    if (!mercadoPagoOAuthAvailable()) {
        throw new ErrorPublico('La conexión con Mercado Pago no está configurada en el servidor: falta subir api/mercadopago.local.php.', 503);
    }
    // El state viaja a Mercado Pago y vuelve en la URL: se guarda su hash (un solo uso,
    // 15 minutos) y además queda en una cookie de este navegador.
    $state = mercadoPagoNewState();
    fsPatch(MP_RUTA_CONFIG, [
        'oauthState' => hash('sha256', $state),
        'oauthStateExpira' => gmdate('Y-m-d\TH:i:s\Z', time() + MP_OAUTH_STATE_SECONDS),
        'updatedAt' => fsTimestamp(),
    ], []);
    setcookie(MP_COOKIE_STATE, $state, [
        'expires' => time() + MP_OAUTH_STATE_SECONDS, 'path' => '/api/',
        'secure' => str_starts_with(SITE_URL, 'https://'), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    auditar('mp_conectar_inicio', $admin['email']);
    return ['url' => mercadoPagoAuthorizeUrl($state)];
}

try {
    requireMethod('POST');
    rateLimit('admin', 120, 60);
    $admin = requireAdmin();
    $entrada = readJsonBody();
    $accion = is_string($entrada['accion'] ?? null) ? $entrada['accion'] : '';

    $respuesta = match ($accion) {
        'sesion' => [
            'admin' => ['email' => $admin['email']],
            'mp' => mercadoPagoAdminView(mercadoPagoRefreshIfNeeded(mercadoPagoConfig())),
            'ebooks' => array_map('vistaAdminEbook', listarEbooks()),
            'resumen' => resumenVentas(),
        ],
        'resumen' => ['resumen' => resumenVentas()],
        'ventas' => listarVentas($entrada),
        'ebook-guardar' => guardarEbook($admin, $entrada),
        'mp-conectar' => iniciarConexionMP($admin),
        'mp-desconectar' => (static function () use ($admin): array {
            fsPatch(MP_RUTA_CONFIG, array_fill_keys(MP_OAUTH_FIELDS, '') + ['oauthState' => '', 'updatedAt' => fsTimestamp()], []);
            auditar('mp_desconectar', $admin['email']);
            return ['mp' => mercadoPagoAdminView([])];
        })(),
        'venta-verificar' => (static function () use ($entrada): array {
            ['venta' => $venta, 'evento' => $evento] = reconciliar(ventaDeEntrada($entrada), '', 'panel');
            return ['venta' => vistaAdminVenta($venta), 'evento' => $evento];
        })(),
        'venta-link' => (static function () use ($admin, $entrada): array {
            $venta = ventaDeEntrada($entrada);
            if (($venta['estado'] ?? '') !== 'aprobado') throw new ErrorPublico('Solo las ventas aprobadas tienen enlace de descarga.', 409);
            $url = rotarClave($venta);
            auditar('venta_link', $admin['email'], ['venta' => $venta['id']]);
            return ['url' => $url];
        })(),
        'venta-reiniciar' => (static function () use ($admin, $entrada): array {
            $venta = ventaDeEntrada($entrada);
            $actualizada = reiniciarDescargas($venta);
            auditar('venta_reiniciar_descargas', $admin['email'], ['venta' => $venta['id'], 'antes' => (int)($venta['descargas'] ?? 0)]);
            return ['venta' => vistaAdminVenta($actualizada)];
        })(),
        default => throw new ErrorPublico('Acción inválida.', 400),
    };
    jsonResponse($respuesta);
} catch (Throwable $error) {
    responderError($error, 'admin');
}
