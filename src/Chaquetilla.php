<?php
declare(strict_types=1);

namespace GranChance;

/**
 * Dibuja en SVG la chaquetilla y la gorra de un stud a partir de la descripción de sus colores en el Stud Book
 * ("Azul, franja y mangas oro, gorra a cascos"). Es una lectura aproximada: entiende los colores, los diseños más
 * comunes del cuerpo (rayas, mitades, cuarteles, cuadros, franja, banda, tiradores, cruz, V, estrellas, lunares...),
 * las mangas, puños, brazales, cuello y la gorra; lo que no reconoce lo deja afuera.
 */
final class Chaquetilla
{
    /** Patrón de cada color (sin tildes, en minúscula) → color. Los compuestos van antes que los simples. */
    private const COLORES = [
        'azul marino' => '#1a2550', 'azul electrico' => '#1e50ff', 'azul francia' => '#2b4fc7',
        'azul(?:es)? (?:oscuro|noche)' => '#1b2a6b', 'azul(?:es)? (?:claro|cielo)' => '#7fb2ff', 'azul(?:es)?' => '#1f3fbf',
        'verdes? (?:manzana|limon|claro|luz|loro|fluo\w*)' => '#7ccf3a', 'verdes? (?:oscuro|ingles|botella|militar|oliva)' => '#14532d',
        'verdes? agua' => '#5fd3b8', 'verdes?' => '#1e8f3e', 'celestes?' => '#6ec6f0', 'turquesas?' => '#1fbfb8',
        'blanc[oa]s?' => '#ffffff', 'negr[oa]s?' => '#111111', 'roj[oa]s?|punzo|carmesi|lacre|escarlata' => '#d4161c',
        'bordo|bordeaux|granate|guinda|vino' => '#7a1022', 'amarill[oa]s?' => '#ffd31a', 'oro|dorad[oa]s?|mostaza' => '#d4a017',
        'naranjas?|anaranjad[oa]s?' => '#ff7a12', 'fucsias?' => '#e0168a', 'rosad[oa]s?|rosas?' => '#f7a1c4',
        'violetas? clar[oa]s?' => '#a98be0', 'violetas?|morad[oa]s?|purpuras?' => '#6b2fa0', 'lilas?|lavanda' => '#b79be0',
        'gris(?:es)?(?: perla)?|platead[oa]s?|plata' => '#9aa0a6', 'marron(?:es)?|cafe|chocolate|habano' => '#6b3e1f',
        'cremas?|beige|hueso|marfil' => '#f3e6c4', 'salmon' => '#fa8f72', 'ocre' => '#c98b2b', 'caqui|kaki' => '#b5a36b',
    ];

    /** Partes y adornos que se nombran en la descripción → nombre interno. */
    private const PARTES = [
        'mangas?' => 'mangas', 'gorras?' => 'gorra', 'cuellos?' => 'cuello', 'punos?' => 'punos', 'brazal(?:es)?' => 'brazales',
        'visera' => 'visera', 'borla|pompon' => 'pompon',
        'estrell\w*' => 'estrellas', 'lunares|bolas|pelotas' => 'lunares', 'rayas?|listas?|lineas?|listones?' => 'rayas',
        'franjas?|fajas?|cinturon|cinto' => 'franja', 'bandas?|bandoleras?' => 'banda', 'tiradores' => 'tiradores',
        'cruz|cruces' => 'cruz', 'v' => 'v', 'circulos?|aros?' => 'circulo', 'rombos?|diamantes?' => 'rombos',
        'canesu' => 'canesu', 'laterales|costados' => 'laterales',
        'hombros|hombreras|charreteras' => 'hombros', 'herraduras?' => 'figura', 'chevron\w*|galones?' => 'chevron',
        'letras?|leyendas?|iniciales|figuras?|escudos?|corazon\w*|trebol\w*|medias? lunas?' => 'figura',
    ];

    /** Palabras de diseño → patrón. */
    private const PATRONES = [
        'mitades? horizontales|horizontalmente' => 'mitades_h', 'mitades? (?:oblicuas|diagonales)' => 'oblicuas',
        'mitades?|verticalmente' => 'mitades', 'cuarteles|cuartos' => 'cuarteles', 'cascos|longitudinal\w*' => 'cascos',
        'horizontal(?:es)?' => 'horizontales', 'vertical(?:es)?' => 'verticales', 'oblicu\w*|diagonal\w*|sesgad\w*' => 'oblicuas',
        'cuadros|damero|ajedrez|cuadriculad\w*' => 'cuadros', 'triangulos?' => 'triangulos',
    ];

