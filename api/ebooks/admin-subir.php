<?php
declare(strict_types=1);

// POST /api/ebooks/admin-subir.php  (multipart: id, archivo)  — Authorization: Bearer <token>
// Sube o reemplaza el PDF de un ebook en storage/ebooks/{id}.pdf. El reemplazo es atómico:
// quien esté descargando en ese momento termina de recibir el archivo anterior.

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/ebooks.php';
require_once dirname(__DIR__) . '/lib/registro.php';

try {
    requireMethod('POST');
    rateLimit('admin-subir', 20, 600);
    $admin = requireAdmin();

    $largo = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($largo > PDF_MAX_BYTES + 1048576 || (empty($_FILES) && $largo > 0)) {
        throw new ErrorPublico('El PDF es demasiado pesado (máximo ' . (int)(PDF_MAX_BYTES / 1048576) . ' MB).', 413);
    }
    $ebook = leerEbook(cleanText($_POST['id'] ?? '', 60));
    if (!$ebook) throw new ErrorPublico('No existe ese ebook.', 404);
    $archivo = $_FILES['archivo'] ?? null;
    if (!is_array($archivo) || is_array($archivo['error'] ?? null)) throw new ErrorPublico('Elegí un archivo PDF.', 422);
    $error = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new ErrorPublico('El PDF es demasiado pesado para el servidor.', 413);
    if ($error !== UPLOAD_ERR_OK) throw new ErrorPublico('No se pudo subir el archivo. Probá de nuevo.', 422);
    $tamano = (int)($archivo['size'] ?? 0);
    if ($tamano < 1024 || $tamano > PDF_MAX_BYTES) throw new ErrorPublico('El archivo no parece un PDF válido o supera el tamaño permitido.', 422);
    $temporal = (string)$archivo['tmp_name'];
    $inicio = (string)@file_get_contents($temporal, false, null, 0, 5);
    if ($inicio !== '%PDF-') throw new ErrorPublico('El archivo tiene que ser un PDF.', 422);

    if (!is_dir(EBOOKS_DIR) && !@mkdir(EBOOKS_DIR, 0755, true)) throw new RuntimeException('No se pudo crear ' . EBOOKS_DIR);
    $destino = rutaPdf((string)$ebook['id']);
    $intermedio = EBOOKS_DIR . '/.subiendo-' . randomId(8) . '.pdf';
    $movido = is_uploaded_file($temporal) ? move_uploaded_file($temporal, $intermedio) : @rename($temporal, $intermedio);
    if (!$movido || !@rename($intermedio, $destino)) {
        @unlink($intermedio);
        throw new RuntimeException('No se pudo guardar el PDF en ' . EBOOKS_DIR);
    }
    @chmod($destino, 0644);

    $sha256 = (string)hash_file('sha256', $destino);
    $actualizado = fsPatch('ebooks/' . $ebook['id'], [
        'archivoBytes' => $tamano,
        'archivoSha256' => $sha256,
        'archivoActualizadoAt' => fsTimestamp(),
        'actualizadoAt' => fsTimestamp(),
    ]);
    auditar('ebook_pdf', $admin['email'], ['ebook' => $ebook['id'], 'bytes' => $tamano, 'sha256' => $sha256]);
    jsonResponse(['ebook' => vistaAdminEbook($actualizado)]);
} catch (Throwable $error) {
    responderError($error, 'admin-subir');
}
