<?php
declare(strict_types=1);

/*
 * Ciclo de vida de una venta de ebook: pendiente → aprobado (se habilita la descarga) →
 * eventualmente reembolsado. El estado real SIEMPRE sale de la API de Mercado Pago
 * (webhook, vuelta del checkout o verificación desde el panel), nunca del navegador.
 *
 * Documento ventas/{código}: el código (8 caracteres sin ambiguos) es el número de compra
 * que ve el comprador. La descarga exige además la `clave` secreta que viaja en el enlace
 * de la página de gracias; en Firestore solo se guarda su hash.
 */

require_once __DIR__ . '/mercadopago.php';

const ALFABETO_CODIGO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const CODIGO_VENTA = '/^[A-HJ-NP-Z2-9]{8}$/';

function nuevoCodigo(): string {
    // 32 símbolos: 256 es múltiplo de 32, así que el módulo no introduce sesgo.
    $codigo = '';
    foreach (str_split(random_bytes(8)) as $byte) $codigo .= ALFABETO_CODIGO[ord($byte) % 32];
    return $codigo;
}

function nuevaClave(): string {
    return mercadoPagoBase64Url(random_bytes(24));
}

function hashClave(string $clave): string {
    return hash('sha256', $clave);
}

function claveCorrecta(?array $venta, mixed $clave): bool {
    if (!$venta || empty($venta['claveHash']) || !is_string($clave) || strlen($clave) < 20 || strlen($clave) > 100) return false;
    return hash_equals((string)$venta['claveHash'], hashClave($clave));
}

function validarComprador(array $entrada): array {
    $nombre = cleanText($entrada['nombre'] ?? '', 120);
    $email = strtolower(cleanText($entrada['email'] ?? '', 160));
    if (mb_strlen($nombre) < 2) throw new ErrorPublico('Escribí tu nombre y apellido.', 422, 'nombre');
    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $email)) throw new ErrorPublico('Revisá el email: parece incompleto.', 422, 'email');
    return ['nombre' => $nombre, 'email' => $email];
}

/** "2026-09" en hora de Argentina: permite sumar las ventas del mes sin índices compuestos. */
function mesArgentina(?int $momento = null): string {
    return (new DateTimeImmutable('@' . ($momento ?? time())))->setTimezone(new DateTimeZone(ZONA_HORARIA))->format('Y-m');
}

function estadoDesdeMP(string $status): string {
    return match ($status) {
        'approved' => 'aprobado',
        'rejected' => 'rechazado',
        'cancelled' => 'cancelado',
        'refunded', 'charged_back' => 'reembolsado',
        'pending', 'in_process', 'authorized', 'in_mediation' => 'pendiente',
        default => '',
    };
}

function crearVenta(array $ebook, array $comprador, int $descargasMax = DESCARGAS_MAX): array {
    $clave = nuevaClave();
    $datos = [
        'ebookId' => (string)$ebook['id'],
        'ebookTitulo' => (string)($ebook['titulo'] ?? ''),
        'monto' => $ebook['precio'],
        'moneda' => (string)($ebook['moneda'] ?? MONEDA),
        'compradorNombre' => $comprador['nombre'],
        'compradorEmail' => $comprador['email'],
        'estado' => 'pendiente',
        'claveHash' => hashClave($clave),
        'descargas' => 0,
        'descargasMax' => $descargasMax,
        'ultimaDescargaAt' => null,
        'mpPreferenciaId' => '',
        'mpPagoId' => '',
        'mpEstado' => '',
        'mpDetalle' => '',
        'mpMedio' => '',
        'requiereRevision' => false,
        'incidente' => '',
        'mesAprobacion' => '',
        'aprobadaAt' => null,
        'creadaAt' => fsTimestamp(),
        'actualizadaAt' => fsTimestamp(),
    ];
    for ($intento = 0; $intento < 4; $intento++) {
        try {
            return ['venta' => fsCreate('ventas', nuevoCodigo(), $datos), 'clave' => $clave];
        } catch (FirestoreError $error) {
            if ($error->status !== 409) throw $error; // código repetido: se prueba con otro
        }
    }
    throw new RuntimeException('No se pudo generar un código de venta único.');
}

