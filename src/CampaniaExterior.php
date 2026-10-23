<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Campaña en otros países de los caballos de un programa: cada país tiene su base, y un caballo que viaja (de Argentina
 * a Uruguay, de Japón a Hong Kong...) figura en las dos con distinto id. Es el mismo si coinciden el nombre (sin el país
 * que va al final: "SNAP DECISION-ARG", "GIULIA(USA)", "CIENCIA NACIONAL (USA)"), el padre y la madre, y el año de
 * nacimiento difiere en uno como mucho (Brasil y el hemisferio norte lo cuentan distinto: LONESTAR es de 2014 en
 * Uruguay y de 2015 en Brasil). El nombre solo no alcanza: HANNAH MONTANA 2012 de Uruguay es hija de Smarty Jones, la
 * de Argentina de Glick.
 *
 * Se busca al armar el programa, por el nombre (indexado en todas las bases), así sirve también para el que debuta en
 * el país viniendo de afuera. Con las bases de octubre de 2026 hay unos 470 caballos en dos países (Uruguay y Brasil
 * 220, Uruguay y Argentina 82, Argentina y Brasil 51, Hong Kong y Japón 121). Es solo para leer: el índice y la
 * probabilidad siguen con la campaña del país, porque cada país tiene su propia escala de velocidades.
 */
final class CampaniaExterior
{
    public const PAISES = ['uy' => 'Uruguay', 'ar' => 'Argentina', 'br' => 'Brasil', 'hk' => 'Hong Kong', 'jp' => 'Japón'];
    /** Últimas carreras de cada país que se muestran. */
    public const CARRERAS = 6;
    /** Sufijos de país que van al final del nombre con guion ("SNAP DECISION-ARG"); entre paréntesis se saca cualquiera. */
    private const SUFIJOS = 'ARG|URU|BRZ|BRA|USA|CHI|PER|GB|IRE|FR|FRA|GER|AUS|NZ|JPN|SAF|CAN|ITY|ITA|SPA|ECU|VEN|PAN|COL|MEX';

    /** @var array<string, PDO|false> bases ya abiertas (false si no se pudo) */
    private array $pdo = [];

    /**
     * @param array<string, array{dsn: string, usuario: string, clave: string}> $bases país => conexión
     * @param string $local el país del programa (no se busca en su propia base)
     */
    public function __construct(private array $bases, private string $local)
    {
    }

    /** Las bases de los países que están en config.php (Uruguay en 'db', los demás en 'ar', 'br', 'hk', 'jp'). */
    public static function desdeConfig(array $config, string $local): self
    {
        $bases = ['uy' => $config['db']];
        foreach (['ar', 'br', 'hk', 'jp'] as $p) {
            if (isset($config[$p]['db'])) {
                $bases[$p] = $config[$p]['db'];
            }
        }
        return new self($bases, $local);
    }

    /** Nombre (o padre, o madre) para comparar entre países: sin el país del final, sin acentos, espacios ni signos. */
    public static function clave(?string $nombre): string
    {
        $s = strtoupper(self::sinAcentos(trim((string)$nombre)));
        $s = preg_replace('/\s*\([^)]*\)\s*,?$/', '', $s);
        $s = preg_replace('/\s*-\s*(' . self::SUFIJOS . ')$/', '', $s);
        return preg_replace('/[^A-Z0-9]/', '', $s);
    }

