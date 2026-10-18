<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Probabilidad de ganar de cada caballo: un logit condicional (softmax dentro de cada carrera) sobre la proyección de
 * velocidad, la campaña del caballo y cómo vienen ganando su jockey y su cuidador, con cualquier caballo.
 *
 * Probado entrenando con 2019-2023 y midiendo en las carreras de 2024-2026 (que el modelo no vio), el caballo con más
 * probabilidad ganó el 28 % de las veces, contra 25,5 % ordenando solo por la proyección (el favorito de las apuestas
 * gana el 35 %). Los parciales (tiempos del puntero) no mejoraron nada y no se usan.
 *
 * Jockeys y cuidadores: % de carreras ganadas y % en el marcador (1º a 3º), en general y en ese hipódromo y tramo de
 * distancia, con las carreras pesando la mitad cada MEDIA_VIDA_DIAS y achicadas hacia el promedio si son pocas.
 */
final class Modelo
{
    public const VARIABLES = ['proyeccion', 'mejor_anio', 'debutante', 'carreras', 'pct_ganadas', 'pct_place', 'dias', 'dias2',
        'ultimo_puesto', 'gano_ultima', 'cambio_categoria', 'kg_menos', 'gatera', 'mismo_jockey', 'mismo_cuidador',
        'jockey_gana', 'cuidador_gana', 'jockey_gana_tramo', 'cuidador_gana_tramo', 'jockey_place', 'cuidador_place'];
    public const MEDIA_VIDA = 5;            // carreras: cada 5 carreras hacia atrás el índice pesa la mitad
    private const MEDIA_VIDA_DIAS = 180;
    private const SIN_DATO = -1.5;          // proyección de un debutante
    private const MIN_CORREDORES = 5;

    /** @var array<string, array{0:float,1:float,2:float}> variable => [media, desvío, coeficiente] */
    private array $coef = [];
    /** @var array<string, array{0:float,1:float,2:float,3:int}> persona => [ganadas, placé, corridas, día] con decaimiento */
    private array $est = [];

    public function __construct(private PDO $db, private Velocidades $vel)
    {
    }

    // ---------------------------------------------------------------- índices del caballo

    /** Promedio ponderado por lo reciente (el primero es el más reciente): el peso se divide por 2 cada MEDIA_VIDA carreras. */
    public static function ponderado(array $valores): ?float
    {
        $suma = $pesos = 0.0;
        foreach (array_values($valores) as $k => $v) {
            $w = 0.5 ** ($k / self::MEDIA_VIDA);
            $suma += $w * $v;
            $pesos += $w;
        }
        return $pesos ? $suma / $pesos : null;
    }

    /**
     * Historia, último, historia en la distancia y proyección de una lista de carreras (la más reciente primero), cada una
     * con indice, distancia y superficie.
     * @return array{promedio:?float, ultimo:?float, en_distancia:?float, proyeccion:?float}
     */
    public static function proyeccion(array $ant, int $distancia, string $superficie): array
    {
        $validos = array_values(array_filter($ant, fn ($a) => $a['indice'] !== null));
        if (!$validos) {
            return ['promedio' => null, 'ultimo' => null, 'en_distancia' => null, 'proyeccion' => null];
        }
        $prom = self::ponderado(array_column($validos, 'indice'));
        $ult = (float)$validos[0]['indice'];
        $dist = self::ponderado(array_column(array_filter($validos, fn ($a) => (int)$a['distancia'] === $distancia
            && $a['superficie'] === $superficie), 'indice'));
        return ['promedio' => $prom, 'ultimo' => $ult, 'en_distancia' => $dist,
            'proyeccion' => $dist !== null ? (3 * $prom + $ult + 2 * $dist) / 6 : (2 * $prom + $ult) / 3];
    }

