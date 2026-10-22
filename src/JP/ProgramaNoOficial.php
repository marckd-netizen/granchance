<?php
declare(strict_types=1);

namespace GranChance\JP;

use GranChance\{Modelo as ModeloUy, ProgramaNoOficial as ProgramaUy};
use GranChance\Fichas;
use PDO;

/**
 * Programa no oficial de una reunión de Japón, como el de Uruguay (GranChance\ProgramaNoOficial) y el de Hong Kong: cada
 * carrera del Race Card de la JRA con los antecedentes de cada caballo, su velocidad corregida por el estado de la pista y la
 * semana de la reunión, el índice contra el par, la proyección y la probabilidad de ganar del modelo (JP\Modelo).
 *
 * Los índices se muestran en la escala del hipódromo de hoy. Lo que agrega la JRA y se muestra: cuadro (el color del mandil),
 * posiciones en los 4 codos, la cuota final y el favoritismo de cada antecedente, propietario y criador.
 */
final class ProgramaNoOficial
{
    public const ANTECEDENTES = 6;
    public const NOMBRES = [1 => 'Sapporo', 2 => 'Hakodate', 3 => 'Fukushima', 4 => 'Niigata', 5 => 'Tokyo', 6 => 'Nakayama',
        7 => 'Chukyo', 8 => 'Kyoto', 9 => 'Hanshin', 10 => 'Kokura'];
    public const SIGLAS = [1 => 'SAP', 2 => 'HAK', 3 => 'FUK', 4 => 'NII', 5 => 'TOK', 6 => 'NAK', 7 => 'CHU', 8 => 'KYO', 9 => 'HAN', 10 => 'KOK'];
    public const ZONA = 'Asia/Tokyo';
    /** Colores de los 8 cuadros (waku) de la JRA: el mandil va por cuadro, no por número. */
    private const CUADROS = [1 => ['#ffffff', '#000000'], 2 => ['#000000', '#ffffff'], 3 => ['#e60012', '#ffffff'], 4 => ['#0068b7', '#ffffff'],
        5 => ['#fff100', '#000000'], 6 => ['#009944', '#ffffff'], 7 => ['#f39800', '#000000'], 8 => ['#f19ec2', '#000000']];

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
            $hip = (int)$c['hipodromo'];
            $c['hip'] = $hip;
            $c['superficie'] = Velocidades::superficie($c['superficie']);
            $c['nivel'] = $this->vel->nivelDe($c, $fecha);
            $c['par'] = $this->vel->par($hip, (int)$c['distancia'], $c['superficie'], $c['nivel']);
            $c['par_general'] = $this->vel->parGeneral($hip, (int)$c['distancia'], $c['superficie']);
            $eqHoy = $this->vel->equivalencia($hip);
            // Nivel de la carrera de hoy en la escala de Tokyo, como lo usa el modelo.
            $nivelHoy = $c['par'] && $c['par_general'] ? $c['par']['velocidad'] - $c['par_general']['velocidad'] - $eqHoy : null;
            $corren = array_filter($c['caballos'], fn ($h) => !$h['retirado']);
            $pesoTop = max(array_map(fn ($h) => (float)$h['peso'], $corren ?: [['peso' => 0]]));
            foreach ($c['caballos'] as &$h) {
                $h['caballo_id'] = $ids[$h['codigo']] ?? null;
                $h['hist'] = $h['caballo_id'] ? $hist[$h['caballo_id']] ?? [] : [];
                $h['antecedentes'] = $h['caballo_id'] ? $this->antecedentes($h['caballo_id'], $fecha, $hip) : [];
                $h['pedigri'] = $this->pedigri($h);
                $h['ultimas6'] = implode('-', array_map(fn ($a) => $a['puesto'] ?? '0', array_slice($h['antecedentes'], 0, 6)));
                $h += $this->resumen($h, $c, $eqHoy, $pesoTop);
            }
            unset($h);
            $this->frente($c, $fecha);
            $this->probabilidades($c, $fecha, $nivelHoy, $pesoTop);
            $res[] = $c;
        }
        return ['fecha' => $fecha, 'hipodromo' => $res[0]['hip'] ?? 5, 'carreras' => $res];
    }

    /** Nivel de una carrera de la base (para la predicción vs resultado). */
    public function nivel(array $carrera, string $fecha): string
    {
        return $this->vel->nivelDe($carrera, $fecha);
    }

    /** @return array<string, int> número JRA => caballo_id */
    private function caballos(array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter($codigos)));
        if (!$codigos) {
            return [];
        }
        $st = $this->db->prepare('SELECT codigo_jra, id FROM caballo WHERE codigo_jra IN (' . implode(',', array_fill(0, count($codigos), '?')) . ')');
        $st->execute($codigos);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function pedigri(array $h): array
    {
        if ($h['caballo_id']) {
            $st = $this->db->prepare('SELECT ca.padre, ca.madre, ca.abuelo_materno, ca.pais, ca.anio_nac, ca.criador_id, cr.nombre AS criador
                FROM caballo ca LEFT JOIN persona cr ON cr.id = ca.criador_id WHERE ca.id = ?');
            $st->execute([$h['caballo_id']]);
            if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
                foreach (['padre', 'madre', 'abuelo_materno', 'criador'] as $k) {
                    $f[$k] = ($h[$k] ?? null) ?: $f[$k];
                }
                return $f;
            }
        }
        return ['padre' => $h['padre'], 'madre' => $h['madre'], 'abuelo_materno' => $h['abuelo_materno'], 'pais' => $h['pais'],
            'anio_nac' => preg_match('/^(19|20)\d\d/', (string)$h['codigo'], $m) ? (int)$m[0] : null, 'criador_id' => null, 'criador' => $h['criador']];
    }

    private function antecedentes(int $caballo, string $fecha, int $hipHoy): array
    {
        $st = $this->db->prepare(
            'SELECT a.carrera_id, r.fecha, r.hipodromo_id, r.dia_reunion, c.numero, c.distancia, c.superficie, c.pista, c.clase, c.grado, c.edad,
                    c.premio_base, c.premio, c.tiempo_ganador, a.puesto, a.puesto_texto, a.tiempo, a.tiempo_seg, a.numero AS gatera,
                    a.peso_asignado, a.dist_anterior, a.posiciones, a.cuota, a.favorito, j.nombre AS jockey,
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
            $var = Velocidades::variante($sup, $f['dia_reunion'] !== null ? (int)$f['dia_reunion'] : null);
            $f['superficie'] = $sup;
            $f['nivel'] = $this->vel->nivelDe($f, $f['fecha']);
            $f['primero'] = $podio[$f['carrera_id']][1]['nombre'] ?? null;
            $f['primero_peso'] = $podio[$f['carrera_id']][1]['peso'] ?? null;
            $f['segundo'] = $podio[$f['carrera_id']][2]['nombre'] ?? null;
            $f['segundo_peso'] = $podio[$f['carrera_id']][2]['peso'] ?? null;
            $f['velocidad'] = Velocidades::kmh($dist, $f['tiempo_seg'] !== null ? (float)$f['tiempo_seg'] : null);
            $aj = $this->vel->ajuste($hip, $sup, $dist, $pista, $var);
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

    /** @return array<int, array<int, array{nombre: string, peso: ?string}>> carrera => puesto => caballo y kilos que cargó (1º y 2º) */
    private function podio(array $carreras): array
    {
        $carreras = array_values(array_unique(array_map('intval', $carreras)));
        $st = $this->db->query('SELECT a.carrera_id, a.puesto, ca.nombre, a.peso_asignado AS peso FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id
            WHERE a.carrera_id IN (' . implode(',', $carreras) . ') AND a.puesto IN (1, 2)');
        $res = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $res[(int)$r['carrera_id']][(int)$r['puesto']] ??= ['nombre' => $r['nombre'], 'peso' => $r['peso']];
        }
        return $res;
    }

    private function resumen(array $h, array $c, float $eqHoy, float $pesoTop): array
    {
        $r = ['dias' => null, 'promedio' => null, 'ultimo' => null, 'en_distancia' => null, 'proyeccion' => null,
            'vs_par_hoy' => null, 'cambio_jockey' => false, 'notas' => []];
        $ant = $h['antecedentes'];
        if ($h['retirado']) {
            return $r;
        }
        if (!$ant) {
            $r['notas'][] = 'Debuta en la JRA';
            return $r;
        }
        $u = $ant[0];
        $r['dias'] = (int)((strtotime($c['fecha']) - strtotime($u['fecha'])) / 86400);
        $p = ModeloUy::proyeccion($h['hist'], (int)$c['distancia'], $c['superficie']);
        foreach (['promedio', 'ultimo', 'en_distancia', 'proyeccion'] as $k) {
            $r[$k] = $p[$k] !== null ? $p[$k] + $eqHoy : null;
        }
        $nivelHoy = $c['par'] && $c['par_general'] ? $c['par']['velocidad'] - $c['par_general']['velocidad'] - $eqHoy : null;
        if ($p['proyeccion'] !== null && $nivelHoy !== null) {
            $r['vs_par_hoy'] = $p['proyeccion'] - $nivelHoy;
        }
        $r['cambio_jockey'] = $u['jockey'] && Modelo::persona($u['jockey']) !== Modelo::persona($h['jockey']);
        if (!in_array($c['hip'], array_map('intval', array_column($ant, 'hipodromo_id')), true)) {
            $r['notas'][] = 'Primera vez en ' . self::NOMBRES[$c['hip']];
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
        $antes = self::orden($u['nivel']);
        $hoy = self::orden($c['nivel']);
        if ($antes !== null && $hoy !== null && $antes !== $hoy) {
            $r['notas'][] = ($antes > $hoy ? 'Baja' : 'Sube') . ' de nivel (viene de ' . Traduccion::nivel($u['nivel']) . ')';
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
        return $r;
    }

    /** Escalón del nivel (sin la edad): Newcomer y Maiden 0, 1 Win 1, 2 Win 2, 3 Win 3, Open 4, L 5, G3 6, G2 7, G1 8. */
    private static function orden(?string $nivel): ?int
    {
        $n = preg_replace('/ \S+$/', '', (string)$nivel);
        return ['Newcomer' => 0, 'Maiden' => 0, '1 Win' => 1, '2 Win' => 2, '3 Win' => 3, 'Open' => 4, 'L' => 5, 'G3' => 6, 'G2' => 7, 'G1' => 8][$n] ?? null;
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
                    $txt[] = sprintf('%s %d-%d', $nombre[$otro] ?? $otro, $gp[0] ?? 0, $gp[1] ?? 0);
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
                'nivel_par' => $nivelHoy, 'peso' => (float)$h['peso'], 'peso_max' => $pesoTop, 'gatera' => (int)$h['numero'],
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

    /** Número del caballo con el color de su cuadro. */
    public static function mandil(?int $numero, ?int $cuadro): string
    {
        [$fondo, $texto] = self::CUADROS[(int)$cuadro] ?? ['#555555', '#ffffff'];
        return '<span class="nro" style="background:' . $fondo . ';color:' . $texto . '" title="Cuadro ' . (int)$cuadro . '">' . (int)$numero . '</span>';
    }

    public function html(array $p, string $actualizado): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $titulo = sprintf('Programa no oficial · %s · %s', self::NOMBRES[$p['hipodromo']], date('d/m/Y', strtotime($p['fecha'])));
        $o = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($titulo) . '</title><style>' . ProgramaUy::CSS . self::CSS . '</style></head><body>';
        $o .= '<nav class="navegacion"><div class="fila carreras">Carreras:';
        foreach ($p['carreras'] as $c) {
            $o .= ' <a href="#carrera-' . $c['numero'] . '" title="' . $e($c['distancia'] . ' m en ' . $c['superficie'] . ' · ' . Traduccion::nivel($c['nivel'])) . '">'
                . $c['numero'] . 'ª <small>' . ProgramaUy::hora($p['fecha'], $c['hora'], self::ZONA) . '</small></a>';
        }
        $o .= '</div></nav>';
        $o .= '<header><h1>' . $e($titulo) . '</h1><p>Horas de Japón (la página las pasa a tu zona horaria). Programa oficial de la JRA leído el ' . $e($actualizado) . '. Velocidades en km/h. '
            . '<b>Par</b>: promedio de los 4 primeros en pista normal para esa distancia, superficie, nivel y edad. <b>Ajuste</b>: estado de la pista (algo pesada, pesada, fangosa) y, en césped, la semana de la reunión (el pasto se gasta), medidos con los datos (la JRA no publica el viento). '
            . '<b>Nivel</b>: debutantes, perdedores, 1, 2 y 3 victorias (sale del premio), Open, listadas y grupos. '
            . '<b>Índice</b>: velocidad corregida menos el par general de esa distancia (0 = normal), llevado a ' . $e(self::NOMBRES[$p['hipodromo']]) . '. '
            . '<b>Historia</b>: promedio de los índices de toda la campaña en la JRA, pesando más las últimas carreras. <b>Proyección</b>: (3 × historia + último + 2 × historia en la distancia) / 6. '
            . '<b>vs par de hoy</b>: la proyección comparada con los 4 primeros normales del nivel de esta carrera. '
            . '<b>Prob.</b>: probabilidad de ganar del mismo modelo que el de Uruguay, entrenado con las carreras de la JRA; el resumen se ordena por ella. '
            . '<b>Fecha en negrita</b>: en esa carrera también corrieron otros caballos de hoy. <b>Pos.</b>: puesto en cada codo. <b>Cuota</b>: cuota final a ganador (por ¥1) y, entre paréntesis, el orden de favoritismo. '
            . 'El color del número es el de su cuadro (la JRA reparte los caballos en 8 cuadros).</p></header>';
        foreach ($p['carreras'] as $c) {
            $o .= $this->htmlCarrera($c, $e);
        }
        return $o . ProgramaUy::JS_HORAS . '</body></html>';
    }

    private function htmlCarrera(array $c, \Closure $e): string
    {
        $o = '<section class="carrera" id="carrera-' . $c['numero'] . '"><h2>' . $c['numero'] . 'ª carrera · ' . ProgramaUy::hora($c['fecha'], $c['hora'], self::ZONA) . ' · '
            . $e($c['distancia']) . ' m en ' . $e($c['superficie']) . ($c['pista'] ? ' · pista ' . $e(Traduccion::pista($c['pista'])) : '')
            . ($c['clima'] ? ' · ' . $e(Traduccion::clima($c['clima'])) : '') . '</h2>';
        $o .= '<p class="premio">' . ($c['premio'] ? '<b>' . $e($c['premio']) . '</b>. ' : '') . $e(Traduccion::nivel($c['nivel']))
            . ($c['sexo'] ? ', ' . $e($c['sexo']) : '') . ($c['peso_tipo'] === 'Handicap' ? ', handicap' : '')
            . ($c['bolsa'] ? ' · Bolsa ¥ ' . number_format($c['bolsa'], 0, ',', '.') : '') . '</p>';
        $o .= '<p class="par">Nivel: <b>' . $e(Traduccion::nivel($c['nivel'])) . '</b>';
        if ($c['par']) {
            $o .= ' · Par: <b>' . self::num($c['par']['velocidad']) . '</b> (' . $e($c['par']['categoria'] === 'todos los niveles' ? 'todos los niveles' : Traduccion::nivel($c['par']['categoria'])) . ', ' . $c['par']['carreras'] . ' carreras)';
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
            $notas = $h['retirado'] ? 'Retirado' : implode('. ', $h['notas']);
            $o .= '<tr' . ($h['retirado'] ? ' class="retirado"' : '') . '><td>' . self::mandil($h['numero'], $h['cuadro']) . '</td><td>' . Fichas::enlazar('caballo', $h['caballo_id'] ?? null, $h['nombre'], 'jp')
                . '</td><td>' . self::num($h['peso'], 1) . '</td><td>' . $e($h['ultimas6'])
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

    private ?\PDOStatement $stPersona = null;

    /** El nombre de un jockey, cuidador, propietario o criador con el enlace a su ficha (si figura en la base). */
    private function persona(string $tipo, ?string $nombre): string
    {
        $id = null;
        if ((string)$nombre !== '') {
            $this->stPersona ??= $this->db->prepare('SELECT id FROM persona WHERE tipo = ? AND nombre = ? LIMIT 1');
            $this->stPersona->execute([$tipo, $nombre]);
            $id = $this->stPersona->fetchColumn() ?: null;
        }
        return Fichas::enlazar($tipo, $id ? (int)$id : null, $nombre, 'jp');
    }

    private function htmlCaballo(array $h, array $juntos, \Closure $e): string
    {
        $ped = $h['pedigri'];
        $o = '<div class="caballo' . ($h['retirado'] ? ' retirado' : '') . '"><h3>' . self::mandil($h['numero'], $h['cuadro']) . ' '
            . Fichas::enlazar('caballo', $h['caballo_id'] ?? null, $h['nombre'], 'jp') . ($ped['pais'] ? ' (' . $e($ped['pais']) . ')' : '')
            . ' <small>' . $e(trim(Traduccion::sexo($h['sexo']) . ' ' . ($h['edad'] ? $h['edad'] . ' años' : ''))) . '</small></h3>';
        $o .= '<p>' . self::num($h['peso'], 1) . ' kg · Jockey: '
            . ($h['retirado'] ? 'Retirado' : $this->persona('jockey', $h['jockey']) . (($h['cambio_jockey'] ?? false) ? ' <span class="cambio">(cambió)</span>' : ''))
            . ' · Cuidador: ' . $this->persona('cuidador', $h['cuidador'])
            . ' · ' . Fichas::enlazarLinea('padrillo', $ped['padre'], 'jp') . ' y ' . Fichas::enlazarLinea('madre', $ped['madre'], 'jp')
            . ($ped['abuelo_materno'] ? ' por ' . Fichas::enlazarLinea('abuelo', $ped['abuelo_materno'], 'jp') : '')
            . ($h['propietario'] ? ' · Propietario: ' . $this->persona('stud', $h['propietario']) : '')
            . ($ped['criador'] ? ' · Criador: ' . $this->persona('criador', $ped['criador']) : '') . '</p>';
        if (!$h['antecedentes']) {
            return $o . '<p class="debut">Sin carreras en la JRA</p></div>';
        }
        $o .= '<table class="ant"><thead><tr><th>Fecha</th><th>Hip.</th><th>P.</th><th>Ganador</th><th>Segundo</th><th>Cpos.</th><th>Dist.</th><th>Sup.</th>'
            . '<th>Pista</th><th>T. ganador</th><th>T. final</th><th>Vel.</th><th>Ajuste</th><th>Vel. corr.</th><th>Índ. nivel</th><th>Índice</th>'
            . '<th>Nivel</th><th>Jockey</th><th>Kg</th><th>Nº</th><th>Pos.</th><th>Cuota</th></tr></thead><tbody>';
        foreach (array_slice($h['antecedentes'], 0, self::ANTECEDENTES) as $a) {
            $fecha = date('d/m/y', strtotime($a['fecha']));
            if ($otros = array_diff($juntos[(int)$a['carrera_id']] ?? [], [$h['numero']])) {
                $fecha = '<b>' . $fecha . '</b> <small>con ' . $e(implode(', ', $otros)) . '</small>';
            }
            $o .= '<tr><td>' . $fecha . '</td><td>' . self::SIGLAS[(int)$a['hipodromo_id']] . '</td><td>' . $e(Traduccion::puesto($a['puesto_texto'])) . '/' . $a['corredores']
                . '</td><td>' . $e($a['primero']) . ProgramaUy::kilosPodio($a['primero_peso']) . '</td><td>' . $e($a['segundo']) . ProgramaUy::kilosPodio($a['segundo_peso']) . '</td><td>' . $e(Traduccion::cuerpos($a['dist_anterior'])) . '</td><td>' . $e($a['distancia'])
                . '</td><td>' . ['césped' => 'c', 'arena' => 'a', 'obstáculos' => 'o'][$a['superficie']] . '</td><td>' . $e(Traduccion::pista($a['pista']))
                . '</td><td>' . $e($a['tiempo_ganador']) . '</td><td>' . $e($a['tiempo']) . '</td><td>' . self::num($a['velocidad'])
                . '</td><td>' . self::idx($a['ajuste']) . '</td><td>' . self::num($a['vel_corregida']) . '</td><td>' . self::idx($a['idx_categoria'])
                . '</td><td class="fuerte">' . self::idx($a['indice']) . '</td><td class="cat">' . $e(Traduccion::nivel($a['nivel'])) . '</td><td>' . $e($a['jockey'])
                . '</td><td>' . self::num($a['peso_asignado'] !== null ? (float)$a['peso_asignado'] : null, 1) . '</td><td>' . $e($a['gatera'])
                . '</td><td>' . $e($a['posiciones']) . '</td><td>' . ($a['cuota'] !== null ? self::num((float)$a['cuota'], 1) . ($a['favorito'] ? ' <small>(' . (int)$a['favorito'] . ')</small>' : '') : '') . '</td></tr>';
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
