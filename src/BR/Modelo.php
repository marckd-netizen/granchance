<?php
declare(strict_types=1);

namespace GranChance\BR;

use GranChance\{Clasificador, LogitCondicional, Modelo as ModeloUy, Personas};
use PDO;

/**
 * El modelo de Uruguay (GranChance\Modelo) aplicado a Brasil: las mismas variables y el mismo logit condicional, con lo
 * que cambia en los datos del JCB (como HK\Modelo para Hong Kong).
 *
 * - Índice de cada carrera con BR\Velocidades: par por nivel, ajuste por estado de pista y por "variante", y los demás
 *   hipódromos llevados a la escala de la Gávea. El tiempo de los que no ganan sale de los cuerpos al ganador.
 * - El mercado sale del rateio a ganador de cada caballo (cuota final) en lugar del sport.
 * - Kilos: el peso llevado (con el descargo del aprendiz).
 * - Gatera: el número del caballo (en Brasil es el lugar en el partidor).
 * - Jockeys y cuidadores por su nombre normalizado ("V.LEAL" y "V.Leal" son el mismo).
 * - Corrió: no es forfait y tiene rateio; los NCP cuentan como perdedores.
 * - Cuando el JCB publicó solo los 5 primeros, los demás quedan sin puesto (perdedores sin ubicación).
 */
final class Modelo
{
    use LogitCondicional;

    public const VARIABLES = ModeloUy::VARIABLES;
    private const MEDIA_VIDA_DIAS = 180;
    private const MEDIA_VIDA_LARGA = ['p' => 730, 'pt' => 730, 'a' => 730, 'm' => 1460, 'g' => 1460,
        'je' => 365, 'ce' => 365, 'jd' => 730, 'cd' => 730, 'pd' => 1460, 'md' => 2920, 'ad' => 1460];
    private const SIN_MERCADO = -2.5;       // log de 1/12: un caballo promedio en un lote de 12
    private const SIN_DATO = -1.5;
    private const MIN_CORREDORES = 5;

    private array $est = [];

