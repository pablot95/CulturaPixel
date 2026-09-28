<?php
declare(strict_types=1);

$carpeta = sys_get_temp_dir() . '/culturapixel-prueba-ebooks-' . bin2hex(random_bytes(4));
mkdir($carpeta);
define('EBOOKS_DIR', $carpeta);

require __DIR__ . '/_comprobar.php';
require_once dirname(__DIR__, 2) . '/api/lib/ebooks.php';

// Nombre del archivo descargado
$nombre = nombreDeDescarga(['nombreArchivo' => 'Quinceañeras - 100 ideas para tu sesión de fotos'], null);
comprobar($nombre, 'Quinceañeras - 100 ideas para tu sesión de fotos.pdf', 'conserva tildes');
$cabecera = disposicion('attachment', $nombre);
comprobarQue(str_starts_with($cabecera, 'attachment; filename="Quinceaneras - 100 ideas para tu sesion de fotos.pdf"; filename*=UTF-8\'\''), 'respaldo ASCII');
comprobarQue(str_contains($cabecera, 'Quincea%C3%B1eras'), 'nombre UTF-8 codificado');
comprobar(nombreDeDescarga(['nombreArchivo' => 'a/b:c*?"<>|'], null), 'a b c.pdf', 'limpia caracteres inválidos');
comprobar(nombreDeDescarga(null, ['ebookTitulo' => '']), 'ebook.pdf', 'nombre por defecto');
comprobarQue(!str_contains(disposicion('inline', "a\"b\\c.pdf"), 'filename="a"'), 'no deja cortar el nombre con comillas');

// Disponibilidad: activo + precio + PDF subido
$ebook = ['id' => 'quinceaneras', 'activo' => true, 'precio' => 14900];
comprobar(sePuedeComprar($ebook), false, 'sin PDF no se vende');
file_put_contents($carpeta . '/quinceaneras.pdf', "%PDF-1.4\n%prueba\n");
comprobar(sePuedeComprar($ebook), true, 'con PDF se vende');
comprobar(sePuedeComprar(['activo' => false] + $ebook), false, 'pausado no se vende');
comprobar(sePuedeComprar(['precio' => 0] + $ebook), false, 'sin precio no se vende');
comprobar(archivoDelEbook('quinceaneras')['bytes'], strlen("%PDF-1.4\n%prueba\n"), 'informa el tamaño del PDF');
comprobarError(static fn() => rutaPdf('../config'), '/inválido/', 'no permite salir de la carpeta');
comprobar(vistaPublicaEbook($ebook + ['titulo' => 'Q'])['precio'], 14900, 'precio entero');

// Enlace para compartir desde el panel: siempre con el dominio del sitio
comprobar(enlaceEbook(['id' => 'quinceaneras', 'url' => '/ebooks/quinceaneras/']), SITE_URL . '/ebooks/quinceaneras/', 'enlace con el dominio del sitio');
comprobar(enlaceEbook(['id' => 'quinceaneras', 'url' => 'ebooks/quinceaneras/']), SITE_URL . '/ebooks/quinceaneras/', 'agrega la barra que falta');
comprobar(enlaceEbook(['id' => 'quinceaneras']), SITE_URL . '/ebooks/quinceaneras/', 'sin url usa la carpeta del ebook');
comprobar(enlaceEbook(['id' => 'x', 'url' => 'https://otro.com/libro/']), 'https://otro.com/libro/', 'respeta una url completa');
comprobar(vistaAdminEbook($ebook + ['titulo' => 'Q', 'url' => '/ebooks/quinceaneras/'])['enlace'], SITE_URL . '/ebooks/quinceaneras/', 'el panel recibe el enlace');

unlink($carpeta . '/quinceaneras.pdf');
rmdir($carpeta);
terminarPruebas('ebooks');