    /**
     * Variables de un caballo para el modelo.
     * @param array $ant  carreras anteriores, la más reciente primero: fecha, indice, distancia, superficie, puesto,
     *                    nivel_par (par de su categoría − par general), jockey y cuidador (claves de Personas)
     * @param array $hoy  fecha, hipodromo, distancia, superficie, nivel_par, peso, peso_max, gatera, corredores, jockey, cuidador
     */
    public function rasgos(array $ant, array $hoy): array
    {
        $t = self::dia($hoy['fecha']);
        $nh = $hoy['nivel_par'] ?? 0.0;
        $p = self::proyeccion($ant, (int)$hoy['distancia'], $hoy['superficie']);
        $proy = $p['proyeccion'] !== null ? $p['proyeccion'] - $nh : self::SIN_DATO;
        $anio = array_filter($ant, fn ($a) => $a['indice'] !== null && $t - self::dia($a['fecha']) <= 365);
        $idx = array_map('floatval', array_column($anio, 'indice'));
        rsort($idx);
        $mejor = $idx ? array_sum(array_slice($idx, 0, 3)) / count(array_slice($idx, 0, 3)) - $nh : $proy;
        $n = count($ant);
        $puestos = array_column($ant, 'puesto');
        $u = $ant[0] ?? null;
        $dias = $u ? log1p($t - self::dia($u['fecha'])) : 0.0;
        $tramo = Clasificador::tramo((int)$hoy['distancia']);
        return [
            'proyeccion' => $proy,
            'mejor_anio' => $mejor,
            'debutante' => $p['proyeccion'] === null ? 1.0 : 0.0,
            'carreras' => log1p($n),
            'pct_ganadas' => (count(array_keys($puestos, 1)) + 0.5) / ($n + 5),
            'pct_place' => (count(array_filter($puestos, fn ($x) => in_array($x, [1, 2, 3], true))) + 1.5) / ($n + 5),
            'dias' => $dias,
            'dias2' => ($dias - 3.3) ** 2,
            'ultimo_puesto' => (float)($u['puesto'] ?? 6),
            'gano_ultima' => $u && $u['puesto'] === 1 ? 1.0 : 0.0,
            'cambio_categoria' => $u && $u['nivel_par'] !== null ? $u['nivel_par'] - $nh : 0.0,
            'kg_menos' => $hoy['peso'] ? $hoy['peso_max'] - $hoy['peso'] : 0.0,
            'gatera' => ($hoy['gatera'] ?: 6) / max(1, $hoy['corredores']),
            'mismo_jockey' => $u && $u['jockey'] === $hoy['jockey'] ? 1.0 : 0.0,
            'mismo_cuidador' => $u && $u['cuidador'] === $hoy['cuidador'] ? 1.0 : 0.0,
            'jockey_gana' => $this->tasa('j|' . $hoy['jockey'], $t, 0),
            'cuidador_gana' => $this->tasa('c|' . $hoy['cuidador'], $t, 0),
            'jockey_gana_tramo' => $this->tasa("j|{$hoy['jockey']}|{$hoy['hipodromo']}|$tramo", $t, 0),
            'cuidador_gana_tramo' => $this->tasa("c|{$hoy['cuidador']}|{$hoy['hipodromo']}|$tramo", $t, 0),
            'jockey_place' => $this->tasa('j|' . $hoy['jockey'], $t, 1),
            'cuidador_place' => $this->tasa('c|' . $hoy['cuidador'], $t, 1),
        ];
    }

    // ---------------------------------------------------------------- jockeys y cuidadores

    private function tasa(string $k, int $t, int $col): float
    {
        [$g, $pl, $r] = $this->decaido($k, $t);
        $prior = $col === 0 ? 0.1 : 0.3;
        return (($col === 0 ? $g : $pl) + 15 * $prior) / ($r + 15);
    }

    private function decaido(string $k, int $t): array
    {
        if (!isset($this->est[$k])) {
            return [0.0, 0.0, 0.0];
        }
        [$g, $pl, $r, $t0] = $this->est[$k];
        $f = 0.5 ** (($t - $t0) / self::MEDIA_VIDA_DIAS);
        return [$g * $f, $pl * $f, $r * $f];
    }

    private function sumar(string $jockey, string $cuidador, int $hip, int $distancia, int $t, ?int $puesto): void
    {
        $tramo = Clasificador::tramo($distancia);
        foreach (["j|$jockey", "c|$cuidador", "j|$jockey|$hip|$tramo", "c|$cuidador|$hip|$tramo"] as $k) {
            [$g, $pl, $r] = $this->decaido($k, $t);
            $this->est[$k] = [$g + ($puesto === 1 ? 1 : 0), $pl + ($puesto !== null && $puesto <= 3 ? 1 : 0), $r + 1, $t];
        }
    }

    /** Estadísticas de jockeys y cuidadores con las carreras de los 4 años anteriores a la fecha (para el programa). */
    public function cargarPersonas(string $fecha): void
    {
        $this->est = [];
        $st = $this->db->prepare(
            'SELECT r.fecha, r.hipodromo_id, c.distancia, a.puesto, j.nombre AS jockey, cu.nombre AS cuidador
             FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id
             LEFT JOIN persona j ON j.id = a.jockey_id LEFT JOIN persona cu ON cu.id = a.cuidador_id
             WHERE r.fecha < ? AND r.fecha >= DATE_SUB(?, INTERVAL 4 YEAR) ORDER BY r.fecha'
        );
        $st->execute([$fecha, $fecha]);
        foreach ($st as $f) {
            $this->sumar(Personas::jockey($f['jockey']), Personas::cuidador($f['cuidador']), (int)$f['hipodromo_id'],
                (int)$f['distancia'], self::dia($f['fecha']), $f['puesto'] !== null ? (int)$f['puesto'] : null);
        }
    }

