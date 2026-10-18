<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Velocidades normales de carrera (par) por hipódromo, distancia, superficie y categoría, y ajustes por pista y viento.
 *
 * 1. Para cada carrera se toma la velocidad (km/h) de los 4 primeros: distancia / tiempo.
 * 2. Par = promedio de esas velocidades en las carreras con pista seca y sin viento, por
 *    (hipódromo, distancia, superficie, nivel, edad, sexo). Si una categoría tiene pocas carreras se usa la
 *    más general: nivel+edad, después nivel solo, después todas las categorías de esa distancia.
 * 3. Ajuste de pista y viento = cuánto se apartan del par los 4 primeros de las carreras corridas en esa
 *    condición, medido con los mismos datos (reemplaza las tablas fijas de viento del programa viejo).
 */
final class Velocidades
{
    public const MIN_CARRERAS = 8;      // carreras necesarias para confiar en un par o un ajuste
    private const VEL_MIN = 35.0, VEL_MAX = 80.0;

    /** @var array<string, array{carreras:int, velocidad:float, desvio:?float}> */
    private array $par = [];
    /** @var array<string, array{carreras:int, ajuste:float}> */
    private array $ajustes = [];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function kmh(int $distancia, float $segundos): ?float
    {
        if ($distancia <= 0 || $segundos <= 0) {
            return null;
        }
        $v = $distancia / $segundos * 3.6;
        return $v >= self::VEL_MIN && $v <= self::VEL_MAX ? $v : null;
    }

    public static function viento(?string $viento): string
    {
        $v = mb_strtolower(trim((string)$viento));
        return match (true) {
            $v === '' => '?',
            str_contains($v, 'favor') => 'A favor',
            str_contains($v, 'contra') => 'En contra',
            str_contains($v, 'cruz') => 'Cruzado',
            str_contains($v, 'sin') => 'Sin viento',
            default => $viento,
        };
    }

    /** Recalcula carrera_categoria, par_velocidad y ajuste_condicion. Devuelve un resumen. */
    public function recalcular(): array
    {
        $carreras = $this->leerCarreras();

        // Par en pista seca y sin viento.
        $grupos = [];
        foreach ($carreras as $c) {
            if ($c['pista'] !== 'seca' || $c['viento'] !== 'Sin viento' || !$c['velocidades'] || $c['superficie'] === '?') {
                continue;
            }
            foreach (self::nivelesPar($c['nivel'], $c['edad'], $c['sexo']) as [$n, $e, $s]) {
                $k = self::clavePar($c['hip'], $c['distancia'], $c['superficie'], $n, $e, $s);
                $grupos[$k][] = $c['velocidades'];
            }
        }
        $this->par = [];
        foreach ($grupos as $k => $listas) {
            $todas = array_merge(...$listas);
            $prom = array_sum($todas) / count($todas);
            $var = array_sum(array_map(fn ($v) => ($v - $prom) ** 2, $todas)) / max(1, count($todas) - 1);
            $this->par[$k] = ['carreras' => count($listas), 'velocidad' => $prom, 'desvio' => count($todas) > 1 ? sqrt($var) : null];
        }

        // Ajustes: desvío del promedio de los 4 primeros respecto del par de su categoría.
        $desvios = [];
        foreach ($carreras as $c) {
            if (!$c['velocidades'] || $c['superficie'] === '?' || $c['pista'] === '?' || $c['viento'] === '?') {
                continue;
            }
            $par = $this->par($c['hip'], $c['distancia'], $c['superficie'], $c['nivel'], $c['edad'], $c['sexo']);
            if ($par === null) {
                continue;
            }
            $d = array_sum($c['velocidades']) / count($c['velocidades']) - $par['velocidad'];
            $tramo = Clasificador::tramo($c['distancia']);
            foreach ([[$c['hip'], $c['superficie'], $tramo], [$c['hip'], $c['superficie'], '*'], [0, $c['superficie'], $tramo], [0, '*', '*']] as [$h, $s, $t]) {
                $desvios[self::claveAjuste($h, $s, $t, $c['pista'], $c['viento'])][] = $d;
            }
        }
        $this->ajustes = [];
        foreach ($desvios as $k => $ds) {
            $this->ajustes[$k] = ['carreras' => count($ds), 'ajuste' => array_sum($ds) / count($ds)];
        }

        $this->guardar($carreras);
        return ['carreras' => count($carreras), 'pares' => count($this->par), 'ajustes' => count($this->ajustes)];
    }

