<?php
declare(strict_types=1);

require __DIR__ . '/_comprobar.php';
require_once dirname(__DIR__, 2) . '/api/lib/ventas.php';

$ahora = strtotime('2026-09-26T15:00:00Z');
$venta = static fn(array $extra = []): array => $extra + ['id' => 'K7F2QX9M', 'estado' => 'pendiente', 'monto' => 14900, 'moneda' => 'ARS', 'mpPagoId' => '', 'mpEstado' => ''];
$pago = static fn(array $extra = []): array => $extra + ['id' => 111, 'status' => 'approved', 'status_detail' => 'accredited', 'external_reference' => 'K7F2QX9M', 'transaction_amount' => 14900, 'currency_id' => 'ARS', 'payment_method_id' => 'visa'];

// Códigos y claves
for ($i = 0; $i < 300; $i++) comprobarQue((bool)preg_match(CODIGO_VENTA, nuevoCodigo()), 'código de 8 caracteres sin ambiguos');
comprobarQue(!preg_match(CODIGO_VENTA, 'K7F2QX0M'), 'el 0 no se usa');
comprobarQue(!preg_match(CODIGO_VENTA, 'K7F2QXIM'), 'la I no se usa');
$clave = nuevaClave();
$guardada = ['claveHash' => hashClave($clave)];
comprobar(claveCorrecta($guardada, $clave), true, 'la clave correcta abre la compra');
comprobar(claveCorrecta($guardada, substr($clave, 0, -1) . 'x'), false, 'otra clave no');
comprobar(claveCorrecta($guardada, ''), false, 'vacía no');
comprobar(claveCorrecta($guardada, null), false, 'null no');
comprobar(claveCorrecta([], $clave), false, 'sin hash no');

// Comprador
comprobar(validarComprador(['nombre' => "  Sofía \t Pérez ", 'email' => ' Sofia@Mail.COM ']), ['nombre' => 'Sofía Pérez', 'email' => 'sofia@mail.com'], 'normaliza nombre y email');
comprobarError(static fn() => validarComprador(['nombre' => 'S', 'email' => 'a@b.com']), '/nombre/', 'nombre corto');
comprobarError(static fn() => validarComprador(['nombre' => 'Sofía', 'email' => 'sofia@mail']), '/email/', 'email incompleto');
comprobarError(static fn() => validarComprador(['nombre' => 'Sofía', 'email' => ['a@b.com']]), '/email/', 'email que no es texto');

// Estados de Mercado Pago
comprobar(estadoDesdeMP('approved'), 'aprobado', 'approved');
comprobar(estadoDesdeMP('in_process'), 'pendiente', 'in_process');
comprobar(estadoDesdeMP('rejected'), 'rechazado', 'rejected');
comprobar(estadoDesdeMP('charged_back'), 'reembolsado', 'charged_back');
comprobar(estadoDesdeMP('otro'), '', 'desconocido');