    /** El nombre sin el país del final, para buscarlo con LIKE 'NOMBRE%' (la base no distingue mayúsculas ni acentos). */
    private static function prefijo(string $nombre): string
    {
        $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($nombre));
        $s = preg_replace('/\s*-\s*(' . self::SUFIJOS . ')$/i', '', $s);
        return addcslashes(trim($s), '%_\\');
    }

    private static function sinAcentos(string $s): string
    {
        $t = class_exists(\Transliterator::class) ? \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC') : null;
        return $t ? $t->transliterate($s) : strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ã' => 'a', 'õ' => 'o',
            'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'ç' => 'c', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O',
            'Ú' => 'U', 'Ã' => 'A', 'Õ' => 'O', 'Â' => 'A', 'Ê' => 'E', 'Ô' => 'O', 'Ç' => 'C', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    private function db(string $pais): ?PDO
    {
        if (!array_key_exists($pais, $this->pdo)) {
            $c = $this->bases[$pais];
            try {
                $this->pdo[$pais] = new PDO($c['dsn'], $c['usuario'], $c['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (\PDOException) {
                // Sin la base de ese país (otro servidor, todavía no cargada): el programa sale igual, sin esa campaña.
                $this->pdo[$pais] = false;
            }
        }
        return $this->pdo[$pais] ?: null;
    }

    /**
     * Las carreras del caballo en los otros países antes de $fecha, de la más reciente a la más vieja.
     * Sin padre o madre no se busca (sin el pedigrí no se sabe si es el mismo).
     * @return list<array{pais: string, nombre_pais: string, caballo_id: int, nombre: string, total: int, carreras: list<array>}>
     */
    public function buscar(string $nombre, ?int $anioNac, ?string $padre, ?string $madre, string $fecha): array
    {
        $clave = self::clave($nombre);
        $cp = self::clave($padre);
        $cm = self::clave($madre);
        $pre = self::prefijo($nombre);
        if ($clave === '' || $cp === '' || $cm === '' || $pre === '') {
            return [];
        }
        $res = [];
        foreach (array_keys($this->bases) as $pais) {
            if ($pais === $this->local || !($db = $this->db($pais))) {
                continue;
            }
            $st = $db->prepare('SELECT id, nombre, anio_nac, padre, madre FROM caballo WHERE nombre LIKE ?');
            $st->execute([$pre . '%']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ca) {
                if (self::clave($ca['nombre']) !== $clave || self::clave($ca['padre']) !== $cp || self::clave($ca['madre']) !== $cm
                    || ($anioNac && $ca['anio_nac'] && abs($anioNac - (int)$ca['anio_nac']) > 1)) {
                    continue;
                }
                $carreras = $this->carreras($pais, $db, (int)$ca['id'], $fecha);
                if (!$carreras) {
                    continue;
                }
                // Brasil a veces tiene el mismo caballo dos veces ("ITAPERUNA", "ITAPERUNA (L)"): una sola campaña,
                // con el enlace a la ficha que tiene más carreras.
                $x = $res[$pais] ?? null;
                if (!$x || count($carreras) > $x['propias']) {
                    $x = ['caballo_id' => (int)$ca['id'], 'nombre' => $ca['nombre'], 'propias' => count($carreras)] + ($x ?? []);
                }
                foreach ($carreras as $c) {
                    $x['carreras'][sprintf('%s %02d %s', $c['fecha'], $c['numero'], $c['hipodromo'])] = $c;
                }
                $res[$pais] = $x;
            }
        }
        foreach ($res as $pais => &$x) {
            krsort($x['carreras']);
            $x = ['pais' => $pais, 'nombre_pais' => self::PAISES[$pais], 'caballo_id' => $x['caballo_id'], 'nombre' => $x['nombre'],
                'total' => count($x['carreras']), 'carreras' => array_values($x['carreras'])];
        }
        unset($x);
        $res = array_values($res);
        // Primero el país donde corrió más recientemente.
        usort($res, fn ($a, $b) => $b['carreras'][0]['fecha'] <=> $a['carreras'][0]['fecha']);
        return $res;
    }

    /** Lo que se muestra de cada carrera, igual en todos los países (cada base nombra distinto algunas columnas). */
    private function carreras(string $pais, PDO $db, int $caballo, string $fecha): array
    {
        $div = in_array($pais, ['uy', 'ar'], true) ? 'a.sport' : 'a.cuota';
        $cpos = $pais === 'jp' ? 'a.dist_anterior' : 'a.dist_acumulada';
        $kg = in_array($pais, ['hk', 'jp'], true) ? 'a.peso_asignado' : 'COALESCE(a.peso_real, a.peso_asignado)';
        $cat = match ($pais) {
            'ar' => 'COALESCE(c.grupo, c.categoria)',
            'br' => 'c.tipo_prova',
            'hk' => 'c.clase',
            'jp' => 'COALESCE(c.grado, c.clase)',
            default => 'NULL',
        };
        $st = $db->prepare(
            "SELECT r.fecha, h.nombre AS hipodromo, c.numero, c.premio, $cat AS categoria, c.distancia, c.superficie, c.pista,
                    c.tiempo_ganador, a.puesto, a.puesto_texto, $cpos AS cuerpos, $kg AS kilos, $div AS dividendo, j.nombre AS jockey,
                    (SELECT COUNT(*) FROM actuacion x WHERE x.carrera_id = a.carrera_id) AS corredores,
                    (SELECT g.nombre FROM actuacion x JOIN caballo g ON g.id = x.caballo_id
                     WHERE x.carrera_id = a.carrera_id AND x.puesto = 1 ORDER BY g.nombre LIMIT 1) AS ganador
             FROM actuacion a
             JOIN carrera c ON c.id = a.carrera_id
             JOIN reunion r ON r.id = c.reunion_id
             JOIN hipodromo h ON h.id = r.hipodromo_id
             LEFT JOIN persona j ON j.id = a.jockey_id
             WHERE a.caballo_id = ? AND r.fecha < ?
             ORDER BY r.fecha DESC, c.numero DESC"
        );
        $st->execute([$caballo, $fecha]);
        $res = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $f['superficie'] = self::superficie($f['superficie']);
            $res[] = $f;
        }
        return $res;
    }

    private static function superficie(?string $s): ?string
    {
        return match (mb_strtolower((string)$s)) {
            'turf', 'césped', 'cesped', 'grama' => 'césped',
            'dirt', 'arena', 'areia' => 'arena',
            'sintética', 'sintetica', 'awt' => 'sintética',
            'jump' => 'obstáculos',
            '' => null,
            default => $s,
        };
    }

    /** "Argentina (12)": dónde más corrió, para la nota del programa. */
    public static function resumen(array $exterior): string
    {
        return implode(', ', array_map(fn ($x) => $x['nombre_pais'] . ' (' . $x['total'] . ')', $exterior));
    }

    /**
     * La campaña de afuera debajo de los antecedentes: una tabla por país, con las últimas carreras y el enlace a la
     * ficha del caballo en ese país.
     */
    public static function html(array $exterior, \Closure $e): string
    {
        $o = '';
        foreach ($exterior as $x) {
            $ganadas = count(array_filter($x['carreras'], fn ($c) => (int)$c['puesto'] === 1));
            $placé = count(array_filter($x['carreras'], fn ($c) => $c['puesto'] !== null && (int)$c['puesto'] >= 1 && (int)$c['puesto'] <= 3));
            $o .= '<p class="exterior">Campaña en ' . $e($x['nombre_pais']) . ' (' . Fichas::enlazar('caballo', $x['caballo_id'], $x['nombre'], $x['pais'] === 'uy' ? null : $x['pais'])
                . '): ' . $x['total'] . ' carrera' . ($x['total'] === 1 ? '' : 's') . ', ' . $ganadas . ' ganada' . ($ganadas === 1 ? '' : 's') . ', ' . $placé . ' entre los 3 primeros'
                . ($x['total'] > self::CARRERAS ? '; las últimas ' . self::CARRERAS : '') . '.</p>';
            $o .= '<table class="ant exterior"><thead><tr><th>Fecha</th><th>Hipódromo</th><th>P.</th><th>Ganador</th><th>Cpos.</th><th>Dist.</th><th>Sup.</th>'
                . '<th>Pista</th><th>T. ganador</th><th>Carrera</th><th>Jockey</th><th>Kg</th><th>Div.</th></tr></thead><tbody>';
            foreach (array_slice($x['carreras'], 0, self::CARRERAS) as $c) {
                // La categoría, si el nombre del premio no la trae ya ("Gran Premio Jockey Club (G1)").
                $cat = $c['categoria'] && mb_stripos((string)$c['premio'], $c['categoria']) === false ? $c['categoria'] . ' · ' : '';
                $carrera = trim($cat . (string)$c['premio'], ' ·');
                $o .= '<tr><td>' . date('d/m/y', strtotime($c['fecha'])) . '</td><td>' . $e($c['hipodromo']) . '</td><td>' . $e($c['puesto'] ?? $c['puesto_texto']) . '/' . $c['corredores']
                    . '</td><td>' . $e($c['ganador']) . '</td><td>' . $e($c['cuerpos']) . '</td><td>' . $e($c['distancia']) . '</td><td>' . $e(mb_substr((string)$c['superficie'], 0, 1))
                    . '</td><td>' . $e($c['pista']) . '</td><td>' . $e($c['tiempo_ganador']) . '</td><td class="cat">' . $e($carrera) . '</td><td>' . $e($c['jockey'])
                    . '</td><td>' . ($c['kilos'] !== null ? number_format((float)$c['kilos'], fmod((float)$c['kilos'], 1) == 0 ? 0 : 1, ',', '.') : '')
                    . '</td><td>' . ($c['dividendo'] !== null && (float)$c['dividendo'] > 0 ? number_format((float)$c['dividendo'], 2, ',', '.') : '') . '</td></tr>';
            }
            $o .= '</tbody></table>';
        }
        return $o;
    }
}
