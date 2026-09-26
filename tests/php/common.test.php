<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/culturapixel-prueba-' . bin2hex(random_bytes(4));
putenv('CULTURAPIXEL_TMP=' . $tmp);
putenv('LIMITES_DESACTIVADOS');

require __DIR__ . '/_comprobar.php';
require_once dirname(__DIR__, 2) . '/api/lib/common.php';

// Límite por IP: 3 pedidos en la ventana; el cuarto se frena.
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
for ($i = 0; $i < 3; $i++) rateLimit('prueba', 3, 60);
comprobarError(static fn() => rateLimit('prueba', 3, 60), '/demasiados intentos/', 'frena el cuarto pedido');
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
rateLimit('prueba', 3, 60);
comprobarQue(true, 'otra IP tiene su propio límite');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
rateLimit('otro-ambito', 3, 60);
comprobarQue(true, 'cada ámbito cuenta por separado');

// Espera entre consultas
comprobar(pasoElTiempo('consulta', 60), true, 'la primera consulta pasa');
comprobar(pasoElTiempo('consulta', 60), false, 'la segunda inmediata espera');

// Texto de una línea
comprobar(cleanText("  Sofía\t\nPérez\x00 \x7f ok  ", 50), 'Sofía Pérez ok', 'limpia controles y espacios');
comprobar(cleanText(['no'], 50), '', 'rechaza lo que no es texto');
comprobar(cleanText('abcdef', 3), 'abc', 'corta al máximo');

terminarPruebas('common');
