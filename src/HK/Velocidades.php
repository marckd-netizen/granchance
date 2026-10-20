<?php
declare(strict_types=1);

namespace GranChance\HK;

use GranChance\Clasificador;
use PDO;

/**
 * Velocidades normales (par) de Hong Kong, con el mismo método que las de Uruguay (GranChance\Velocidades):
 *
 * 1. Velocidad de cada caballo = distancia / su propio tiempo (el HKJC publica el tiempo de cada uno).
 * 2. Par = promedio de los 4 primeros en pista GOOD, por hipódromo, distancia, superficie y clase (Class 1 a 5, Group,
 *    Griffin...). Con menos de 8 carreras se usa el par de todas las clases. En HK no hay edad ni sexo del lote que
 *    separen (casi todos son castrados de 3 a 9 años), así que la categoría es solo la clase.
 * 3. Ajuste de pista: cuánto se apartan del par los 4 primeros con cada going (GOOD TO FIRM, YIELDING, WET SLOW...).
 *    El HKJC no publica el viento; en su lugar se mide el riel (A, B, C+3...), que cambia los tiempos casi tanto como
 *    el going: un segundo ajuste por hipódromo, superficie, tramo y riel, sobre la velocidad ya corregida por going.
 * 4. Equivalencia entre hipódromos: Sha Tin = 0 y Happy Valley medido con los caballos que pasan de uno a otro en 90 días.
 *
 * Se calcula en memoria cada vez (son ~10.000 carreras, tarda unos segundos): no hay tablas de velocidades en la base HK.
 */
final class Velocidades
{
    public const MIN_CARRERAS = 8;
    public const PISTA_BASE = 'GOOD';
    private const VEL_MIN = 40.0, VEL_MAX = 80.0;
    private const MIN_PARES = 100;

    /** @var array<string, array{carreras:int, velocidad:float}> */
    private array $par = [];
    /** @var array<string, array{carreras:int, ajuste:float}> */
    private array $ajustes = [];
    /** @var array<string, array{carreras:int, ajuste:float}> */
    private array $rieles = [];
    /** @var array<int, float> */
    private array $equivalencias = [1 => 0.0];
    /** @var array<int, array> carrera => datos y clase (nivel) */
    private array $carreras = [];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function kmh(int $distancia, ?float $segundos): ?float
    {
        if ($distancia <= 0 || !$segundos || $segundos <= 0) {
            return null;
        }
        $v = $distancia / $segundos * 3.6;
        return $v >= self::VEL_MIN && $v <= self::VEL_MAX ? $v : null;
    }

    /** Clase de la carrera para el par: "Class 4 (Restricted)" → "Class 4", "Hong Kong Group One" → "G1". */
    public static function nivel(?string $clase): string
    {
        $c = trim((string)$clase);
        if (preg_match('/Group (One|Two|Three)/i', $c, $m)) {
            return 'G' . ['one' => 1, 'two' => 2, 'three' => 3][strtolower($m[1])];
        }
        if (preg_match('/^Class (\d)/i', $c, $m)) {
            return 'Class ' . $m[1];
        }
        if (stripos($c, 'Griffin') !== false) {
            return 'Griffin';
        }
        if (stripos($c, '4 Year') !== false) {
            return '4 Year Olds';
        }
        return 'Otras';
    }

    /** "césped" / "sintética" tal cual la base; cualquier cosa que no empiece con c es la sintética (all weather). */
    public static function superficie(?string $s): string
    {
        return str_starts_with(mb_strtolower(trim((string)$s)), 'c') ? 'césped' : 'sintética';
    }

    public static function pista(?string $p): string
    {
        $p = strtoupper(trim((string)$p));
        return $p === '' ? '?' : $p;
    }

    /** Riel sin el desplazamiento: "C+3" → "C+3" (se mide cada uno por separado), vacío en la sintética. */
    public static function riel(?string $r): string
    {
        return trim((string)$r) ?: '-';
    }

