<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Arma el programa no oficial: cada carrera del Programa con los antecedentes de cada caballo, sus velocidades
 * corregidas por pista y viento, y cuánto corrió por encima o por debajo del par de su categoría.
 *
 * Índices (todos en km/h):
 * - vel. corregida = velocidad real − ajuste de la pista y el viento de ese día (medido con los datos, ver Velocidades).
 * - índice categoría = vel. corregida − par de la categoría de esa carrera (cómo corrió frente a los 4 primeros normales de esa categoría).
 * - índice = vel. corregida − par general de esa distancia/superficie/hipódromo: sirve para comparar carreras de distinta
 *   distancia, hipódromo o categoría.
 * - proyección = (3 × promedio de índices + último + 2 × promedio en la distancia de hoy) / 6, como el programa viejo
 *   (sin carreras en la distancia: (2 × promedio + último) / 3).
 * - vs par de hoy = proyección − (par de la categoría de hoy − par general de hoy): + significa que, por lo que viene
 *   corriendo, anda más rápido que los 4 primeros normales de la categoría de hoy.
 */
final class ProgramaNoOficial
{
    public const ANTECEDENTES = 6;
    private const MESES = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
        'set' => 9, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    public function __construct(private PDO $db, private Velocidades $vel)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array $programa resultado de ParserPrograma
     * @param array<int,int> $distancias distancia a mano por número de carrera (cuando no se puede deducir del récord)
     */
    public function armar(array $programa, int $hip, string $fecha, array $distancias = []): array
    {
        $carreras = [];
        foreach ($programa['carreras'] as $c) {
            $sup = $c['superficie'] ?? Clasificador::superficie(null, $hip);
            $c['distancia'] = $distancias[$c['numero']] ?? $this->distanciaPorRecord($hip, $c['record'], $fecha);
            $c['distancia_origen'] = isset($distancias[$c['numero']]) ? 'indicada' : ($c['distancia'] ? 'récord' : null);
            $c['superficie'] = $sup;
            $corren = array_filter($c['caballos'], fn ($h) => !$h['retirado'] && !$h['suplente']);
            $c['nivel'] = Clasificador::nivel($c['premio'], $c['condicion']);
            $c['edad'] = Clasificador::edad(array_column($corren, 'edad'));
            $c['sexo'] = Clasificador::sexo(array_column($corren, 'sexo'));
            $c['par'] = $c['distancia'] ? $this->vel->par($hip, $c['distancia'], $sup, $c['nivel'], $c['edad'], $c['sexo']) : null;
            $c['par_general'] = $c['distancia'] ? $this->vel->parGeneral($hip, $c['distancia'], $sup) : null;
            $nivelHoy = $c['par'] && $c['par_general'] ? $c['par']['velocidad'] - $c['par_general']['velocidad'] : null;
            $pesoTop = max(array_map(fn ($h) => (float)$h['peso'], $corren ?: [['peso' => 0]]));

            foreach ($c['caballos'] as &$h) {
                $h['anio_nac'] = Clasificador::anioNac($h['edad'], $fecha);
                $h['antecedentes'] = $this->antecedentes($h['nombre'], $h['pais'], $h['anio_nac'], $fecha);
                $h += $this->resumen($h, $c, $fecha, $nivelHoy, $pesoTop);
            }
            unset($h);
            $nombres = array_map(fn ($h) => self::clave($h['nombre']), $c['caballos']);
            // Carreras pasadas en las que corrieron juntos dos o más caballos de hoy: carrera => números de mandil.
            $juntos = [];
            foreach ($c['caballos'] as $h) {
                foreach ($h['antecedentes'] as $a) {
                    $juntos[(int)$a['carrera_id']][$h['numero']] = $h['nombre'];
                }
            }
            foreach ($c['caballos'] as &$h) {
                foreach ($h['antecedentes'] as &$a) {
                    // En negrita los rivales de hoy que le ganaron o lo escoltaron.
                    $a['primero_rival'] = $a['primero'] && self::clave($a['primero']) !== self::clave($h['nombre']) && in_array(self::clave($a['primero']), $nombres, true);
                    $a['segundo_rival'] = $a['segundo'] && self::clave($a['segundo']) !== self::clave($h['nombre']) && in_array(self::clave($a['segundo']), $nombres, true);
                    $otros = array_diff_key($juntos[(int)$a['carrera_id']] ?? [], [$h['numero'] => 1]);
                    $a['con_rivales'] = $otros;
                }
                unset($a);
            }
            unset($h);
            $carreras[] = $c;
        }
        return ['fecha' => $fecha, 'hipodromo' => $hip, 'nro_reunion' => $programa['nro_reunion'], 'carreras' => $carreras];
    }

