"""
Imágenes de un ebook para la web, en ebooks/<id>/img/:
  - portada.webp (tapa) y portada-chica.webp (home, página de gracias)
  - resumen.webp + resumen-grande.webp (presentación del libro, opcional)
  - pagina-01.webp ... (carrusel) + pagina-01-grande.webp ... (visor ampliado)
  - og.jpg (1200x630, para compartir el link en WhatsApp, Instagram y Facebook)

Desde imágenes sueltas (JPG, PNG o WebP de cualquier tamaño; las ideas van en el orden dado):
  python scripts/imagenes_ebook.py --id quinceaneras --tapa ebooks/001.jpg --resumen ebooks/002.jpg --ideas "ebooks/a caballo.jpg" ebooks/Y2K.jpg
Desde el PDF real:
  python scripts/imagenes_ebook.py --pdf "C:/ruta/libro.pdf" --id quinceaneras --paginas 1,4,9,15,22,30,41,55
Provisorias (sin PDF todavía):
  python scripts/imagenes_ebook.py --provisorio --id quinceaneras

Requiere Pillow, y PyMuPDF solo para --pdf (pip install pillow pymupdf).
"""

import argparse
import io
import os
import sys

from PIL import Image, ImageCms, ImageDraw, ImageFilter, ImageFont, ImageOps

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FUENTES = "C:/Windows/Fonts"
VIOLETA_NOCHE = (42, 26, 110)
VIOLETA = (109, 63, 224)
ROSA = (217, 70, 143)
TINTA = (15, 16, 53)

# Anchos de salida. Las "grandes" solo se bajan al ampliar una página en el visor:
# a 1400 px se lee el texto chico de las páginas.
ANCHO_PORTADA, ANCHO_PORTADA_CHICA = 900, 480
ANCHO_RESUMEN, ANCHO_PAGINA, ANCHO_GRANDE = 1000, 600, 1400


def fuente(tamano, peso="Bold"):
    for archivo in ("bahnschrift.ttf", "seguibl.ttf", "arialbd.ttf"):
        ruta = os.path.join(FUENTES, archivo)
        if os.path.exists(ruta):
            f = ImageFont.truetype(ruta, tamano)
            if archivo == "bahnschrift.ttf":
                try:
                    f.set_variation_by_name(peso)
                except Exception:
                    pass
            return f
    return ImageFont.load_default(tamano)


def degradado(ancho, alto, colores):
    """Degradado vertical entre varios colores."""
    img = Image.new("RGB", (ancho, alto))
    pintor = ImageDraw.Draw(img)
    tramos = len(colores) - 1
    for y in range(alto):
        t = y / max(1, alto - 1) * tramos
        i = min(int(t), tramos - 1)
        f = t - i
        c = tuple(round(colores[i][k] + (colores[i + 1][k] - colores[i][k]) * f) for k in range(3))
        pintor.line([(0, y), (ancho, y)], fill=c)
    return img


def texto_espaciado(pintor, xy, texto, letra, color, espacio):
    x, y = xy
    for caracter in texto:
        pintor.text((x, y), caracter, font=letra, fill=color)
        x += pintor.textlength(caracter, font=letra) + espacio
    return x


def ancho_espaciado(pintor, texto, letra, espacio):
    return sum(pintor.textlength(c, font=letra) for c in texto) + espacio * (len(texto) - 1)


def lineas_balanceadas(pintor, texto, letra, ancho_max, espacio=0):
    """Corta el texto en la menor cantidad de líneas y con largos parejos (sin palabras huérfanas)."""
    palabras = texto.split()
    medir = lambda t: ancho_espaciado(pintor, t, letra, espacio) if espacio else pintor.textlength(t, font=letra)
    if medir(texto) <= ancho_max:
        return [texto]
    mejor = None
    for corte in range(1, len(palabras)):
        a, b = " ".join(palabras[:corte]), " ".join(palabras[corte:])
        peor = max(medir(a), medir(b))
        if peor <= ancho_max and (mejor is None or peor < mejor[0]):
            mejor = (peor, [a, b])
    if mejor:
        return mejor[1]
    lineas, linea = [], ""
    for palabra in palabras:
        prueba = (linea + " " + palabra).strip()
        if medir(prueba) > ancho_max and linea:
            lineas.append(linea)
            linea = palabra
        else:
            linea = prueba
    return lineas + [linea]


def pixeles(pintor, x0, y0, columnas, filas, lado, colores):
    """Motivo de cuadraditos como el logo de Cultura Pixel."""
    for fila in range(filas):
        for col in range(columnas):
            if (fila * 7 + col * 3) % 5 == 0:
                continue
            alfa = max(40, 230 - col * 26 - fila * 12)
            color = colores[(fila + col) % len(colores)] + (alfa,)
            x = x0 + col * (lado + 5)
            y = y0 + fila * (lado + 5)
            pintor.rectangle([x, y, x + lado, y + lado], fill=color)


