<?php
declare(strict_types=1);

namespace GranChance\HK;

use GranChance\{Clasificador, Modelo as ModeloUy, ProgramaNoOficial as ProgramaUy};
use PDO;

/**
 * Programa no oficial de una reunión de Hong Kong, como el de Uruguay (GranChance\ProgramaNoOficial): cada carrera del
 * Race Card con los antecedentes de cada caballo, su velocidad corregida por going y riel, el índice contra el par, la
 * proyección y la probabilidad de ganar del modelo (HK\Modelo).
 *
 * Los índices se muestran en la escala del hipódromo de hoy (lo corrido en Sha Tin suma la equivalencia de Happy Valley).
 * Lo que agrega el HKJC y se muestra: rating y su cambio, banda de la carrera, riel, equipo (anteojeras por primera vez...),
 * peso del caballo, posiciones en carrera y la chaquetilla oficial.
 */
final class ProgramaNoOficial
{
    public const ANTECEDENTES = 6;
    private const NOMBRES = [1 => 'Sha Tin', 2 => 'Happy Valley'];
    private const ZONA = 'Asia/Hong_Kong';
    private const SIGLAS = [1 => 'ST', 2 => 'HV'];
    private const CODIGOS = ['ST' => 1, 'HV' => 2];
    private const CHAQUETILLA = 'https://racing.hkjc.com/racing/content/Images/RaceColor/%s.gif';
    private const EQUIPO = ['B' => 'anteojeras', 'BO' => 'anteojera de un lado', 'CP' => 'carrilleras', 'CO' => 'carrillera de un lado',
        'E' => 'tapones de oído', 'H' => 'capucha', 'P' => 'pacificador', 'PC' => 'pacificador con anteojeras', 'PS' => 'pacificador con una anteojera',
        'SB' => 'frontalera de piel', 'SR' => 'muserola de piel', 'TT' => 'lengua atada', 'V' => 'visera', 'VO' => 'visera de un lado',
        'XB' => 'muserola cruzada', 'BL' => 'cepillo a la izquierda', 'BR' => 'cepillo a la derecha', 'BK' => 'manta en la gatera', 'CC' => 'collar Cornell'];

    public function __construct(private PDO $db, private Velocidades $vel, private Modelo $modelo)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @param list<array> $carreras resultado de ParserPrograma::carrera() para cada carrera de la reunión */
    public function armar(array $carreras, string $fecha): array
    {
        $codigos = [];
        foreach ($carreras as $c) {
            foreach ($c['caballos'] as $h) {
                $codigos[] = $h['codigo'];
            }
        }
        $ids = $this->caballos($codigos);
        $hist = $this->modelo->preparar($fecha, array_values($ids));
        $res = [];
        foreach ($carreras as $c) {
            $hip = self::CODIGOS[$c['hipodromo']];
            $c['hip'] = $hip;
            $c['nivel'] = Velocidades::nivel($c['clase']);
            $c['par'] = $this->vel->par($hip, (int)$c['distancia'], $c['superficie'], $c['nivel']);
            $c['par_general'] = $this->vel->parGeneral($hip, (int)$c['distancia'], $c['superficie']);
            $eqHoy = $this->vel->equivalencia($hip);
            // Nivel de la clase de hoy en la escala de Sha Tin, como lo usa el modelo.
            $nivelHoy = $c['par'] && $c['par_general'] ? $c['par']['velocidad'] - $c['par_general']['velocidad'] - $eqHoy : null;
            $corren = array_filter($c['caballos'], fn ($h) => !$h['retirado']);
            $pesoTop = max(array_map(fn ($h) => (float)$h['peso'], $corren ?: [['peso' => 0]]));
            foreach ($c['caballos'] as &$h) {
                $h['caballo_id'] = $ids[$h['codigo']] ?? null;
                $h['hist'] = $h['caballo_id'] ? $hist[$h['caballo_id']] ?? [] : [];
                $h['antecedentes'] = $h['caballo_id'] ? $this->antecedentes($h['caballo_id'], $fecha, $hip) : [];
                $h['pedigri'] = $this->pedigri($h);
                $h += $this->resumen($h, $c, $eqHoy, $nivelHoy, $pesoTop);
            }
            unset($h);
            $this->frente($c, $fecha);
            $this->probabilidades($c, $fecha, $nivelHoy, $pesoTop);
            $res[] = $c;
        }
        return ['fecha' => $fecha, 'hipodromo' => $res[0]['hip'] ?? 2, 'carreras' => $res];
    }

