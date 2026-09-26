<?php
declare(strict_types=1);

/*
 * Crea o actualiza en Firestore los documentos de la venta de ebooks, con la cuenta de
 * servicio de api/service-account.json:
 *   php scripts/sembrar-firestore.php
 *
 * - ebooks/{id}: título, URL, portada y nombre del archivo salen de scripts/datos-ebooks.json.
 *   El precio y "a la venta" solo se escriben al crear el ebook: después los maneja el panel.
 * - admins/{email}: se crea si no existe (activo: true).
 */

require_once dirname(__DIR__) . '/api/lib/firestore.php';

$datos = json_decode((string)file_get_contents(__DIR__ . '/datos-ebooks.json'), true);
echo 'Proyecto de Firebase: ' . FIREBASE_PROJECT_ID . (firestoreEmulador() !== '' ? ' (emulador)' : '') . "\n";

foreach ($datos['ebooks'] as $ebook) {
    $id = $ebook['id'];
    unset($ebook['id']);
    $existente = fsGet('ebooks/' . $id);
    if (!$existente) {
        fsCreate('ebooks', $id, $ebook + ['creadoAt' => fsTimestamp(), 'actualizadoAt' => fsTimestamp()]);
        echo "✔ ebooks/{$id} creado (precio \${$ebook['precio']})\n";
        continue;
    }
    $cambios = array_diff_key($ebook, array_flip($datos['camposEditables']));
    fsPatch('ebooks/' . $id, $cambios + ['actualizadoAt' => fsTimestamp()]);
    echo "✔ ebooks/{$id} actualizado (precio y venta sin tocar: \${$existente['precio']}, " . (($existente['activo'] ?? false) ? 'a la venta' : 'pausado') . ")\n";
}

$email = $datos['admin'];
if (!fsGet('admins/' . $email)) {
    fsCreate('admins', $email, ['email' => $email, 'activo' => true, 'rol' => 'owner', 'updatedAt' => fsTimestamp()]);
    echo "✔ admins/{$email} creado\n";
} else {
    echo "✔ admins/{$email} ya existe\n";
}
