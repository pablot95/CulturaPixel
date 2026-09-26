<?php
declare(strict_types=1);

/*
 * Corre contra el Firestore real (solo lectura) todas las consultas que usan la API y el
 * panel, para detectar índices faltantes antes de que los vea el cliente. El emulador de
 * las pruebas no exige índices compuestos, así que esto no lo cubren los tests.
 *   php scripts/verificar-firestore.php      (usa api/service-account.json)
 */

require_once dirname(__DIR__) . '/api/lib/ebooks.php';
require_once dirname(__DIR__) . '/api/lib/ventas.php';

$consultas = [
    'catálogo de ebooks' => static fn() => listarEbooks(),
    'config de Mercado Pago' => static fn() => mercadoPagoConfig(),
    'resumen: aprobadas + total' => static fn() => fsCountSum('ventas', [['estado', '==', 'aprobado']], 'monto'),
    'resumen: vendido en el mes' => static fn() => fsCountSum('ventas', [['estado', '==', 'aprobado'], ['mesAprobacion', '==', mesArgentina()]], 'monto'),
    'resumen: pendientes' => static fn() => fsCountSum('ventas', [['estado', '==', 'pendiente']]),
    'resumen: para revisar' => static fn() => fsCountSum('ventas', [['requiereRevision', '==', true]]),
    'ventas: todas' => static fn() => fsQuery('ventas', [], [['creadaAt', 'desc'], ['__name__', 'desc']], 26),
    'ventas: por estado' => static fn() => fsQuery('ventas', [['estado', '==', 'aprobado']], [['creadaAt', 'desc'], ['__name__', 'desc']], 26),
    'ventas: por email' => static fn() => fsQuery('ventas', [['compradorEmail', '==', 'prueba@ejemplo.com']], [], 50),
];

echo 'Proyecto: ' . FIREBASE_PROJECT_ID . (firestoreEmulador() !== '' ? ' (emulador)' : '') . "\n";
$fallas = 0;
foreach ($consultas as $nombre => $consulta) {
    try {
        $consulta();
        echo "✔ {$nombre}\n";
    } catch (Throwable $error) {
        $fallas++;
        echo "✖ {$nombre}: " . $error->getMessage() . "\n";
    }
}
exit($fallas > 0 ? 1 : 0);