    // ---------------------------------------------------------------- probabilidades

    public function cargar(): bool
    {
        $this->coef = [];
        foreach ($this->db->query('SELECT variable, media, desvio, coeficiente FROM modelo_coef', PDO::FETCH_ASSOC) as $r) {
            $this->coef[$r['variable']] = [(float)$r['media'], (float)$r['desvio'], (float)$r['coeficiente']];
        }
        return (bool)$this->coef;
    }

    /**
     * Probabilidad de ganar de cada corredor de una carrera.
     * @param array<int|string, array> $rasgos  clave => variables (de rasgos())
     * @return array<int|string, float>
     */
    public function probabilidades(array $rasgos): array
    {
        if (!$this->coef || !$rasgos) {
            return [];
        }
        $s = array_map(fn ($x) => $this->puntaje($x), $rasgos);
        $max = max($s);
        $e = array_map(fn ($v) => exp($v - $max), $s);
        $z = array_sum($e);
        return array_map(fn ($v) => $v / $z, $e);
    }

    private function puntaje(array $x): float
    {
        $s = 0.0;
        foreach ($this->coef as $v => [$m, $d, $b]) {
            $s += $b * (($x[$v] ?? $m) - $m) / $d;
        }
        return $s;
    }

    // ---------------------------------------------------------------- entrenamiento

    /**
     * Recorre todas las carreras en orden, arma las variables de cada corredor con lo que se sabía ese día y ajusta el
     * modelo con las carreras desde $desde. Si se da $prueba, entrena hasta esa fecha y mide el acierto desde ahí.
     * @return array{carreras:int, acierto?:float, acierto_proyeccion?:float, carreras_prueba?:int}
     */
    public function entrenar(string $desde = '2019-01-01', ?string $prueba = null): array
    {
        $this->est = [];
        $hist = [];
        $X = $y = $grupo = $fechas = [];
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $this->db->query(
            'SELECT a.carrera_id, a.caballo_id, r.fecha, r.hipodromo_id, c.distancia, c.superficie, c.pista, c.viento,
                    a.puesto, a.tiempo_seg, a.peso_real, a.gatera, j.nombre AS jockey, cu.nombre AS cuidador,
                    cc.nivel, cc.edad, cc.sexo
             FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id
             LEFT JOIN carrera_categoria cc ON cc.carrera_id = c.id
             LEFT JOIN persona j ON j.id = a.jockey_id LEFT JOIN persona cu ON cu.id = a.cuidador_id
             ORDER BY r.fecha, a.carrera_id',
            PDO::FETCH_ASSOC
        );
        $carrera = [];
        $procesar = function (array $carrera) use (&$hist, &$X, &$y, &$grupo, &$fechas, $desde) {
            $f0 = $carrera[0];
            $t = self::dia($f0['fecha']);
            $llegaron = array_values(array_filter($carrera, fn ($f) => $f['puesto'] !== null));
            $ganadores = count(array_filter($llegaron, fn ($f) => $f['puesto'] === 1));
            if ($f0['fecha'] >= $desde && count($llegaron) >= self::MIN_CORREDORES && $ganadores === 1) {
                $pesoMax = max(array_map(fn ($f) => (float)$f['peso_real'], $llegaron));
                foreach ($llegaron as $f) {
                    $X[] = array_values($this->rasgos($hist[$f['caballo_id']] ?? [], [
                        'fecha' => $f['fecha'], 'hipodromo' => $f['hip'], 'distancia' => $f['dist'], 'superficie' => $f['sup'],
                        'nivel_par' => $f['nivel_par'], 'peso' => (float)$f['peso_real'], 'peso_max' => $pesoMax,
                        'gatera' => (int)$f['gatera'], 'corredores' => count($llegaron), 'jockey' => $f['jockey'], 'cuidador' => $f['cuidador']]));
                    $y[] = $f['puesto'] === 1 ? 1 : 0;
                    $grupo[] = (int)$f0['carrera_id'];
                    $fechas[] = $f0['fecha'];
                }
            }
            foreach ($carrera as $f) {
                $this->sumar($f['jockey'], $f['cuidador'], $f['hip'], $f['dist'], $t, $f['puesto']);
                $hist[$f['caballo_id']] ??= [];
                array_unshift($hist[$f['caballo_id']], ['fecha' => $f['fecha'], 'indice' => $f['indice'], 'distancia' => $f['dist'],
                    'superficie' => $f['sup'], 'puesto' => $f['puesto'], 'nivel_par' => $f['nivel_par'], 'jockey' => $f['jockey'], 'cuidador' => $f['cuidador']]);
            }
        };
        foreach ($st as $f) {
            if ($carrera && $carrera[0]['carrera_id'] !== $f['carrera_id']) {
                $procesar($carrera);
                $carrera = [];
            }
            $carrera[] = $this->fila($f);
        }
        if ($carrera) {
            $procesar($carrera);
        }
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

        $res = ['carreras' => count(array_unique($grupo))];
        if ($prueba !== null) {
            $ent = array_keys(array_filter($fechas, fn ($f) => $f < $prueba));
            $pru = array_keys(array_filter($fechas, fn ($f) => $f >= $prueba));
            $this->ajustar($X, $y, $grupo, $ent);
            $res += $this->medir($X, $y, $grupo, $pru);
        }
        $this->ajustar($X, $y, $grupo, array_keys($y));
        $this->guardar();
        return $res;
    }

