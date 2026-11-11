<?php
declare(strict_types=1);

/*
 * Lector de bases de datos de Microsoft Access (.mdb Jet 3 / Jet 4 y .accdb) en PHP puro,
 * sin extensiones ni programas externos. Solo lectura de tablas (no consultas, ni formularios).
 * Basado en la descripción pública del formato de mdbtools.
 *
 *   $mdb = new MdbReader('archivo.mdb');
 *   foreach ($mdb->tables() as $name) { foreach ($mdb->rows($name) as $row) { … } }
 */
final class MdbReader
{
    private string $d;          // archivo completo
    private int $ps;            // tamaño de página
    private bool $jet3;
    private string $charset;    // codificación del texto en Jet 3
    private array $tdefCache = [];

    public function __construct(string $file, string $charset = 'Windows-1252')
    {
        $this->d = (string)file_get_contents($file);
        if (strlen($this->d) < 4096 || substr($this->d, 4, 15) !== 'Standard Jet DB' && substr($this->d, 4, 15) !== 'Standard ACE DB') {
            throw new RuntimeException('No es una base de datos de Access.');
        }
        $ver = ord($this->d[0x14]);
        $this->jet3 = $ver === 0;
        $this->ps = $this->jet3 ? 2048 : 4096;
        $this->charset = $charset;
    }

    public function isJet3(): bool
    {
        return $this->jet3;
    }

    /* ---------- utilidades binarias ---------- */

    private function u8(int $o): int
    {
        return ord($this->d[$o]);
    }

    private function u16(int $o): int
    {
        return unpack('v', $this->d, $o)[1];
    }

    private function u32(int $o): int
    {
        return unpack('V', $this->d, $o)[1];
    }

    private function page(int $n): int
    {
        return $n * $this->ps;
    }

    /* ---------- definición de tabla (TDEF) ---------- */

    /** Junta las páginas de una definición de tabla en un solo bloque. */
    private function tdefBuffer(int $pg): string
    {
        $buf = substr($this->d, $this->page($pg), $this->ps);
        $next = $this->u32($this->page($pg) + 4);
        $guard = 0;
        while ($next !== 0 && $guard++ < 1000) {
            $buf .= substr($this->d, $this->page($next) + 8, $this->ps - 8);
            $next = $this->u32($this->page($next) + 4);
        }
        return $buf;
    }

    /** Columnas de una tabla: [nombre, tipo, nº, índice variable, offset fijo, tamaño, fija?] */
    public function tdef(int $pg): array
    {
        if (isset($this->tdefCache[$pg])) {
            return $this->tdefCache[$pg];
        }
        if ($this->u8($this->page($pg)) !== 0x02) {
            throw new RuntimeException('Página ' . $pg . ' no es una definición de tabla.');
        }
        $b = $this->tdefBuffer($pg);
        $U16 = fn(int $o) => unpack('v', $b, $o)[1];
        $U32 = fn(int $o) => unpack('V', $b, $o)[1];
        if ($this->jet3) {
            $numRows = $U32(12);
            $numVar = $U16(23);
            $numCols = $U16(25);
            $numRealIdx = $U32(31);
            $usage = $U32(35);
            $pos = 43 + $numRealIdx * 8;
            $colSize = 18;
        } else {
            $numRows = $U32(16);
            $numVar = $U16(43);
            $numCols = $U16(45);
            $numRealIdx = $U32(51);
            $usage = $U32(55);
            $pos = 63 + $numRealIdx * 12;
            $colSize = 25;
        }
        $cols = [];
        for ($i = 0; $i < $numCols; $i++) {
            $o = $pos + $i * $colSize;
            if ($this->jet3) {
                $cols[] = [
                    'type' => ord($b[$o]), 'num' => $U16($o + 1), 'var' => $U16($o + 3),
                    'flags' => ord($b[$o + 13]), 'fixed_off' => $U16($o + 14), 'size' => $U16($o + 16),
                ];
            } else {
                $cols[] = [
                    'type' => ord($b[$o]), 'num' => $U16($o + 5), 'var' => $U16($o + 7),
                    'flags' => ord($b[$o + 15]), 'fixed_off' => $U16($o + 21), 'size' => $U16($o + 23),
                ];
            }
        }
        $pos += $numCols * $colSize;
        foreach ($cols as &$c) {
            if ($this->jet3) {
                $len = ord($b[$pos]);
                $c['name'] = $this->text(substr($b, $pos + 1, $len), false);
                $pos += 1 + $len;
            } else {
                $len = $U16($pos);
                $c['name'] = $this->text(substr($b, $pos + 2, $len), true);
                $pos += 2 + $len;
            }
            $c['fixed'] = ($c['flags'] & 0x01) !== 0;
        }
        unset($c);
        usort($cols, fn($a, $b) => $a['num'] <=> $b['num']);
        return $this->tdefCache[$pg] = ['rows' => $numRows, 'cols' => $cols, 'usage' => $usage, 'num_var' => $numVar];
    }

