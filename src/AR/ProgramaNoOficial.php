<?php
declare(strict_types=1);

namespace GranChance\AR;

use GranChance\{Modelo as ModeloUy, ProgramaNoOficial as ProgramaUy};
use GranChance\Fichas;
use PDO;

/**
 * Programa no oficial de una reunión de Argentina, como el de Uruguay, Hong Kong y Brasil: cada carrera del programa del
 * Stud Book con los antecedentes de cada caballo, su velocidad corregida por pista y recorrido, el índice contra el par,
 * la proyección y la probabilidad de ganar del modelo (AR\Modelo).
 *
 * Los caballos, jockeys y entrenadores se cruzan con la base por su id del Stud Book. Los índices se muestran en la
 * escala del hipódromo de hoy.
 */
final class ProgramaNoOficial
{
    public const ANTECEDENTES = 6;
    // Ids de granchance_ar (los del respaldo hipodromos/ar/granchance_ar.sql.gz). En la web son 200 + id (AR\Publicador).
    public const NOMBRES = [1 => 'San Isidro', 2 => 'Palermo', 3 => 'La Plata', 4 => 'Tandil', 5 => 'Rosario', 6 => 'La Punta', 7 => 'Azul',
        29 => 'Santa Fe', 32 => 'Dolores', 35 => 'Tucumán', 38 => 'Córdoba', 41 => 'Río Cuarto', 44 => 'Venado Tuerto', 47 => 'San Francisco',
        50 => 'Villa María', 53 => 'Santa Rosa', 56 => 'Gualeguay', 59 => 'Gualeguaychú'];
    public const SIGLAS = [1 => 'SIS', 2 => 'PAL', 3 => 'LP', 4 => 'TAN', 5 => 'ROS', 6 => 'SLU', 7 => 'AZL', 29 => 'SFE', 32 => 'DOL', 35 => 'TUC',
        38 => 'CBA', 41 => 'RCU', 44 => 'VTU', 47 => 'SFC', 50 => 'VMA', 53 => 'SRO', 56 => 'GGY', 59 => 'GCH'];
    private const ZONA = 'America/Argentina/Buenos_Aires';

    public function __construct(private PDO $db, private Velocidades $vel, private Modelo $modelo)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array $prog salida de ParserResultados::reunion() de la página de la reunión, todavía con el programa
     */
    public function armar(array $prog, int $hip): array
    {
        $fecha = $prog['fecha'];
        $carreras = [];
        foreach ($prog['carreras'] as $pc) {
            $carreras[] = self::carrera($pc, $fecha);
        }
        $ids = $this->caballos($carreras);
        $hist = $this->modelo->preparar($fecha, array_values(array_unique($ids)));
        $res = [];
        foreach ($carreras as $c) {
            $c['hip'] = $hip;
            $c['nivel'] = Velocidades::nivel($c['categoria'], $c['condicion'], $c['grupo']);
            // Una condicional abierta (sin tope de carreras ganadas) no tiene nivel ("Otras"): se compara con el par de todos los niveles.
            $c['par'] = $this->vel->par($hip, (int)$c['distancia'], $c['superficie'], $c['nivel'] === 'Otras' ? '*' : $c['nivel']);
            $c['par_general'] = $this->vel->parGeneral($hip, (int)$c['distancia'], $c['superficie']);
            $eqHoy = $this->vel->equivalencia($hip);
            // Nivel de hoy en la escala de Palermo, como lo usa el modelo.
            $nivelHoy = $c['par'] && $c['par_general'] ? $c['par']['velocidad'] - $c['par_general']['velocidad'] - $eqHoy : null;
            $corren = array_filter($c['caballos'], fn ($h) => !$h['retirado']);
            $pesoTop = max(array_map(fn ($h) => (float)$h['peso'], $corren ?: [['peso' => 0]]));
            foreach ($c['caballos'] as &$h) {
                $h['caballo_id'] = $ids[$h['clave']] ?? null;
                $h['hist'] = $h['caballo_id'] ? $hist[$h['caballo_id']] ?? [] : [];
                $h['antecedentes'] = $h['caballo_id'] ? $this->antecedentes($h['caballo_id'], $fecha, $hip) : [];
                $h += $this->resumen($h, $c, $eqHoy, $nivelHoy, $pesoTop);
            }
            unset($h);
            $this->frente($c, $fecha);
            $this->probabilidades($c, $fecha, $nivelHoy, $pesoTop);
            $res[] = $c;
        }
        return ['fecha' => $fecha, 'hipodromo' => $hip, 'nro_reunion' => $prog['nro_reunion'], 'carreras' => $res];
    }

