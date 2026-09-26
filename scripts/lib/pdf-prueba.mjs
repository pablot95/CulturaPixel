// Genera un PDF simple y válido (páginas de texto) para probar el circuito de compra y
// descarga sin usar el ebook real. `rellenoBytes` agrega un objeto binario para simular
// un archivo pesado (por ejemplo, más de 4,5 MB).

import crypto from 'node:crypto';

const escaparTexto = (texto) => texto.replace(/[\\()]/g, (c) => `\\${c}`);

export function pdfDePrueba({ titulo = 'Ebook de prueba', paginas = 3, rellenoBytes = 0 } = {}) {
  const objetos = [];
  const agregar = (contenido) => {
    objetos.push(contenido);
    return objetos.length;
  };

  const catalogo = agregar(null);
  const arbol = agregar(null);
  const fuente = agregar(Buffer.from('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', 'latin1'));
  const hojas = [];
  for (let numero = 1; numero <= paginas; numero += 1) {
    const texto = [
      'BT /F1 26 Tf 72 740 Td',
      `(${escaparTexto(titulo)}) Tj`,
      '0 -40 Td /F1 14 Tf',
      `(Página ${numero} de ${paginas} · Instituto Cultura Pixel) Tj`,
      '0 -26 Td',
      '(Archivo de prueba del circuito de compra: no es el ebook real.) Tj',
      'ET',
    ].join('\n');
    const flujo = Buffer.from(texto, 'latin1');
    const contenido = agregar(Buffer.concat([Buffer.from(`<< /Length ${flujo.length} >>\nstream\n`, 'latin1'), flujo, Buffer.from('\nendstream', 'latin1')]));
    hojas.push(agregar(Buffer.from(`<< /Type /Page /Parent ${arbol} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ${fuente} 0 R >> >> /Contents ${contenido} 0 R >>`, 'latin1')));
  }
  if (rellenoBytes > 0) {
    const relleno = crypto.randomBytes(rellenoBytes);
    agregar(Buffer.concat([Buffer.from(`<< /Length ${relleno.length} >>\nstream\n`, 'latin1'), relleno, Buffer.from('\nendstream', 'latin1')]));
  }
  objetos[catalogo - 1] = Buffer.from(`<< /Type /Catalog /Pages ${arbol} 0 R >>`, 'latin1');
  objetos[arbol - 1] = Buffer.from(`<< /Type /Pages /Kids [${hojas.map((hoja) => `${hoja} 0 R`).join(' ')}] /Count ${hojas.length} >>`, 'latin1');

  const partes = [Buffer.from('%PDF-1.4\n%\xe2\xe3\xcf\xd3\n', 'latin1')];
  let desplazamiento = partes[0].length;
  const posiciones = [];
  objetos.forEach((contenido, indice) => {
    posiciones.push(desplazamiento);
    const bloque = Buffer.concat([Buffer.from(`${indice + 1} 0 obj\n`, 'latin1'), contenido, Buffer.from('\nendobj\n', 'latin1')]);
    partes.push(bloque);
    desplazamiento += bloque.length;
  });
  const xref = [
    'xref',
    `0 ${objetos.length + 1}`,
    '0000000000 65535 f ',
    ...posiciones.map((posicion) => `${String(posicion).padStart(10, '0')} 00000 n `),
    'trailer',
    `<< /Size ${objetos.length + 1} /Root ${catalogo} 0 R >>`,
    'startxref',
    String(desplazamiento),
    '%%EOF',
    '',
  ].join('\n');
  partes.push(Buffer.from(xref, 'latin1'));
  return Buffer.concat(partes);
}
