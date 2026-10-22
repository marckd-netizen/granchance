<?php
declare(strict_types=1);

namespace GranChance\AR;

use GranChance\Clasificador;
use PDO;

/**
 * Velocidades normales (par) de Argentina, con el mismo método que las de Uruguay, Hong Kong y Brasil:
 *
 * 1. Velocidad de cada caballo = distancia / su tiempo. El Stud Book publica solo el del ganador; el de los demás se
 *    estima con los cuerpos al ganador (AR\Repositorio, actuacion.tiempo_seg).
 * 2. Par = promedio de los 4 primeros en pista normal, por hipódromo, distancia, superficie y nivel (Grupo I a III,
 *    Listado, Handicap, clásicos y especiales, y en las condicionales las carreras ganadas que permite la condición).
 *    Con menos de 8 carreras se usa el par de todos los niveles.
 * 3. Ajuste de pista: cuánto se apartan del par los 4 primeros con cada estado (húmeda, pesada, fangosa). Sobre eso, un
 *    segundo ajuste por el recorrido (codo, codo nuevo, recta, diagonal), en lugar del riel de Hong Kong.
 * 4. Equivalencia entre hipódromos: Palermo = 0 y los demás medidos con los caballos que pasan de uno a otro en 90 días.
 *
 * Se calcula en memoria cada vez (son ~60.000 carreras): no hay tablas de velocidades en la base AR.
 */
final class Velocidades
{
    public const MIN_CARRERAS = 8;
    public const PISTA_BASE = 'normal';
    private const VEL_MIN = 40.0, VEL_MAX = 80.0;
    private const MIN_PARES = 100;