    /** Carga las tablas ya calculadas. */
    public function cargar(): void
    {
        $this->par = [];
        foreach ($this->db->query('SELECT * FROM par_velocidad', PDO::FETCH_ASSOC) as $r) {
            $k = self::clavePar((int)$r['hipodromo_id'], (int)$r['distancia'], $r['superficie'], $r['nivel'], $r['edad'], $r['sexo']);
            $this->par[$k] = ['carreras' => (int)$r['carreras'], 'velocidad' => (float)$r['velocidad'], 'desvio' => $r['desvio'] !== null ? (float)$r['desvio'] : null];
        }
        $this->ajustes = [];
        foreach ($this->db->query('SELECT * FROM ajuste_condicion', PDO::FETCH_ASSOC) as $r) {
            $k = self::claveAjuste((int)$r['hipodromo_id'], $r['superficie'], $r['tramo'], $r['pista'], $r['viento']);
            $this->ajustes[$k] = ['carreras' => (int)$r['carreras'], 'ajuste' => (float)$r['ajuste']];
        }
    }

    /**
     * Par de una categoría, bajando a una más general si hay menos de MIN_CARRERAS.
     * @return array{carreras:int, velocidad:float, desvio:?float, categoria:string}|null
     */
    public function par(int $hip, int $distancia, string $superficie, string $nivel, string $edad, string $sexo): ?array
    {
        foreach (self::nivelesPar($nivel, $edad, $sexo) as $i => [$n, $e, $s]) {
            $p = $this->par[self::clavePar($hip, $distancia, $superficie, $n, $e, $s)] ?? null;
            // El par general de la distancia (último nivel) se acepta con menos carreras: es la única referencia que queda.
            if ($p !== null && ($p['carreras'] >= self::MIN_CARRERAS || ($i === 3 && $p['carreras'] >= 3))) {
                return $p + ['categoria' => self::nombreCategoria($n, $e, $s)];
            }
        }
        return null;
    }

    /** Par de todas las categorías juntas: referencia para comparar carreras de distinta distancia o hipódromo. */
    public function parGeneral(int $hip, int $distancia, string $superficie): ?array
    {
        return $this->par($hip, $distancia, $superficie, '*', '*', '*');
    }

    /** @return array{carreras:int, ajuste:float}|null km/h que corrió de más (+) o de menos (-) la condición respecto del par */
    public function ajuste(int $hip, string $superficie, int $distancia, string $pista, string $viento): ?array
    {
        if ($pista === 'seca' && $viento === 'Sin viento') {
            return ['carreras' => 0, 'ajuste' => 0.0];
        }
        $tramo = Clasificador::tramo($distancia);
        foreach ([[$hip, $superficie, $tramo], [$hip, $superficie, '*'], [0, $superficie, $tramo], [0, '*', '*']] as [$h, $s, $t]) {
            $a = $this->ajustes[self::claveAjuste($h, $s, $t, $pista, $viento)] ?? null;
            if ($a !== null && $a['carreras'] >= self::MIN_CARRERAS) {
                return $a;
            }
        }
        return null;
    }

    public static function nombreCategoria(string $nivel, string $edad, string $sexo): string
    {
        if ($nivel === '*') {
            return 'todas las categorías';
        }
        return $nivel . ($edad !== '*' ? " $edad" : '') . ($sexo === 'hembras' ? ' hembras' : '');
    }

    /** Niveles de respaldo: categoría completa → sin sexo → solo nivel → todas. */
    private static function nivelesPar(string $nivel, string $edad, string $sexo): array
    {
        return [[$nivel, $edad, $sexo], [$nivel, $edad, '*'], [$nivel, '*', '*'], ['*', '*', '*']];
    }

    private static function clavePar(int $hip, int $dist, string $sup, string $n, string $e, string $s): string
    {
        return "$hip|$dist|$sup|$n|$e|$s";
    }

