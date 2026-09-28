<?php
declare(strict_types=1);

// Utilidades HTTP. Base: api/lib/common.php del Template-Ecommerce de Gokywebs.

require_once dirname(__DIR__) . '/config.php';

/** Error con mensaje apto para mostrarle al usuario. */
final class ErrorPublico extends RuntimeException {
    public function __construct(string $mensaje, public int $status = 400, public string $codigo = '') {
        parent::__construct($mensaje);
    }
}

function jsonResponse(array $payload, int $status = 200, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($headers['Cache-Control'])) header('Cache-Control: no-store');
    foreach ($headers as $nombre => $valor) header($nombre . ': ' . $valor);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Respuesta de error: el mensaje de ErrorPublico va al usuario; el resto, al log. */
function responderError(Throwable $error, string $contexto): never {
    if ($error instanceof ErrorPublico) {
        $cuerpo = ['error' => $error->getMessage()];
        if ($error->codigo !== '') $cuerpo['codigo'] = $error->codigo;
        jsonResponse($cuerpo, $error->status);
    }
    error_log('[' . $contexto . '] ' . $error->getMessage());
    jsonResponse(['error' => 'Tuvimos un problema. Intentá de nuevo en unos minutos.'], 500);
}

function requireMethod(string ...$metodos): void {
    $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
    if (in_array($actual, $metodos, true)) return;
    header('Allow: ' . implode(', ', $metodos));
    jsonResponse(['error' => 'Método no permitido.'], 405);
}

function readJsonBody(): array {
    $largo = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($largo > MAX_REQUEST_BYTES) throw new ErrorPublico('Solicitud demasiado grande.', 413);
    $crudo = file_get_contents('php://input');
    if ($crudo === false || strlen($crudo) > MAX_REQUEST_BYTES) throw new ErrorPublico('Solicitud demasiado grande.', 413);
    if (trim($crudo) === '') return [];
    $datos = json_decode($crudo, true);
    if (!is_array($datos) || array_is_list($datos) && $datos !== []) throw new ErrorPublico('JSON inválido.', 400);
    return $datos;
}

function requestHeader(string $name): string {
    // Authorization necesita caso especial: varios hostings compartidos (Apache/CGI sin
    // CGIPassAuth, LiteSpeed) no lo exponen como HTTP_AUTHORIZATION.
    if (strcasecmp($name, 'Authorization') === 0) {
        $direct = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($direct !== '') return $direct;
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $headerName => $value) {
                if (strcasecmp((string)$headerName, 'Authorization') === 0) return trim((string)$value);
            }
        }
        return '';
    }
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function clientIp(): string {
    $forwarded = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    return TRUST_CF_CONNECTING_IP && $forwarded !== '' ? $forwarded : trim((string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida'));
}

function carpetaTemporal(string $nombre): string {
    // CULTURAPIXEL_TMP solo se usa en las pruebas locales, para arrancar cada corrida limpia.
    $base = configEntorno('CULTURAPIXEL_TMP', sys_get_temp_dir() . '/culturapixel');
    $carpeta = rtrim($base, '/\\') . '/' . $nombre;
    if (!is_dir($carpeta)) @mkdir($carpeta, 0700, true);
    return $carpeta;
}

/** Límite de pedidos por IP y ámbito (archivos en /tmp, como el template). */
function rateLimit(string $scope, int $limit, int $windowSeconds): void {
    // Las pruebas de integración van todas desde 127.0.0.1: ahí se apaga (se prueba aparte).
    if (configEntorno('LIMITES_DESACTIVADOS') === '1') return;
    $file = carpetaTemporal('limites') . '/' . hash('sha256', $scope . '|' . clientIp()) . '.json';
    $now = time();
    $state = ['start' => $now, 'count' => 0];
    $handle = @fopen($file, 'c+');
    if (!$handle) return;
    try {
        flock($handle, LOCK_EX);
        $decoded = json_decode((string)stream_get_contents($handle), true);
        if (is_array($decoded)) $state = $decoded + $state;
        if (($now - (int)$state['start']) >= $windowSeconds) $state = ['start' => $now, 'count' => 0];
        $state['count'] = (int)$state['count'] + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state));
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    if ($state['count'] > $limit) throw new ErrorPublico('Hiciste demasiados intentos seguidos. Esperá unos minutos y probá de nuevo.', 429);
}

/** true si pasaron $segundos desde la última vez que se pidió $clave (y la marca). */
function pasoElTiempo(string $clave, int $segundos): bool {
    $file = carpetaTemporal('esperas') . '/' . hash('sha256', $clave);
    $ultima = is_file($file) ? (int)@filemtime($file) : 0;
    if ($ultima > 0 && time() - $ultima < $segundos) return false;
    @touch($file);
    return true;
}

function httpJson(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 15): array {
    $ch = curl_init($url);
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0,
    ];
    if ($body !== null) {
        // Un $body vacío es un objeto vacío para estas APIs, nunca una lista.
        $encoded = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) throw new RuntimeException('No se pudo codificar la solicitud a JSON (' . json_last_error_msg() . ').');
        $options[CURLOPT_POSTFIELDS] = $encoded;
        $requestHeaders[] = 'Content-Type: application/json';
    }
    if ($body === null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) $options[CURLOPT_POSTFIELDS] = '';
    $options[CURLOPT_HTTPHEADER] = $requestHeaders;
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($decoded) ? $decoded : [], 'raw' => (string)$raw, 'error' => $error];
}

function nowIso(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** Fecha actual con milisegundos (ordena las ventas creadas en el mismo segundo). */
function nowIsoMs(): string {
    $ahora = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new DateTimeImmutable('now');
    return $ahora->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
}

function randomId(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function utf8Slice(string $text, int $max): string {
    // Texto pegado desde otros programas puede traer bytes UTF-8 inválidos: sin esto,
    // json_encode() falla en silencio más adelante.
    if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
    if (preg_match_all('/./us', $text, $characters) === false) return substr($text, 0, $max);
    return implode('', array_slice($characters[0], 0, $max));
}

/** Texto de una sola línea: sin caracteres de control y con espacios normalizados. */
function cleanText(mixed $value, int $max = 500): string {
    if (!is_string($value) && !is_int($value) && !is_float($value)) return '';
    $text = (string)$value;
    if (class_exists('Normalizer')) $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
    $text = (string)preg_replace('/[\p{Cc}]+/u', ' ', utf8Slice($text, $max * 2));
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    return utf8Slice($text, $max);
}

function escaparHtml(mixed $texto): string {
    return htmlspecialchars((string)$texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ruta de un archivo de la web con su versión (?v=<hash del contenido>), como hace
 * scripts/versionar.mjs en los HTML: Hostinger cachea imágenes, CSS y JS 7 días y así un
 * archivo que cambia sin cambiar de nombre no queda viejo en el navegador.
 */
function urlConVersion(string $ruta): string {
    static $huellas = [];
    $limpia = (string)strtok($ruta, '?#');
    if (!str_starts_with($limpia, '/') || str_contains($limpia, '..')) return $ruta;
    if (!array_key_exists($limpia, $huellas)) {
        $archivo = dirname(__DIR__, 2) . rawurldecode($limpia);
        $huellas[$limpia] = is_file($archivo) ? substr((string)sha1_file($archivo), 0, 10) : null;
    }
    return $huellas[$limpia] === null ? $limpia : $limpia . '?v=' . $huellas[$limpia];
}