    /* ---------- filas ---------- */

    /** [inicio, fin exclusivo, flags] de la fila $r de la página $pg. */
    private function rowBounds(int $pg, int $r): ?array
    {
        $base = $this->page($pg);
        $countOff = $this->jet3 ? 8 : 12;
        $n = $this->u16($base + $countOff);
        if ($r >= $n) {
            return null;
        }
        $raw = $this->u16($base + $countOff + 2 + $r * 2);
        $start = $raw & 0x1FFF;
        $end = $r === 0 ? $this->ps : ($this->u16($base + $countOff + 2 + ($r - 1) * 2) & 0x1FFF);
        return [$base + $start, $base + $end, $raw & 0xE000];
    }

    /** Datos de una fila apuntada por un puntero de 4 bytes (nº de fila + página). */
    private function rowByPointer(int $ptr): ?string
    {
        $r = $ptr & 0xFF;
        $pg = $ptr >> 8;
        if ($pg <= 0 || $pg * $this->ps >= strlen($this->d)) {
            return null;
        }
        $b = $this->rowBounds($pg, $r);
        return $b ? substr($this->d, $b[0], $b[1] - $b[0]) : null;
    }

    /** Páginas de datos de una tabla, según su mapa de uso. */
    private function usagePages(int $usagePtr): array
    {
        $map = $this->rowByPointer($usagePtr);
        if ($map === null || $map === '') {
            return [];
        }
        $pages = [];
        $type = ord($map[0]);
        if ($type === 0) {
            $startPg = unpack('V', $map, 1)[1];
            for ($i = 5, $n = strlen($map); $i < $n; $i++) {
                $byte = ord($map[$i]);
                for ($bit = 0; $bit < 8; $bit++) {
                    if ($byte & (1 << $bit)) {
                        $pages[] = $startPg + ($i - 5) * 8 + $bit;
                    }
                }
            }
        } elseif ($type === 1) {
            $perPage = ($this->ps - 4) * 8;
            for ($j = 0; ($j + 1) * 4 + 1 <= strlen($map); $j++) {
                $mp = unpack('V', $map, 1 + $j * 4)[1];
                if ($mp === 0 || $mp * $this->ps >= strlen($this->d)) {
                    continue;
                }
                $base = $this->page($mp);
                for ($i = 4; $i < $this->ps; $i++) {
                    $byte = ord($this->d[$base + $i]);
                    if ($byte === 0) {
                        continue;
                    }
                    for ($bit = 0; $bit < 8; $bit++) {
                        if ($byte & (1 << $bit)) {
                            $pages[] = $j * $perPage + ($i - 4) * 8 + $bit;
                        }
                    }
                }
            }
        }
        return $pages;
    }

    /**
     * Filas de la tabla cuya definición está en la página $tdefPg (generador de arrays nombre => valor).
     */
    public function rowsByPage(int $tdefPg): Generator
    {
        $t = $this->tdef($tdefPg);
        $pages = $this->usagePages($t['usage']);
        $total = intdiv(strlen($this->d), $this->ps);
        foreach ($pages as $pg) {
            if ($pg <= 0 || $pg >= $total) {
                continue;
            }
            $base = $this->page($pg);
            if ($this->u8($base) !== 0x01 || $this->u32($base + 4) !== $tdefPg) {
                continue;
            }
            $n = $this->u16($base + ($this->jet3 ? 8 : 12));
            for ($r = 0; $r < $n; $r++) {
                [$s, $e, $flags] = $this->rowBounds($pg, $r);
                if ($flags & 0x8000) {
                    continue; // borrada
                }
                $row = substr($this->d, $s, $e - $s);
                if ($flags & 0x4000) {
                    // fila desbordada: aquí solo hay un puntero a la fila real
                    if (strlen($row) < 4) {
                        continue;
                    }
                    $row = $this->rowByPointer(unpack('V', $row, 0)[1]);
                    if ($row === null) {
                        continue;
                    }
                }
                if ($row === '') {
                    continue;
                }
                $vals = $this->crackRow($row, $t['cols']);
                if ($vals !== null) {
                    yield $vals;
                }
            }
        }
    }