/**
 * Decide qué cambia en la venta a partir de un pago de Mercado Pago. Es pura (sin
 * Firestore) para poder probar todas las transiciones:
 * - Aprobado solo si coinciden la referencia, el monto y la moneda.
 * - Una venta aprobada no vuelve atrás por el rechazo de otro intento de pago; solo la
 *   devuelve el reembolso o contracargo del MISMO pago.
 */
function evaluarPago(?array $venta, ?array $pago, ?int $ahora = null): array {
    if (!$venta || !$pago || (string)($pago['external_reference'] ?? '') !== (string)$venta['id']) return ['cambios' => null, 'evento' => 'referencia_ajena'];
    $ahora ??= time();
    $marca = fsTimestamp(gmdate('Y-m-d\TH:i:s\Z', $ahora));
    $pagoId = (string)($pago['id'] ?? '');
    $status = (string)($pago['status'] ?? '');
    $nuevo = estadoDesdeMP($status);
    if ($nuevo === '') return ['cambios' => null, 'evento' => 'estado_desconocido'];
    $detalle = [
        'mpEstado' => $status,
        'mpDetalle' => cleanText($pago['status_detail'] ?? '', 80),
        'mpMedio' => cleanText($pago['payment_method_id'] ?? '', 40),
    ];
    $actual = (string)($venta['estado'] ?? '');
    $monedaVenta = (string)($venta['moneda'] ?? MONEDA);

    if ($nuevo === 'aprobado') {
        $monto = is_numeric($pago['transaction_amount'] ?? null) ? (float)$pago['transaction_amount'] : null;
        $moneda = (string)($pago['currency_id'] ?? $monedaVenta);
        if ($monto === null || abs($monto - (float)$venta['monto']) > 0.01 || $moneda !== $monedaVenta) {
            if (($venta['incidente'] ?? '') === 'monto_incorrecto' && (string)($venta['mpPagoId'] ?? '') === $pagoId) return ['cambios' => null, 'evento' => 'monto_incorrecto'];
            return [
                'cambios' => ['requiereRevision' => true, 'incidente' => 'monto_incorrecto', 'montoRecibido' => $monto ?? 0, 'mpPagoId' => $pagoId] + $detalle + ['actualizadaAt' => $marca],
                'evento' => 'monto_incorrecto',
            ];
        }
        if ($actual === 'aprobado') {
            if ((string)($venta['mpPagoId'] ?? '') === $pagoId || (string)($venta['mpPagoDuplicadoId'] ?? '') === $pagoId) return ['cambios' => null, 'evento' => 'sin_cambios'];
            // El mismo comprador pagó dos veces el mismo link: queda marcado para devolver uno.
            return ['cambios' => ['requiereRevision' => true, 'incidente' => 'pago_duplicado', 'mpPagoDuplicadoId' => $pagoId, 'actualizadaAt' => $marca], 'evento' => 'pago_duplicado'];
        }
        return [
            'cambios' => ['estado' => 'aprobado', 'aprobadaAt' => $marca, 'mesAprobacion' => mesArgentina($ahora), 'mpPagoId' => $pagoId] + $detalle
                + ['requiereRevision' => false, 'incidente' => '', 'actualizadaAt' => $marca],
            'evento' => 'aprobado',
        ];
    }

    if ($nuevo === 'reembolsado') {
        if ($actual === 'reembolsado') return ['cambios' => null, 'evento' => 'sin_cambios'];
        if ($actual === 'aprobado' && (string)($venta['mpPagoId'] ?? '') !== '' && (string)$venta['mpPagoId'] !== $pagoId) return ['cambios' => null, 'evento' => 'reembolso_de_otro_pago'];
        return ['cambios' => ['estado' => 'reembolsado', 'reembolsadaAt' => $marca, 'mpPagoId' => $pagoId] + $detalle + ['actualizadaAt' => $marca], 'evento' => 'reembolsado'];
    }

    if ($actual === 'aprobado' || $actual === 'reembolsado') return ['cambios' => null, 'evento' => 'ignorado'];
    if ($actual === $nuevo && (string)($venta['mpPagoId'] ?? '') === $pagoId && (string)($venta['mpEstado'] ?? '') === $status) return ['cambios' => null, 'evento' => 'sin_cambios'];
    return ['cambios' => ['estado' => $nuevo, 'mpPagoId' => $pagoId] + $detalle + ['actualizadaAt' => $marca], 'evento' => $nuevo];
}