    /** @var array<string, array{carreras:int, velocidad:float}> */
    private array $par = [];
    /** @var array<string, array{carreras:int, ajuste:float}> */
    private array $ajustes = [];
    /** @var array<string, array{carreras:int, ajuste:float}> */
    private array $variantes = [];
    /** @var array<int, float> */
    private array $equivalencias = [];
    /** Palermo: la escala de los índices */
    private int $base;
    /** @var array<int, array> carrera => datos y clase (nivel) */
    private array $carreras = [];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->base = (int)$this->db->query("SELECT id FROM hipodromo WHERE codigo = 'palermo'")->fetchColumn();
        $this->equivalencias = [$this->base => 0.0];
    }

    public static function kmh(int $distancia, ?float $segundos): ?float
    {
        if ($distancia <= 0 || !$segundos || $segundos <= 0) {
            return null;
        }
        $v = $distancia / $segundos * 3.6;
        return $v >= self::VEL_MIN && $v <= self::VEL_MAX ? $v : null;
    }

    /**
     * Nivel de la carrera para el par: "Clasico Grupo I" → "G1", "Clasico Listado" → "L", clásicos, especiales e
     * internacionales → "Especial", handicaps → "Handicap"; las condicionales (y promociones, de jockeys...) por las
     * carreras ganadas que permite la condición: "que no hayan ganado" → "Cond 0", "ganadores de 1 carrera" → "Cond 1",
     * "de 2/3 carreras" → "Cond 2", "3 y más" → "Cond 3"... hasta "Cond 4". Sin condición, "Otras".
     */
    public static function nivel(?string $categoria, ?string $condicion, ?string $grupo = null): string
    {
        $t = trim((string)$categoria);
        if (preg_match('/Grupo (I{1,3})\b/', $t, $m)) {
            return 'G' . strlen($m[1]);
        }
        if (in_array($grupo, ['G1', 'G2', 'G3'], true)) {
            return $grupo;
        }
        if (str_contains($t, 'Listado') || $grupo === 'L') {
            return 'L';
        }
        if (str_starts_with($t, 'Handic')) {
            return 'Handicap';
        }
        if (preg_match('/^(Clasico|Cl\.|Especial|Tabla)/u', $t)) {
            return 'Especial';
        }
        $c = mb_strtolower((string)$condicion);
        if ($c === '') {
            return 'Otras';
        }
        if (preg_match('/no hay[ae]n? ganado/u', $c)) {
            return 'Cond 0';
        }
        if (preg_match('/ganador(?:a|es|as)? de (una|\d+)/u', $c, $m) || preg_match('/ganador(?:a|es|as)? (una)\b/u', $c, $m)) {
            return 'Cond ' . min(4, $m[1] === 'una' ? 1 : (int)$m[1]);
        }
        return 'Otras';
    }

    /** "césped" o "arena" tal cual la base; sin dato, arena. */
    public static function superficie(?string $s): string
    {
        return mb_strtolower(trim((string)$s)) === 'césped' ? 'césped' : 'arena';
    }

    /** Estado en minúsculas: normal, humeda, pesada, fangosa. */
    public static function pista(?string $p): string
    {
        $p = mb_strtolower(trim((string)$p));
        return $p === '' ? '?' : $p;
    }

    /** El recorrido (codo, codo nuevo, recta, diagonal) hace las veces del riel de Hong Kong y del "variante" de Brasil. */
    public static function variante(?string $recorrido): string
    {
        $r = mb_strtolower(trim((string)$recorrido));
        return $r === '' ? '-' : $r;
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
        // Estado de pista: desvío de los 4 primeros respecto del par de su nivel.
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
        // Variante: lo que queda después del estado de pista.
        $desvios = [];
        foreach ($this->carreras as $c) {
            $par = $c['velocidades'] ? $this->par($c['hip'], $c['distancia'], $c['superficie'], $c['nivel']) : null;
            $aj = $par ? $this->ajustePista($c['hip'], $c['superficie'], $c['distancia'], $c['pista']) : null;
            if ($aj === null) {
                continue;
            }
            $d = array_sum($c['velocidades']) / count($c['velocidades']) - $aj - $par['velocidad'];
            foreach ([Clasificador::tramo($c['distancia']), '*'] as $t) {
                $desvios["{$c['hip']}|{$c['superficie']}|$t|{$c['variante']}"][] = $d;
            }
        }
        $this->variantes = array_map(fn ($ds) => ['carreras' => count($ds), 'ajuste' => array_sum($ds) / count($ds)], $desvios);
        $this->calcularEquivalencias();
        return ['carreras' => count($this->carreras), 'pares' => count($this->par), 'ajustes' => count($this->ajustes),
            'variantes' => count($this->variantes), 'equivalencias' => $this->equivalencias];
    }

    /** Carrera ya leída (nivel, pista, variante...), o null. */
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
                return $p + ['categoria' => $n === '*' ? 'todos los niveles' : $n];
            }
        }
        return null;
    }

    public function parGeneral(int $hip, int $distancia, string $superficie): ?array
    {
        return $this->par($hip, $distancia, $superficie, '*');
    }

    /** km/h que corrió de más (+) o de menos (−) ese estado de pista respecto del par; null si no hay con qué medirlo. */
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

    public function ajusteVariante(int $hip, string $superficie, int $distancia, string $variante): float
    {
        foreach ([Clasificador::tramo($distancia), '*'] as $t) {
            $a = $this->variantes["$hip|$superficie|$t|$variante"] ?? null;
            if ($a !== null && $a['carreras'] >= self::MIN_CARRERAS) {
                return $a['ajuste'];
            }
        }
        return 0.0;
    }

    /** Estado de pista + variante; null si el estado no se puede medir. */
    public function ajuste(int $hip, string $superficie, int $distancia, string $pista, string $variante): ?float
    {
        $p = $this->ajustePista($hip, $superficie, $distancia, $pista);
        return $p === null ? null : $p + $this->ajusteVariante($hip, $superficie, $distancia, $variante);
    }

    public function equivalencia(int $hip): float
    {
        return $this->equivalencias[$hip] ?? 0.0;
    }

    /** Índice contra el par general de su hipódromo, sin pasar a la escala de Palermo. */
    public function indiceLocal(int $hip, int $distancia, string $superficie, string $pista, string $variante, ?float $segundos): ?float
    {
        $v = self::kmh($distancia, $segundos);
        $aj = $v !== null ? $this->ajuste($hip, $superficie, $distancia, $pista, $variante) : null;
        $gen = $aj !== null ? $this->parGeneral($hip, $distancia, $superficie) : null;
        return $gen ? $v - $aj - $gen['velocidad'] : null;
    }

    /**
     * Cada hipódromo contra Palermo, con los caballos que corrieron en dos con 90 días o menos de diferencia: mínimos
     * cuadrados sobre todos los pases (de San Isidro a La Plata también cuenta), resueltos por iteración.
     */
    private function calcularEquivalencias(): void
    {
        $st = $this->db->query(
            'SELECT a.caballo_id, a.carrera_id, a.tiempo_seg FROM actuacion a JOIN carrera c ON c.id = a.carrera_id
             JOIN reunion r ON r.id = c.reunion_id WHERE a.tiempo_seg IS NOT NULL AND a.puesto IS NOT NULL ORDER BY a.caballo_id, r.fecha'
        );
        $pares = [];
        $mismo = [0.0, 0];
        $ant = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $c = $this->carreras[(int)$f['carrera_id']] ?? null;
            if ($c === null) {
                continue;
            }
            $idx = $this->indiceLocal($c['hip'], $c['distancia'], $c['superficie'], $c['pista'], $c['variante'], (float)$f['tiempo_seg']);
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
        // Pasar de a a b sube el índice local en e[b] − e[a] (más lo que mejora cualquier caballo de una carrera a otra).
        $hips = [];
        foreach ($pares as $k => [$s, $n]) {
            [$a, $b] = array_map('intval', explode('|', $k));
            $hips[$a] = $hips[$b] = true;
        }
        $e = array_fill_keys(array_keys($hips), 0.0);
        $conDatos = [];
        for ($it = 0; $it < 200; $it++) {
            foreach (array_keys($e) as $h) {
                if ($h === $this->base) {
                    continue;
                }
                $num = $den = 0.0;
                foreach ($pares as $k => [$s, $n]) {
                    [$a, $b] = array_map('intval', explode('|', $k));
                    $d = $s / $n - $base;
                    if ($b === $h && isset($e[$a])) {
                        $num += $n * ($e[$a] + $d);
                        $den += $n;
                    } elseif ($a === $h && isset($e[$b])) {
                        $num += $n * ($e[$b] - $d);
                        $den += $n;
                    }
                }
                if ($den >= self::MIN_PARES) {
                    $e[$h] = $num / $den;
                    $conDatos[$h] = true;
                }
            }
        }
        $this->equivalencias = [$this->base => 0.0];
        foreach ($conDatos as $h => $_) {
            $this->equivalencias[$h] = $e[$h];
        }
    }

    private static function clavePar(int $hip, int $dist, string $sup, string $n): string
    {
        return "$hip|$dist|$sup|$n";
    }

    private function leerCarreras(): array
    {
        $carreras = [];
        $st = $this->db->query(
            'SELECT c.id, r.hipodromo_id, r.fecha, c.distancia, c.superficie, c.pista, c.recorrido, c.categoria, c.condicion, c.grupo
             FROM carrera c JOIN reunion r ON r.id = c.reunion_id WHERE c.distancia > 0'
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $carreras[(int)$r['id']] = [
                'hip' => (int)$r['hipodromo_id'], 'fecha' => $r['fecha'], 'distancia' => (int)$r['distancia'],
                'superficie' => self::superficie($r['superficie']), 'pista' => self::pista($r['pista']),
                'variante' => self::variante($r['recorrido']), 'nivel' => self::nivel($r['categoria'], $r['condicion'], $r['grupo']), 'velocidades' => [],
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
