<?php
declare(strict_types=1);

namespace GranChance\JP;

use GranChance\Clasificador;
use PDO;

/**
 * Velocidades normales (par) de Japón, con el mismo método que las de Uruguay, Hong Kong, Brasil y Argentina:
 *
 * 1. Velocidad de cada caballo = distancia / su propio tiempo (la JRA publica el tiempo de cada uno, en décimas).
 * 2. Par = promedio de los 4 primeros en pista normal (Firm en césped y obstáculos, Standard en arena), por hipódromo,
 *    distancia, superficie y nivel: grado (G1, G2, G3, L), Open, las Allowance por la clase que marca el premio (1 Win,
 *    2 Win y 3 Win: la JRA no la publica en inglés), Maiden y Newcomer, junto con la edad del lote (2a, 3a, 3a+, 4a+).
 *    Con menos de 8 carreras se usa el par de todos los niveles.
 * 3. Ajuste de pista: cuánto se apartan del par los 4 primeros con cada estado (Good, Yielding, Soft en césped; Good,
 *    Muddy, Sloppy en arena). Sobre eso, en césped, un segundo ajuste por la semana de la reunión (el pasto se gasta y la
 *    JRA corre el riel), en lugar del riel de Hong Kong que la JRA no publica en inglés.
 * 4. Equivalencia entre hipódromos: Tokyo = 0 y los demás medidos con los caballos que pasan de uno a otro en 90 días.
 *
 * Se calcula en memoria cada vez (son ~40.000 carreras): no hay tablas de velocidades en la base JP.
 */
final class Velocidades
{
    public const MIN_CARRERAS = 8;
    public const PISTA_BASE = 'normal';     // Firm en césped y obstáculos, Standard en arena (ver pista())
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
    /** Tokyo: la escala de los índices */
    private int $base = 5;
    /** @var array<int, float> año => premio base más común de las Allowance (el de 1 Win) */
    private array $base1Win = [];
    /** @var array<int, array> carrera => datos y clase (nivel) */
    private array $carreras = [];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
     * Nivel de la carrera para el par: el grado (G1, G2, G3, L; los de obstáculos como J.G1), Open (Open Class sin grado),
     * Newcomer, Maiden, y las Allowance por su premio base comparado con el de 1 Win de ese año: hasta 1,3 veces es "1 Win",
     * hasta 1,85 "2 Win", hasta 2,3 "3 Win" y más, "Open". Se suma la edad del lote: "2 Win 4a+", "Maiden 2a".
     */
    public static function nivel(?string $clase, ?string $grado, ?string $edad, ?float $premioBase, ?float $base1Win): string
    {
        $clase = trim((string)$clase);
        $n = match (true) {
            (string)$grado !== '' => $grado === 'OP' ? 'Open' : $grado,
            $clase === 'Newcomer' => 'Newcomer',
            $clase === 'Maiden' => 'Maiden',
            $clase === 'Allowance' && $premioBase && $base1Win => match (true) {
                $premioBase / $base1Win <= 1.3 => '1 Win',
                $premioBase / $base1Win <= 1.85 => '2 Win',
                $premioBase / $base1Win <= 2.3 => '3 Win',
                default => 'Open',
            },
            $clase === 'Allowance' => 'Allowance',
            $clase === 'Open Class' => 'Open',
            default => 'Otras',
        };
        return $n . ' ' . self::edad($edad);
    }

    /** "3-Year-Olds & Up" → "3a+", "2-Year-Olds" → "2a". */
    public static function edad(?string $edad): string
    {
        return preg_match('/(\d)-Year-Olds?(\s*&\s*Up)?/i', (string)$edad, $m) ? $m[1] . 'a' . (empty($m[2]) ? '' : '+') : '?';
    }