    /**
     * El Programa no trae la distancia, pero sí el récord de esa distancia ("1'21''09 Si Señor - 56 - 29 Set, 2024"):
     * se busca esa carrera en la base (ganador, fecha y tiempo) y se toma su distancia.
     */
    public function distanciaPorRecord(int $hip, ?string $record, string $fechaHoy): ?int
    {
        if (!$record) {
            return null;
        }
        // Primero, lo aprendido de los programas viejos (bin/records.php): cubre los récords anteriores a 2015.
        $st = $this->db->prepare('SELECT distancia FROM record_distancia WHERE hipodromo_id = ? AND record = ? ORDER BY carreras DESC LIMIT 1');
        $st->execute([$hip, mb_substr($record, 0, 150)]);
        $d = $st->fetchColumn();
        if ($d !== false) {
            return (int)$d;
        }
        if (!preg_match("/^(?:(\\d+)')?(\\d+)''(\\d+)\\s+(.+?)\\s+-\\s+[\\d.,]+\\s+-\\s+(\\d{1,2})\\s+([A-Za-zé]{3})[a-z]*,?\\s+([\\d.]+)/u", $record, $m)) {
            return null;
        }
        $seg = (int)$m[1] * 60 + (int)$m[2] + (int)$m[3] / 100;
        $mes = self::MESES[mb_strtolower($m[6])] ?? null;
        if ($mes === null) {
            return null;
        }
        $fecha = sprintf('%04d-%02d-%02d', (int)str_replace('.', '', $m[7]), $mes, (int)$m[5]);
        $st = $this->db->prepare(
            'SELECT c.distancia FROM carrera c JOIN reunion r ON r.id = c.reunion_id
             JOIN actuacion a ON a.carrera_id = c.id AND a.puesto = 1 JOIN caballo ca ON ca.id = a.caballo_id
             WHERE r.hipodromo_id = ? AND r.fecha = ? AND ca.nombre = ? AND ABS(a.tiempo_seg - ?) < 0.006 LIMIT 1'
        );
        $st->execute([$hip, $fecha, preg_replace('/\s*\([A-Z]{2,4}\)$/u', '', $m[4]), $seg]);
        $d = $st->fetchColumn();
        return $d !== false ? (int)$d : null;
    }

    /** Últimas actuaciones del caballo (mismo nombre, país y año de nacimiento) antes de la fecha. */
    private function antecedentes(string $nombre, string $pais, int $anioNac, string $fecha): array
    {
        $st = $this->db->prepare(
            "SELECT a.carrera_id, r.fecha, r.hipodromo_id, c.numero, c.distancia, c.superficie, c.pista, c.viento, c.premio,
                    c.tiempo_ganador, c.parciales, a.puesto, a.puesto_texto, a.tiempo, a.tiempo_seg, a.gatera, a.peso_caballo,
                    a.peso_asignado, a.peso_real, a.dist_acumulada, a.sport, a.tratamiento, a.marca, a.retiro_motivo,
                    j.nombre AS jockey, cu.nombre AS cuidador, s.nombre AS stud,
                    cc.nivel, cc.edad, cc.sexo,
                    (SELECT COUNT(*) FROM actuacion x WHERE x.carrera_id = a.carrera_id) AS corredores
             FROM caballo ca
             JOIN actuacion a ON a.caballo_id = ca.id
             JOIN carrera c ON c.id = a.carrera_id
             JOIN reunion r ON r.id = c.reunion_id
             LEFT JOIN carrera_categoria cc ON cc.carrera_id = c.id
             LEFT JOIN persona j ON j.id = a.jockey_id
             LEFT JOIN persona cu ON cu.id = a.cuidador_id
             LEFT JOIN persona s ON s.id = a.stud_id
             WHERE ca.nombre = ? AND ca.pais = ? AND ca.anio_nac = ? AND r.fecha < ?
             ORDER BY r.fecha DESC, c.numero DESC LIMIT " . (self::ANTECEDENTES * 3)
        );
        $st->execute([$nombre, $pais, $anioNac, $fecha]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) {
            return [];
        }
        $podio = $this->podio(array_column($filas, 'carrera_id'));
        $res = [];
        foreach ($filas as $f) {
            $hip = (int)$f['hipodromo_id'];
            $dist = (int)$f['distancia'];
            $sup = Clasificador::superficie($f['superficie'], $hip);
            $pista = Clasificador::pista($f['pista']);
            $viento = Velocidades::viento($f['viento']);
            $f['superficie'] = $sup;
            $f['pista'] = $pista;
            $f['viento'] = $viento;
            $f['primero'] = $podio[$f['carrera_id']][1] ?? null;
            $f['segundo'] = $podio[$f['carrera_id']][2] ?? null;
            $f['velocidad'] = $f['tiempo_seg'] !== null ? Velocidades::kmh($dist, (float)$f['tiempo_seg']) : null;
            $f['cuarto_milla'] = $f['nivel'] === 'Cuarto de milla';
            $aj = $this->vel->ajuste($hip, $sup, $dist, $pista, $viento);
            $f['ajuste'] = $aj['ajuste'] ?? null;
            $f['vel_corregida'] = $f['velocidad'] !== null && $aj !== null ? $f['velocidad'] - $aj['ajuste'] : null;
            $par = $f['nivel'] ? $this->vel->par($hip, $dist, $sup, $f['nivel'], $f['edad'], $f['sexo']) : null;
            $gen = $this->vel->parGeneral($hip, $dist, $sup);
            $f['categoria'] = $f['nivel'] ? Velocidades::nombreCategoria($f['nivel'], $f['edad'], $f['sexo']) : null;
            $f['idx_categoria'] = $f['vel_corregida'] !== null && $par ? $f['vel_corregida'] - $par['velocidad'] : null;
            $f['indice'] = $f['vel_corregida'] !== null && $gen && !$f['cuarto_milla'] ? $f['vel_corregida'] - $gen['velocidad'] : null;
            $res[] = $f;
        }
        return $res;
    }