    public function recalcular(): array
    {
        $this->carreras = $this->leerCarreras();
        $grupos = [];
        foreach ($this->carreras as $c) {
            if ($c['pista'] !== self::PISTA_BASE || !$c['velocidades']) {
                continue;
            }
            foreach ([$c['nivel'], '*'] as $n) {
                $grupos[self::clavePar($c['hip'], $c['distancia'], $c['superficie'], $n)][] = $c['velocidades'];
            }
        }
        $this->par = [];
        foreach ($grupos as $k => $listas) {
            $todas = array_merge(...$listas);
            $this->par[$k] = ['carreras' => count($listas), 'velocidad' => array_sum($todas) / count($todas)];
        }
        // Going: desvío de los 4 primeros respecto del par de su clase.
        $desvios = [];
        foreach ($this->carreras as $c) {
            $par = $c['velocidades'] && $c['pista'] !== self::PISTA_BASE ? $this->par($c['hip'], $c['distancia'], $c['superficie'], $c['nivel']) : null;
            if ($par === null) {
                continue;
            }
            $d = array_sum($c['velocidades']) / count($c['velocidades']) - $par['velocidad'];
            $tramo = Clasificador::tramo($c['distancia']);
            foreach ([[$c['hip'], $c['superficie'], $tramo], [$c['hip'], $c['superficie'], '*'], [0, $c['superficie'], '*']] as [$h, $s, $t]) {
                $desvios["$h|$s|$t|{$c['pista']}"][] = $d;
            }
        }
        $this->ajustes = array_map(fn ($ds) => ['carreras' => count($ds), 'ajuste' => array_sum($ds) / count($ds)], $desvios);
        // Riel: lo que queda después del going.
        $desvios = [];
        foreach ($this->carreras as $c) {
            $par = $c['velocidades'] ? $this->par($c['hip'], $c['distancia'], $c['superficie'], $c['nivel']) : null;
            $aj = $par ? $this->ajustePista($c['hip'], $c['superficie'], $c['distancia'], $c['pista']) : null;
            if ($aj === null) {
                continue;
            }
            $d = array_sum($c['velocidades']) / count($c['velocidades']) - $aj - $par['velocidad'];
            foreach ([Clasificador::tramo($c['distancia']), '*'] as $t) {
                $desvios["{$c['hip']}|{$c['superficie']}|$t|{$c['riel']}"][] = $d;
            }
        }
        $this->rieles = array_map(fn ($ds) => ['carreras' => count($ds), 'ajuste' => array_sum($ds) / count($ds)], $desvios);
        $this->calcularEquivalencias();
        return ['carreras' => count($this->carreras), 'pares' => count($this->par), 'ajustes' => count($this->ajustes),
            'rieles' => count($this->rieles), 'equivalencia_hv' => $this->equivalencias[2] ?? null];
    }

    /** Carrera ya leída (nivel, pista, riel...), o null. */
    public function carrera(int $id): ?array
    {
        return $this->carreras[$id] ?? null;
    }

    /** @return array{carreras:int, velocidad:float, categoria:string}|null */
    public function par(int $hip, int $distancia, string $superficie, string $nivel): ?array
    {
        foreach ([$nivel, '*'] as $i => $n) {
            $p = $this->par[self::clavePar($hip, $distancia, $superficie, $n)] ?? null;
            if ($p !== null && ($p['carreras'] >= self::MIN_CARRERAS || ($i === 1 && $p['carreras'] >= 3))) {
                return $p + ['categoria' => $n === '*' ? 'todas las clases' : $n];
            }
        }
        return null;
    }

    public function parGeneral(int $hip, int $distancia, string $superficie): ?array
    {
        return $this->par($hip, $distancia, $superficie, '*');
    }

    /** km/h que corrió de más (+) o de menos (−) ese going respecto del par; null si no hay con qué medirlo. */
    public function ajustePista(int $hip, string $superficie, int $distancia, string $pista): ?float
    {
        if ($pista === self::PISTA_BASE) {
            return 0.0;
        }
        foreach ([[$hip, $superficie, Clasificador::tramo($distancia)], [$hip, $superficie, '*'], [0, $superficie, '*']] as [$h, $s, $t]) {
            $a = $this->ajustes["$h|$s|$t|$pista"] ?? null;
            if ($a !== null && $a['carreras'] >= self::MIN_CARRERAS) {
                return $a['ajuste'];
            }
        }
        return null;
    }

    public function ajusteRiel(int $hip, string $superficie, int $distancia, string $riel): float
    {
        foreach ([Clasificador::tramo($distancia), '*'] as $t) {
            $a = $this->rieles["$hip|$superficie|$t|$riel"] ?? null;
            if ($a !== null && $a['carreras'] >= self::MIN_CARRERAS) {
                return $a['ajuste'];
            }
        }
        return 0.0;
    }

    /** Going + riel; null si el going no se puede medir. */
    public function ajuste(int $hip, string $superficie, int $distancia, string $pista, string $riel): ?float
    {
        $p = $this->ajustePista($hip, $superficie, $distancia, $pista);
        return $p === null ? null : $p + $this->ajusteRiel($hip, $superficie, $distancia, $riel);
    }

