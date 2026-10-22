<?php
declare(strict_types=1);

namespace GranChance\BR;

/**
 * Lo que el JCB escribe en portugués, pasado al español para la web: estado de la pista, pelaje, sexo, tipo de prueba,
 * nombre del premio, cuerpos al ganador y el puesto de los que no llegaron. En la base queda tal cual.
 */
final class Traduccion
{
    private const PISTA = ['leve' => 'firme', 'macia' => 'blanda', 'pesada' => 'pesada', 'encharcada' => 'anegada',
        'úmida' => 'húmeda', 'umida' => 'húmeda', 'seca' => 'seca'];
    private const PELAJE = ['castanh' => 'Castaño', 'alaz' => 'Alazán', 'tordilh' => 'Tordillo', 'pret' => 'Negro', 'tostad' => 'Tostado',
        'gate' => 'Gateado', 'baio' => 'Bayo', 'rosilh' => 'Rosillo', 'zain' => 'Zaino'];
    private const SEXO = ['m' => 'macho', 'macho' => 'macho', 'h' => 'hembra', 'f' => 'hembra', 'fêmea' => 'hembra', 'femea' => 'hembra',
        'c' => 'castrado', 'castrado' => 'castrado'];
    private const TIPO = ['Pesos Especiais' => 'Pesos especiales', 'Prova Especial' => 'Prueba especial', 'Intermediário' => 'Intermedia',
        'Prova Seletiva' => 'Prueba selectiva', 'Copa dos Leilões' => 'Copa de los remates', 'Índice Técnico' => 'Índice técnico'];
    /** Palabras de los nombres de los premios (el resto son nombres propios). */
    private const PREMIO = ['GRANDE PRÊMIO' => 'GRAN PREMIO', 'PRÊMIO' => 'PREMIO', 'CLÁSSICO' => 'CLÁSICO', 'PROVA' => 'PRUEBA',
        'ESPECIAIS' => 'ESPECIALES', 'SELETIVA' => 'SELECTIVA', 'INTERMEDIÁRIO' => 'INTERMEDIO', 'TAÇA' => 'COPA'];
    /** Cuerpos al ganador: las ventajas cortas y los que no se midieron. */
    private const DISTANCIA = ['focinho' => 'hocico', 'foc.' => 'hocico', 'mínima' => 'hocico', 'mín.' => 'hocico', 'cabeça' => 'cabeza',
        'cab.' => 'cabeza', 'pescoço' => 'pescuezo', 'pesc.' => 'pescuezo', 'paleta' => '1/2 cuerpo', 'pal.' => '1/2 cuerpo',
        'vários' => 'varios cuerpos', 'v.' => 'varios cuerpos', 'desclas' => 'descalificado', 'descl.' => 'descalificado'];

    /** "pesada variante" → "pesada, pasada a la arena". */
    public static function pista(?string $p): string
    {
        $p = mb_strtolower(trim((string)$p));
        if ($p === '') {
            return '';
        }
        $variante = str_contains($p, 'variante');
        $estado = trim(str_replace('variante', '', $p));
        $o = self::PISTA[$estado] ?? $estado;
        return $variante ? ($o !== '' ? "$o, " : '') . 'pasada a la arena' : $o;
    }

    /** "Castanha" → "Castaño". */
    public static function pelaje(?string $p): string
    {
        $p = (string)$p;
        foreach (self::PELAJE as $raiz => $es) {
            if (str_starts_with(mb_strtolower($p), $raiz)) {
                return $es;
            }
        }
        return $p;
    }

    public static function sexo(?string $s): string
    {
        $s = mb_strtolower(trim((string)$s));
        return self::SEXO[$s] ?? $s;
    }

    public static function tipo(?string $t): string
    {
        return self::TIPO[(string)$t] ?? (string)$t;
    }

    public static function premio(?string $p): string
    {
        return strtr((string)$p, self::PREMIO);
    }

    public static function distancia(?string $d): string
    {
        return self::DISTANCIA[mb_strtolower(trim((string)$d))] ?? (string)$d;
    }

    /** Puesto de los que no tienen uno: forfait, NCP (no completó el recorrido). */
    public static function puesto(?string $p): string
    {
        return ['forfait' => 'retirado', 'ncp' => 'no terminó'][mb_strtolower((string)$p)] ?? (string)$p;
    }
}