/** Aplica un pago a la venta. Si otra solicitud la modificó en el medio, reevalúa. */
function aplicarPago(array $venta, array $pago, string $origen): array {
    $primero = evaluarPago($venta, $pago);
    if (!$primero['cambios']) return ['venta' => $venta, 'evento' => $primero['evento']];
    try {
        $condicion = !empty($venta['_updateTime']) ? ['updateTime' => $venta['_updateTime']] : ['exists' => true];
        return ['venta' => fsPatch('ventas/' . $venta['id'], $primero['cambios'] + ['ultimoOrigen' => $origen], $condicion), 'evento' => $primero['evento']];
    } catch (FirestoreError $error) {
        if ($error->estado !== 'FAILED_PRECONDITION' && $error->status !== 409) throw $error;
        $fresca = fsGet('ventas/' . $venta['id']);
        $segundo = evaluarPago($fresca, $pago);
        if (!$segundo['cambios']) return ['venta' => $fresca, 'evento' => $segundo['evento']];
        return ['venta' => fsPatch('ventas/' . $venta['id'], $segundo['cambios'] + ['ultimoOrigen' => $origen]), 'evento' => $segundo['evento']];
    }
}

/**
 * Consulta a Mercado Pago el estado de una venta todavía no aprobada: primero el pago que
 * informó la vuelta del checkout (si lo hay) y si no, los pagos con su referencia. Es el
 * rescate para cuando el webhook se demora o no llega.
 */
function reconciliar(array $venta, string $pagoId = '', string $origen = 'retorno'): array {
    if (in_array($venta['estado'] ?? '', ['aprobado', 'reembolsado'], true)) return ['venta' => $venta, 'evento' => 'sin_cambios'];
    $token = mercadoPagoAccessToken();
    if ($token === '') return ['venta' => $venta, 'evento' => 'sin_token'];
    $pagos = [];
    if (preg_match('/^\d{1,20}$/', $pagoId)) {
        $pago = obtenerPago($pagoId, $token);
        if ($pago && (string)($pago['external_reference'] ?? '') === (string)$venta['id']) $pagos[] = $pago;
    }
    if (!$pagos || ($pagos[0]['status'] ?? '') !== 'approved') $pagos = array_merge($pagos, buscarPagos((string)$venta['id'], $token));
    $propios = array_values(array_filter($pagos, static fn(array $pago): bool => (string)($pago['external_reference'] ?? '') === (string)$venta['id']));
    $elegido = null;
    foreach ($propios as $pago) if (($pago['status'] ?? '') === 'approved') { $elegido = $pago; break; }
    $elegido ??= $propios[0] ?? null;
    if (!$elegido) return ['venta' => $venta, 'evento' => 'sin_pagos'];
    return aplicarPago($venta, $elegido, $origen);
}

// ---------------------------------------------------------------- descargas

function evaluarDescarga(array $venta, ?int $ahora = null): array {
    $ahora ??= time();
    if (($venta['estado'] ?? '') !== 'aprobado') return ['permitido' => false, 'motivo' => ($venta['estado'] ?? '') === 'reembolsado' ? 'reembolsada' : 'no_aprobada'];
    $max = (int)($venta['descargasMax'] ?? 0) ?: DESCARGAS_MAX;
    $usadas = (int)($venta['descargas'] ?? 0);
    $ultima = !empty($venta['ultimaDescargaAt']) ? strtotime((string)$venta['ultimaDescargaAt']) : false;
    // Reintentos y sondeos del navegador dentro de la ventana no consumen otra descarga.
    if ($ultima !== false && $ahora - $ultima < VENTANA_DESCARGA_SEGUNDOS) return ['permitido' => true, 'contar' => false];
    if ($usadas >= $max) return ['permitido' => false, 'motivo' => 'limite'];
    return ['permitido' => true, 'contar' => true];
}