    /** Fila de la base → corredor con su índice y el nivel de su categoría. */
    private function fila(array $f): array
    {
        $hip = (int)$f['hipodromo_id'];
        $dist = (int)$f['distancia'];
        $sup = Clasificador::superficie($f['superficie'], $hip);
        $indice = $nivelPar = null;
        $gen = $dist ? $this->vel->parGeneral($hip, $dist, $sup) : null;
        if ($gen && $f['tiempo_seg'] !== null && $f['nivel'] !== 'Cuarto de milla') {
            $v = Velocidades::kmh($dist, (float)$f['tiempo_seg']);
            $aj = $this->vel->ajuste($hip, $sup, $dist, Clasificador::pista($f['pista']), Velocidades::viento($f['viento']));
            $indice = $v !== null && $aj ? $v - $aj['ajuste'] - $gen['velocidad'] : null;
        }
        if ($gen && $f['nivel']) {
            $par = $this->vel->par($hip, $dist, $sup, $f['nivel'], $f['edad'], $f['sexo']);
            $nivelPar = $par ? $par['velocidad'] - $gen['velocidad'] : null;
        }
        return ['carrera_id' => (int)$f['carrera_id'], 'caballo_id' => (int)$f['caballo_id'], 'fecha' => $f['fecha'], 'hip' => $hip,
            'dist' => $dist, 'sup' => $sup, 'puesto' => $f['puesto'] !== null ? (int)$f['puesto'] : null, 'indice' => $indice,
            'nivel_par' => $nivelPar, 'peso_real' => $f['peso_real'], 'gatera' => $f['gatera'],
            'jockey' => Personas::jockey($f['jockey']), 'cuidador' => Personas::cuidador($f['cuidador'])];
    }

    /** Logit condicional por Newton-Raphson (variables estandarizadas, con una regularización chica). */
    private function ajustar(array $X, array $y, array $grupo, array $filas): void
    {
        $k = count(self::VARIABLES);
        $media = array_fill(0, $k, 0.0);
        $desvio = array_fill(0, $k, 0.0);
        foreach ($filas as $i) {
            foreach ($X[$i] as $j => $v) {
                $media[$j] += $v;
            }
        }
        $media = array_map(fn ($m) => $m / count($filas), $media);
        foreach ($filas as $i) {
            foreach ($X[$i] as $j => $v) {
                $desvio[$j] += ($v - $media[$j]) ** 2;
            }
        }
        $desvio = array_map(fn ($d) => sqrt($d / count($filas)) ?: 1.0, $desvio);
        $Z = [];
        $porCarrera = [];
        foreach ($filas as $i) {
            $Z[$i] = array_map(fn ($v, $j) => ($v - $media[$j]) / $desvio[$j], $X[$i], array_keys($X[$i]));
            $porCarrera[$grupo[$i]][] = $i;
        }
        $b = array_fill(0, $k, 0.0);
        $lambda = 1.0;
        for ($it = 0; $it < 12; $it++) {
            $g = array_map(fn ($v) => -$lambda * $v, $b);
            $H = [];
            for ($a = 0; $a < $k; $a++) {
                $H[$a] = array_fill(0, $k, 0.0);
                $H[$a][$a] = $lambda;
            }
            foreach ($porCarrera as $is) {
                $s = [];
                foreach ($is as $i) {
                    $s[$i] = 0.0;
                    foreach ($Z[$i] as $j => $z) {
                        $s[$i] += $b[$j] * $z;
                    }
                }
                $max = max($s);
                $e = array_map(fn ($v) => exp($v - $max), $s);
                $tot = array_sum($e);
                $xm = array_fill(0, $k, 0.0);
                foreach ($is as $i) {
                    $p = $e[$i] / $tot;
                    foreach ($Z[$i] as $j => $z) {
                        $xm[$j] += $p * $z;
                        $g[$j] += ($y[$i] - $p) * $z;
                    }
                    for ($a = 0; $a < $k; $a++) {
                        $pa = $p * $Z[$i][$a];
                        for ($c = $a; $c < $k; $c++) {
                            $H[$a][$c] += $pa * $Z[$i][$c];
                        }
                    }
                }
                for ($a = 0; $a < $k; $a++) {
                    for ($c = $a; $c < $k; $c++) {
                        $H[$a][$c] -= $xm[$a] * $xm[$c];
                    }
                }
            }
            for ($a = 0; $a < $k; $a++) {
                for ($c = 0; $c < $a; $c++) {
                    $H[$a][$c] = $H[$c][$a];
                }
            }
            $paso = self::resolver($H, $g);
            $b = array_map(fn ($v, $d) => $v + $d, $b, $paso);
            if (max(array_map('abs', $paso)) < 1e-6) {
                break;
            }
        }
        $this->coef = [];
        foreach (self::VARIABLES as $j => $v) {
            $this->coef[$v] = [$media[$j], $desvio[$j], $b[$j]];
        }
    }

