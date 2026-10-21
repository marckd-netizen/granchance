<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Logit condicional (softmax dentro de cada carrera): ajuste por Newton-Raphson, calibración de la escala, medición del
 * acierto y coeficientes guardados en modelo_coef. Lo comparten el modelo de Uruguay (Modelo) y el de Hong Kong
 * (HK\Modelo); cada uno define VARIABLES, arma las variables de cada corredor y tiene $db.
 */
trait LogitCondicional
{
    /** @var array<string, array{0:float,1:float,2:float}> variable => [media, desvío, coeficiente] */
    private array $coef = [];
    /** Los puntajes se dividen por esta escala para que las probabilidades no salgan exageradas (ver calibrar()). */
    private float $escala = 1.0;
    /** @var list<int> caballo de cada fila de entrenamiento */
    private array $caballoDeFila = [];
    /** @var array<int, array<int, float>> carrera => caballo => probabilidad, en las carreras de prueba (sin verlas al entrenar) */
    private array $predicciones = [];
    /** Probabilidades que dio el modelo en las carreras de prueba del último entrenar(), que no usó para ajustar. */
    public function predicciones(): array
    {
        return $this->predicciones;
    }

    public function cargar(): bool
    {
        $this->coef = [];
        foreach ($this->db->query('SELECT variable, media, desvio, coeficiente FROM modelo_coef', PDO::FETCH_ASSOC) as $r) {
            $this->coef[$r['variable']] = [(float)$r['media'], (float)$r['desvio'], (float)$r['coeficiente']];
        }
        $this->escala = (float)($this->coef['_escala'][2] ?? 1.0);
        unset($this->coef['_escala']);
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
        return $s / $this->escala;
    }
    /** Variables en el orden de VARIABLES (así quedan alineadas con los coeficientes que se guardan). */
    private static function vector(array $rasgos): array
    {
        return array_map(fn ($v) => $rasgos[$v], self::VARIABLES);
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

    /**
     * El modelo sale demasiado seguro con los favoritos claros (daba 54 % a caballos que ganaban el 43 %): se busca la
     * escala que mejor predice las carreras de prueba (máxima verosimilitud) y se dividen los puntajes por ella.
     */
    private function calibrar(array $X, array $y, array $grupo, array $filas): void
    {
        $porCarrera = [];
        foreach ($filas as $i) {
            $porCarrera[$grupo[$i]][] = $i;
        }
        $this->escala = 1.0;
        $puntajes = [];
        foreach ($porCarrera as $c => $is) {
            foreach ($is as $i) {
                $puntajes[$c][$i] = $this->puntaje(array_combine(self::VARIABLES, $X[$i]));
            }
        }
        $mejor = [-INF, 1.0];
        for ($e = 0.8; $e <= 2.0; $e += 0.05) {
            $ll = 0.0;
            foreach ($puntajes as $ps) {
                $max = max($ps) / $e;
                $z = 0.0;
                foreach ($ps as $i => $p) {
                    $z += exp($p / $e - $max);
                    if ($y[$i]) {
                        $gan = $p / $e;
                    }
                }
                $ll += $gan - $max - log($z);
            }
            if ($ll > $mejor[0]) {
                $mejor = [$ll, $e];
            }
        }
        $this->escala = $mejor[1];
    }

    /**
     * Acierto (el de más probabilidad gana) del modelo y de la proyección sola, en las filas dadas, y trifecta y exacta
     * sin orden con los 3 de más probabilidad: si son los 3 primeros, o si entre ellos están los 2 primeros (por carrera).
     */
    private function medir(array $X, array $y, array $puesto, array $grupo, array $filas): array
    {
        $porCarrera = [];
        foreach ($filas as $i) {
            $porCarrera[$grupo[$i]][] = $i;
        }
        $ok = $okProy = 0;
        $trifectas = $exactas = [];
        $tramos = [];   // calibración: probabilidad dada (de a 10 %) => [suma de probabilidades, ganadores, caballos]
        foreach ($porCarrera as $is) {
            $mejor = $mejorProy = null;
            $prob = $this->probabilidades(array_combine($is, array_map(fn ($i) => array_combine(self::VARIABLES, $X[$i]), $is)));
            foreach ($prob as $i => $p) {
                $this->predicciones[$grupo[$i]][$this->caballoDeFila[$i]] = $p;
            }
            arsort($prob);
            $tres = array_slice(array_keys($prob), 0, 3);
            $trifectas[$grupo[$is[0]]] = count(array_filter($tres, fn ($i) => $puesto[$i] <= 3)) === 3;
            $exactas[$grupo[$is[0]]] = count(array_filter($tres, fn ($i) => $puesto[$i] <= 2)) === 2;
            foreach ($prob as $i => $p) {
                $k = min(6, intdiv((int)($p * 100), 10));
                $tramos[$k] = [($tramos[$k][0] ?? 0) + $p, ($tramos[$k][1] ?? 0) + $y[$i], ($tramos[$k][2] ?? 0) + 1];
            }
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
        ksort($tramos);
        return ['carreras_prueba' => $n, 'acierto' => $n ? $ok / $n : 0.0, 'acierto_proyeccion' => $n ? $okProy / $n : 0.0,
            'trifecta' => $n ? array_sum($trifectas) / $n : 0.0, 'trifectas' => $trifectas,
            'exacta' => $n ? array_sum($exactas) / $n : 0.0, 'exactas' => $exactas,
            'calibracion' => array_map(fn ($t) => ['dada' => $t[0] / $t[2], 'real' => $t[1] / $t[2], 'caballos' => $t[2]], $tramos)];
    }

    private function guardar(): void
    {
        $this->db->exec('DELETE FROM modelo_coef');
        $st = $this->db->prepare('INSERT INTO modelo_coef (variable, media, desvio, coeficiente) VALUES (?, ?, ?, ?)');
        foreach ($this->coef as $v => [$m, $d, $b]) {
            $st->execute([$v, $m, $d, $b]);
        }
        $st->execute(['_escala', 0, 1, $this->escala]);
    }

    /** @return array<string, float> variable => coeficiente (estandarizado), para mostrar */
    public function coeficientes(): array
    {
        return array_map(fn ($c) => $c[2], $this->coef);
    }

    /** Resuelve H·x = g por eliminación gaussiana con pivoteo parcial. */
    public static function resolver(array $H, array $g): array
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
}