    public function equivalencia(int $hip): float
    {
        return $this->equivalencias[$hip] ?? 0.0;
    }

    /** Índice contra el par general de su hipódromo, sin pasar al otro. */
    public function indiceLocal(int $hip, int $distancia, string $superficie, string $pista, string $riel, ?float $segundos): ?float
    {
        $v = self::kmh($distancia, $segundos);
        $aj = $v !== null ? $this->ajuste($hip, $superficie, $distancia, $pista, $riel) : null;
        $gen = $aj !== null ? $this->parGeneral($hip, $distancia, $superficie) : null;
        return $gen ? $v - $aj - $gen['velocidad'] : null;
    }

    /** Happy Valley contra Sha Tin, con los caballos que corrieron en los dos con 90 días o menos de diferencia. */
    private function calcularEquivalencias(): void
    {
        $st = $this->db->query(
            'SELECT a.caballo_id, a.carrera_id, a.tiempo_seg FROM actuacion a JOIN carrera c ON c.id = a.carrera_id
             JOIN reunion r ON r.id = c.reunion_id WHERE a.tiempo_seg IS NOT NULL ORDER BY a.caballo_id, r.fecha'
        );
        $pares = [];
        $mismo = [0.0, 0];
        $ant = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $c = $this->carreras[(int)$f['carrera_id']] ?? null;
            if ($c === null) {
                continue;
            }
            $idx = $this->indiceLocal($c['hip'], $c['distancia'], $c['superficie'], $c['pista'], $c['riel'], (float)$f['tiempo_seg']);
            $t = strtotime($c['fecha']);
            if ($idx !== null && $ant && $ant['caballo'] === $f['caballo_id'] && $t - $ant['t'] <= 90 * 86400) {
                if ($ant['hip'] === $c['hip']) {
                    $mismo = [$mismo[0] + $idx - $ant['idx'], $mismo[1] + 1];
                } else {
                    $k = $ant['hip'] . '|' . $c['hip'];
                    $pares[$k] = [($pares[$k][0] ?? 0) + $idx - $ant['idx'], ($pares[$k][1] ?? 0) + 1];
                }
            }
            if ($idx !== null) {
                $ant = ['caballo' => $f['caballo_id'], 'hip' => $c['hip'], 't' => $t, 'idx' => $idx];
            }
        }
        $base = $mismo[1] ? $mismo[0] / $mismo[1] : 0.0;
        // Con dos hipódromos la solución de mínimos cuadrados es el promedio ponderado de los dos sentidos.
        [$s12, $n12] = $pares['1|2'] ?? [0.0, 0];
        [$s21, $n21] = $pares['2|1'] ?? [0.0, 0];
        if ($n12 + $n21 >= self::MIN_PARES) {
            $d12 = $n12 ? $s12 / $n12 - $base : 0.0;
            $d21 = $n21 ? $s21 / $n21 - $base : 0.0;
            $this->equivalencias[2] = ($n12 * $d12 - $n21 * $d21) / ($n12 + $n21);
        }
        $this->equivalencias[1] = 0.0;
    }

    private static function clavePar(int $hip, int $dist, string $sup, string $n): string
    {
        return "$hip|$dist|$sup|$n";
    }

    private function leerCarreras(): array
    {
        $carreras = [];
        $st = $this->db->query(
            'SELECT c.id, r.hipodromo_id, r.fecha, c.distancia, c.superficie, c.pista, c.recorrido, c.clase
             FROM carrera c JOIN reunion r ON r.id = c.reunion_id WHERE c.distancia > 0'
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $carreras[(int)$r['id']] = [
                'hip' => (int)$r['hipodromo_id'], 'fecha' => $r['fecha'], 'distancia' => (int)$r['distancia'],
                'superficie' => self::superficie($r['superficie']), 'pista' => self::pista($r['pista']),
                'riel' => self::riel($r['recorrido']), 'nivel' => self::nivel($r['clase']), 'velocidades' => [],
            ];
        }
        $st = $this->db->query('SELECT carrera_id, tiempo_seg FROM actuacion WHERE puesto BETWEEN 1 AND 4 AND tiempo_seg IS NOT NULL');
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $seg]) {
            if (isset($carreras[(int)$id]) && ($v = self::kmh($carreras[(int)$id]['distancia'], (float)$seg)) !== null) {
                $carreras[(int)$id]['velocidades'][] = $v;
            }
        }
        return $carreras;
    }
}