    /** @return array<string, int> código HKJC => caballo_id */
    private function caballos(array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter($codigos)));
        if (!$codigos) {
            return [];
        }
        $st = $this->db->prepare('SELECT codigo_hkjc, id FROM caballo WHERE codigo_hkjc IN (' . implode(',', array_fill(0, count($codigos), '?')) . ')');
        $st->execute($codigos);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function pedigri(array $h): array
    {
        if ($h['caballo_id']) {
            $st = $this->db->prepare('SELECT padre, madre, abuelo_materno, pais, pelaje, sexo FROM caballo WHERE id = ?');
            $st->execute([$h['caballo_id']]);
            if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
                return $f;
            }
        }
        return ['padre' => $h['padre'], 'madre' => $h['madre'], 'abuelo_materno' => null, 'pais' => null, 'pelaje' => null, 'sexo' => null];
    }

    private function antecedentes(int $caballo, string $fecha, int $hipHoy): array
    {
        $st = $this->db->prepare(
            'SELECT a.carrera_id, r.fecha, r.hipodromo_id, c.numero, c.distancia, c.superficie, c.pista, c.recorrido, c.clase,
                    c.tiempo_ganador, a.puesto, a.puesto_texto, a.tiempo, a.tiempo_seg, a.gatera, a.peso_caballo_lb, a.peso_asignado_lb,
                    a.peso_asignado, a.dist_acumulada, a.posiciones, a.cuota, j.nombre AS jockey,
                    (SELECT COUNT(*) FROM actuacion x WHERE x.carrera_id = a.carrera_id AND x.cuota IS NOT NULL) AS corredores
             FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id
             LEFT JOIN persona j ON j.id = a.jockey_id
             WHERE a.caballo_id = ? AND r.fecha < ? ORDER BY r.fecha DESC, c.numero DESC'
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
            $riel = Velocidades::riel($f['recorrido']);
            $f['superficie'] = $sup;
            $f['nivel'] = Velocidades::nivel($f['clase']);
            $f['primero'] = $podio[$f['carrera_id']][1] ?? null;
            $f['segundo'] = $podio[$f['carrera_id']][2] ?? null;
            $f['velocidad'] = Velocidades::kmh($dist, $f['tiempo_seg'] !== null ? (float)$f['tiempo_seg'] : null);
            $aj = $this->vel->ajuste($hip, $sup, $dist, $pista, $riel);
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
        $r = ['dias' => $h['dias'], 'promedio' => null, 'ultimo' => null, 'en_distancia' => null, 'proyeccion' => null,
            'vs_par_hoy' => null, 'cambio_jockey' => false, 'notas' => []];
        $ant = $h['antecedentes'];
        if ($h['retirado']) {
            return $r;
        }
        if (!$ant) {
            $r['notas'][] = 'Debuta en Hong Kong' . ($h['importacion'] === 'PPG' || $h['importacion'] === 'ISG' ? " (importado con campaña afuera, {$h['importacion']})" : '');
            return $r + self::notasHoy($h);
        }
        $u = $ant[0];
        $r['dias'] ??= (int)((strtotime($c['fecha']) - strtotime($u['fecha'])) / 86400);
        $p = ModeloUy::proyeccion($h['hist'], (int)$c['distancia'], $c['superficie']);
        foreach (['promedio', 'ultimo', 'en_distancia', 'proyeccion'] as $k) {
            $r[$k] = $p[$k] !== null ? $p[$k] + $eqHoy : null;
        }
        if ($p['proyeccion'] !== null && $nivelHoy !== null) {
            $r['vs_par_hoy'] = $p['proyeccion'] - $nivelHoy;
        }
        $r['cambio_jockey'] = $u['jockey'] && Modelo::persona($u['jockey']) !== Modelo::persona($h['jockey']);
        if (!in_array($c['hip'], array_map('intval', array_column($ant, 'hipodromo_id')), true)) {
            $r['notas'][] = 'Primera vez en ' . self::NOMBRES[$c['hip']];
        }
        if (!in_array($c['superficie'], array_column($ant, 'superficie'), true)) {
            $r['notas'][] = 'Primera vez en ' . ($c['superficie'] === 'césped' ? 'césped' : 'arena sintética');
        }
        if ($r['dias'] > 60) {
            $r['notas'][] = "Hace {$r['dias']} días que no corre";
        }
        if (max(array_map(fn ($a) => (int)$a['distancia'], $ant)) < (int)$c['distancia']) {
            $r['notas'][] = 'Nunca corrió tan largo';
        }
        $claseAntes = self::numeroClase($u['nivel']);
        $claseHoy = self::numeroClase($c['nivel']);
        if ($claseAntes !== null && $claseHoy !== null && $claseAntes !== $claseHoy) {
            $r['notas'][] = ($claseAntes < $claseHoy ? 'Baja' : 'Sube') . " de clase (viene de {$u['nivel']})";
        }
        if ($r['cambio_jockey']) {
            $juntos = array_filter($ant, fn ($a) => $a['jockey'] && Modelo::persona($a['jockey']) === Modelo::persona($h['jockey']));
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
        if ($h['peso_caballo_lb'] && $u['peso_caballo_lb'] && abs($h['peso_caballo_lb'] - (int)$u['peso_caballo_lb']) >= 20) {
            $r['notas'][] = sprintf('Peso del caballo %+d lb respecto de su última', $h['peso_caballo_lb'] - (int)$u['peso_caballo_lb']);
        }
        return $r + self::notasHoy($h);
    }

    /** Notas que salen solo del programa de hoy: rating, aprendiz y equipo nuevo. Se agregan después de las demás. */
    private static function notasHoy(array $h): array
    {
        $n = [];
        if ($h['rating_cambio'] !== null && (int)$h['rating_cambio'] !== 0) {
            $n[] = sprintf('Rating %d (%+d desde su última)', $h['rating'], (int)$h['rating_cambio']);
        }
        if ($h['descargo']) {
            $n[] = "Aprendiz: descarga {$h['descargo']} lb";
        }
        foreach (preg_split('#/#', (string)$h['equipo'], -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (preg_match('/^([A-Z]+)([12-])$/', $e, $m)) {
                $que = self::EQUIPO[$m[1]] ?? $m[1];
                $n[] = match ($m[2]) {'1' => "Primera vez con $que", '2' => "Cambia $que", '-' => "Saca $que"};
            }
        }
        return ['notas_hoy' => $n];
    }

    private static function numeroClase(?string $nivel): ?int
    {
        return match (true) {
            $nivel === null => null,
            (bool)preg_match('/^Class (\d)$/', $nivel, $m) => (int)$m[1],
            $nivel === 'G1', $nivel === 'G2', $nivel === 'G3' => 0,
            default => null,
        };
    }

    /** Cómo le fue contra los rivales de hoy cuando corrieron juntos. */
    private function frente(array &$c, string $fecha): void
    {
        $puestos = [];
        foreach ($c['caballos'] as $h) {
            if (!$h['retirado'] && $h['caballo_id']) {
                foreach ($h['hist'] as $a) {
                    $puestos[$a['carrera']][$h['numero']] = [$a['puesto'], $a['fecha']];
                }
            }
        }
        $nombre = array_column($c['caballos'], 'nombre', 'numero');
        foreach ($c['caballos'] as &$h) {
            $h['frente'] = [];
            $enf = [];
            foreach ($puestos as $carrera) {
                [$mio, $f] = $carrera[$h['numero']] ?? [null, null];
                if ($mio === null) {
                    continue;
                }
                foreach ($carrera as $otro => [$suyo]) {
                    if ($otro !== $h['numero'] && $suyo !== null && $suyo !== $mio) {
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
                'corredores' => count($corren), 'jockey' => Modelo::persona($h['jockey']), 'cuidador' => Modelo::persona($h['cuidador']),
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
        $titulo = sprintf('Programa no oficial · %s · %s', self::NOMBRES[$p['hipodromo']], date('d/m/Y', strtotime($p['fecha'])));
        $o = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($titulo) . '</title><style>' . ProgramaUy::CSS . self::CSS . '</style></head><body>';
        $o .= '<nav class="navegacion"><div class="fila carreras">Carreras:';
        foreach ($p['carreras'] as $c) {
            $o .= ' <a href="#carrera-' . $c['numero'] . '" title="' . $e($c['distancia'] . ' m · ' . $c['clase'] . ' · ' . $c['premio']) . '">' . $c['numero'] . 'ª <small>' . ProgramaUy::hora($p['fecha'], $c['hora'], self::ZONA) . '</small></a>';
        }
        $o .= '</div></nav>';
        $o .= '<header><h1>' . $e($titulo) . '</h1><p>Horas de Hong Kong (la página las pasa a tu zona horaria). Programa oficial del HKJC leído el ' . $e($actualizado) . '. Velocidades en km/h. '
            . '<b>Par</b>: promedio de los 4 primeros en pista GOOD para esa distancia, superficie y clase. <b>Ajuste</b>: going (GOOD TO FIRM, YIELDING...) y riel (A, B, C+3...) de ese día, medidos con los datos (el HKJC no publica el viento). '
            . '<b>Índice</b>: velocidad corregida menos el par general de esa distancia (0 = normal), llevado a ' . $e(self::NOMBRES[$p['hipodromo']]) . '. '
            . '<b>Historia</b>: promedio de los índices de toda la campaña en HK, pesando más las últimas carreras. <b>Proyección</b>: (3 × historia + último + 2 × historia en la distancia) / 6. '
            . '<b>vs par de hoy</b>: la proyección comparada con los 4 primeros normales de la clase de esta carrera. '
            . '<b>Prob.</b>: probabilidad de ganar del mismo modelo que el de Uruguay, entrenado con las carreras de HK; el resumen se ordena por ella. '
            . '<b>Fecha en negrita</b>: en esa carrera también corrieron otros caballos de hoy. <b>Pos.</b>: posiciones en cada tramo. <b>Cuota</b>: cuota final a ganador (por HK$1).</p></header>';
        foreach ($p['carreras'] as $c) {
            $o .= $this->htmlCarrera($c, $e);
        }
        return $o . ProgramaUy::JS_HORAS . '</body></html>';
    }

    private function htmlCarrera(array $c, \Closure $e): string
    {
        $sup = $c['superficie'] === 'césped' ? 'césped' : 'arena sintética';
        $o = '<section class="carrera" id="carrera-' . $c['numero'] . '"><h2>' . $c['numero'] . 'ª carrera · ' . ProgramaUy::hora($c['fecha'], $c['hora'], self::ZONA) . ' · ' . $e($c['distancia']) . ' m en ' . $sup
            . ($c['recorrido'] ? ' · riel "' . $e($c['recorrido']) . '"' : '') . ' · ' . $e($c['pista']) . '</h2>';
        $o .= '<p class="premio"><b>' . $e($c['premio']) . '</b>. ' . $e($c['clase']) . ($c['rating'] ? ' (rating ' . $e($c['rating']) . ')' : '')
            . ($c['bolsa'] ? ' · Bolsa HK$ ' . number_format($c['bolsa'], 0, ',', '.') : '') . '</p>';
        $o .= '<p class="par">Clase: <b>' . $e($c['nivel']) . '</b>';
        if ($c['par']) {
            $o .= ' · Par: <b>' . self::num($c['par']['velocidad']) . '</b> (' . $e($c['par']['categoria']) . ', ' . $c['par']['carreras'] . ' carreras)';
        }
        if ($c['par_general']) {
            $o .= ' · Par general de la distancia: ' . self::num($c['par_general']['velocidad']);
        }
        $o .= '</p>';
        $orden = $c['caballos'];
        usort($orden, fn ($a, $b) => [$a['retirado'], $b['prob'] ?? -1] <=> [$b['retirado'], $a['prob'] ?? -1]);
        $o .= '<table class="resumen"><thead><tr><th>Nº</th><th>Caballo</th><th>Kg</th><th>Gat.</th><th>Rtg.</th><th>Últimas 6</th><th title="Índices de toda la campaña, pesando más las últimas carreras">Historia</th><th>Último</th>'
            . '<th>En la dist.</th><th>Proyección</th><th>vs par de hoy</th><th>Prob.</th><th>Días</th><th>Notas</th></tr></thead><tbody>';
        foreach ($orden as $h) {
            $notas = $h['retirado'] ? 'Retirado' : implode('. ', array_merge($h['notas'], $h['notas_hoy'] ?? []));
            $o .= '<tr' . ($h['retirado'] ? ' class="retirado"' : '') . '><td>' . ProgramaUy::mandil((string)$h['numero']) . '</td><td>' . $e($h['nombre'])
                . '</td><td>' . self::num($h['peso'], 1) . '</td><td>' . $e($h['gatera']) . '</td><td>' . $e($h['rating']) . '</td><td>' . $e($h['ultimas6'])
                . '</td><td>' . self::idx($h['promedio']) . '</td><td>' . self::idx($h['ultimo']) . '</td><td>' . self::idx($h['en_distancia'])
                . '</td><td>' . self::idx($h['proyeccion']) . '</td><td class="fuerte">' . self::idx($h['vs_par_hoy']) . '</td><td class="fuerte">'
                . ($h['prob'] !== null ? self::num(100 * $h['prob'], 0) . ' %' : '') . '</td><td>' . $e($h['dias']) . '</td><td>' . $e($notas) . '</td></tr>';
        }
        $o .= '</tbody></table>';
        $juntos = self::juntos($c['caballos']);
        foreach ($c['caballos'] as $h) {
            $o .= $this->htmlCaballo($h, $juntos, $e);
        }
        return $o . '</section>';
    }

    private function htmlCaballo(array $h, array $juntos, \Closure $e): string
    {
        $ped = $h['pedigri'];
        $o = '<div class="caballo' . ($h['retirado'] ? ' retirado' : '') . '"><h3>'
            . ($h['marca'] ? '<img class="chaquetilla" loading="lazy" alt="" width="38" height="38" src="' . $e(sprintf(self::CHAQUETILLA, $h['marca'])) . '"> ' : '')
            . ProgramaUy::mandil((string)$h['numero']) . ' ' . $e($h['nombre']) . ($ped['pais'] ? ' (' . $e($ped['pais']) . ')' : '')
            . ' <small>' . $e(trim(($ped['pelaje'] ?? '') . ' ' . ($ped['sexo'] ?? '') . ' ' . ($h['edad'] ? $h['edad'] . ' años' : ''))) . ' · ' . $e($h['marca']) . '</small></h3>';
        $o .= '<p>' . self::num($h['peso'], 1) . ' kg (' . $e($h['peso_lb']) . ' lb' . ($h['descargo'] ? ' ' . $h['descargo'] : '') . ') · Jockey: '
            . ($h['retirado'] ? 'Retirado' : $e($h['jockey']) . (($h['cambio_jockey'] ?? false) ? ' <span class="cambio">(cambió)</span>' : ''))
            . ' · Cuidador: ' . $e($h['cuidador']) . ' · Gatera: ' . $e($h['gatera']) . ' · Rating: ' . $e($h['rating'])
            . ($h['peso_caballo_lb'] ? ' · Peso del caballo: ' . $e($h['peso_caballo_lb']) . ' lb' : '')
            . ($h['equipo'] ? ' · Equipo: ' . $e($h['equipo']) : '') . ' · ' . $e($ped['padre'] . ' y ' . $ped['madre'] . ($ped['abuelo_materno'] ? ' por ' . $ped['abuelo_materno'] : ''))
            . ($h['importacion'] ? ' · ' . $e($h['importacion']) : '') . ' · Propietario: ' . $e($h['propietario']) . '</p>';
        if (!$h['antecedentes']) {
            return $o . '<p class="debut">Sin carreras en Hong Kong</p></div>';
        }
        $o .= '<table class="ant"><thead><tr><th>Fecha</th><th>Hip.</th><th>P.</th><th>Ganador</th><th>Segundo</th><th>Cpos.</th><th>Dist.</th><th>Sup.</th>'
            . '<th>Going</th><th>Riel</th><th>T. ganador</th><th>T. final</th><th>Vel.</th><th>Ajuste</th><th>Vel. corr.</th><th>Índ. clase</th><th>Índice</th>'
            . '<th>Clase</th><th>Jockey</th><th>Kg</th><th>Gat.</th><th>Pos.</th><th>Cuota</th><th>Peso cab.</th></tr></thead><tbody>';
        foreach (array_slice($h['antecedentes'], 0, self::ANTECEDENTES) as $a) {
            $fecha = date('d/m/y', strtotime($a['fecha']));
            if ($otros = array_diff($juntos[(int)$a['carrera_id']] ?? [], [$h['numero']])) {
                $fecha = '<b>' . $fecha . '</b> <small>con ' . $e(implode(', ', $otros)) . '</small>';
            }
            $o .= '<tr><td>' . $fecha . '</td><td>' . self::SIGLAS[(int)$a['hipodromo_id']] . '</td><td>' . $e($a['puesto_texto']) . '/' . $a['corredores']
                . '</td><td>' . $e($a['primero']) . '</td><td>' . $e($a['segundo']) . '</td><td>' . $e($a['dist_acumulada']) . '</td><td>' . $e($a['distancia'])
                . '</td><td>' . ($a['superficie'] === 'césped' ? 'c' : 's') . '</td><td>' . $e($a['pista']) . '</td><td>' . $e($a['recorrido'])
                . '</td><td>' . $e($a['tiempo_ganador']) . '</td><td>' . $e($a['tiempo']) . '</td><td>' . self::num($a['velocidad'])
                . '</td><td>' . self::idx($a['ajuste']) . '</td><td>' . self::num($a['vel_corregida']) . '</td><td>' . self::idx($a['idx_categoria'])
                . '</td><td class="fuerte">' . self::idx($a['indice']) . '</td><td class="cat">' . $e($a['clase']) . '</td><td>' . $e($a['jockey'])
                . '</td><td>' . self::num($a['peso_asignado'] !== null ? (float)$a['peso_asignado'] : null, 1) . '</td><td>' . $e($a['gatera'])
                . '</td><td>' . $e($a['posiciones']) . '</td><td>' . $e($a['cuota']) . '</td><td>' . $e($a['peso_caballo_lb']) . '</td></tr>';
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

    private const CSS = '.chaquetilla { vertical-align:middle; } .nro { padding:0 4px; }';
}