    /** @return array<int, array<int, string>> carrera => puesto => nombre del caballo (1º y 2º) */
    private function podio(array $carreras): array
    {
        $carreras = array_values(array_unique(array_map('intval', $carreras)));
        $st = $this->db->prepare(sprintf(
            'SELECT a.carrera_id, a.puesto, ca.nombre FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id
             WHERE a.carrera_id IN (%s) AND a.puesto IN (1, 2)',
            implode(',', array_fill(0, count($carreras), '?'))
        ));
        $st->execute($carreras);
        $res = [];
        foreach ($st as $r) {
            $res[(int)$r['carrera_id']][(int)$r['puesto']] = $r['nombre'];
        }
        return $res;
    }

    private function resumen(array $h, array $c, string $fecha, ?float $nivelHoy, float $pesoTop): array
    {
        $ant = $h['antecedentes'];
        $r = ['dias' => null, 'cambio_jockey' => false, 'cambio_cuidador' => false, 'cambio_stud' => false, 'sube_distancia' => false,
            'promedio' => null, 'ultimo' => null, 'en_distancia' => null, 'proyeccion' => null, 'vs_par_hoy' => null, 'notas' => []];
        if (!$ant) {
            return $r;
        }
        $u = $ant[0];
        $r['dias'] = (int)((strtotime($fecha) - strtotime($u['fecha'])) / 86400);
        if (!$h['retirado'] && $h['jockey'] && $u['jockey'] && self::claveJockey($h['jockey']) !== self::claveJockey($u['jockey'])) {
            $r['cambio_jockey'] = true;
        }
        $r['cambio_cuidador'] = $u['cuidador'] && self::clave($h['cuidador']) !== self::clave($u['cuidador']);
        $r['cambio_stud'] = $u['stud'] && self::clave($h['stud']) !== self::clave($u['stud']);
        if ($c['distancia'] && max(array_map(fn ($a) => (int)$a['distancia'], $ant)) < $c['distancia']) {
            $r['sube_distancia'] = true;
        }

        $validos = array_values(array_filter(array_slice($ant, 0, self::ANTECEDENTES), fn ($a) => $a['indice'] !== null));
        if ($validos) {
            $idx = array_column($validos, 'indice');
            $r['promedio'] = array_sum($idx) / count($idx);
            $r['ultimo'] = $validos[0]['indice'];
            $enDist = array_column(array_filter($ant, fn ($a) => $a['indice'] !== null && (int)$a['distancia'] === (int)$c['distancia']
                && $a['superficie'] === $c['superficie']), 'indice');
            $r['en_distancia'] = $enDist ? array_sum($enDist) / count($enDist) : null;
            $r['proyeccion'] = $r['en_distancia'] !== null
                ? (3 * $r['promedio'] + $r['ultimo'] + 2 * $r['en_distancia']) / 6
                : (2 * $r['promedio'] + $r['ultimo']) / 3;
            if ($nivelHoy !== null) {
                $r['vs_par_hoy'] = $r['proyeccion'] - $nivelHoy;
            }
        }

        if ($r['dias'] > 45) {
            $r['notas'][] = "Hace {$r['dias']} días que no corre";
        }
        if ($r['sube_distancia']) {
            $r['notas'][] = 'Nunca corrió tan largo';
        }
        if ($u['nivel'] && $u['nivel'] !== $c['nivel']) {
            $r['notas'][] = "Viene de {$u['nivel']}";
        }
        if ($c['superficie'] === 'césped' && !array_filter($ant, fn ($a) => $a['superficie'] === 'césped')) {
            $r['notas'][] = 'Debuta en césped';
        }
        if ($h['peso'] && $u['peso_real'] && (float)$h['peso'] - (float)$u['peso_real'] >= 2) {
            $r['notas'][] = sprintf('Sube %s kg', self::num((float)$h['peso'] - (float)$u['peso_real'], 1));
        }
        if ($h['peso'] && $pesoTop - (float)$h['peso'] >= 3) {
            $r['notas'][] = sprintf('%s kg menos que el más cargado', self::num($pesoTop - (float)$h['peso'], 1));
        }
        return $r;
    }