    /** Una carrera del programa con sus caballos en la forma que usa el programa no oficial. */
    private static function carrera(array $pc, string $fecha): array
    {
        $c = [
            'numero' => $pc['numero'], 'hora' => $pc['hora'] ? substr($pc['hora'], 11, 5) : null,
            'premio' => $pc['premio'], 'categoria' => $pc['categoria'], 'grupo' => $pc['grupo'], 'condicion' => $pc['condicion'],
            'distancia' => $pc['distancia'], 'superficie' => Velocidades::superficie($pc['superficie']), 'recorrido' => $pc['recorrido'],
            'pista' => $pc['pista'], 'bolsa' => $pc['bolsa'], 'fecha' => $fecha, 'caballos' => [],
        ];
        foreach ($pc['actuaciones'] as $a) {
            $retirado = $a['retiro_motivo'] !== null;
            $peso = $a['peso_asignado'] !== null ? $a['peso_asignado'] - (float)$a['descargo'] : null;
            $c['caballos'][] = [
                'numero' => $a['gatera'], 'nombre' => $a['nombre'], 'retirado' => $retirado, 'clave' => (string)$a['sb_id'],
                'edad' => $a['edad'], 'sexo' => $a['sexo'], 'pelaje' => $a['pelaje'], 'estreante' => $a['debutante'],
                'peso' => $peso, 'peso_asignado' => $a['peso_asignado'], 'descargo' => $a['descargo'],
                'gatera' => $a['gatera'], 'jockey' => $retirado ? null : ($a['jockey']['nombre'] ?? null),
                'jockey_codigo' => $retirado ? null : ($a['jockey']['codigo'] ?? null),
                'cuidador' => $a['cuidador']['nombre'] ?? null, 'cuidador_codigo' => $a['cuidador']['codigo'] ?? null,
                'stud' => $a['stud']['nombre'] ?? null, 'stud_codigo' => $a['stud']['codigo'] ?? null,
                'criador' => $a['criador']['nombre'] ?? null, 'criador_codigo' => $a['criador']['codigo'] ?? null, 'peso_caballo' => null,
                'pedigri' => ['padre' => $a['padre_nombre'], 'madre' => $a['madre_nombre'], 'abuelo_materno' => null],
            ];
        }
        return $c;
    }

