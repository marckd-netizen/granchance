<?php
declare(strict_types=1);

namespace GranChance\BR;

use GranChance\Publicador as PublicadorUy;
use PDO;

/**
 * Publica las reuniones de Brasil en la misma web que las de Uruguay y Hong Kong (GranChance\Publicador), en las mismas
 * carpetas: programas/, resultados/ y calendario/. En la web cada hipódromo es 300 + su id en granchance_br (Gávea 301,
 * Cidade Jardim 302, Cristal 303, Tarumã 304, Madalena 305, Sorocaba 306): programas/2026-10-22_303.html.
 *
 * Las predicciones se guardan en prediccion de granchance_br antes de la reunión y se congelan cuando larga el primer
 * páreo (hora de Brasília); con los resultados cargados (bin/br_diario.php) se arma la página de predicción vs
 * resultado y el acierto se suma a resultados/resumen.json, junto con el de Uruguay y Hong Kong.
 */
final class Publicador
{
    public const WEB = 300;
    private const ZONA = 'America/Sao_Paulo';
    private const DURACION_EVENTO = 5;

    public function __construct(
        private PDO $db,
        private ProgramaNoOficial $gen,
        private Cliente $cli,
        private string $carpeta,
        private string $analytics = '',
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE IF NOT EXISTS prediccion (
            hipodromo_id SMALLINT UNSIGNED NOT NULL, fecha DATE NOT NULL, carrera TINYINT UNSIGNED NOT NULL,
            mandil VARCHAR(4) NOT NULL, clave VARCHAR(80) NULL, caballo VARCHAR(60) NOT NULL, orden TINYINT UNSIGNED NOT NULL,
            prob DECIMAL(5,4) NULL, proyeccion DECIMAL(6,3) NULL, vs_par_hoy DECIMAL(6,3) NULL,
            origen ENUM('previa','reconstruida') NOT NULL DEFAULT 'previa', generado_en DATETIME NOT NULL,
            PRIMARY KEY (hipodromo_id, fecha, carrera, caballo)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['/programas', '/resultados', '/calendario'] as $sub) {
            if (!is_dir($this->carpeta . $sub)) {
                mkdir($this->carpeta . $sub, 0775, true);
            }
        }
    }