    /**
     * Chaquetilla oficial del hipódromo según el número del mandil, para los studs que no figuran en el Stud Book.
     * Según la lista oficial (la 17 es de un violeta más claro que la 10).
     */
    public const OFICIALES = [
        1 => 'Rojo y blanco en mitades horizontales, mangas y gorra rojas',
        2 => 'Blanco y negro en mitades horizontales, mangas y gorra blancas',
        3 => 'Azul y blanco en mitades horizontales, mangas y gorra azules',
        4 => 'Oro y negro en mitades horizontales, mangas y gorra oro',
        5 => 'Verde y blanco en mitades horizontales, mangas y gorra verdes',
        6 => 'Negro y amarillo en mitades horizontales, mangas y gorra negras',
        7 => 'Naranja y negro en mitades horizontales, mangas y gorra naranja',
        8 => 'Rosado y negro en mitades horizontales, mangas y gorra rosado',
        9 => 'Turquesa y negro en mitades horizontales, mangas y gorra turquesa',
        10 => 'Violeta y blanco en mitades horizontales, mangas y gorra violeta',
        11 => 'Gris y rojo en mitades horizontales, mangas y gorra gris',
        12 => 'Verde luz y negro en mitades horizontales, mangas y gorra verde luz',
        13 => 'Marrón y blanco en mitades horizontales, mangas y gorra marrones',
        14 => 'Granate y oro en mitades horizontales, mangas y gorra granate',
        15 => 'Caqui y blanco en mitades horizontales, mangas y gorra caqui',
        16 => 'Azul y amarillo en mitades horizontales, mangas y gorra azules',
        17 => 'Violeta claro y blanco en mitades horizontales, mangas y gorra violeta claro',
        18 => 'Verde claro y blanco en mitades horizontales, mangas y gorra verde claro',
        19 => 'Azul y rosado en mitades horizontales, mangas y gorra azules',
        20 => 'Marrón y amarillo en mitades horizontales, mangas y gorra marrones',
    ];

    private const BORDE = '#3a3a3a';
    private static int $n = 0;

