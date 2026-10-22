<?php
declare(strict_types=1);

namespace GranChance\JP;

/**
 * Lo que la JRA publica en inglés, pasado al español para la web: superficie, estado de la pista, clima, sexo, nivel,
 * edad del lote, cuerpos y el puesto de los que no llegaron. En la base queda tal cual.
 */
final class Traduccion
{
    /** La escala de la JRA tiene 4 estados (良, 稍重, 重, 不良), los mismos en césped y en arena. */
    private const PISTA = ['firm' => 'normal', 'standard' => 'normal', 'normal' => 'normal', 'good' => 'algo pesada',
        'yielding' => 'pesada', 'muddy' => 'pesada', 'soft' => 'fangosa', 'sloppy' => 'fangosa'];
    private const CLIMA = ['fine' => 'despejado', 'cloudy' => 'nublado', 'rainy' => 'lluvia', 'drizzle' => 'llovizna',
        'light snow' => 'nevisca', 'snow' => 'nieve', 'snowy' => 'nieve'];
    private const SEXO = ['C' => 'potro', 'F' => 'potranca', 'H' => 'entero', 'M' => 'yegua', 'G' => 'castrado'];
    private const NIVEL = ['Newcomer' => 'Debutantes', 'Maiden' => 'Perdedores', '1 Win' => '1 victoria', '2 Win' => '2 victorias',
        '3 Win' => '3 victorias', 'Open' => 'Open', 'Allowance' => 'Condicional', 'Otras' => 'Otras'];
    private const EDAD = ['2a' => '2 años', '3a' => '3 años', '3a+' => '3 años y más', '4a+' => '4 años y más', '2a+' => '2 años y más'];
    private const CUERPOS = ['NS' => 'hocico', 'HD' => 'cabeza', 'NK' => 'pescuezo', 'DT' => 'lejos', 'DH' => 'empate'];
    private const PUESTO = ['FF' => 'no terminó', 'S' => 'no largó', 'ER' => 'excluido', 'DQ' => 'descalificado'];

    public static function superficie(?string $s): string
    {
        return Velocidades::superficie($s);
    }

    /** Estado de la pista en castellano: "Firm" → "normal", "Muddy" → "pesada". */
    public static function pista(?string $p): string
    {
        $p = strtolower(trim((string)$p));
        return self::PISTA[$p] ?? $p;
    }

    public static function clima(?string $c): string
    {
        $c = strtolower(trim((string)$c));
        return self::CLIMA[$c] ?? $c;
    }

    public static function sexo(?string $s): string
    {
        return self::SEXO[strtoupper(trim((string)$s))] ?? (string)$s;
    }

    /** "2 Win 4a+" → "2 victorias, 4 años y más"; "G1 3a+" → "G1, 3 años y más". */
    public static function nivel(?string $n): string
    {
        if (!preg_match('/^(.*) (\S+)$/', trim((string)$n), $m)) {
            return (string)$n;
        }
        return (self::NIVEL[$m[1]] ?? $m[1]) . (isset(self::EDAD[$m[2]]) ? ', ' . self::EDAD[$m[2]] : '');
    }

    /** Clase de la carrera, como la publica la JRA: "Maiden" → "Perdedores". */
    public static function clase(?string $c): string
    {
        return ['Newcomer' => 'Debutantes', 'Maiden' => 'Perdedores', 'Allowance' => 'Condicional', 'Open Class' => 'Open'][trim((string)$c)] ?? (string)$c;
    }

    /** "NK" → "pescuezo", "1 1/4" → "1 1/4 cuerpos". */
    public static function cuerpos(?string $c): string
    {
        $c = trim((string)$c);
        if ($c === '') {
            return '';
        }
        if (isset(self::CUERPOS[$c])) {
            return self::CUERPOS[$c];
        }
        return preg_match('#^[\d /]+$#', $c) ? $c . ($c === '1' || $c === '1/2' || $c === '3/4' ? ' cuerpo' : ' cuerpos') : $c;
    }

    public static function puesto(?string $p): string
    {
        $p = trim((string)$p);
        return self::PUESTO[$p] ?? $p;
    }
}
