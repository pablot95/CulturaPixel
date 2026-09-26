<?php
declare(strict_types=1);

// Cliente REST de Firestore con la cuenta de servicio. Base: api/lib/firestore.php del
// Template-Ecommerce, más precondiciones, consultas con cursor, conteos/sumas e
// incrementos atómicos. Las reglas de Firestore niegan todo acceso desde el navegador.

require_once __DIR__ . '/firebase-admin.php';

final class FsTimestamp {
    public function __construct(public string $value) {}
}

/** Error de Firestore con su status HTTP y el estado de la API (ALREADY_EXISTS, FAILED_PRECONDITION...). */
final class FirestoreError extends RuntimeException {
    public function __construct(string $mensaje, public int $status = 0, public string $estado = '') {
        parent::__construct($mensaje);
    }
}

function fsTimestamp(?string $value = null): FsTimestamp {
    return new FsTimestamp($value ?: nowIsoMs());
}

function fsDatabase(): string {
    $emulador = firestoreEmulador();
    $host = $emulador !== '' ? 'http://' . $emulador : 'https://firestore.googleapis.com';
    return $host . '/v1/projects/' . rawurlencode(FIREBASE_PROJECT_ID) . '/databases/(default)';
}

function fsBase(): string {
    return fsDatabase() . '/documents';
}

function fsDocumentName(string $path): string {
    return 'projects/' . FIREBASE_PROJECT_ID . '/databases/(default)/documents/' . trim($path, '/');
}

function fsEncodedPath(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
}

function fsIsList(array $value): bool {
    return $value === [] || array_is_list($value);
}

function fsValue(mixed $value): array {
    if ($value instanceof FsTimestamp) return ['timestampValue' => $value->value];
    if ($value === null) return ['nullValue' => null];
    if (is_bool($value)) return ['booleanValue' => $value];
    if (is_int($value)) return ['integerValue' => (string)$value];
    if (is_float($value)) return is_finite($value) ? ['doubleValue' => $value] : ['nullValue' => null];
    if (is_string($value)) return ['stringValue' => $value];
    if (is_array($value)) {
        if ($value === []) return ['mapValue' => ['fields' => new stdClass()]];
        if (fsIsList($value)) return ['arrayValue' => ['values' => array_map('fsValue', $value)]];
        return ['mapValue' => ['fields' => fsFields($value)]];
    }
    return ['stringValue' => (string)$value];
}

function fsFields(array $data): array|stdClass {
    $fields = [];
    foreach ($data as $key => $value) $fields[(string)$key] = fsValue($value);
    return $fields === [] ? new stdClass() : $fields;
}

function fsDecodeValue(array $value): mixed {
    if (array_key_exists('nullValue', $value)) return null;
    if (array_key_exists('booleanValue', $value)) return (bool)$value['booleanValue'];
    if (array_key_exists('integerValue', $value)) return (int)$value['integerValue'];
    if (array_key_exists('doubleValue', $value)) return (float)$value['doubleValue'];
    if (array_key_exists('stringValue', $value)) return (string)$value['stringValue'];
    // Las fechas se devuelven como texto ISO 8601.
    if (array_key_exists('timestampValue', $value)) return (string)$value['timestampValue'];
    if (isset($value['arrayValue'])) return array_map('fsDecodeValue', $value['arrayValue']['values'] ?? []);
    if (isset($value['mapValue'])) return fsDecodeFields($value['mapValue']['fields'] ?? []);
    if (array_key_exists('referenceValue', $value)) return (string)$value['referenceValue'];
    return null;
}

function fsDecodeFields(array $fields): array {
    $data = [];
    foreach ($fields as $key => $value) $data[$key] = fsDecodeValue($value);
    return $data;
}

function fsDecodeDocument(array $document): array {
    $name = (string)($document['name'] ?? '');
    return array_merge(fsDecodeFields($document['fields'] ?? []), [
        'id' => rawurldecode(basename($name)),
        '_name' => $name,
        '_updateTime' => $document['updateTime'] ?? null,
        '_createTime' => $document['createTime'] ?? null,
    ]);
}

function fsRequest(string $method, string $url, ?array $body = null): array {
    $response = httpJson($method, $url, $body, ['Authorization: Bearer ' . firebaseAdminToken()]);
    if (!$response['ok']) {
        $error = $response['data']['error'] ?? (is_array($response['data'][0]['error'] ?? null) ? $response['data'][0]['error'] : []);
        throw new FirestoreError('Firestore ' . $response['status'] . ': ' . (string)($error['message'] ?? $response['error'] ?: substr($response['raw'], 0, 200)), $response['status'], (string)($error['status'] ?? ''));
    }
    return $response['data'];
}

function fsCondicion(array $condicion): string {
    $partes = [];
    if (isset($condicion['updateTime'])) $partes[] = 'currentDocument.updateTime=' . rawurlencode((string)$condicion['updateTime']);
    elseif (array_key_exists('exists', $condicion)) $partes[] = 'currentDocument.exists=' . ($condicion['exists'] ? 'true' : 'false');
    return implode('&', $partes);
}

/** Documento o null si no existe. */
function fsGet(string $path): ?array {
    try {
        return fsDecodeDocument(fsRequest('GET', fsBase() . '/' . fsEncodedPath($path)));
    } catch (FirestoreError $error) {
        if ($error->status === 404) return null;
        throw $error;
    }
}