    public function __construct(private PDO $db, private Velocidades $vel)
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS modelo_coef (variable VARCHAR(40) PRIMARY KEY, media DOUBLE NOT NULL,
            desvio DOUBLE NOT NULL, coeficiente DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public static function extremo(int $distancia): ?string
    {
        return $distancia && $distancia <= 1000 ? 'sprint' : ($distancia >= 1800 ? 'fondo' : null);
    }

    /**
     * Variables de un caballo (como ModeloUy::rasgos).
     * @param array $ant carreras anteriores, la más reciente primero
     * @param array $hoy fecha, hipodromo, distancia, superficie, nivel_par, peso, peso_max, gatera, corredores, jockey, cuidador, frente, padre, madre, abuelo
     */
    public function rasgos(array $ant, array $hoy): array
    {
        $t = self::dia($hoy['fecha']);
        $nh = $hoy['nivel_par'] ?? 0.0;
        $p = ModeloUy::proyeccion($ant, (int)$hoy['distancia'], $hoy['superficie']);
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
        $e = self::extremo((int)$hoy['distancia']);
        $mercado = array_slice(array_values(array_filter(array_column($ant, 'mercado'))), 0, 3);
        return [
            'proyeccion' => $proy,
            'mejor_anio' => $mejor,
            'debutante' => $p['proyeccion'] === null ? 1.0 : 0.0,
            'carreras' => log1p($n),
            'pct_ganadas' => (count(array_keys($puestos, 1)) + 0.5) / ($n + 5),
            'pct_place' => (count(array_filter($puestos, fn ($x) => in_array($x, [1, 2, 3], true))) + 1.5) / ($n + 5),
            'dias' => $dias,
            'dias2' => ($dias - 3.3) ** 2,
            'ultimo_puesto' => (float)($u['puesto'] ?? 7),
            'gano_ultima' => $u && $u['puesto'] === 1 ? 1.0 : 0.0,
            'cambio_categoria' => $u && $u['nivel_par'] !== null ? $u['nivel_par'] - $nh : 0.0,
            'kg_menos' => $hoy['peso'] ? $hoy['peso_max'] - $hoy['peso'] : 0.0,
            'gatera' => ($hoy['gatera'] ?: 7) / max(1, $hoy['corredores']),
            'mismo_jockey' => $u && $u['jockey'] === $hoy['jockey'] ? 1.0 : 0.0,
            'mismo_cuidador' => $u && $u['cuidador'] === $hoy['cuidador'] ? 1.0 : 0.0,
            'frente_rivales' => $hoy['frente'] ?? 0.0,
            'mercado_ultima' => $mercado ? log($mercado[0]) : self::SIN_MERCADO,
            'mercado_prom' => $mercado ? array_sum(array_map('log', $mercado)) / count($mercado) : self::SIN_MERCADO,
            'sin_mercado' => $mercado ? 0.0 : 1.0,
            'padre_gana' => $this->tasa('p|' . ($hoy['padre'] ?? ''), $t, 0, 30),
            'padre_place' => $this->tasa('p|' . ($hoy['padre'] ?? ''), $t, 1, 30),
            'padre_tramo' => $this->tasa('pt|' . ($hoy['padre'] ?? '') . "|{$hoy['superficie']}|$tramo", $t, 0, 30),
            'abuelo_gana' => $this->tasa('a|' . ($hoy['abuelo'] ?? ''), $t, 0, 30),
            'madre_gana' => $this->tasa('m|' . ($hoy['madre'] ?? ''), $t, 0, 30),
            'gatera_gana' => $this->tasa(self::claveGatera($hoy), $t, 0, 50),
            'gatera_place' => $this->tasa(self::claveGatera($hoy), $t, 1, 50),
            'jockey_gana' => $this->tasa('j|' . $hoy['jockey'], $t, 0),
            'cuidador_gana' => $this->tasa('c|' . $hoy['cuidador'], $t, 0),
            'jockey_gana_tramo' => $this->tasa("j|{$hoy['jockey']}|{$hoy['hipodromo']}|$tramo", $t, 0),
            'cuidador_gana_tramo' => $this->tasa("c|{$hoy['cuidador']}|{$hoy['hipodromo']}|$tramo", $t, 0),
            'jockey_place' => $this->tasa('j|' . $hoy['jockey'], $t, 1),
            'cuidador_place' => $this->tasa('c|' . $hoy['cuidador'], $t, 1),
            'jockey_extremo' => $e ? $this->tasa("je|{$hoy['jockey']}|$e", $t, 0) : 0.0,
            'cuidador_extremo' => $e ? $this->tasa("ce|{$hoy['cuidador']}|$e", $t, 0) : 0.0,
            ...$this->debut($ant ? null : $hoy, $t),
            'caballo_lugar' => self::rindeLugar($ant, (int)$hoy['hipodromo'], $hoy['superficie']),
        ];
    }

    private static function rindeLugar(array $ant, int $hip, string $sup): float
    {
        $prom = ModeloUy::ponderado(array_values(array_filter(array_column($ant, 'indice'), fn ($v) => $v !== null)));
        if ($prom === null) {
            return 0.0;
        }
        $suma = $n = 0.0;
        foreach ($ant as $a) {
            if ($a['indice'] !== null && (int)$a['hipodromo'] === $hip && $a['superficie'] === $sup) {
                $suma += $a['indice'] - $prom;
                $n++;
            }
        }
        return $suma / ($n + 3);
    }

    private function debut(?array $hoy, int $t): array
    {
        $r = [];
        foreach (['jockey' => 'jd', 'cuidador' => 'cd', 'padre' => 'pd', 'madre' => 'md', 'abuelo' => 'ad'] as $quien => $pre) {
            $r[$quien . '_debut'] = $hoy !== null ? $this->tasa("$pre|" . ($hoy[$quien] ?? ''), $t, 0, 15) : 0.0;
        }
        return $r;
    }

    // ---------------------------------------------------------------- jockeys, cuidadores, pedigrí y gateras

    private function tasa(string $k, int $t, int $col, int $previas = 15): float
    {
        [$g, $pl, $r] = $this->decaido($k, $t);
        $prior = $col === 0 ? 1 / 12 : 0.25;
        return (($col === 0 ? $g : $pl) + $previas * $prior) / ($r + $previas);
    }

    private function decaido(string $k, int $t): array
    {
        if (!isset($this->est[$k])) {
            return [0.0, 0.0, 0.0];
        }
        [$g, $pl, $r, $t0] = $this->est[$k];
        $f = 0.5 ** (($t - $t0) / (self::MEDIA_VIDA_LARGA[strstr($k, '|', true)] ?? self::MEDIA_VIDA_DIAS));
        return [$g * $f, $pl * $f, $r * $f];
    }

    private function sumar(array $f, int $t): void
    {
        $tramo = Clasificador::tramo($f['dist']);
        $claves = ["j|{$f['jockey']}", "c|{$f['cuidador']}", "j|{$f['jockey']}|{$f['hip']}|$tramo", "c|{$f['cuidador']}|{$f['hip']}|$tramo"];
        foreach (['p' => $f['padre'], 'a' => $f['abuelo'], 'm' => $f['madre']] as $tipo => $nombre) {
            if ($nombre !== '') {
                $claves[] = "$tipo|$nombre";
            }
        }
        if ($f['padre'] !== '') {
            $claves[] = "pt|{$f['padre']}|{$f['sup']}|$tramo";
        }
        if ($e = self::extremo($f['dist'])) {
            array_push($claves, "je|{$f['jockey']}|$e", "ce|{$f['cuidador']}|$e");
        }
        if (!empty($f['debut']) && $f['corrio']) {
            array_push($claves, "jd|{$f['jockey']}", "cd|{$f['cuidador']}");
            foreach (['pd' => $f['padre'], 'md' => $f['madre'], 'ad' => $f['abuelo']] as $tipo => $nombre) {
                if ($nombre !== '') {
                    $claves[] = "$tipo|$nombre";
                }
            }
        }
        if ($f['corrio'] && (int)$f['gatera'] > 0) {
            $claves[] = self::claveGatera(['hipodromo' => $f['hip'], 'distancia' => $f['dist'], 'superficie' => $f['sup'], 'gatera' => $f['gatera']]);
        }
        $puesto = $f['puesto'];
        foreach ($claves as $k) {
            [$g, $pl, $r] = $this->decaido($k, $t);
            $this->est[$k] = [$g + ($puesto === 1 ? 1 : 0), $pl + ($puesto !== null && $puesto <= 3 ? 1 : 0), $r + 1, $t];
        }
    }

    private static function claveGatera(array $c): string
    {
        return "g|{$c['hipodromo']}|{$c['distancia']}|{$c['superficie']}|" . (int)$c['gatera'];
    }

    public static function persona(?string $nombre): string
    {
        return Personas::clave($nombre);
    }

    // ---------------------------------------------------------------- carreras de la base

    private const SQL = 'SELECT a.carrera_id, a.caballo_id, r.fecha, r.hipodromo_id, c.distancia, a.puesto, a.tiempo_seg,
            a.peso_real AS peso_asignado, a.numero AS gatera, IF(a.retiro_motivo IS NULL, a.cuota, NULL) AS cuota,
            j.nombre AS jockey, cu.nombre AS cuidador, ca.padre, ca.madre, ca.abuelo_materno
        FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id
        JOIN caballo ca ON ca.id = a.caballo_id
        LEFT JOIN persona j ON j.id = a.jockey_id LEFT JOIN persona cu ON cu.id = a.cuidador_id';

    /**
     * Fila de la base → corredor con su índice y el nivel de su carrera, en la escala de la Gávea.
     */
    private function fila(array $f): array
    {
        $c = $this->vel->carrera((int)$f['carrera_id']);
        $hip = (int)$f['hipodromo_id'];
        $dist = (int)$f['distancia'];
        $sup = $c['superficie'] ?? 'arena';
        $indice = $nivelPar = null;
        $gen = $c && $dist ? $this->vel->parGeneral($hip, $dist, $sup) : null;
        if ($gen) {
            $loc = $f['tiempo_seg'] !== null ? $this->vel->indiceLocal($hip, $dist, $sup, $c['pista'], $c['variante'], (float)$f['tiempo_seg']) : null;
            $indice = $loc !== null ? $loc - $this->vel->equivalencia($hip) : null;
            $par = $this->vel->par($hip, $dist, $sup, $c['nivel']);
            $nivelPar = $par ? $par['velocidad'] - $gen['velocidad'] - $this->vel->equivalencia($hip) : null;
        }
        return ['carrera_id' => (int)$f['carrera_id'], 'caballo_id' => (int)$f['caballo_id'], 'fecha' => $f['fecha'], 'hip' => $hip,
            'dist' => $dist, 'sup' => $sup, 'puesto' => $f['puesto'] !== null ? (int)$f['puesto'] : null, 'indice' => $indice,
            'nivel_par' => $nivelPar, 'peso' => $f['peso_asignado'] !== null ? (float)$f['peso_asignado'] : null, 'gatera' => $f['gatera'],
            'jockey' => self::persona($f['jockey']), 'cuidador' => self::persona($f['cuidador']), 'cuota' => $f['cuota'],
            'corrio' => $f['cuota'] !== null]
            + ModeloUy::pedigri($f['padre'], $f['madre'], $f['abuelo_materno']);
    }

    /** Antecedente para rasgos() a partir de una fila y la probabilidad que le daba el mercado. */
    private static function antecedente(array $f, ?float $mercado): array
    {
        return ['carrera' => $f['carrera_id'], 'fecha' => $f['fecha'], 'indice' => $f['indice'], 'distancia' => $f['dist'],
            'superficie' => $f['sup'], 'puesto' => $f['puesto'], 'nivel_par' => $f['nivel_par'], 'jockey' => $f['jockey'],
            'cuidador' => $f['cuidador'], 'hipodromo' => $f['hip'], 'mercado' => $mercado];
    }

    /** Probabilidad que daban las cuotas a cada corredor que tenía cuota: (1/cuota) / Σ(1/cuota). */
    private static function mercado(array $carrera): array
    {
        return ModeloUy::probabilidadesMercado(array_map(fn ($f) => (float)$f['cuota'], array_column($carrera, null, 'caballo_id')));
    }

    /**
     * Estadísticas de jockeys, cuidadores, pedigrí y gateras con todo lo anterior a $fecha, y los antecedentes de los
     * caballos pedidos (más reciente primero) con su índice. Para el programa.
     * @param int[] $caballos
     * @return array<int, list<array>> caballo => antecedentes
     */
    public function preparar(string $fecha, array $caballos): array
    {
        $this->est = [];
        $quiero = array_flip($caballos);
        $hist = [];
        $corrio = [];
        $st = $this->db->prepare(self::SQL . ' WHERE r.fecha < ? ORDER BY r.fecha, a.carrera_id');
        $st->execute([$fecha]);
        $carrera = [];
        $cerrar = function (array $carrera) use (&$hist, &$corrio, $quiero) {
            $mercado = self::mercado($carrera);
            foreach ($carrera as $f) {
                $this->sumar($f + ['debut' => !isset($corrio[$f['caballo_id']])], self::dia($f['fecha']));
                $corrio[$f['caballo_id']] = true;
                if (isset($quiero[$f['caballo_id']])) {
                    array_unshift($hist[$f['caballo_id']], self::antecedente($f, $mercado[$f['caballo_id']] ?? null));
                }
            }
        };
        foreach (array_keys($quiero) as $id) {
            $hist[$id] = [];
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if ($carrera && $carrera[0]['carrera_id'] !== (int)$f['carrera_id']) {
                $cerrar($carrera);
                $carrera = [];
            }
            $carrera[] = $this->fila($f);
        }
        if ($carrera) {
            $cerrar($carrera);
        }
        return $hist;
    }

    // ---------------------------------------------------------------- entrenamiento

    /** Como ModeloUy::entrenar(): ajusta con las carreras desde $desde, mide y calibra desde $prueba (por defecto, los últimos 2 años). */
    public function entrenar(string $desde = '2017-01-01', ?string $prueba = null): array
    {
        $this->est = [];
        $hist = [];
        $X = $y = $grupo = $fechas = $puesto = [];
        $puestos = [];
        $st = $this->db->query(self::SQL . ' ORDER BY r.fecha, a.carrera_id');
        $procesar = function (array $carrera) use (&$hist, &$X, &$y, &$grupo, &$fechas, &$puesto, &$puestos, $desde) {
            $f0 = $carrera[0];
            $t = self::dia($f0['fecha']);
            $llegaron = array_values(array_filter($carrera, fn ($f) => $f['corrio']));
            $ganadores = count(array_filter($llegaron, fn ($f) => $f['puesto'] === 1));
            if ($f0['fecha'] >= $desde && count($llegaron) >= self::MIN_CORREDORES && $ganadores === 1) {
                $pesoMax = max(array_map(fn ($f) => (float)$f['peso'], $llegaron));
                $hoy = array_column($llegaron, 'puesto', 'caballo_id');
                foreach ($llegaron as $f) {
                    $enf = [];
                    foreach ($hist[$f['caballo_id']] ?? [] as $a) {
                        $mio = $puestos[$a['carrera']][$f['caballo_id']] ?? null;
                        foreach ($mio !== null ? array_intersect_key($puestos[$a['carrera']], $hoy) : [] as $otro => $suyo) {
                            if ($otro !== $f['caballo_id'] && $suyo !== null && $suyo !== $mio) {
                                $enf[] = ['fecha' => $a['fecha'], 'gano' => $mio < $suyo];
                            }
                        }
                    }
                    $X[] = self::vector($this->rasgos($hist[$f['caballo_id']] ?? [], [
                        'fecha' => $f['fecha'], 'hipodromo' => $f['hip'], 'distancia' => $f['dist'], 'superficie' => $f['sup'],
                        'nivel_par' => $f['nivel_par'], 'peso' => (float)$f['peso'], 'peso_max' => $pesoMax,
                        'gatera' => (int)$f['gatera'], 'corredores' => count($llegaron), 'jockey' => $f['jockey'], 'cuidador' => $f['cuidador'],
                        'frente' => ModeloUy::frente($enf, $f['fecha'])] + $f));
                    $y[] = $f['puesto'] === 1 ? 1 : 0;
                    $puesto[] = $f['puesto'] ?? 99;
                    $grupo[] = $f0['carrera_id'];
                    $this->caballoDeFila[] = $f['caballo_id'];
                    $fechas[] = $f0['fecha'];
                }
            }
            $puestos[$f0['carrera_id']] = array_column($carrera, 'puesto', 'caballo_id');
            $mercado = self::mercado($carrera);
            foreach ($carrera as $f) {
                $this->sumar($f + ['debut' => empty($hist[$f['caballo_id']])], $t);
                $hist[$f['caballo_id']] ??= [];
                array_unshift($hist[$f['caballo_id']], self::antecedente($f, $mercado[$f['caballo_id']] ?? null));
            }
        };
        $carrera = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if ($carrera && $carrera[0]['carrera_id'] !== (int)$f['carrera_id']) {
                $procesar($carrera);
                $carrera = [];
            }
            $carrera[] = $this->fila($f);
        }
        if ($carrera) {
            $procesar($carrera);
        }
        $prueba ??= date('Y-m-d', strtotime(max($fechas) . ' -2 years'));
        $res = ['carreras' => count(array_unique($grupo)), 'prueba' => $prueba];
        $ent = array_keys(array_filter($fechas, fn ($f) => $f < $prueba));
        $pru = array_keys(array_filter($fechas, fn ($f) => $f >= $prueba));
        $this->ajustar($X, $y, $grupo, $ent);
        $this->calibrar($X, $y, $grupo, $pru);
        $res += $this->medir($X, $y, $puesto, $grupo, $pru) + ['escala' => $this->escala];
        $res['favorito'] = $this->aciertoFavorito($pru, $grupo);
        $this->ajustar($X, $y, $grupo, array_keys($y));
        $this->guardar();
        return $res;
    }

    /** Cuántas carreras de prueba ganó el favorito de las cuotas (para comparar). */
    private function aciertoFavorito(array $filas, array $grupo): float
    {
        $ids = array_values(array_unique(array_map(fn ($i) => $grupo[$i], $filas)));
        if (!$ids) {
            return 0.0;
        }
        $ok = 0;
        foreach (array_chunk($ids, 1000) as $lote) {
            $st = $this->db->query('SELECT carrera_id, puesto FROM actuacion a WHERE carrera_id IN (' . implode(',', $lote) . ')
                AND cuota = (SELECT MIN(cuota) FROM actuacion b WHERE b.carrera_id = a.carrera_id)');
            $porCarrera = [];
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$c, $p]) {
                $porCarrera[$c][] = (int)$p;
            }
            foreach ($porCarrera as $ps) {
                $ok += in_array(1, $ps, true) ? 1 / count($ps) : 0;
            }
        }
        return $ok / count($ids);
    }

    private static function dia(string $fecha): int
    {
        return intdiv(strtotime($fecha . ' 12:00:00 UTC'), 86400);
    }
}
