<?php
declare(strict_types=1);

// Catálogo de ebooks: cada uno es un documento ebooks/{id} en Firestore (precio y venta
// activa se editan desde el panel) más su PDF en storage/ebooks/{id}.pdf, que se sube
// desde el panel y no es accesible desde la web (storage/.htaccess).

require_once __DIR__ . '/firestore.php';

const ID_EBOOK = '/^[a-z0-9][a-z0-9-]{0,59}$/';

function leerEbook(string $id): ?array {
    return preg_match(ID_EBOOK, $id) ? fsGet('ebooks/' . $id) : null;
}

function listarEbooks(): array {
    $ebooks = fsQuery('ebooks');
    usort($ebooks, static fn(array $a, array $b): int => ((int)($a['orden'] ?? 99) <=> (int)($b['orden'] ?? 99)) ?: strcmp((string)$a['id'], (string)$b['id']));
    return $ebooks;
}

function rutaPdf(string $id): string {
    if (!preg_match(ID_EBOOK, $id)) throw new InvalidArgumentException('Id de ebook inválido.');
    return EBOOKS_DIR . '/' . $id . '.pdf';
}

/** Datos del PDF subido o null si todavía no está. */
function archivoDelEbook(string $id): ?array {
    $ruta = rutaPdf($id);
    if (!is_file($ruta)) return null;
    clearstatcache(true, $ruta);
    return ['bytes' => (int)filesize($ruta), 'actualizado' => gmdate('Y-m-d\TH:i:s\Z', (int)filemtime($ruta))];
}

/** Se puede comprar si está activo, tiene precio y su PDF ya está subido. */
function sePuedeComprar(?array $ebook): bool {
    return $ebook !== null && ($ebook['activo'] ?? false) === true && (float)($ebook['precio'] ?? 0) > 0 && archivoDelEbook((string)$ebook['id']) !== null;
}

function vistaPublicaEbook(array $ebook): array {
    return [
        'id' => (string)$ebook['id'],
        'titulo' => (string)($ebook['titulo'] ?? ''),
        'precio' => is_int($ebook['precio'] ?? null) ? $ebook['precio'] : (float)($ebook['precio'] ?? 0),
        'moneda' => (string)($ebook['moneda'] ?? MONEDA),
        'disponible' => sePuedeComprar($ebook),
        'url' => (string)($ebook['url'] ?? ''),
        'portada' => (string)($ebook['portada'] ?? ''),
    ];
}

/** Dirección pública completa de la landing, para compartir (siempre con el dominio real). */
function enlaceEbook(array $ebook): string {
    $ruta = (string)($ebook['url'] ?? '');
    if (preg_match('#^https?://#i', $ruta)) return $ruta;
    if ($ruta === '') $ruta = '/ebooks/' . rawurlencode((string)($ebook['id'] ?? '')) . '/';
    return SITE_URL . '/' . ltrim($ruta, '/');
}

function vistaAdminEbook(array $ebook): array {
    return vistaPublicaEbook($ebook) + [
        'enlace' => enlaceEbook($ebook),
        'activo' => ($ebook['activo'] ?? false) === true,
        'descargasMax' => (int)($ebook['descargasMax'] ?? DESCARGAS_MAX) ?: DESCARGAS_MAX,
        'archivo' => archivoDelEbook((string)$ebook['id']),
    ];
}

function nombreDeDescarga(?array $ebook, ?array $venta): string {
    $base = (string)($ebook['nombreArchivo'] ?? $venta['ebookTitulo'] ?? 'ebook');
    $base = trim((string)preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $base));
    $base = trim((string)preg_replace('/\.pdf$/i', '', $base));
    return ($base !== '' ? $base : 'ebook') . '.pdf';
}

/* Tildes y eñes a ASCII con una tabla propia (como asciiFold() del template):
   iconv('ASCII//TRANSLIT') depende del sistema y en algunos devuelve cosas como "Jam'on". */
function asciiFold(string $text): string {
    return strtr($text, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'ñ' => 'n', 'Ñ' => 'N', 'ç' => 'c', 'Ç' => 'C', '·' => '-',
    ]);
}

/** Content-Disposition con nombre UTF-8 (RFC 6266) y respaldo ASCII. */
function disposicion(string $tipo, string $nombre): string {
    $ascii = trim((string)preg_replace('/[^\x20-\x7e]|["\\\\]/', '', asciiFold($nombre)));
    if ($ascii === '') $ascii = 'ebook.pdf';
    return $tipo . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre);
}