function registrarDescarga(array $venta): void {
    fsIncrement('ventas/' . $venta['id'], 'descargas', 1, ['ultimaDescargaAt' => fsTimestamp()]);
}

function rutaDescarga(string $ventaId, string $clave, bool $ver = false): string {
    return '/api/ebooks/descargar.php?orden=' . rawurlencode($ventaId) . '&clave=' . rawurlencode($clave) . ($ver ? '&ver=1' : '');
}

function primerNombre(string $nombre): string {
    return cleanText(explode(' ', trim($nombre))[0] ?? '', 40);
}

/** Lo que ve el comprador en la página de gracias. */
function vistaComprador(array $venta, string $clave): array {
    $max = (int)($venta['descargasMax'] ?? 0) ?: DESCARGAS_MAX;
    $usadas = (int)($venta['descargas'] ?? 0);
    $aprobada = ($venta['estado'] ?? '') === 'aprobado';
    return [
        'codigo' => (string)$venta['id'],
        'estado' => (string)$venta['estado'],
        'nombre' => primerNombre((string)($venta['compradorNombre'] ?? '')),
        'ebook' => [
            'id' => (string)$venta['ebookId'],
            'titulo' => (string)($venta['ebookTitulo'] ?? ''),
            'portada' => urlConVersion('/ebooks/' . rawurlencode((string)$venta['ebookId']) . '/img/portada-chica.webp'),
        ],
        'monto' => $venta['monto'] ?? 0,
        'moneda' => (string)($venta['moneda'] ?? MONEDA),
        'descargas' => ['usadas' => $usadas, 'max' => $max, 'restantes' => max(0, $max - $usadas)],
        'descarga' => $aprobada ? rutaDescarga((string)$venta['id'], $clave) : '',
        'ver' => $aprobada ? rutaDescarga((string)$venta['id'], $clave, true) : '',
    ];
}

/** Lo que ve el panel (sin hashes ni secretos). */
function vistaAdminVenta(array $venta): array {
    return [
        'codigo' => (string)$venta['id'],
        'estado' => (string)($venta['estado'] ?? ''),
        'ebookId' => (string)($venta['ebookId'] ?? ''),
        'ebookTitulo' => (string)($venta['ebookTitulo'] ?? ''),
        'monto' => $venta['monto'] ?? 0,
        'moneda' => (string)($venta['moneda'] ?? MONEDA),
        'comprador' => ['nombre' => (string)($venta['compradorNombre'] ?? ''), 'email' => (string)($venta['compradorEmail'] ?? '')],
        'descargas' => ['usadas' => (int)($venta['descargas'] ?? 0), 'max' => (int)($venta['descargasMax'] ?? 0) ?: DESCARGAS_MAX, 'ultima' => $venta['ultimaDescargaAt'] ?? null],
        'mp' => ['pagoId' => (string)($venta['mpPagoId'] ?? ''), 'estado' => (string)($venta['mpEstado'] ?? ''), 'detalle' => (string)($venta['mpDetalle'] ?? ''), 'medio' => (string)($venta['mpMedio'] ?? '')],
        'requiereRevision' => ($venta['requiereRevision'] ?? false) === true,
        'incidente' => (string)($venta['incidente'] ?? ''),
        'creadaAt' => $venta['creadaAt'] ?? null,
        'aprobadaAt' => $venta['aprobadaAt'] ?? null,
    ];
}

/** Genera un enlace nuevo de descarga (el anterior deja de funcionar). */
function rotarClave(array $venta): string {
    $clave = nuevaClave();
    fsPatch('ventas/' . $venta['id'], ['claveHash' => hashClave($clave), 'actualizadaAt' => fsTimestamp()]);
    return urlGracias((string)$venta['id'], $clave);
}

function reiniciarDescargas(array $venta): array {
    return fsPatch('ventas/' . $venta['id'], ['descargas' => 0, 'ultimaDescargaAt' => null, 'actualizadaAt' => fsTimestamp()]);
}