    /** Descompone una fila en valores. */
    private function crackRow(string $row, array $cols): ?array
    {
        $len = strlen($row);
        if ($this->jet3) {
            $numCols = ord($row[0]);
            $bm = intdiv($numCols + 7, 8);
            if ($len < $bm + 2) {
                return null;
            }
            $end = $len - 1; // último byte (incluido)
            $numVar = ord($row[$end - $bm]);
            $numJumps = intdiv($len - 1, 256);
            $colPtr = $end - $bm - $numJumps - 1;
            if ($numJumps > 0 && intdiv($colPtr - $numVar, 256) < $numJumps) {
                $numJumps--;
            }
            $varOff = [];
            $jumps = 0;
            for ($i = 0; $i <= $numVar; $i++) {
                while ($jumps < $numJumps && $i === ord($row[$end - $bm - $jumps - 1])) {
                    $jumps++;
                }
                $p = $colPtr - $i;
                $varOff[$i] = ($p >= 0 && $p < $len ? ord($row[$p]) : 0) + $jumps * 256;
            }
            $fixedStart = 1;
        } else {
            $numCols = unpack('v', $row, 0)[1];
            $bm = intdiv($numCols + 7, 8);
            if ($len < $bm + 4) {
                return null;
            }
            $numVar = unpack('v', $row, $len - $bm - 2)[1];
            $varOff = [];
            for ($i = 0; $i <= $numVar; $i++) {
                $p = $len - $bm - 4 - $i * 2;
                $varOff[$i] = $p >= 0 ? unpack('v', $row, $p)[1] : 0;
            }
            $fixedStart = 2;
        }
        $nullMask = substr($row, $len - $bm, $bm);
        $out = [];
        foreach ($cols as $c) {
            $num = $c['num'];
            $present = $num < $numCols && $num < $bm * 8 && (ord($nullMask[intdiv($num, 8)]) & (1 << ($num % 8))) !== 0;
            if ($c['type'] === 1) { // Sí/No: el valor es el propio bit
                $out[$c['name']] = $present ? '1' : '0';
                continue;
            }
            if (!$present) {
                $out[$c['name']] = null;
                continue;
            }
            if ($c['fixed']) {
                $o = $fixedStart + $c['fixed_off'];
                $raw = substr($row, $o, $c['size']);
            } else {
                $vi = $c['var'];
                if ($vi >= $numVar) {
                    $out[$c['name']] = null;
                    continue;
                }
                $a = $varOff[$vi];
                $b = $varOff[$vi + 1];
                if ($b < $a || $b > $len) {
                    $out[$c['name']] = null;
                    continue;
                }
                $raw = substr($row, $a, $b - $a);
            }
            $out[$c['name']] = $this->value($c['type'], $raw);
        }
        return $out;
    }

    /** Convierte el dato binario según el tipo de columna. */
    private function value(int $type, string $raw): ?string
    {
        $n = strlen($raw);
        switch ($type) {
            case 2: // byte
                return $n >= 1 ? (string)ord($raw[0]) : null;
            case 3: // entero
                return $n >= 2 ? (string)unpack('s', $raw)[1] : null;
            case 4: // entero largo / autonumérico
                return $n >= 4 ? (string)unpack('l', $raw)[1] : null;
            case 5: // moneda (entero de 64 bits / 10000)
                if ($n < 8) {
                    return null;
                }
                $v = unpack('q', $raw)[1];
                return rtrim(rtrim(number_format($v / 10000, 4, '.', ''), '0'), '.');
            case 6: // simple
                return $n >= 4 ? (string)round(unpack('g', $raw)[1], 6) : null;
            case 7: // doble
                return $n >= 8 ? (string)unpack('e', $raw)[1] : null;
            case 8: // fecha: días desde 30/12/1899
                if ($n < 8) {
                    return null;
                }
                $days = unpack('e', $raw)[1];
                $ts = (int)round(($days - 25569) * 86400);
                return gmdate($days == floor($days) ? 'Y-m-d' : 'Y-m-d H:i:s', $ts);
            case 10: // texto
                return $this->text($raw, !$this->jet3);
            case 12: // memo
                return $this->memo($raw);
            case 15: // GUID
                if ($n < 16) {
                    return null;
                }
                $h = bin2hex($raw);
                return '{' . strtoupper(substr($h, 6, 2) . substr($h, 4, 2) . substr($h, 2, 2) . substr($h, 0, 2) . '-' . substr($h, 10, 2) . substr($h, 8, 2) . '-' . substr($h, 14, 2) . substr($h, 12, 2) . '-' . substr($h, 16, 4) . '-' . substr($h, 20)) . '}';
            case 16: // numérico (decimal)
                if ($n < 17) {
                    return null;
                }
                $neg = (ord($raw[0]) & 0x80) !== 0;
                $parts = unpack('V4', $raw, 1);
                $num = '0';
                foreach ([$parts[4], $parts[3], $parts[2], $parts[1]] as $p) {
                    $num = function_exists('bcadd') ? bcadd(bcmul($num, '4294967296'), (string)$p) : (string)((float)$num * 4294967296 + $p);
                }
                return ($neg ? '-' : '') . $num;
            default: // binario, OLE, adjuntos…: no se importan
                return null;
        }
    }

