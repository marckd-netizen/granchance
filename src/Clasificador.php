<?php
declare(strict_types=1);

namespace GranChance;

/**
 * Categoría de una carrera, para comparar velocidades entre carreras parecidas.
 *
 * - nivel: sale del premio y la condición (G1, G2, G3, Listado, Clásico, Handicap, Perdedores, Ganadores de 1..4+, ...).
 * - edad: sale de las edades del lote (2a, 3a, 3a+ mixto, 4a+), así vale igual para resultados y programas.
 * - sexo: "hembras" si todo el lote son hembras, si no "mixto".
 */
final class Clasificador
{
    public const NIVELES = ['G1', 'G2', 'G3', 'Listado', 'Clásico', 'Especial', 'Handicap', 'Perdedores',
        'Ganadores de 1', 'Ganadores de 2', 'Ganadores de 3', 'Ganadores de 4+', 'Preferencial', 'Condicional', 'Cuarto de milla', 'Otras'];

    private const NUMEROS = ['una' => 1, 'un' => 1, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6];

    public static function nivel(?string $premio, ?string $condicion): string
    {
        $p = mb_strtoupper(trim((string)$premio));
        $c = mb_strtolower(trim((string)$condicion));
        if (str_contains($p, 'CUARTO DE MILLA') || str_contains($c, 'cuarto de milla')) {
            return 'Cuarto de milla';
        }
        if (preg_match('/\((?:URU\s*)?G\s*(1|2|3|I{1,3})\)/u', $p, $m)) {
            return 'G' . (['I' => 1, 'II' => 2, 'III' => 3][$m[1]] ?? $m[1]);
        }
        if (preg_match('/\(L\)|\bLISTED\b/u', $p)) {
            return 'Listado';
        }
        if (preg_match('/h[aá]ndicap/u', $c) || preg_match('/^H[AÁ]NDICAP\b/u', $p)) {
            return 'Handicap';
        }
        if (preg_match('/^(CL[AÁ]SICO|GRAN PREMIO)\b/u', $p) || str_contains($c, 'peso por edad')) {
            return 'Clásico';
        }
        if (preg_match('/^ESPECIAL\b/u', $p)) {
            return 'Especial';
        }
        if (preg_match('/perdedor/u', $c)) {
            return 'Perdedores';
        }
        $n = self::ganadasMinimas($c);
        if ($n !== null) {
            return $n >= 4 ? 'Ganadores de 4+' : "Ganadores de $n";
        }
        if (str_starts_with($p, 'PREFERENCIAL')) {
            return 'Preferencial';
        }
        if (str_starts_with($p, 'CONDICIONAL')) {
            return 'Condicional';
        }
        return 'Otras';
    }

    /**
     * Menor cantidad de carreras ganadas que pide la condición: "ganadores de una y dos carreras" → 1,
     * "gdres. de 2 cs" → 2, "hasta una carrera ganada" → 1. Null si la condición no habla de carreras ganadas.
     */
    public static function ganadasMinimas(string $condicion): ?int
    {
        $num = '(\d|una|un|uno|dos|tres|cuatro|cinco|seis)';
        $min = null;
        $patrones = [
            "/\\bg(?:an(?:ador(?:es|as|a)?)?|dres?|dras?|dr|dor|dora)?\\.?\\s+(?:de\\s+)?(?:hasta\\s+)?$num\\b/u",
            "/hasta\\s+$num\\s+carreras?\\s+ganadas?/u",
            "/ganador(?:es|as|a)?\\s+(?:en|de)\\s+(?:el\\s+)?(?:hnm|hlp|sint)/u",
        ];
        foreach ($patrones as $i => $re) {
            if (!preg_match_all($re, $condicion, $mm)) {
                continue;
            }
            foreach ($mm[1] ?? [] as $v) {
                $v = self::NUMEROS[$v] ?? (int)$v;
                if ($v > 0) {
                    $min = $min === null ? $v : min($min, $v);
                }
            }
            if ($i === 2 && $min === null) {
                $min = 1;    // "ganador en HNM" sin número: al menos una ganada
            }
        }
        return $min;
    }

    /** @param int[] $edades edades del lote */
    public static function edad(array $edades): string
    {
        $edades = array_filter($edades, fn ($e) => $e > 0);
        if (!$edades) {
            return '?';
        }
        [$min, $max] = [min($edades), max($edades)];
        return match (true) {
            $max <= 2 => '2a',
            $max === 3 => '3a',
            $min <= 3 => '3a+',
            default => '4a+',
        };
    }

    /** @param string[] $sexos 'm' / 'h' del lote */
    public static function sexo(array $sexos): string
    {
        $sexos = array_filter($sexos);
        return $sexos && !array_diff($sexos, ['h']) ? 'hembras' : 'mixto';
    }

    /** Edad hípica: en Uruguay los caballos cumplen años el 1° de julio. */
    public static function edadEn(int $anioNac, string $fecha): int
    {
        [$a, $m] = [(int)substr($fecha, 0, 4), (int)substr($fecha, 5, 2)];
        return $a - $anioNac - ($m < 7 ? 1 : 0);
    }

    /** Año de nacimiento a partir de la edad que figura en el programa. */
    public static function anioNac(int $edad, string $fecha): int
    {
        [$a, $m] = [(int)substr($fecha, 0, 4), (int)substr($fecha, 5, 2)];
        return $a - $edad - ($m < 7 ? 1 : 0);
    }

    /** Tramo de distancia para agrupar los ajustes de pista y viento. */
    public static function tramo(int $distancia): string
    {
        return $distancia <= 1200 ? 'corta' : ($distancia <= 1600 ? 'media' : 'larga');
    }

    /** Estado de pista normalizado: "arena - seca" → "seca". */
    public static function pista(?string $pista): string
    {
        $p = mb_strtolower(trim((string)$pista));
        foreach (['seca', 'húmeda', 'humeda', 'pesada', 'fangosa', 'barrosa'] as $e) {
            if (str_contains($p, $e)) {
                return $e === 'humeda' ? 'húmeda' : $e;
            }
        }
        return $p === '' ? '?' : $p;
    }

    /** Superficie: los hipódromos que no la informan corren en arena; en Maroñas sin dato queda '?'. */
    public static function superficie(?string $superficie, int $hipodromo): string
    {
        $s = mb_strtolower(trim((string)$superficie));
        if ($s === '') {
            return $hipodromo === 1 ? '?' : 'arena';
        }
        return str_starts_with($s, 'c') ? 'césped' : 'arena';
    }
}