    /** @return array<string, int> id del Stud Book => caballo_id. De paso completa el abuelo materno con la base. */
    private function caballos(array &$carreras): array
    {
        $st = $this->db->prepare('SELECT id, padre, madre, abuelo_materno FROM caballo WHERE sb_id = ?');
        $ids = [];
        foreach ($carreras as &$c) {
            foreach ($c['caballos'] as &$h) {
                $st->execute([(int)$h['clave']]);
                if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
                    $ids[$h['clave']] = (int)$f['id'];
                    $h['pedigri'] = ['padre' => $f['padre'] ?? $h['pedigri']['padre'], 'madre' => $f['madre'] ?? $h['pedigri']['madre'],
                        'abuelo_materno' => $f['abuelo_materno']];
                }
            }
            unset($h);
        }
        unset($c);
        return $ids;
    }

    private function antecedentes(int $caballo, string $fecha, int $hipHoy): array
    {
        $st = $this->db->prepare(
            'SELECT a.carrera_id, r.fecha, r.hipodromo_id, c.numero, c.distancia, c.superficie, c.pista, c.recorrido, c.categoria, c.condicion,
                    c.grupo, c.premio, c.tiempo_ganador, a.puesto, a.puesto_texto, a.tiempo_seg, a.gatera, a.peso_caballo, a.peso_asignado,
                    a.dist_acumulada, a.sport AS cuota, j.nombre AS jockey, j.codigo AS jockey_codigo,
                    (SELECT COUNT(*) FROM actuacion x WHERE x.carrera_id = a.carrera_id AND x.retiro_motivo IS NULL) AS corredores
             FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id
             LEFT JOIN persona j ON j.id = a.jockey_id
             WHERE a.caballo_id = ? AND r.fecha < ? AND a.retiro_motivo IS NULL ORDER BY r.fecha DESC, c.numero DESC'
        );
        $st->execute([$caballo, $fecha]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) {
            return [];
        }
        $podio = $this->podio(array_column($filas, 'carrera_id'));
        foreach ($filas as &$f) {
            $hip = (int)$f['hipodromo_id'];
            $dist = (int)$f['distancia'];
            $sup = Velocidades::superficie($f['superficie']);
            $pista = Velocidades::pista($f['pista']);
            $variante = Velocidades::variante($f['recorrido']);
            $f['superficie'] = $sup;
            $f['nivel'] = Velocidades::nivel($f['categoria'], $f['condicion'], $f['grupo']);
            $f['primero'] = $podio[$f['carrera_id']][1] ?? null;
            $f['segundo'] = $podio[$f['carrera_id']][2] ?? null;
            $f['velocidad'] = Velocidades::kmh($dist, $f['tiempo_seg'] !== null ? (float)$f['tiempo_seg'] : null);
            $aj = $this->vel->ajuste($hip, $sup, $dist, $pista, $variante);
            $f['ajuste'] = $aj;
            $f['vel_corregida'] = $f['velocidad'] !== null && $aj !== null ? $f['velocidad'] - $aj : null;
            $par = $this->vel->par($hip, $dist, $sup, $f['nivel']);
            $gen = $this->vel->parGeneral($hip, $dist, $sup);
            $f['idx_categoria'] = $f['vel_corregida'] !== null && $par ? $f['vel_corregida'] - $par['velocidad'] : null;
            $eq = $this->vel->equivalencia($hipHoy) - $this->vel->equivalencia($hip);
            $f['indice'] = $f['vel_corregida'] !== null && $gen ? $f['vel_corregida'] - $gen['velocidad'] + $eq : null;
        }
        unset($f);
        return $filas;
    }

    /** @return array<int, array<int, string>> carrera => puesto => caballo (1º y 2º) */
    private function podio(array $carreras): array
    {
        $carreras = array_values(array_unique(array_map('intval', $carreras)));
        $st = $this->db->query('SELECT a.carrera_id, a.puesto, ca.nombre FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id
            WHERE a.carrera_id IN (' . implode(',', $carreras) . ') AND a.puesto IN (1, 2)');
        $res = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $res[(int)$r['carrera_id']][(int)$r['puesto']] ??= $r['nombre'];
        }
        return $res;
    }

    private function resumen(array $h, array $c, float $eqHoy, ?float $nivelHoy, float $pesoTop): array
    {
        $r = ['dias' => null, 'promedio' => null, 'ultimo' => null, 'en_distancia' => null, 'proyeccion' => null,
            'vs_par_hoy' => null, 'cambio_jockey' => false, 'ultimas6' => '', 'notas' => []];
        $ant = $h['antecedentes'];
        if ($h['retirado']) {
            return $r;
        }
        if (!$ant) {
            $r['notas'][] = $h['estreante'] ? 'Debutante' : 'Sin carreras en la base (corrió en el exterior o antes de 2015)';
            return $r + self::notasHoy($h);
        }
        $u = $ant[0];
        $r['ultimas6'] = implode('-', array_map(fn ($a) => $a['puesto'] ?? '0', array_slice($ant, 0, 6)));
        $r['dias'] = (int)((strtotime($c['fecha']) - strtotime($u['fecha'])) / 86400);
        $p = ModeloUy::proyeccion($h['hist'], (int)$c['distancia'], $c['superficie']);
        foreach (['promedio', 'ultimo', 'en_distancia', 'proyeccion'] as $k) {
            $r[$k] = $p[$k] !== null ? $p[$k] + $eqHoy : null;
        }
        if ($p['proyeccion'] !== null && $nivelHoy !== null) {
            $r['vs_par_hoy'] = $p['proyeccion'] - $nivelHoy;
        }
        $r['cambio_jockey'] = $u['jockey_codigo'] && $u['jockey_codigo'] !== $h['jockey_codigo'];
        if (!in_array($c['hip'], array_map('intval', array_column($ant, 'hipodromo_id')), true)) {
            $r['notas'][] = 'Primera vez en ' . (self::NOMBRES[$c['hip']] ?? 'este hipódromo');
        }
        if (!in_array($c['superficie'], array_column($ant, 'superficie'), true)) {
            $r['notas'][] = 'Primera vez en ' . $c['superficie'];
        }
        if ($r['dias'] > 60) {
            $r['notas'][] = "Hace {$r['dias']} días que no corre";
        }
        if (max(array_map(fn ($a) => (int)$a['distancia'], $ant)) < (int)$c['distancia']) {
            $r['notas'][] = 'Nunca corrió tan largo';
        }
        if ($u['nivel'] !== $c['nivel'] && $c['nivel'] !== 'Otras' && $u['nivel'] !== 'Otras') {
            $r['notas'][] = "Viene de {$u['nivel']}";
        }
        if ($r['cambio_jockey']) {
            $juntos = array_filter($ant, fn ($a) => $a['jockey_codigo'] && $a['jockey_codigo'] === $h['jockey_codigo']);
            if ($juntos) {
                $gan = count(array_filter($juntos, fn ($a) => (int)$a['puesto'] === 1));
                $pla = count(array_filter($juntos, fn ($a) => $a['puesto'] !== null && (int)$a['puesto'] <= 3));
                $r['notas'][] = sprintf('Vuelve con %s (%d %s juntos%s)', $h['jockey'], count($juntos), count($juntos) > 1 ? 'carreras' : 'carrera',
                    $pla ? ": $gan " . ($gan === 1 ? 'ganada' : 'ganadas') . ", $pla en el marcador" : '');
            }
        }
        if ($h['peso'] && $u['peso_asignado'] && (float)$h['peso'] - (float)$u['peso_asignado'] >= 2) {
            $r['notas'][] = sprintf('Sube %s kg', self::num((float)$h['peso'] - (float)$u['peso_asignado'], 1));
        } elseif ($h['peso'] && $u['peso_asignado'] && (float)$u['peso_asignado'] - (float)$h['peso'] >= 2) {
            $r['notas'][] = sprintf('Baja %s kg', self::num((float)$u['peso_asignado'] - (float)$h['peso'], 1));
        }
        if ($h['peso'] && $pesoTop - (float)$h['peso'] >= 3) {
            $r['notas'][] = sprintf('%s kg menos que el más cargado', self::num($pesoTop - (float)$h['peso'], 1));
        }
        if ($h['peso_caballo'] && $u['peso_caballo'] && abs($h['peso_caballo'] - (int)$u['peso_caballo']) >= 10) {
            $r['notas'][] = sprintf('Peso del caballo %+d kg respecto de su última', $h['peso_caballo'] - (int)$u['peso_caballo']);
        }
        return $r + self::notasHoy($h);
    }

    /** Notas que salen solo del programa de hoy: el aprendiz y su descargo. */
    private static function notasHoy(array $h): array
    {
        $n = [];
        if ($h['descargo']) {
            $n[] = sprintf('Aprendiz: descarga %s kg', self::num((float)$h['descargo'], 1));
        }
        return ['notas_hoy' => $n];
    }

    /** Cómo le fue contra los rivales de hoy cuando corrieron juntos. */
    private function frente(array &$c, string $fecha): void
    {
        $puestos = [];
        foreach ($c['caballos'] as $h) {
            if (!$h['retirado'] && $h['caballo_id']) {
                foreach ($h['hist'] as $a) {
                    $puestos[$a['carrera']][$h['clave']] = [$a['puesto'], $a['fecha']];
                }
            }
        }
        $nombre = array_column($c['caballos'], 'nombre', 'clave');
        foreach ($c['caballos'] as &$h) {
            $h['frente'] = [];
            $enf = [];
            foreach ($puestos as $carrera) {
                [$mio, $f] = $carrera[$h['clave']] ?? [null, null];
                if ($mio === null) {
                    continue;
                }
                foreach ($carrera as $otro => [$suyo]) {
                    if ($otro !== $h['clave'] && $suyo !== null && $suyo !== $mio) {
                        $gano = $mio < $suyo;
                        $enf[] = ['fecha' => $f, 'gano' => $gano];
                        $h['frente'][$otro][$gano ? 0 : 1] = ($h['frente'][$otro][$gano ? 0 : 1] ?? 0) + 1;
                    }
                }
            }
            $h['frente_indice'] = ModeloUy::frente($enf, $fecha);
            if ($h['frente'] && !$h['retirado']) {
                $txt = [];
                foreach ($h['frente'] as $otro => $gp) {
                    $txt[] = sprintf('%s %d-%d', $nombre[$otro], $gp[0] ?? 0, $gp[1] ?? 0);
                }
                $h['notas'][] = 'Frente a rivales de hoy: ' . implode(', ', $txt);
            }
        }
        unset($h);
    }

    private function probabilidades(array &$c, string $fecha, ?float $nivelHoy, float $pesoTop): void
    {
        $corren = array_filter($c['caballos'], fn ($h) => !$h['retirado']);
        $rasgos = [];
        foreach ($corren as $k => $h) {
            $rasgos[$k] = $this->modelo->rasgos($h['hist'], [
                'fecha' => $fecha, 'hipodromo' => $c['hip'], 'distancia' => (int)$c['distancia'], 'superficie' => $c['superficie'],
                'nivel_par' => $nivelHoy, 'peso' => (float)$h['peso'], 'peso_max' => $pesoTop, 'gatera' => (int)$h['gatera'],
                'corredores' => count($corren), 'jockey' => Modelo::persona($h['jockey_codigo']), 'cuidador' => Modelo::persona($h['cuidador_codigo']),
                'frente' => $h['frente_indice']] + ModeloUy::pedigri($h['pedigri']['padre'], $h['pedigri']['madre'], $h['pedigri']['abuelo_materno']));
        }
        $p = $this->modelo->probabilidades($rasgos);
        foreach ($c['caballos'] as $k => &$h) {
            $h['prob'] = $p[$k] ?? null;
        }
        unset($h);
    }

    // ---------------------------------------------------------------- HTML

    public function html(array $p, string $actualizado): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $titulo = sprintf('Programa no oficial · %s · %s', self::NOMBRES[$p['hipodromo']] ?? 'Argentina', date('d/m/Y', strtotime($p['fecha'])));
        $o = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($titulo) . '</title><style>' . ProgramaUy::CSS . self::CSS . '</style></head><body>';
        $o .= '<nav class="navegacion"><div class="fila carreras">Carreras:';
        foreach ($p['carreras'] as $c) {
            $o .= ' <a href="#carrera-' . $c['numero'] . '" title="' . $e($c['distancia'] . ' m · ' . ($c['categoria'] ?? '') . ' · ' . $c['premio']) . '">' . $c['numero'] . 'º <small>' . ProgramaUy::hora($p['fecha'], $c['hora'], self::ZONA) . '</small></a>';
        }
        $o .= '</div></nav>';
        $o .= '<header><h1>' . $e($titulo) . '</h1><p>Horas de Argentina (la página las pasa a tu zona horaria). Programa del Stud Book Argentino leído el ' . $e($actualizado) . '. Velocidades en km/h. '
            . '<b>Par</b>: promedio de los 4 primeros en pista normal para esa distancia, superficie y nivel (Grupo, Listado, Handicap, clásicos y especiales, y en las condicionales las carreras ganadas que permite la condición). '
            . 'El Stud Book publica solo el tiempo del ganador: el de los demás sale de los cuerpos al ganador. '
            . '<b>Ajuste</b>: estado de la pista (húmeda, pesada, fangosa) y recorrido (codo, codo nuevo, recta, diagonal), medidos con los datos. '
            . '<b>Índice</b>: velocidad corregida menos el par general de esa distancia (0 = normal), llevado a ' . $e(self::NOMBRES[$p['hipodromo']] ?? 'este hipódromo') . '. '
            . '<b>Historia</b>: promedio de los índices de toda la campaña desde 2015, pesando más las últimas carreras. <b>Proyección</b>: (3 × historia + último + 2 × historia en la distancia) / 6. '
            . '<b>vs par de hoy</b>: la proyección comparada con los 4 primeros normales del nivel de esta carrera. '
            . '<b>Prob.</b>: probabilidad de ganar del mismo modelo que el de Uruguay, entrenado con las carreras de Argentina; el resumen se ordena por ella. '
            . '<b>Fecha en negrita</b>: en esa carrera también corrieron otros caballos de hoy. <b>Sport</b>: dividendo a ganador por $ 1.</p></header>';
        foreach ($p['carreras'] as $c) {
            $o .= $this->htmlCarrera($c, $e);
        }
        return $o . ProgramaUy::JS_HORAS . ProgramaUy::JS_ANCHO . '</body></html>';
    }

    private function htmlCarrera(array $c, \Closure $e): string
    {
        $o = '<section class="carrera" id="carrera-' . $c['numero'] . '"><h2>' . $c['numero'] . 'ª carrera · ' . ProgramaUy::hora($c['fecha'], $c['hora'], self::ZONA) . ' · ' . $e($c['distancia']) . ' m en ' . $e($c['superficie'])
            . ($c['pista'] ? ' · ' . $e($c['pista']) : '') . '</h2>';
        $o .= '<p class="premio"><b>' . $e($c['premio']) . '</b>' . ($c['categoria'] ? '. ' . $e($c['categoria']) : '')
            . ($c['recorrido'] ? '. Recorrido: ' . $e($c['recorrido']) : '') . ($c['bolsa'] ? '. Bolsa: $ ' . number_format((float)$c['bolsa'], 0, ',', '.') : '') . '</p>'
            . ($c['condicion'] ? '<p class="premio">' . $e($c['condicion']) . '</p>' : '');
        $o .= '<p class="par">Nivel: <b>' . $e($c['nivel']) . '</b>';
        if ($c['par']) {
            $o .= ' · Par: <b>' . self::num($c['par']['velocidad']) . '</b> (' . $e($c['par']['categoria']) . ', ' . $c['par']['carreras'] . ' carreras)';
        }
        if ($c['par_general']) {
            $o .= ' · Par general de la distancia: ' . self::num($c['par_general']['velocidad']);
        }
        $o .= '</p>';
        $orden = $c['caballos'];
        usort($orden, fn ($a, $b) => [$a['retirado'], $b['prob'] ?? -1] <=> [$b['retirado'], $a['prob'] ?? -1]);
        $o .= '<table class="resumen"><thead><tr><th>Nº</th><th>Caballo</th><th>Kg</th><th>Últimas 6</th><th title="Índices de toda la campaña, pesando más las últimas carreras">Historia</th><th>Último</th>'
            . '<th>En la dist.</th><th>Proyección</th><th>vs par de hoy</th><th>Prob.</th><th>Días</th><th>Notas</th></tr></thead><tbody>';
        foreach ($orden as $h) {
            $notas = $h['retirado'] ? 'Retirado' : implode('. ', array_merge($h['notas'], $h['notas_hoy'] ?? []));
            $o .= '<tr' . ($h['retirado'] ? ' class="retirado"' : '') . '><td>' . ProgramaUy::mandil((string)$h['numero']) . '</td><td>' . Fichas::enlazar('caballo', $h['caballo_id'] ?? null, $h['nombre'], 'ar')
                . '</td><td>' . self::num($h['peso'], 1) . '</td><td>' . $e($h['ultimas6']) . '</td><td>' . self::idx($h['promedio']) . '</td><td>' . self::idx($h['ultimo'])
                . '</td><td>' . self::idx($h['en_distancia']) . '</td><td>' . self::idx($h['proyeccion']) . '</td><td class="fuerte">' . self::idx($h['vs_par_hoy']) . '</td><td class="fuerte">'
                . ($h['prob'] !== null ? self::num(100 * $h['prob'], 0) . ' %' : '') . '</td><td>' . $e($h['dias']) . '</td><td>' . $e($notas) . '</td></tr>';
        }
        $o .= '</tbody></table>';
        $juntos = self::juntos($c['caballos']);
        foreach ($c['caballos'] as $h) {
            $o .= $this->htmlCaballo($h, $juntos, $e);
        }
        return $o . '</section>';
    }

    private ?\PDOStatement $stPersona = null;

    /** El nombre de un jockey, entrenador, caballeriza o criador del programa con el enlace a su ficha (por su código del Stud Book). */
    private function persona(string $tipo, ?string $codigo, ?string $nombre): string
    {
        $id = null;
        if ((string)$codigo !== '') {
            $this->stPersona ??= $this->db->prepare('SELECT id FROM persona WHERE tipo = ? AND codigo = ?');
            $this->stPersona->execute([$tipo, $codigo]);
            $id = $this->stPersona->fetchColumn() ?: null;
        }
        return Fichas::enlazar($tipo, $id ? (int)$id : null, $nombre, 'ar');
    }

    private function htmlCaballo(array $h, array $juntos, \Closure $e): string
    {
        $ped = $h['pedigri'];
        $o = '<div class="caballo' . ($h['retirado'] ? ' retirado' : '') . '"><h3>' . ProgramaUy::mandil((string)$h['numero']) . ' ' . Fichas::enlazar('caballo', $h['caballo_id'] ?? null, $h['nombre'], 'ar')
            . ' <small>' . $e(trim(($h['pelaje'] ?? '') . ' ' . mb_strtolower((string)$h['sexo']) . ' ' . ($h['edad'] ? $h['edad'] . ' años' : ''))) . '</small></h3>';
        $o .= '<p>' . self::num($h['peso'], 1) . ' kg' . ($h['descargo'] ? ' (' . self::num((float)$h['peso_asignado'], 1) . ' − ' . self::num((float)$h['descargo'], 1) . ')' : '') . ' · Jockey: '
            . ($h['retirado'] ? 'Retirado' : $this->persona('jockey', $h['jockey_codigo'], $h['jockey']) . (($h['cambio_jockey'] ?? false) ? ' <span class="cambio">(cambió)</span>' : ''))
            . ' · Entrenador: ' . $this->persona('cuidador', $h['cuidador_codigo'], $h['cuidador'])
            . ($h['peso_caballo'] ? ' · Peso del caballo: ' . $e($h['peso_caballo']) . ' kg' : '')
            . ' · ' . $e(($ped['padre'] ?? '') . ' y ' . ($ped['madre'] ?? '') . ($ped['abuelo_materno'] ? ' por ' . $ped['abuelo_materno'] : ''))
            . ' · Caballeriza: ' . $this->persona('stud', $h['stud_codigo'], $h['stud']) . ($h['criador'] ? ' · Criador: ' . $this->persona('criador', $h['criador_codigo'], $h['criador']) : '') . '</p>';
        if (!$h['antecedentes']) {
            return $o . '<p class="debut">' . ($h['estreante'] ? 'Debutante' : 'Sin carreras en la base') . '</p></div>';
        }
        $o .= '<table class="ant"><thead><tr><th>Fecha</th><th>Hip.</th><th>P.</th><th>Ganador</th><th>Segundo</th><th>Cpos.</th><th>Dist.</th><th>Sup.</th>'
            . '<th>Pista</th><th>T. ganador</th><th>Vel.</th><th>Ajuste</th><th>Vel. corr.</th><th>Índ. nivel</th><th>Índice</th>'
            . '<th>Nivel</th><th>Jockey</th><th>Kg</th><th>Nº</th><th>Sport</th><th>Peso cab.</th></tr></thead><tbody>';
        foreach (array_slice($h['antecedentes'], 0, self::ANTECEDENTES) as $a) {
            $fecha = date('d/m/y', strtotime($a['fecha']));
            if ($otros = array_diff($juntos[(int)$a['carrera_id']] ?? [], [$h['numero']])) {
                $fecha = '<b>' . $fecha . '</b> <small>con ' . $e(implode(', ', $otros)) . '</small>';
            }
            $o .= '<tr><td>' . $fecha . '</td><td>' . (self::SIGLAS[(int)$a['hipodromo_id']] ?? '') . '</td><td>' . $e($a['puesto_texto']) . '/' . $a['corredores']
                . '</td><td>' . $e($a['primero']) . '</td><td>' . $e($a['segundo']) . '</td><td>' . $e($a['dist_acumulada']) . '</td><td>' . $e($a['distancia'])
                . '</td><td>' . $e(mb_substr($a['superficie'], 0, 1)) . '</td><td>' . $e($a['pista']) . '</td><td>' . $e($a['tiempo_ganador'])
                . '</td><td>' . self::num($a['velocidad']) . '</td><td>' . self::idx($a['ajuste']) . '</td><td>' . self::num($a['vel_corregida'])
                . '</td><td>' . self::idx($a['idx_categoria']) . '</td><td class="fuerte">' . self::idx($a['indice']) . '</td><td class="cat">' . $e($a['nivel'])
                . '</td><td>' . $e($a['jockey']) . '</td><td>' . self::num($a['peso_asignado'] !== null ? (float)$a['peso_asignado'] : null, 1) . '</td><td>' . $e($a['gatera'])
                . '</td><td>' . self::num($a['cuota'] !== null ? (float)$a['cuota'] : null, 1) . '</td><td>' . $e($a['peso_caballo']) . '</td></tr>';
        }
        return $o . '</tbody></table></div>';
    }

    /** carrera pasada => números de los caballos de hoy (que corren) que la corrieron. */
    private static function juntos(array $caballos): array
    {
        $mapa = [];
        foreach ($caballos as $h) {
            if (!$h['retirado']) {
                foreach ($h['hist'] as $a) {
                    $mapa[$a['carrera']][] = $h['numero'];
                }
            }
        }
        return $mapa;
    }

    private static function num(int|float|null $v, int $dec = 2): string
    {
        return $v === null ? '' : number_format((float)$v, $dec, ',', '.');
    }

    private static function idx(?float $v): string
    {
        if ($v === null) {
            return '';
        }
        $clase = $v >= 0.25 ? 'mas' : ($v <= -0.25 ? 'menos' : 'igual');
        return '<span class="' . $clase . '">' . ($v > 0 ? '+' : '') . number_format($v, 2, ',', '.') . '</span>';
    }

    private const CSS = '.nro { padding:0 4px; }';
}
