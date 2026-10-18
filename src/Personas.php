<?php
declare(strict_types=1);

namespace GranChance;

/** Claves para comparar nombres de caballos, jockeys y cuidadores que vienen escritos distinto en programas y resultados. */
final class Personas
{
    /** Minúsculas, sin tildes, puntos ni sufijos entre paréntesis ("Ruben A. Marrero (CP)" → "ruben a marrero"). */
    public static function clave(?string $s): string
    {
        $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', (string)$s);
        $s = strtr(mb_strtolower(trim($s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '.' => '', "'" => '']);
        return preg_replace('/\s+/', ' ', $s);
    }

    /** "Deividi Gaier" y "D. Gaier" son el mismo jockey: inicial del nombre + último apellido. */
    public static function jockey(?string $s): string
    {
        $p = explode(' ', self::clave($s));
        return mb_substr($p[0], 0, 1) . ' ' . end($p);
    }

    public static function cuidador(?string $s): string
    {
        return self::clave($s);
    }
}