    /** Acierto (el de más probabilidad gana) del modelo y de la proyección sola, en las filas dadas. */
    private function medir(array $X, array $y, array $grupo, array $filas): array
    {
        $porCarrera = [];
        foreach ($filas as $i) {
            $porCarrera[$grupo[$i]][] = $i;
        }
        $ok = $okProy = 0;
        foreach ($porCarrera as $is) {
            $mejor = $mejorProy = null;
            foreach ($is as $i) {
                $x = array_combine(self::VARIABLES, $X[$i]);
                $s = $this->puntaje($x);
                if ($mejor === null || $s > $mejor[0]) {
                    $mejor = [$s, $i];
                }
                if ($mejorProy === null || $x['proyeccion'] > $mejorProy[0]) {
                    $mejorProy = [$x['proyeccion'], $i];
                }
            }
            $ok += $y[$mejor[1]];
            $okProy += $y[$mejorProy[1]];
        }
        $n = count($porCarrera);
        return ['carreras_prueba' => $n, 'acierto' => $n ? $ok / $n : 0.0, 'acierto_proyeccion' => $n ? $okProy / $n : 0.0];
    }

    private function guardar(): void
    {
        $this->db->exec('DELETE FROM modelo_coef');
        $st = $this->db->prepare('INSERT INTO modelo_coef (variable, media, desvio, coeficiente) VALUES (?, ?, ?, ?)');
        foreach ($this->coef as $v => [$m, $d, $b]) {
            $st->execute([$v, $m, $d, $b]);
        }
    }

    /** @return array<string, float> variable => coeficiente (estandarizado), para mostrar */
    public function coeficientes(): array
    {
        return array_map(fn ($c) => $c[2], $this->coef);
    }

    /** Resuelve H·x = g por eliminación gaussiana con pivoteo parcial. */
    private static function resolver(array $H, array $g): array
    {
        $n = count($g);
        for ($i = 0; $i < $n; $i++) {
            $H[$i][] = $g[$i];
        }
        for ($c = 0; $c < $n; $c++) {
            $p = $c;
            for ($r = $c + 1; $r < $n; $r++) {
                if (abs($H[$r][$c]) > abs($H[$p][$c])) {
                    $p = $r;
                }
            }
            [$H[$c], $H[$p]] = [$H[$p], $H[$c]];
            for ($r = $c + 1; $r < $n; $r++) {
                $f = $H[$r][$c] / $H[$c][$c];
                for ($k = $c; $k <= $n; $k++) {
                    $H[$r][$k] -= $f * $H[$c][$k];
                }
            }
        }
        $x = array_fill(0, $n, 0.0);
        for ($r = $n - 1; $r >= 0; $r--) {
            $s = $H[$r][$n];
            for ($k = $r + 1; $k < $n; $k++) {
                $s -= $H[$r][$k] * $x[$k];
            }
            $x[$r] = $s / $H[$r][$r];
        }
        return $x;
    }

    private static function dia(string $fecha): int
    {
        return intdiv(strtotime($fecha . ' 12:00:00 UTC'), 86400);
    }
}