    // ---------------------------------------------------------------- HTML

    public function html(array $p): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $titulo = sprintf('Programa no oficial · %s · %s', self::nombreHipodromo($p['hipodromo']), date('d/m/Y', strtotime($p['fecha'])));
        $o = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($titulo) . '</title><style>' . self::CSS . '</style></head><body>';
        $o .= '<header><h1>' . $e($titulo) . '</h1><p>Reunión Nº ' . $e($p['nro_reunion']) . '. Velocidades en km/h. '
            . '<b>Par</b>: promedio de los 4 primeros en pista seca y sin viento para esa distancia, superficie y categoría. '
            . '<b>Fecha en negrita</b>: en esa carrera también corrieron otros caballos de hoy (al lado, sus números). <b>Índice</b>: velocidad corregida por pista y viento menos el par general de esa distancia (0 = normal). '
            . '<b>vs par de hoy</b>: lo que viene corriendo comparado con los 4 primeros normales de la categoría de esta carrera.</p></header>';
        foreach ($p['carreras'] as $c) {
            $o .= $this->htmlCarrera($c, $e);
        }
        return $o . '</body></html>';
    }

    private function htmlCarrera(array $c, \Closure $e): string
    {
        $dist = $c['distancia'] ? $c['distancia'] . ' m' : 'distancia sin dato';
        $o = '<section class="carrera"><h2>' . $c['numero'] . 'ª carrera · ' . $e($c['hora']) . ' · ' . $dist . ' en ' . $e($c['superficie'])
            . ' <small>Nro. ' . $e($c['nro_oficial']) . '</small></h2>';
        $o .= '<p class="premio"><b>' . $e($c['premio']) . '</b>. ' . $e($c['condicion']) . '</p>';
        $cat = Velocidades::nombreCategoria($c['nivel'], $c['edad'], $c['sexo']);
        $o .= '<p class="par">Categoría: <b>' . $e($cat) . '</b>';
        if ($c['par']) {
            $o .= ' · Par: <b>' . self::num($c['par']['velocidad']) . '</b> (' . $e($c['par']['categoria']) . ', ' . $c['par']['carreras'] . ' carreras)';
        }
        if ($c['par_general']) {
            $o .= ' · Par general de la distancia: ' . self::num($c['par_general']['velocidad']);
        }
        if ($c['record']) {
            $o .= ' · Récord: ' . $e($c['record']);
        }
        $o .= '</p>';

        // Resumen ordenado por "vs par de hoy".
        $orden = $c['caballos'];
        usort($orden, fn ($a, $b) => [$a['retirado'], $b['vs_par_hoy'] ?? $b['proyeccion'] ?? -99] <=> [$b['retirado'], $a['vs_par_hoy'] ?? $a['proyeccion'] ?? -99]);
        $o .= '<table class="resumen"><thead><tr><th>Nº</th><th>Caballo</th><th>Kg</th><th>Gat.</th><th>Prom. índice</th><th>Último</th>'
            . '<th>En la dist.</th><th>Proyección</th><th>vs par de hoy</th><th>Días</th><th>Notas</th></tr></thead><tbody>';
        foreach ($orden as $h) {
            $o .= '<tr' . ($h['retirado'] ? ' class="retirado"' : '') . '><td>' . $e($h['numero']) . '</td><td>' . $e($h['nombre']) . '</td><td>' . self::num($h['peso'], 1)
                . '</td><td>' . $e($h['gatera']) . '</td><td>' . self::idx($h['promedio']) . '</td><td>' . self::idx($h['ultimo']) . '</td><td>' . self::idx($h['en_distancia'])
                . '</td><td>' . self::idx($h['proyeccion']) . '</td><td class="fuerte">' . self::idx($h['vs_par_hoy']) . '</td><td>' . $e($h['dias'])
                . '</td><td>' . ($h['retirado'] ? 'No correrá' : ($h['suplente'] ? 'Suplente. ' : '') . (!$h['antecedentes'] ? 'Debutante' : $e(implode('. ', $h['notas'])))) . '</td></tr>';
        }
        $o .= '</tbody></table>';

        foreach ($c['caballos'] as $h) {
            $o .= $this->htmlCaballo($h, $e);
        }
        return $o . '</section>';
    }

    private function htmlCaballo(array $h, \Closure $e): string
    {
        $cambio = fn (bool $c) => $c ? ' <span class="cambio">(cambió)</span>' : '';
        $o = '<div class="caballo' . ($h['retirado'] ? ' retirado' : '') . '"><h3><span class="nro">' . $e($h['numero']) . '</span> ' . $e($h['nombre'])
            . ($h['pais'] !== 'URU' ? ' (' . $e($h['pais']) . ')' : '') . ($h['suplente'] ? ' <small>(suplente)</small>' : '') . ' <small>' . $e("{$h['pelaje']} {$h['sexo']} {$h['edad']} años") . '</small></h3>';
        $o .= '<p>' . self::num($h['peso'], 1) . ' kg · Jockey: ' . ($h['retirado'] ? 'No correrá' : $e($h['jockey']) . ($h['descargo'] ? ' (' . $h['descargo'] . ')' : '') . $cambio($h['cambio_jockey']))
            . ' · Cuidador: ' . $e($h['cuidador']) . $cambio($h['cambio_cuidador']) . ' · Stud: ' . $e($h['stud']) . $cambio($h['cambio_stud'])
            . ' · Gatera: ' . $e($h['gatera']) . ' · ' . $e($h['pedigri']) . '</p>';
        if (!$h['antecedentes']) {
            return $o . '<p class="debut">Debutante (sin antecedentes en la base)</p></div>';
        }
        $o .= '<table class="ant"><thead><tr><th>Fecha</th><th>Hip.</th><th>P.</th><th>Ganador</th><th>Segundo</th><th>Cpos.</th><th>Dist.</th><th>Sup.</th>'
            . '<th>Pista</th><th>Viento</th><th>T. ganador</th><th>T. final</th><th>Vel.</th><th>Ajuste</th><th>Vel. corr.</th><th>Índ. cat.</th><th>Índice</th>'
            . '<th>Categoría</th><th>Jockey</th><th>Kg</th><th>Gat.</th><th>Div.</th><th>Peso cab.</th><th>Trat.</th><th>Marca</th></tr></thead><tbody>';
        foreach (array_slice($h['antecedentes'], 0, self::ANTECEDENTES) as $a) {
            $nom = fn (?string $n, bool $rival) => $rival ? '<b>' . $e($n) . '</b>' : $e($n);
            $fecha = date('d/m/y', strtotime($a['fecha']));
            if ($a['con_rivales']) {
                // Fecha en negrita si en esa carrera corrieron otros caballos de hoy; al pasar el mouse dice cuáles.
                $quienes = implode(', ', array_map(fn ($n, $nom) => "($n) $nom", array_keys($a['con_rivales']), $a['con_rivales']));
                $fecha = '<b title="' . $e('Corrieron también: ' . $quienes) . '">' . $fecha . '</b> <small>con ' . $e(implode(', ', array_keys($a['con_rivales']))) . '</small>';
            }
            $o .= '<tr><td>' . $fecha . '</td><td>' . $e(self::siglaHipodromo((int)$a['hipodromo_id'])) . '</td><td>'
                . $e($a['puesto'] ?? $a['puesto_texto']) . '/' . $a['corredores'] . '</td><td>' . $nom($a['primero'], $a['primero_rival']) . '</td><td>' . $nom($a['segundo'], $a['segundo_rival'])
                . '</td><td>' . $e($a['dist_acumulada']) . '</td><td>' . $e($a['distancia']) . '</td><td>' . $e(mb_substr($a['superficie'], 0, 1)) . '</td><td>' . $e($a['pista'])
                . '</td><td>' . $e($a['viento']) . '</td><td>' . $e($a['tiempo_ganador']) . '</td><td>' . $e($a['tiempo']) . '</td><td>' . self::num($a['velocidad'])
                . '</td><td>' . self::idx($a['ajuste']) . '</td><td>' . self::num($a['vel_corregida']) . '</td><td>' . self::idx($a['idx_categoria']) . '</td><td class="fuerte">' . self::idx($a['indice'])
                . '</td><td class="cat">' . $e($a['categoria']) . '</td><td>' . $e($a['jockey']) . '</td><td>' . self::num($a['peso_real'] !== null ? (float)$a['peso_real'] : null, 1)
                . '</td><td>' . $e($a['gatera']) . '</td><td>' . self::num($a['sport'] !== null ? (float)$a['sport'] : null) . '</td><td>' . $e($a['peso_caballo'] ?: '')
                . '</td><td>' . $e($a['tratamiento']) . '</td><td>' . $e($a['marca']) . '</td></tr>';
        }
        return $o . '</tbody></table></div>';
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

    private static function clave(?string $s): string
    {
        $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', (string)$s);
        $s = strtr(mb_strtolower(trim($s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '.' => '', "'" => '']);
        return preg_replace('/\s+/', ' ', $s);
    }

    /** "Deividi Gaier" y "D. Gaier" son el mismo jockey: inicial del nombre + último apellido. */
    private static function claveJockey(string $s): string
    {
        $p = explode(' ', self::clave($s));
        return mb_substr($p[0], 0, 1) . ' ' . end($p);
    }

    public static function nombreHipodromo(int $id): string
    {
        return [1 => 'Maroñas', 4 => 'Colonia', 8 => 'Flores', 9 => 'Florida', 13 => 'Las Piedras', 16 => 'Melo', 21 => 'Paysandú', 22 => 'Rocha'][$id] ?? "Hipódromo $id";
    }

    private static function siglaHipodromo(int $id): string
    {
        return [1 => 'MRÑ', 4 => 'COL', 8 => 'FLO', 9 => 'FDA', 13 => 'LPD', 16 => 'MEL', 21 => 'PAY', 22 => 'ROC'][$id] ?? (string)$id;
    }

    private const CSS = <<<'CSS'
:root { --fondo:#fff; --texto:#1d1d1f; --suave:#6b6b70; --linea:#e3e3e6; --cabecera:#f2f2f4; --mas:#0b7a3e; --menos:#b3261e; --acento:#1f4e8c; }
@media (prefers-color-scheme: dark) { :root { --fondo:#161618; --texto:#ececef; --suave:#a0a0a8; --linea:#34343a; --cabecera:#232327; --mas:#5fd08f; --menos:#ff8a80; --acento:#8ab4f8; } }
* { box-sizing:border-box; }
body { margin:0; padding:16px; background:var(--fondo); color:var(--texto); font:13px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; }
header p, .premio, .par { color:var(--suave); max-width:1100px; }
h1 { font-size:20px; margin:0 0 4px; } h2 { font-size:17px; margin:0 0 4px; color:var(--acento); } h3 { font-size:14px; margin:12px 0 2px; }
small { color:var(--suave); font-weight:normal; }
.carrera { border-top:3px solid var(--acento); margin-top:28px; padding-top:10px; }
table { border-collapse:collapse; margin:6px 0; font-variant-numeric:tabular-nums; display:block; overflow-x:auto; max-width:100%; }
th { background:var(--cabecera); text-align:left; font-weight:600; }
th, td { border:1px solid var(--linea); padding:2px 5px; white-space:nowrap; }
.caballo { margin-left:4px; } .caballo p { margin:0; }
.nro { display:inline-block; min-width:22px; text-align:center; background:var(--acento); color:var(--fondo); border-radius:4px; }
.cambio { color:var(--menos); font-weight:600; } .debut { color:var(--suave); font-style:italic; }
.mas { color:var(--mas); font-weight:600; } .menos { color:var(--menos); } .fuerte { font-size:14px; }
.cat { font-size:11px; color:var(--suave); }
.retirado { opacity:.45; text-decoration:line-through; }
@media print { body { font-size:10px; } .carrera { page-break-before:always; } }
CSS;
}