// evaluarPago
$r = evaluarPago($venta(), $pago(), $ahora);
comprobar($r['evento'], 'aprobado', 'aprueba cuando coinciden referencia, monto y moneda');
comprobar($r['cambios']['estado'], 'aprobado', 'estado aprobado');
comprobar($r['cambios']['mpPagoId'], '111', 'guarda el id del pago');
comprobar($r['cambios']['mesAprobacion'], '2026-09', 'mes de aprobación');
comprobar(evaluarPago($venta(), $pago(['external_reference' => 'OTRA0000']))['cambios'], null, 'ignora pagos de otra venta');
$r = evaluarPago($venta(), $pago(['transaction_amount' => 100]));
comprobar($r['evento'], 'monto_incorrecto', 'no aprueba con otro monto');
comprobarQue(!isset($r['cambios']['estado']), 'no cambia el estado');
comprobar($r['cambios']['requiereRevision'], true, 'queda para revisar');
comprobar(evaluarPago($venta(), $pago(['currency_id' => 'USD']))['evento'], 'monto_incorrecto', 'otra moneda');
comprobar(evaluarPago($venta(['estado' => 'aprobado', 'mpPagoId' => '111']), $pago())['cambios'], null, 'idempotente con el mismo pago');
$aprobada = $venta(['estado' => 'aprobado', 'mpPagoId' => '111']);
comprobar(evaluarPago($aprobada, $pago(['id' => 222, 'status' => 'rejected']))['cambios'], null, 'un rechazo de otro intento no pisa la aprobada');
comprobar(evaluarPago($aprobada, $pago(['id' => 222, 'status' => 'pending']))['cambios'], null, 'un pendiente de otro intento tampoco');
$r = evaluarPago($aprobada, $pago(['id' => 333]));
comprobar($r['evento'], 'pago_duplicado', 'marca el pago duplicado');
comprobar($r['cambios']['mpPagoDuplicadoId'], '333', 'guarda el duplicado');
comprobarQue(!isset($r['cambios']['estado']), 'sin tocar el estado');
comprobar(evaluarPago($aprobada, $pago(['status' => 'refunded']))['cambios']['estado'], 'reembolsado', 'reembolso del mismo pago');
comprobar(evaluarPago($aprobada, $pago(['id' => 999, 'status' => 'refunded']))['cambios'], null, 'reembolso de otro pago no');
comprobar(evaluarPago($venta(['estado' => 'rechazado', 'mpPagoId' => '100', 'mpEstado' => 'rejected']), $pago(['id' => 101]))['cambios']['estado'], 'aprobado', 'reintento aprobado');
comprobar(evaluarPago($venta(['estado' => 'rechazado', 'mpPagoId' => '100', 'mpEstado' => 'rejected']), $pago(['id' => 100, 'status' => 'rejected']))['cambios'], null, 'sin cambios');

// Descargas
$t = strtotime('2026-09-26T15:00:00Z');
$aprobadaD = static fn(array $extra = []): array => $venta($extra + ['estado' => 'aprobado', 'descargas' => 0, 'descargasMax' => 10, 'ultimaDescargaAt' => null]);
comprobar(evaluarDescarga($venta(), $t), ['permitido' => false, 'motivo' => 'no_aprobada'], 'pendiente no descarga');
comprobar(evaluarDescarga($venta(['estado' => 'reembolsado']), $t), ['permitido' => false, 'motivo' => 'reembolsada'], 'reembolsada no descarga');
comprobar(evaluarDescarga($aprobadaD(['descargas' => 9]), $t), ['permitido' => true, 'contar' => true], 'cuenta la descarga');
comprobar(evaluarDescarga($aprobadaD(['descargas' => 10]), $t), ['permitido' => false, 'motivo' => 'limite'], 'corta en el límite');
comprobar(evaluarDescarga($aprobadaD(['descargas' => 10, 'ultimaDescargaAt' => gmdate('Y-m-d\TH:i:s\Z', $t - 30)]), $t), ['permitido' => true, 'contar' => false], 'dentro de la ventana no cuenta');
comprobar(evaluarDescarga($aprobadaD(['descargas' => 3, 'ultimaDescargaAt' => gmdate('Y-m-d\TH:i:s\Z', $t - 600)]), $t), ['permitido' => true, 'contar' => true], 'fuera de la ventana cuenta');

// Vista del comprador
$vista = vistaComprador($venta(['compradorNombre' => 'Sofía Pérez', 'claveHash' => 'x', 'ebookId' => 'quinceaneras']), 'CLAVE');
comprobar($vista['descarga'], '', 'sin descarga si no está aprobada');
comprobar($vista['nombre'], 'Sofía', 'primer nombre');
comprobarQue(!str_contains((string)json_encode($vista), 'claveHash'), 'nunca expone el hash');
$vista = vistaComprador($venta(['estado' => 'aprobado', 'descargas' => 2, 'descargasMax' => 10, 'ebookId' => 'quinceaneras']), 'CLAVE');
comprobar($vista['descarga'], '/api/ebooks/descargar.php?orden=K7F2QX9M&clave=CLAVE', 'ruta de descarga');
comprobar($vista['descargas']['restantes'], 8, 'descargas restantes');

// Mes en hora de Argentina (UTC-3)
comprobar(mesArgentina(strtotime('2026-10-01T02:00:00Z')), '2026-09', 'todavía septiembre en Argentina');
comprobar(mesArgentina(strtotime('2026-10-01T04:00:00Z')), '2026-10', 'ya octubre');

terminarPruebas('ventas');
