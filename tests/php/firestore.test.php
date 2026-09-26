<?php
declare(strict_types=1);

require __DIR__ . '/_comprobar.php';
require_once dirname(__DIR__, 2) . '/api/lib/firestore.php';

$datos = ['texto' => 'Quinceañeras', 'entero' => 14900, 'decimal' => 1.5, 'si' => true, 'nada' => null, 'lista' => ['a', 2], 'mapa' => ['a' => 1]];
comprobar(fsDecodeFields(json_decode((string)json_encode(fsFields($datos)), true)), $datos, 'ida y vuelta de los tipos que se usan');
comprobar(fsValue(new FsTimestamp('2026-09-26T15:00:00.123Z')), ['timestampValue' => '2026-09-26T15:00:00.123Z'], 'fechas como timestamp');
comprobar(fsValue(14900), ['integerValue' => '14900'], 'enteros como texto (int64)');
comprobar(fsValue(INF), ['nullValue' => null], 'no finitos como null');
comprobar(json_encode(fsFields([])), '{}', 'documento vacío como objeto, no como lista');
comprobarQue((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', nowIsoMs()), 'fecha con milisegundos');

$documento = fsDecodeDocument([
    'name' => 'projects/p/databases/(default)/documents/admins/contacto%40institutoculturapixel.com',
    'fields' => ['activo' => ['booleanValue' => true]],
    'updateTime' => '2026-09-26T15:23:41.298437Z',
]);
comprobar($documento['id'], 'contacto@institutoculturapixel.com', 'id con @ decodificado');
comprobar($documento['activo'], true, 'campo');
comprobar($documento['_updateTime'], '2026-09-26T15:23:41.298437Z', 'metadatos');
comprobar(fsCondicion(['updateTime' => '2026-09-26T15:23:41.298437Z']), 'currentDocument.updateTime=2026-09-26T15%3A23%3A41.298437Z', 'precondición por versión');
comprobar(fsCondicion(['exists' => true]), 'currentDocument.exists=true', 'precondición de existencia');
comprobar(fsCondicion([]), '', 'sin precondición (upsert)');

terminarPruebas('firestore');