def portada_provisoria(titulo, subtitulo):
    ancho, alto = 900, 1272
    img = degradado(ancho, alto, [VIOLETA_NOCHE, VIOLETA, ROSA]).convert("RGBA")
    capa = Image.new("RGBA", img.size, (0, 0, 0, 0))
    pintor = ImageDraw.Draw(capa)
    # "15" gigante de fondo
    pintor.text((ancho - 20, alto * 0.60), "15", font=fuente(720), fill=(255, 255, 255, 34), anchor="rm")
    pixeles(pintor, 70, 80, 7, 4, 26, [(255, 255, 255), (255, 170, 210), (190, 170, 255)])
    img = Image.alpha_composite(img, capa)
    pintor = ImageDraw.Draw(img)
    letra_titulo = fuente(118)
    while pintor.textlength(titulo.upper(), font=letra_titulo) > ancho - 140:
        letra_titulo = fuente(letra_titulo.size - 4)
    pintor.text((70, 330), titulo.upper(), font=letra_titulo, fill="white")
    letra_sub = fuente(40, "SemiBold")
    y = 330 + letra_titulo.size + 40
    for linea in lineas_balanceadas(pintor, subtitulo.upper(), letra_sub, ancho - 140, 5):
        texto_espaciado(pintor, (70, y), linea, letra_sub, (255, 236, 246), 5)
        y += 58
    pintor.rectangle([70, alto - 150, 150, alto - 144], fill="white")
    texto_espaciado(pintor, (70, alto - 120), "INSTITUTO CULTURA PIXEL", fuente(30, "SemiBold"), "white", 6)
    return img.convert("RGB")


def pagina_provisoria(numero):
    ancho, alto = 720, 1018
    img = Image.new("RGB", (ancho, alto), (250, 248, 255))
    pintor = ImageDraw.Draw(img)
    pintor.text((56, 60), f"IDEA {numero:02d}", font=fuente(34), fill=VIOLETA)
    pintor.rounded_rectangle([56, 130, ancho - 56, 610], radius=18, fill=(231, 224, 252))
    pintor.text((ancho / 2, 370), "Foto de ejemplo", font=fuente(30, "SemiBold"), fill=(150, 130, 210), anchor="mm")
    for i, largo in enumerate([0.92, 0.85, 0.9, 0.6, 0.88, 0.7]):
        y = 660 + i * 40
        pintor.rounded_rectangle([56, y, 56 + (ancho - 112) * largo, y + 14], radius=7, fill=(214, 210, 236))
    pintor.text((ancho / 2, alto - 50), "Página de muestra", font=fuente(22, "SemiBold"), fill=(170, 165, 200), anchor="mm")
    return img


def render_pagina(documento, indice, ancho):
    import fitz  # PyMuPDF, solo para --pdf

    pagina = documento[indice]
    escala = ancho / pagina.rect.width
    mapa = pagina.get_pixmap(matrix=fitz.Matrix(escala, escala), alpha=False)
    return Image.open(io.BytesIO(mapa.tobytes("png"))).convert("RGB")


def abrir_imagen(ruta):
    """Abre una imagen derecha (EXIF) y en sRGB, sin transparencia."""
    if not os.path.exists(ruta):
        sys.exit(f"No existe la imagen: {ruta}")
    with Image.open(ruta) as original:
        img = ImageOps.exif_transpose(original)
        perfil = original.info.get("icc_profile")
        if perfil:
            try:
                origen = ImageCms.ImageCmsProfile(io.BytesIO(perfil))
                if "srgb" not in ImageCms.getProfileDescription(origen).lower():
                    img = ImageCms.profileToProfile(img.convert("RGB"), origen, ImageCms.createProfile("sRGB"))
            except (OSError, ImageCms.PyCMSError):
                pass
        if img.mode in ("RGBA", "LA", "P"):
            fondo = Image.new("RGB", img.size, "white")
            fondo.paste(img.convert("RGBA"), mask=img.convert("RGBA").getchannel("A"))
            return fondo
        return img.convert("RGB")


def imagen_og(portada, titulo, subtitulo):
    ancho, alto = 1200, 630
    fondo = degradado(ancho, alto, [(247, 243, 255), (255, 233, 244)]).convert("RGBA")
    capa = Image.new("RGBA", fondo.size, (0, 0, 0, 0))
    pixeles(ImageDraw.Draw(capa), 70, 64, 6, 2, 18, [VIOLETA, ROSA, (30, 78, 216)])
    fondo = Image.alpha_composite(fondo, capa)
    tapa = portada.copy()
    tapa.thumbnail((420, 520), Image.LANCZOS)
    sombra = Image.new("RGBA", (tapa.width + 80, tapa.height + 80), (0, 0, 0, 0))
    ImageDraw.Draw(sombra).rounded_rectangle([40, 50, 40 + tapa.width, 50 + tapa.height], radius=12, fill=(42, 26, 110, 120))
    sombra = sombra.filter(ImageFilter.GaussianBlur(18))
    x, y = ancho - tapa.width - 90, (alto - tapa.height) // 2
    fondo.alpha_composite(sombra, (x - 40, y - 40))
    fondo.paste(tapa, (x, y))
    pintor = ImageDraw.Draw(fondo)
    ancho_texto = x - 70 - 50
    texto_espaciado(pintor, (70, 150), "EBOOK · PDF", fuente(28, "SemiBold"), VIOLETA, 5)
    letra = fuente(96)
    while pintor.textlength(titulo, font=letra) > ancho_texto:
        letra = fuente(letra.size - 4)
    pintor.text((66, 200), titulo, font=letra, fill=TINTA)
    y = 200 + letra.size + 30
    letra_sub = fuente(40, "SemiBold")
    for linea in lineas_balanceadas(pintor, subtitulo, letra_sub, ancho_texto):
        pintor.text((70, y), linea, font=letra_sub, fill=(74, 74, 122))
        y += 54
    texto_espaciado(pintor, (70, alto - 90), "INSTITUTO CULTURA PIXEL", fuente(26, "SemiBold"), VIOLETA, 5)
    return fondo.convert("RGB")


