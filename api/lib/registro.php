<?php
declare(strict_types=1);

// Registros informativos: acciones del panel (auditoria) y notificaciones de Mercado Pago
// (logs_webhooks). Si Firestore falla acá, no se interrumpe la operación principal.

require_once __DIR__ . '/firestore.php';

function auditar(string $accion, string $actor, array $detalle = []): void {
    try {
        fsCreate('auditoria', randomId(10), ['accion' => $accion, 'actor' => $actor, 'detalle' => $detalle, 'creadoAt' => fsTimestamp()]);
    } catch (Throwable $error) {
        error_log('[auditoria] ' . $error->getMessage());
    }
}

function registrarWebhook(string $tipo, string $ventaId, array $detalle = []): void {
    try {
        fsCreate('logs_webhooks', randomId(10), ['tipo' => $tipo, 'ventaId' => $ventaId, 'detalle' => $detalle, 'creadoAt' => fsTimestamp()]);
    } catch (Throwable $error) {
        error_log('[logs_webhooks] ' . $error->getMessage());
    }
}