/** Crea el documento; falla con status 409 (ALREADY_EXISTS) si ya existe. */
function fsCreate(string $collection, string $id, array $data): array {
    $url = fsBase() . '/' . fsEncodedPath($collection) . '?documentId=' . rawurlencode($id);
    return fsDecodeDocument(fsRequest('POST', $url, ['fields' => fsFields($data)]));
}

/**
 * Actualiza solo los campos recibidos. Por defecto exige que el documento exista;
 * ['updateTime' => ...] falla si alguien lo modificó desde que se leyó y [] hace un upsert.
 */
function fsPatch(string $path, array $data, array $condicion = ['exists' => true]): array {
    $query = [];
    foreach (array_keys($data) as $field) $query[] = 'updateMask.fieldPaths=' . rawurlencode((string)$field);
    $extra = fsCondicion($condicion);
    if ($extra !== '') $query[] = $extra;
    $url = fsBase() . '/' . fsEncodedPath($path) . ($query ? '?' . implode('&', $query) : '');
    return fsDecodeDocument(fsRequest('PATCH', $url, ['fields' => fsFields($data)]));
}

function fsDelete(string $path): void {
    fsRequest('DELETE', fsBase() . '/' . fsEncodedPath($path));
}

const FS_OPERADORES = ['==' => 'EQUAL', '!=' => 'NOT_EQUAL', '<' => 'LESS_THAN', '<=' => 'LESS_THAN_OR_EQUAL', '>' => 'GREATER_THAN', '>=' => 'GREATER_THAN_OR_EQUAL'];

function fsFiltro(array $donde): ?array {
    $filtros = [];
    foreach ($donde as [$campo, $operador, $valor]) {
        $filtros[] = ['fieldFilter' => ['field' => ['fieldPath' => $campo], 'op' => FS_OPERADORES[$operador] ?? $operador, 'value' => fsValue($valor)]];
    }
    if (!$filtros) return null;
    return count($filtros) === 1 ? $filtros[0] : ['compositeFilter' => ['op' => 'AND', 'filters' => $filtros]];
}

/**
 * Consulta simple. $orden: [[campo, 'desc'|'asc'], ...]. $despuesDe: valores ya
 * codificados (fsValue / ['referenceValue' => ...]) del último documento de la página anterior.
 */
function fsQuery(string $coleccion, array $donde = [], array $orden = [], ?int $limite = null, ?array $despuesDe = null): array {
    $consulta = ['from' => [['collectionId' => $coleccion]]];
    $filtro = fsFiltro($donde);
    if ($filtro) $consulta['where'] = $filtro;
    if ($orden) {
        $consulta['orderBy'] = array_map(static fn(array $item): array => [
            'field' => ['fieldPath' => $item[0]],
            'direction' => ($item[1] ?? 'asc') === 'desc' ? 'DESCENDING' : 'ASCENDING',
        ], $orden);
    }
    if ($limite) $consulta['limit'] = $limite;
    if ($despuesDe) $consulta['startAt'] = ['values' => $despuesDe, 'before' => false];
    $filas = fsRequest('POST', fsBase() . ':runQuery', ['structuredQuery' => $consulta]);
    $documentos = [];
    foreach (fsIsList($filas) ? $filas : [] as $fila) if (!empty($fila['document'])) $documentos[] = fsDecodeDocument($fila['document']);
    return $documentos;
}

/** Cantidad y suma de un campo de los documentos que cumplen $donde. */
function fsCountSum(string $coleccion, array $donde, ?string $campoSuma = null): array {
    $consulta = ['from' => [['collectionId' => $coleccion]]];
    $filtro = fsFiltro($donde);
    if ($filtro) $consulta['where'] = $filtro;
    $agregaciones = [['alias' => 'cantidad', 'count' => new stdClass()]];
    if ($campoSuma) $agregaciones[] = ['alias' => 'total', 'sum' => ['field' => ['fieldPath' => $campoSuma]]];
    $filas = fsRequest('POST', fsBase() . ':runAggregationQuery', ['structuredAggregationQuery' => ['structuredQuery' => $consulta, 'aggregations' => $agregaciones]]);
    $campos = [];
    foreach (fsIsList($filas) ? $filas : [] as $fila) if (isset($fila['result']['aggregateFields'])) $campos = $fila['result']['aggregateFields'];
    return [
        'cantidad' => (int)(isset($campos['cantidad']) ? fsDecodeValue($campos['cantidad']) : 0),
        'total' => isset($campos['total']) ? (fsDecodeValue($campos['total']) ?? 0) : 0,
    ];
}

/** Suma $cantidad a un campo numérico de forma atómica (y actualiza otros campos). */
function fsIncrement(string $path, string $campo, int $cantidad, array $datos = []): void {
    $write = [
        'update' => ['name' => fsDocumentName($path), 'fields' => fsFields($datos)],
        'updateMask' => ['fieldPaths' => array_keys($datos)],
        'updateTransforms' => [['fieldPath' => $campo, 'increment' => fsValue($cantidad)]],
        'currentDocument' => ['exists' => true],
    ];
    fsRequest('POST', fsDatabase() . '/documents:commit', ['writes' => [$write]]);
}