    /** Nivel de una carrera de la base o del programa (con el premio base de 1 Win de su año). */
    public function nivelDe(array $c, string $fecha): string
    {
        return self::nivel($c['clase'] ?? null, $c['grado'] ?? null, $c['edad'] ?? null,
            isset($c['premio_base']) ? (float)$c['premio_base'] : null, $this->base1Win((int)substr($fecha, 0, 4)));
    }

    public function base1Win(int $anio): ?float
    {
        if (isset($this->base1Win[$anio])) {
            return $this->base1Win[$anio];
        }
        $anios = array_keys($this->base1Win);
        return $anios ? $this->base1Win[max($anios)] : null;
    }

    /** Turf → césped, Dirt → arena, Jump → obstáculos (tal cual la base). */
    public static function superficie(?string $s): string
    {
        return match (strtolower(trim((string)$s))) {
            'turf', 'césped' => 'césped',
            'jump', 'obstáculos' => 'obstáculos',
            default => 'arena',
        };
    }

    /** Estado de la pista: Firm (césped) y Standard (arena) son la pista normal; los demás en minúsculas. */
    public static function pista(?string $p): string
    {
        $p = strtolower(trim((string)$p));
        return match ($p) {
            '' => '?',
            'firm', 'standard' => self::PISTA_BASE,
            default => $p,
        };
    }

    /** Semana de la reunión en césped (1ª: días 1 y 2, 2ª: 3 y 4...): el pasto se gasta y la JRA corre el riel. */
    public static function variante(string $superficie, ?int $dia): string
    {
        return $superficie === 'césped' && $dia ? 'semana ' . min(6, intdiv($dia + 1, 2)) : '-';
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

    /** Índice contra el par general de su hipódromo, sin pasar a la escala de Tokyo. */
    public function indiceLocal(int $hip, int $distancia, string $superficie, string $pista, string $variante, ?float $segundos): ?float
    {
        $v = self::kmh($distancia, $segundos);
        $aj = $v !== null ? $this->ajuste($hip, $superficie, $distancia, $pista, $variante) : null;
        $gen = $aj !== null ? $this->parGeneral($hip, $distancia, $superficie) : null;
        return $gen ? $v - $aj - $gen['velocidad'] : null;
    }

    /**
     * Cada hipódromo contra Tokyo, con los caballos que corrieron en dos con 90 días o menos de diferencia: mínimos
     * cuadrados sobre todos los pases (de Nakayama a Hanshin también cuenta), resueltos por iteración.
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
            'SELECT c.id, r.hipodromo_id, r.fecha, r.dia_reunion, c.distancia, c.superficie, c.pista, c.clase, c.grado, c.edad, c.premio_base
             FROM carrera c JOIN reunion r ON r.id = c.reunion_id WHERE c.distancia > 0'
        );
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        // Premio base de 1 Win de cada año: el más común entre las Allowance de 3 años y más y de 4 años y más.
        $cuenta = [];
        foreach ($filas as $r) {
            if ($r['clase'] === 'Allowance' && $r['premio_base'] && str_contains((string)$r['edad'], '& Up')) {
                $k = substr($r['fecha'], 0, 4) . '|' . (float)$r['premio_base'];
                $cuenta[$k] = ($cuenta[$k] ?? 0) + 1;
            }
        }
        arsort($cuenta);
        $this->base1Win = [];
        foreach ($cuenta as $k => $_) {
            [$a, $p] = explode('|', $k);
            $this->base1Win[(int)$a] ??= (float)$p;
        }
        foreach ($filas as $r) {
            $sup = self::superficie($r['superficie']);
            $carreras[(int)$r['id']] = [
                'hip' => (int)$r['hipodromo_id'], 'fecha' => $r['fecha'], 'distancia' => (int)$r['distancia'],
                'superficie' => $sup, 'pista' => self::pista($r['pista']),
                'variante' => self::variante($sup, $r['dia_reunion'] !== null ? (int)$r['dia_reunion'] : null),
                'nivel' => $this->nivelDe($r, $r['fecha']), 'velocidades' => [],
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