    /** Texto: en Jet 3 en la página de códigos; en Jet 4, UTF-16 o «unicode comprimido». */
    private function text(string $raw, bool $unicode): string
    {
        if (!$unicode) {
            return (string)mb_convert_encoding($raw, 'UTF-8', $this->charset);
        }
        if (str_starts_with($raw, "\xFF\xFE")) {
            // Unicode comprimido: tramos de 1 byte por carácter, alternando con UTF-16 tras cada 0x00
            $out = '';
            $compressed = true;
            $i = 2;
            $n = strlen($raw);
            while ($i < $n) {
                if ($raw[$i] === "\0") {
                    $compressed = !$compressed;
                    $i++;
                    continue;
                }
                if ($compressed) {
                    $out .= $raw[$i] . "\0";
                    $i++;
                } else {
                    $out .= substr($raw, $i, 2);
                    $i += 2;
                }
            }
            $raw = $out;
        }
        return (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    }

    /** Campos memo: dentro de la fila, en una página aparte o encadenados en varias. */
    private function memo(string $raw): ?string
    {
        if (strlen($raw) < 12) {
            return null;
        }
        $head = unpack('V', $raw, 0)[1];
        $len = $head & 0x3FFFFFFF;
        $ptr = unpack('V', $raw, 4)[1];
        $unicode = !$this->jet3;
        if ($head & 0x80000000) {
            return $this->text(substr($raw, 12, $len), $unicode);
        }
        if ($head & 0x40000000) {
            $data = $this->rowByPointer($ptr);
            return $data === null ? null : $this->text(substr($data, 0, $len), $unicode);
        }
        $data = '';
        $guard = 0;
        while ($ptr !== 0 && strlen($data) < $len && $guard++ < 10000) {
            $chunk = $this->rowByPointer($ptr);
            if ($chunk === null || strlen($chunk) < 4) {
                break;
            }
            $ptr = unpack('V', $chunk, 0)[1];
            $data .= substr($chunk, 4);
        }
        return $this->text(substr($data, 0, $len), $unicode);
    }

    /* ---------- catálogo ---------- */

    /** Tablas del usuario: [nombre => página de su definición]. */
    public function tables(): array
    {
        $out = [];
        foreach ($this->rowsByPage(2) as $r) {
            $type = (int)($r['Type'] ?? 0);
            $flags = (int)($r['Flags'] ?? 0);
            $name = (string)($r['Name'] ?? '');
            if ($type === 1 && ($flags & 0x80000002) === 0 && $name !== '' && !str_starts_with($name, 'MSys') && !str_starts_with($name, '~')) {
                $out[$name] = ((int)$r['Id']) & 0x00FFFFFF;
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** Filas de una tabla por su nombre. */
    public function rows(string $table): Generator
    {
        $t = $this->tables();
        if (!isset($t[$table])) {
            throw new RuntimeException('No existe la tabla ' . $table . '.');
        }
        yield from $this->rowsByPage($t[$table]);
    }

    public function columns(int $tdefPg): array
    {
        return array_column($this->tdef($tdefPg)['cols'], 'name');
    }

    public function rowCount(int $tdefPg): int
    {
        return $this->tdef($tdefPg)['rows'];
    }
}