    /** Descripción → partes con sus colores (lo que después se dibuja). */
    public static function leer(string $descripcion): array
    {
        $txt = self::normalizar($descripcion);
        $d = ['cuerpo' => [], 'patron' => null, 'adornos' => [], 'mangas' => ['colores' => [], 'patron' => null, 'adorno' => null],
            'gorra' => ['colores' => [], 'patron' => null, 'adorno' => null], 'cuello' => null, 'punos' => null, 'brazales' => null,
            'visera' => null, 'pompon' => null];
        $segmentos = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $txt)), fn ($s) => $s !== ''));
        if (!$segmentos) {
            return $d;
        }

        // El primer segmento es el cuerpo: sus colores (antes de nombrar una parte) y su diseño.
        $primero = array_shift($segmentos);
        // "Azul y blanco a rayas horizontales": con dos colores, las rayas son el diseño del cuerpo y no un adorno.
        $partes = self::buscar($primero, self::PARTES);
        if (count(self::buscar($primero, self::COLORES)) > 1) {
            $partes = array_values(array_filter($partes, fn ($p) => $p[1] !== 'rayas'));
            if (!self::buscar($primero, self::PATRONES) && preg_match('/\brayas?\b|\blistas?\b/', $primero)) {
                $primero .= ' verticales';
            }
        }
        $corte = $partes ? $partes[0][0] : strlen($primero);
        $d['cuerpo'] = array_column(self::buscar(substr($primero, 0, $corte), self::COLORES), 1);
        $pat = self::buscar(substr($primero, 0, $corte), self::PATRONES);
        $d['patron'] = $pat ? $pat[0][1] : null;
        if ($partes) {
            array_unshift($segmentos, substr($primero, $corte));
        }
        if (!$d['cuerpo']) {
            $d['cuerpo'] = ['#ffffff'];
        }

        // Los demás: "cuello, puños y visera negro" (las partes sin color esperan al primer color que aparece),
        // "gorra a cascos rojo, blanco y azul" (un segmento con solo colores sigue al anterior) y "mangas azul con rayas rojas".
        $pendientes = $ultimas = [];
        foreach ($segmentos as $seg) {
            [$principal, $adorno] = array_pad(preg_split('/\bcon\b/', $seg, 2), 2, '');
            $items = self::buscar($principal, self::PARTES);
            $colores = array_column(self::buscar($principal, self::COLORES), 1);
            $patrones = self::buscar($principal, self::PATRONES);
            foreach ($items as $k => [$pos, $parte]) {
                $patron = null;
                foreach ($patrones as [$pp, $p]) {
                    if ($pp > $pos && (!isset($items[$k + 1]) || $pp < $items[$k + 1][0])) {
                        $patron = $p;
                    }
                }
                $pendientes[] = ['parte' => $parte, 'patron' => $patron, 'colores' => []];
            }
            if ($colores && $pendientes) {
                foreach ($pendientes as &$p) {
                    $p['colores'] = $colores;
                }
                unset($p);
                array_push($d['adornos'], ...self::aplicar($d, $pendientes));
                $ultimas = $pendientes;
                $pendientes = [];
            } elseif ($colores && $ultimas) {
                foreach ($ultimas as $u) {
                    self::sumarColores($d, $u['parte'], $colores);
                }
            }
            if (trim($adorno) !== '') {
                $color = array_column(self::buscar($adorno, self::COLORES), 1);
                $tipo = self::buscar($adorno, self::PARTES)[0][1] ?? 'figura';
                $pat = self::buscar($adorno, self::PATRONES)[0][1] ?? null;
                foreach ($ultimas ?: $pendientes as $u) {
                    if (in_array($u['parte'], ['mangas', 'gorra'], true)) {
                        $d[$u['parte']]['adorno'] = ['tipo' => $tipo, 'colores' => $color ?: ['#ffffff'], 'patron' => $pat];
                    }
                }
            }
        }
        // Partes sin color propio ("gorra a cascos", "mangas a rayas horizontales"): se arman con los colores del cuerpo.
        array_push($d['adornos'], ...self::aplicar($d, $pendientes));
        return $d;
    }

    /** SVG de la chaquetilla y la gorra; $alto en píxeles. */
    public static function svg(string $descripcion, int $alto = 56): string
    {
        $d = self::leer($descripcion);
        $id = 'q' . ++self::$n . substr(md5($descripcion), 0, 4);
        $base = $d['cuerpo'][0];
        $segundo = $d['cuerpo'][1] ?? ($d['mangas']['colores'][0] ?? null) ?? ($d['adornos'][0]['colores'][0] ?? null) ?? self::contraste($base);

        $cuerpo = 'M40 36 L80 36 L88 104 L32 104 Z';
        $mangas = 'M40 36 L22 44 L9 81 L21 85 L33 58 Z M80 36 L98 44 L111 81 L99 85 L87 58 Z';
        $o = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 110" height="' . $alto . '" width="' . round($alto * 120 / 110)
            . '" role="img"><title>' . htmlspecialchars($descripcion) . '</title><defs>'
            . '<clipPath id="' . $id . 'c"><path d="' . $cuerpo . '"/></clipPath>'
            . '<clipPath id="' . $id . 'm"><path d="' . $mangas . '"/></clipPath>'
            . '<clipPath id="' . $id . 'g"><path d="M42 27 A18 18 0 0 1 78 27 Z"/></clipPath></defs>';

        // Cuerpo
        $o .= '<g clip-path="url(#' . $id . 'c)">' . self::relleno($d['patron'], $d['cuerpo'] + [1 => $segundo], 30, 34, 60, 72);
        foreach ($d['adornos'] as $a) {
            $o .= self::adorno($a, $base);
        }
        $o .= '</g><path d="' . $cuerpo . '" fill="none" stroke="' . self::BORDE . '"/>';

        // Mangas, brazales y puños
        $m = $d['mangas'];
        $colM = $m['colores'] ?: [$base];
        if (count($colM) === 1 && $m['patron'] && !in_array($m['patron'], ['mitades'], true)) {
            $colM[] = $colM[0] === $base ? $segundo : $base;
        }
        $o .= '<g clip-path="url(#' . $id . 'm)">';
        if ($m['patron'] === 'mitades' && count($colM) > 1) {
            $o .= self::rect(0, 30, 60, 70, $colM[0]) . self::rect(60, 30, 60, 70, $colM[1]);
        } else {
            $o .= self::relleno($m['patron'] === 'cascos' ? 'verticales' : $m['patron'], $colM, 0, 30, 120, 70);
        }
        if ($m['adorno']) {
            $a = $m['adorno'];
            $o .= in_array($a['tipo'], ['rayas', 'franja'], true)
                ? self::rayas($a['patron'] === 'verticales' ? 'v' : 'h', [$a['colores'][0]], 0, 30, 120, 70, 12, 4)
                : self::puntos($a['tipo'], $a['colores'][0], [[20, 60], [100, 60], [16, 72], [104, 72]]);
        }
        if ($d['brazales']) {
            $o .= '<path d="M13 70 L27 75 L25 80 L11 75 Z M107 70 L93 75 L95 80 L109 75 Z" fill="' . $d['brazales'] . '"/>';
        }
        $o .= '</g><path d="' . $mangas . '" fill="none" stroke="' . self::BORDE . '"/>';
        $punos = $d['punos'] ?? $colM[0];
        $o .= '<path d="M9 81 L21 85 L19 91 L7 87 Z M111 81 L99 85 L101 91 L113 87 Z" fill="' . $punos . '" stroke="' . self::BORDE . '"/>';

        // Cuello
        $o .= '<path d="M50 36 L60 47 L70 36 Z" fill="' . ($d['cuello'] ?? self::sombra($base)) . '" stroke="' . self::BORDE . '"/>';

        // Gorra
        $g = $d['gorra'];
        $colG = $g['colores'] ?: ($g['patron'] ? [$base, $segundo] : [$base]);
        if (count($colG) === 1 && $g['patron']) {
            $colG[] = $colG[0] === $base ? $segundo : $base;
        }
        $o .= '<g clip-path="url(#' . $id . 'g)">';
        if ($g['patron'] === 'cascos' || $g['patron'] === 'verticales') {
            foreach ([[42, 51], [51, 60], [60, 69], [69, 78]] as $k => [$x1, $x2]) {
                $o .= self::rect($x1, 8, $x2 - $x1, 20, $colG[$k % count($colG)]);
            }
        } else {
            $o .= self::relleno($g['patron'], $colG, 40, 8, 40, 20);
        }
        if ($g['adorno']) {
            $o .= self::puntos($g['adorno']['tipo'], $g['adorno']['colores'][0], [[60, 19]]);
        }
        $o .= '</g><path d="M42 27 A18 18 0 0 1 78 27 Z" fill="none" stroke="' . self::BORDE . '"/>';
        $o .= '<path d="M42 27 L78 27 L80 31 L40 31 Z" fill="' . ($d['visera'] ?? $colG[0]) . '" stroke="' . self::BORDE . '"/>';
        if ($d['pompon']) {
            $o .= '<circle cx="60" cy="8" r="3.5" fill="' . $d['pompon'] . '" stroke="' . self::BORDE . '"/>';
        }
        return $o . '</svg>';
    }

    /** Reparte los colores de las partes ya leídas: mangas, gorra y detalles van a su lugar, lo demás son adornos del cuerpo. */
    private static function aplicar(array &$d, array $items): array
    {
        $adornos = [];
        foreach ($items as $it) {
            $p = $it['parte'];
            if ($p === 'mangas' || $p === 'gorra') {
                $d[$p]['colores'] = $it['colores'];
                $d[$p]['patron'] = $it['patron'] ?? ($p === 'mangas' && $d['mangas']['patron'] ? $d['mangas']['patron'] : null);
                if ($it['patron'] === null && in_array($p, ['mangas'], true) && count($it['colores']) > 1) {
                    $d[$p]['patron'] = 'horizontales';
                }
            } elseif (in_array($p, ['cuello', 'punos', 'brazales', 'visera', 'pompon'], true)) {
                $d[$p] = $it['colores'][0] ?? null;
            } elseif ($p === 'rayas' && $it['patron'] === null && !$it['colores']) {
                continue;
            } else {
                $adornos[] = $it + ['colores' => []];
            }
        }
        return array_map(fn ($a) => $a['colores'] ? $a : ['colores' => [self::contraste($d['cuerpo'][0])]] + $a, $adornos);
    }

    private static function sumarColores(array &$d, string $parte, array $colores): void
    {
        if ($parte === 'mangas' || $parte === 'gorra') {
            $d[$parte]['colores'] = array_merge($d[$parte]['colores'], $colores);
        } else {
            foreach ($d['adornos'] as &$a) {
                if ($a['parte'] === $parte) {
                    $a['colores'] = array_merge($a['colores'], $colores);
                }
            }
        }
    }

    /** Diseño de fondo de una zona: liso, rayas, mitades, cuarteles, cuadros, triángulos u oblicuo. */
    private static function relleno(?string $patron, array $c, float $x, float $y, float $w, float $h): string
    {
        $c = array_values(array_filter($c));
        $a = $c[0];
        $b = $c[1] ?? self::contraste($a);
        return match ($patron) {
            'verticales', 'cascos' => self::rayas('v', $c + [1 => $b], $x, $y, $w, $h, 8, 8),
            'horizontales' => self::rayas('h', $c + [1 => $b], $x, $y, $w, $h, 8, 8),
            'mitades' => self::rect($x, $y, $w / 2, $h, $a) . self::rect($x + $w / 2, $y, $w / 2, $h, $b),
            'mitades_h' => self::rect($x, $y, $w, $h / 2, $a) . self::rect($x, $y + $h / 2, $w, $h / 2, $b),
            'cuarteles' => self::rect($x, $y, $w / 2, $h / 2, $a) . self::rect($x + $w / 2, $y, $w / 2, $h / 2, $b)
                . self::rect($x, $y + $h / 2, $w / 2, $h / 2, $b) . self::rect($x + $w / 2, $y + $h / 2, $w / 2, $h / 2, $a),
            'cuadros' => self::cuadros($a, $b, $x, $y, $w, $h),
            'oblicuas' => self::rect($x, $y, $w, $h, $a) . '<path d="M' . ($x + $w) . " $y L" . ($x + $w) . ' ' . ($y + $h) . " L$x " . ($y + $h) . ' Z" fill="' . $b . '"/>',
            'triangulos' => self::rect($x, $y, $w, $h, $a) . '<path d="M' . $x . ' ' . ($y + $h) . ' L' . ($x + $w / 2) . " $y L" . ($x + $w) . ' ' . ($y + $h) . ' Z" fill="' . $b . '"/>',
            default => self::rect($x, $y, $w, $h, $a),
        };
    }

    /** Un adorno sobre el cuerpo (zona 30..90 × 36..104). */
    private static function adorno(array $a, string $base): string
    {
        $c = $a['colores'][0];
        $c2 = $a['colores'][1] ?? null;
        return match ($a['parte']) {
            'rayas' => self::rayas($a['patron'] === 'horizontales' ? 'h' : 'v', $c2 ? $a['colores'] : [$c], 30, 34, 60, 72, 12, 5),
            'franja' => $a['patron'] === 'cuadros' ? self::cuadros($c, $c2 ?? $base, 30, 62, 60, 14) : self::rect(30, 62, 60, 13, $c),
            'banda' => '<path d="M38 36 L48 36 L86 104 L76 104 Z" fill="' . $c . '"/>',
            'tiradores' => self::rect(46, 36, 6, 70, $c) . self::rect(68, 36, 6, 70, $c),
            'cruz' => self::rect(55, 36, 10, 70, $c) . self::rect(30, 56, 60, 10, $c),
            'v' => '<path d="M40 36 L60 72 L80 36" fill="none" stroke="' . $c . '" stroke-width="9"/>',
            'circulo' => '<circle cx="60" cy="68" r="12" fill="' . $c . '"/>',
            'rombos' => self::puntos('rombos', $c, [[46, 52], [60, 64], [74, 52], [46, 78], [74, 78], [60, 92]]),
            'cuadros' => self::cuadros($c, $c2 ?? $base, 30, 34, 60, 72),
            'canesu' => self::rect(30, 34, 60, 14, $c),
            'laterales' => self::rect(30, 34, 11, 72, $c) . self::rect(79, 34, 11, 72, $c),
            'hombros' => self::rect(30, 34, 16, 10, $c) . self::rect(74, 34, 16, 10, $c),
            'chevron' => '<path d="M36 86 L60 66 L84 86" fill="none" stroke="' . $c . '" stroke-width="8"/>',
            'triangulos' => '<path d="M30 104 L60 50 L90 104 Z" fill="' . $c . '"/>',
            'estrellas', 'lunares' => self::puntos($a['parte'], $c, [[46, 50], [74, 50], [60, 64], [46, 80], [74, 80], [60, 94]]),
            default => self::puntos('figura', $c, [[60, 68]]),
        };
    }

    private static function puntos(string $tipo, string $c, array $lugares): string
    {
        $o = '';
        foreach ($lugares as [$x, $y]) {
            $o .= match ($tipo) {
                'estrellas' => '<path d="' . self::estrella($x, $y, 5.5) . '" fill="' . $c . '"/>',
                'rombos' => "<path d=\"M$x " . ($y - 7) . ' L' . ($x + 6) . " $y L$x " . ($y + 7) . ' L' . ($x - 6) . " $y Z\" fill=\"$c\"/>",
                'lunares' => "<circle cx=\"$x\" cy=\"$y\" r=\"4\" fill=\"$c\"/>",
                default => "<circle cx=\"$x\" cy=\"$y\" r=\"6\" fill=\"none\" stroke=\"$c\" stroke-width=\"3\"/>",
            };
        }
        return $o;
    }

    private static function rayas(string $dir, array $c, float $x, float $y, float $w, float $h, float $paso, float $ancho): string
    {
        $o = '';
        $c = array_values($c);
        $largo = $dir === 'v' ? $w : $h;
        for ($i = 0, $k = 0; $i < $largo; $i += $paso, $k++) {
            $col = count($c) === 1 ? ($k % 2 ? null : $c[0]) : $c[$k % count($c)];
            if ($col === null && $ancho === $paso) {
                continue;
            }
            $col ??= $c[0];
            $o .= $dir === 'v' ? self::rect($x + $i, $y, $ancho, $h, $col) : self::rect($x, $y + $i, $w, $ancho, $col);
        }
        return $o;
    }

    private static function cuadros(string $a, string $b, float $x, float $y, float $w, float $h): string
    {
        $o = self::rect($x, $y, $w, $h, $a);
        for ($i = 0; $i * 7 < $w; $i++) {
            for ($j = 0; $j * 7 < $h; $j++) {
                if (($i + $j) % 2) {
                    $o .= self::rect($x + $i * 7, $y + $j * 7, 7, 7, $b);
                }
            }
        }
        return $o;
    }

    private static function rect(float $x, float $y, float $w, float $h, string $c): string
    {
        return "<rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" fill=\"$c\"/>";
    }

    private static function estrella(float $cx, float $cy, float $r): string
    {
        $p = [];
        for ($i = 0; $i < 10; $i++) {
            $rr = $i % 2 ? $r * 0.45 : $r;
            $ang = -M_PI / 2 + $i * M_PI / 5;
            $p[] = round($cx + $rr * cos($ang), 1) . ' ' . round($cy + $rr * sin($ang), 1);
        }
        return 'M' . implode(' L', $p) . ' Z';
    }

    /** Posiciones (en bytes) y valores de lo que aparece en el texto, en orden. */
    private static function buscar(string $txt, array $tabla): array
    {
        $res = [];
        $ocupado = [];
        foreach ($tabla as $patron => $valor) {
            if (preg_match_all('/(?<![a-z])(?:' . $patron . ')(?![a-z])/', $txt, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$s, $pos]) {
                    for ($i = $pos; $i < $pos + strlen($s); $i++) {
                        if (isset($ocupado[$i])) {
                            continue 2;
                        }
                    }
                    for ($i = $pos; $i < $pos + strlen($s); $i++) {
                        $ocupado[$i] = true;
                    }
                    $res[] = [$pos, $valor];
                }
            }
        }
        usort($res, fn ($a, $b) => $a[0] <=> $b[0]);
        return $res;
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '.' => ' ']);
        return preg_replace('/\s+/', ' ', $s);
    }

    private static function contraste(string $hex): string
    {
        return self::luz($hex) > 0.6 ? '#111111' : '#ffffff';
    }

    private static function sombra(string $hex): string
    {
        $v = array_map(fn ($c) => (int)round(hexdec($c) * 0.8), str_split(ltrim($hex, '#'), 2));
        return vsprintf('#%02x%02x%02x', $v);
    }

    private static function luz(string $hex): float
    {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    }
}