def guardar_webp(img, ruta, ancho, calidad=80):
    copia = img.copy()
    if copia.width > ancho:
        copia = copia.resize((ancho, round(copia.height * ancho / copia.width)), Image.LANCZOS)
    copia.save(ruta, "WEBP", quality=calidad, method=6)
    return copia.size


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--id", required=True)
    parser.add_argument("--tapa", help="imagen de la tapa")
    parser.add_argument("--resumen", help="imagen de presentación del libro (opcional)")
    parser.add_argument("--ideas", nargs="+", default=[], help="páginas de muestra, en el orden del carrusel")
    parser.add_argument("--pdf")
    parser.add_argument("--paginas", default="")
    parser.add_argument("--provisorio", action="store_true")
    parser.add_argument("--titulo", default="Quinceañeras")
    parser.add_argument("--subtitulo", default="100 ideas para tu sesión de fotos")
    args = parser.parse_args()

    resumen = None
    if args.tapa:
        portada = abrir_imagen(args.tapa)
        resumen = abrir_imagen(args.resumen) if args.resumen else None
        paginas = [abrir_imagen(ruta) for ruta in args.ideas]
    elif args.provisorio:
        portada = portada_provisoria(args.titulo, args.subtitulo)
        paginas = [pagina_provisoria(n) for n in range(1, 9)]
    else:
        if not args.pdf or not os.path.exists(args.pdf):
            sys.exit("Falta --tapa, --pdf o --provisorio (o el PDF no existe).")
        import fitz  # PyMuPDF

        documento = fitz.open(args.pdf)
        total = documento.page_count
        if args.paginas:
            numeros = [int(n) for n in args.paginas.split(",") if n.strip()]
        else:
            # Por defecto: 8 páginas repartidas, salteando la tapa.
            numeros = sorted({max(2, round(2 + i * (total - 2) / 7)) for i in range(8)}) if total > 2 else list(range(1, total + 1))
        numeros = [n for n in numeros if 1 <= n <= total]
        portada = render_pagina(documento, 0, 1100)
        paginas = [render_pagina(documento, n - 1, ANCHO_GRANDE) for n in numeros]

    destino = os.path.join(RAIZ, "ebooks", args.id, "img")
    os.makedirs(destino, exist_ok=True)
    for viejo in os.listdir(destino):
        if viejo.startswith("pagina-") and viejo.endswith(".webp"):
            os.remove(os.path.join(destino, viejo))

    medidas = {
        "portada": guardar_webp(portada, os.path.join(destino, "portada.webp"), ANCHO_PORTADA, 82),
        "portada-chica": guardar_webp(portada, os.path.join(destino, "portada-chica.webp"), ANCHO_PORTADA_CHICA, 82),
    }
    if resumen is not None:
        medidas["resumen"] = guardar_webp(resumen, os.path.join(destino, "resumen.webp"), ANCHO_RESUMEN)
        medidas["resumen-grande"] = guardar_webp(resumen, os.path.join(destino, "resumen-grande.webp"), ANCHO_GRANDE)
    for i, pagina in enumerate(paginas, start=1):
        medidas[f"pagina-{i:02d}"] = guardar_webp(pagina, os.path.join(destino, f"pagina-{i:02d}.webp"), ANCHO_PAGINA, 78)
        medidas[f"pagina-{i:02d}-grande"] = guardar_webp(pagina, os.path.join(destino, f"pagina-{i:02d}-grande.webp"), ANCHO_GRANDE)
    imagen_og(portada, args.titulo, args.subtitulo).save(os.path.join(destino, "og.jpg"), "JPEG", quality=84, optimize=True, progressive=True)
    medidas["og"] = (1200, 630)
    for nombre, (ancho, alto) in medidas.items():
        tamano = os.path.getsize(os.path.join(destino, f"{nombre}.{'jpg' if nombre == 'og' else 'webp'}"))
        print(f"{nombre}: {ancho}x{alto}, {tamano // 1024} KB")


if __name__ == "__main__":
    main()