    private static function claveAjuste(int $hip, string $sup, string $tramo, string $pista, string $viento): string
    {
        return "$hip|$sup|$tramo|$pista|$viento";
    }

    /** Carreras con su categoría y las velocidades de los 4 primeros. */
    private function leerCarreras(): array
    {
        $carreras = [];
        $st = $this->db->query(
            'SELECT c.id, r.hipodromo_id, r.fecha, c.distancia, c.superficie, c.pista, c.viento, c.premio, c.condicion
             FROM carrera c JOIN reunion r ON r.id = c.reunion_id WHERE c.distancia > 0'
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hip = (int)$r['hipodromo_id'];
            $carreras[(int)$r['id']] = [
                'hip' => $hip, 'fecha' => $r['fecha'], 'distancia' => (int)$r['distancia'],
                'superficie' => Clasificador::superficie($r['superficie'], $hip),
                'pista' => Clasificador::pista($r['pista']), 'viento' => self::viento($r['viento']),
                'nivel' => Clasificador::nivel($r['premio'], $r['condicion']),
                'edades' => [], 'sexos' => [], 'velocidades' => [],
            ];
        }
        $st = $this->db->query(
            'SELECT a.carrera_id, a.puesto, a.tiempo_seg, ca.anio_nac, ca.sexo
             FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id'
        );
        while ($a = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($carreras[(int)$a['carrera_id']])) {
                continue;
            }
            $c = &$carreras[(int)$a['carrera_id']];
            $c['edades'][] = Clasificador::edadEn((int)$a['anio_nac'], $c['fecha']);
            $c['sexos'][] = $a['sexo'];
            if ($a['puesto'] !== null && (int)$a['puesto'] >= 1 && (int)$a['puesto'] <= 4) {
                $v = self::kmh($c['distancia'], (float)$a['tiempo_seg']);
                if ($v !== null) {
                    $c['velocidades'][] = $v;
                }
            }
            unset($c);
        }
        foreach ($carreras as $id => &$c) {
            $c['edad'] = Clasificador::edad($c['edades']);
            $c['sexo'] = Clasificador::sexo($c['sexos']);
            unset($c['edades'], $c['sexos']);
        }
        unset($c);
        return $carreras;
    }

    private function guardar(array $carreras): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM carrera_categoria');
            $this->db->exec('DELETE FROM par_velocidad');
            $this->db->exec('DELETE FROM ajuste_condicion');
            $this->insertarEnLotes('carrera_categoria', ['carrera_id', 'nivel', 'edad', 'sexo', 'superficie', 'pista', 'velocidad_top4'], array_map(
                fn ($id, $c) => [$id, $c['nivel'], $c['edad'], $c['sexo'], $c['superficie'], $c['pista'],
                    $c['velocidades'] ? round(array_sum($c['velocidades']) / count($c['velocidades']), 3) : null],
                array_keys($carreras), $carreras
            ));
            $filas = [];
            foreach ($this->par as $k => $p) {
                $filas[] = [...explode('|', $k), $p['carreras'], round($p['velocidad'], 3), $p['desvio'] !== null ? round($p['desvio'], 3) : null];
            }
            $this->insertarEnLotes('par_velocidad', ['hipodromo_id', 'distancia', 'superficie', 'nivel', 'edad', 'sexo', 'carreras', 'velocidad', 'desvio'], $filas);
            $filas = [];
            foreach ($this->ajustes as $k => $a) {
                $filas[] = [...explode('|', $k), $a['carreras'], round($a['ajuste'], 3)];
            }
            $this->insertarEnLotes('ajuste_condicion', ['hipodromo_id', 'superficie', 'tramo', 'pista', 'viento', 'carreras', 'ajuste'], $filas);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertarEnLotes(string $tabla, array $columnas, array $filas): void
    {
        $marcas = '(' . implode(',', array_fill(0, count($columnas), '?')) . ')';
        foreach (array_chunk($filas, 500) as $lote) {
            $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $tabla, implode(',', $columnas), implode(',', array_fill(0, count($lote), $marcas)));
            $this->db->prepare($sql)->execute(array_merge(...$lote));
        }
    }
}