    /** Hoy en Brasil. */
    public static function hoy(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::ZONA)))->format('Y-m-d');
    }

    // ---------------------------------------------------------------- programas

    /**
     * Baja el raceday de la reunión, arma el programa, guarda las predicciones (si todavía no largó) y escribe el HTML.
     * Algunos días el JCB arma en el mismo hipódromo una reunión aparte con uno o dos páreos extra: de las reuniones del
     * día en el hipódromo se publica la de más páreos (la web tiene una página por hipódromo y día).
     * @param int[] $idsJcb idReuniao de las reuniones de ese día en el hipódromo
     * @return ?array{carreras:int, archivo:string, congelada:bool} null si el raceday muestra otra reunión o no tiene páreos
     */
    public function programa(int $hip, string $fecha, array $idsJcb): ?array
    {
        $prog = null;
        foreach ($idsJcb as $id) {
            $r = (new ParserResultados())->reunion($this->cli->reunion($fecha, $id, false));
            if ($r['fecha'] === $fecha && count($r['carreras']) > count($prog['carreras'] ?? [])) {
                $prog = $r;
            }
        }
        if ($prog === null) {
            return null;
        }
        $carreras = $prog['carreras'];
        $p = $this->gen->armar($prog, $hip);
        $web = self::WEB + $hip;
        $congelada = !$this->guardarPredicciones($p, $web);
        $clave = "{$fecha}_$web";
        $archivo = "{$this->carpeta}/programas/$clave.html";
        if ($congelada && is_file($archivo)) {
            // Ya largó: el programa queda como se publicó antes de la primera carrera.
            return ['carreras' => count($carreras), 'archivo' => $archivo, 'congelada' => true];
        }
        $html = $this->gen->html($p, (new \DateTimeImmutable('now', new \DateTimeZone('America/Montevideo')))->format('d/m/Y H:i') . ' (hora de Uruguay)');
        $eventos = $this->eventos($p, $web);
        $barra = '<style>' . PublicadorUy::CSS_AVISOS . '</style><div class="gc-barra"><a href="../">GranChance · todos los programas</a>'
            . (is_file("{$this->carpeta}/resultados/$clave.html") ? ' · <a href="../resultados/' . $clave . '.html">Predicción vs resultado</a>' : '')
            . ($eventos ? ' · <a href="../calendario/' . $clave . '.ics">Agregar las carreras al calendario</a>' : '') . '</div>';
        foreach ($eventos as $n => $ev) {
            $html = preg_replace('/(<section class="carrera" id="carrera-' . $n . '"><h2>.*?)<\/h2>/s',
                '$1 <a class="gc-cal" href="' . htmlspecialchars(PublicadorUy::enlaceGoogle($ev), ENT_QUOTES) . '" target="_blank" rel="noopener" title="Agregar a Google Calendar">📅</a></h2>', $html, 1);
        }
        $html = preg_replace('/<nav class="navegacion">/', '<nav class="navegacion">' . $barra, $html, 1);
        $html = preg_replace('/<\/head>/', PublicadorUy::analytics($this->analytics) . '</head>', $html, 1);
        file_put_contents($archivo, $html);
        file_put_contents("{$this->carpeta}/calendario/$clave.ics", PublicadorUy::ics($eventos));
        return ['carreras' => count($carreras), 'archivo' => $archivo, 'congelada' => $congelada];
    }

    /**
     * Guarda el orden y la probabilidad de cada caballo. Desde la hora de la primera carrera ya no se tocan (salvo que no
     * haya ninguna guardada: entonces quedan como "reconstruida"). Devuelve si las guardó.
     */
    private function guardarPredicciones(array $p, int $web): bool
    {
        $zona = new \DateTimeZone(self::ZONA);
        $primera = min(array_map(fn ($c) => $c['hora'] ?? '23:59', $p['carreras']));
        $largo = new \DateTimeImmutable('now', $zona) >= new \DateTimeImmutable("{$p['fecha']} $primera", $zona);
        $st = $this->db->prepare('SELECT COUNT(*) FROM prediccion WHERE hipodromo_id = ? AND fecha = ?');
        $st->execute([$web, $p['fecha']]);
        if ($largo && (int)$st->fetchColumn() > 0) {
            return false;
        }
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM prediccion WHERE hipodromo_id = ? AND fecha = ?')->execute([$web, $p['fecha']]);
        $ins = $this->db->prepare('INSERT INTO prediccion (hipodromo_id, fecha, carrera, mandil, clave, caballo, orden, prob, proyeccion, vs_par_hoy, origen, generado_en)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($p['carreras'] as $c) {
            $corren = array_values(array_filter($c['caballos'], fn ($h) => !$h['retirado']));
            usort($corren, fn ($a, $b) => ($b['prob'] ?? -1) <=> ($a['prob'] ?? -1));
            foreach ($corren as $i => $h) {
                $ins->execute([$web, $p['fecha'], $c['numero'], (string)$h['numero'], $h['clave'], mb_substr($h['nombre'], 0, 60), $i + 1,
                    $h['prob'], $h['proyeccion'], $h['vs_par_hoy'], $largo ? 'reconstruida' : 'previa', date('Y-m-d H:i:s')]);
            }
        }
        $this->db->commit();
        return true;
    }

    /** Los páreos como eventos de 5 minutos, con la hora de Brasília (el calendario las muestra en la hora de cada uno). */
    private function eventos(array $p, int $web): array
    {
        $zona = new \DateTimeZone(self::ZONA);
        $nombre = \GranChance\ProgramaNoOficial::nombreHipodromo($web);
        $res = [];
        foreach ($p['carreras'] as $c) {
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)$c['hora'], $m)) {
                continue;
            }
            // Con la zona del hipódromo: enlaceGoogle e ics la pasan a UTC y cada calendario la muestra en la zona de su dueño.
            $inicio = (new \DateTimeImmutable($p['fecha'], $zona))->setTime((int)$m[1], (int)$m[2]);
            $res[$c['numero']] = [
                'uid' => sprintf('%s-%d-%d@granchance.com', $p['fecha'], $web, $c['numero']),
                'inicio' => $inicio,
                'fin' => $inicio->modify('+' . self::DURACION_EVENTO . ' minutes'),
                'titulo' => sprintf('%dª %s · %s', $c['numero'], PublicadorUy::sigla($web), Traduccion::premio($c['premio'])),
                'lugar' => "$nombre, Brasil",
                'detalle' => "{$c['distancia']} m en {$c['superficie']}." . ($c['tipo_prova'] ? ' ' . Traduccion::tipo($c['tipo_prova']) : '')
                    . "\nhttps://granchance.com/programas/{$p['fecha']}_$web.html#carrera-{$c['numero']}",
            ];
        }
        return $res;
    }

    // ---------------------------------------------------------------- resultados

    /** Reuniones con predicciones y resultados cargados: [hipódromo de granchance_br, fecha]. */
    public function reunionesComparables(): array
    {
        $st = $this->db->query('SELECT DISTINCT p.hipodromo_id, p.fecha FROM prediccion p
            JOIN reunion r ON r.hipodromo_id = p.hipodromo_id - ' . self::WEB . ' AND r.fecha = p.fecha ORDER BY p.fecha, p.hipodromo_id');
        return array_map(fn ($r) => [(int)$r['hipodromo_id'] - self::WEB, $r['fecha']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Página de predicción vs resultado y su acierto en resumen.json. */
    public function resultado(int $hip, string $fecha): ?array
    {
        $web = self::WEB + $hip;
        $carreras = $this->comparar($web, $fecha);
        if (!$carreras) {
            return null;
        }
        $r = PublicadorUy::medir($carreras);
        $r['origen'] = $carreras[0]['origen'];
        file_put_contents("{$this->carpeta}/resultados/{$fecha}_$web.html", $this->htmlResultado($web, $fecha, $carreras, $r));
        $guardado = $r;
        $porClase = [];
        foreach ($carreras as $c) {
            $porClase[Velocidades::nivel($c['carrera']['tipo_prova'], $c['carrera']['condicion'], $c['carrera']['premio'])][] = $c;
        }
        foreach ($porClase as $nivel => $cs) {
            $guardado['niveles'][$nivel] = array_diff_key(PublicadorUy::medir($cs), ['aciertos' => 1]);
        }
        PublicadorUy::guardarResumen($this->carpeta, "{$fecha}_$web", $guardado);
        $this->enlazarResultado($web, $fecha);
        return $r;
    }

    /** Lo predicho en cada páreo con el puesto que sacó cada caballo (por nombre), como Publicador::compararReunion. */
    private function comparar(int $web, string $fecha): array
    {
        $st = $this->db->prepare('SELECT * FROM prediccion WHERE hipodromo_id = ? AND fecha = ? ORDER BY carrera, orden');
        $st->execute([$web, $fecha]);
        $pred = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pred[(int)$r['carrera']][] = $r;
        }
        $st = $this->db->prepare('SELECT c.id, c.numero, c.distancia, c.superficie, c.pista, c.premio, c.tipo_prova, c.condicion, c.tiempo_ganador, c.retirados
            FROM carrera c JOIN reunion r ON r.id = c.reunion_id WHERE r.hipodromo_id = ? AND r.fecha = ?
            ORDER BY (SELECT COUNT(*) FROM carrera x WHERE x.reunion_id = r.id), c.numero');
        $st->execute([$web - self::WEB, $fecha]);
        $porNumero = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'numero');     // con dos reuniones, queda la de más páreos
        $act = $this->db->prepare('SELECT UPPER(ca.nombre) AS clave, ca.nombre, a.numero, a.puesto, a.puesto_texto, a.cuota AS sport
            FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id WHERE a.carrera_id = ? AND a.retiro_motivo IS NULL ORDER BY a.puesto IS NULL, a.puesto, a.numero');
        $res = [];
        foreach ($pred as $n => $lista) {
            $c = $porNumero[$n] ?? null;
            if ($c === null) {
                continue;
            }
            $act->execute([$c['id']]);
            $llegada = $act->fetchAll(PDO::FETCH_ASSOC);
            $puesto = array_column($llegada, 'puesto', 'clave');
            $cuota = array_column($llegada, 'sport', 'clave');
            foreach ($lista as &$p) {
                $p['clave'] = mb_strtoupper($p['caballo']);
                $p['corrio'] = array_key_exists($p['clave'], $puesto);
                $p['puesto'] = $p['corrio'] && $puesto[$p['clave']] !== null ? (int)$puesto[$p['clave']] : null;
                $p['sport'] = $cuota[$p['clave']] ?? null;
            }
            unset($p);
            $corrieron = array_values(array_filter($lista, fn ($p) => $p['corrio']));
            $orden = [];
            foreach ($corrieron as $i => $p) {
                $orden[$p['clave']] = $i + 1;
            }
            $favorito = null;
            foreach ($llegada as $a) {
                if ($a['sport'] !== null && ($favorito === null || (float)$a['sport'] < (float)$favorito['sport'])) {
                    $favorito = $a;
                }
            }
            $res[] = ['numero' => $n, 'carrera' => $c, 'prediccion' => $lista, 'corrieron' => $corrieron, 'llegada' => $llegada,
                'orden_de' => $orden, 'favorito' => $favorito, 'origen' => $lista[0]['origen']];
        }
        return $res;
    }

    private function htmlResultado(int $web, string $fecha, array $carreras, array $r): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $titulo = sprintf('Predicción vs resultado · %s · %s', \GranChance\ProgramaNoOficial::nombreHipodromo($web), date('d/m/Y', strtotime($fecha)));
        $clave = "{$fecha}_$web";
        $o = PublicadorUy::cabeceraHtml($titulo, $this->analytics) . '<div class="gc-barra"><a href="../">GranChance · todos los programas</a>'
            . (is_file("{$this->carpeta}/programas/$clave.html") ? ' · <a href="../programas/' . $clave . '.html">Programa no oficial</a>' : '')
            . ' · <a href="estadisticas.html">Acierto acumulado</a></div><h1>' . $e($titulo) . '</h1>';
        if ($r['origen'] === 'reconstruida') {
            $o .= '<p class="nota">Esta predicción se generó después de largada la reunión, no se publicó antes.</p>';
        }
        $o .= PublicadorUy::tablaAcierto([['', $r]], false);
        $o .= '<p class="nota">El orden es el del programa no oficial (probabilidad del modelo), contando solo a los que largaron. '
            . '<b>Exacta</b> y <b>trifecta</b>: los 2 o 3 primeros del programa llegaron 1º-2º o 1º-2º-3º, en cualquier orden. '
            . '<b>Retorno</b>: lo que se cobraría por cada R$ 1 apostado a ganador (dividendo del JCB) al primero del programa, y apostando R$ 1 a cada uno de los 3 primeros. Más de R$ 1 es ganancia.</p>';
        foreach ($carreras as $c) {
            $car = $c['carrera'];
            $ok = $r['aciertos'][$c['numero']] ?? [];
            $o .= '<section class="carrera" id="carrera-' . $c['numero'] . '"><h2>' . $c['numero'] . 'ª carrera · ' . $e($car['distancia']) . ' m en ' . $e($car['superficie'])
                . ' · pista ' . $e(Traduccion::pista($car['pista'])) . ' <small>' . $e(Traduccion::premio($car['premio'])) . ($car['tipo_prova'] ? ' · ' . $e(Traduccion::tipo($car['tipo_prova'])) : '') . '</small>'
                . ($ok ? ' <span class="ok">' . implode(' · ', array_map(fn ($x) => ['ganador' => '✔ ganador', 'exacta' => '✔ exacta', 'trifecta' => '✔ trifecta'][$x], $ok)) . '</span>' : '')
                . '</h2><div class="dos"><table><thead><tr><th colspan="5">Programa no oficial</th></tr><tr><th>#</th><th>Nº</th><th>Caballo</th><th>Prob.</th><th>Llegó</th></tr></thead><tbody>';
            $i = 0;
            foreach ($c['prediccion'] as $p) {
                $cls = !$p['corrio'] ? 'retirado' : ($p['puesto'] !== null && $p['puesto'] <= 3 ? 'podio p' . $p['puesto'] : '');
                $o .= '<tr class="' . $cls . '"><td>' . ($p['corrio'] ? ++$i : '') . '</td><td>' . $e($p['mandil']) . '</td><td>' . $e($p['caballo']) . '</td><td>'
                    . ($p['prob'] !== null ? number_format(100 * (float)$p['prob'], 0) . ' %' : '') . '</td><td>'
                    . (!$p['corrio'] ? 'no corrió' : ($p['puesto'] !== null ? $p['puesto'] . 'º' : '—')) . '</td></tr>';
            }
            $o .= '</tbody></table><table><thead><tr><th colspan="5">Resultado</th></tr><tr><th>Puesto</th><th>Nº</th><th>Caballo</th><th>Cuota</th><th>En el programa</th></tr></thead><tbody>';
            foreach (array_slice($c['llegada'], 0, 5) as $a) {
                $fav = $c['favorito'] && $a['clave'] === $c['favorito']['clave'];
                $o .= '<tr class="' . ($a['puesto'] !== null && $a['puesto'] <= 3 ? 'podio p' . $a['puesto'] : '') . '"><td>' . $e(Traduccion::puesto($a['puesto_texto']) ?: $a['puesto']) . '</td><td>'
                    . $e($a['numero']) . '</td><td>' . $e($a['nombre']) . ($fav ? ' <small title="Favorito del público">(fav.)</small>' : '') . '</td><td>'
                    . ($a['sport'] !== null ? number_format((float)$a['sport'], 1, ',', '.') : '') . '</td><td>' . (isset($c['orden_de'][$a['clave']]) ? $c['orden_de'][$a['clave']] . 'º' : '') . '</td></tr>';
            }
            $o .= '</tbody></table></div>' . ($car['tiempo_ganador'] ? '<p class="nota">Tiempo: ' . $e($car['tiempo_ganador']) . '</p>' : '') . '</section>';
        }
        return $o . '</body></html>';
    }

    /** Agrega al programa ya publicado el enlace a su resultado (sin regenerarlo: queda como estaba antes de la carrera). */
    private function enlazarResultado(int $web, string $fecha): void
    {
        $archivo = "{$this->carpeta}/programas/{$fecha}_$web.html";
        $html = is_file($archivo) ? (string)file_get_contents($archivo) : '';
        if ($html === '' || str_contains($html, '../resultados/')) {
            return;
        }
        $enlace = ' · <a href="../resultados/' . $fecha . '_' . $web . '.html">Predicción vs resultado</a>';
        file_put_contents($archivo, preg_replace('/(<div class="gc-barra"><a href="\.\.\/">[^<]*<\/a>)/', '$1' . $enlace, $html, 1));
    }
}
