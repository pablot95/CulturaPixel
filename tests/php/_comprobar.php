<?php
declare(strict_types=1);

// Mini framework de pruebas (mismo estilo que los tests PHP del Template-Ecommerce).

$GLOBALS['comprobaciones'] = 0;

function comprobar(mixed $real, mixed $esperado, string $mensaje): void {
    $GLOBALS['comprobaciones']++;
    if ($real !== $esperado) {
        fwrite(STDERR, "✖ {$mensaje}\n  Esperado: " . var_export($esperado, true) . "\n  Recibido: " . var_export($real, true) . "\n");
        exit(1);
    }
}

function comprobarQue(bool $condicion, string $mensaje): void {
    comprobar($condicion, true, $mensaje);
}

function comprobarError(callable $accion, string $patron, string $mensaje): void {
    try {
        $accion();
    } catch (Throwable $error) {
        comprobarQue((bool)preg_match($patron, $error->getMessage()), $mensaje . ' (mensaje: ' . $error->getMessage() . ')');
        return;
    }
    comprobarQue(false, $mensaje . ' (no tiró error)');
}

function terminarPruebas(string $nombre): void {
    fwrite(STDOUT, "✔ {$nombre}: {$GLOBALS['comprobaciones']} comprobaciones\n");
}
