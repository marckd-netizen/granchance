<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Arma lo que se publica en la web (carpeta pública, por defecto public/):
 * - programas/AAAA-MM-DD_<hip>.html: el programa no oficial, con los borrados tachados y los cambios detectados
 *   (monta, horario, peso, retiros) desde la primera vez que se publicó.
 * - resultados/AAAA-MM-DD_<hip>.html: lo que decía el programa antes de la carrera contra lo que pasó, con el acierto.
 * - resultados/estadisticas.html: el acierto acumulado de todas las reuniones publicadas.
 * - calendario/AAAA-MM-DD_<hip>.ics: las carreras de la reunión como eventos de 5 minutos, y calendario.ics con las
 *   de todas las reuniones próximas (para suscribirse desde Google Calendar). Cada carrera del programa trae además
 *   un enlace para agregarla sola a Google Calendar.
 * - indice.json: lo que lee index.php para listar las reuniones (sin necesitar la base).
 *
 * Las predicciones se guardan en la tabla prediccion cada vez que se genera el programa antes de correrse; después
 * quedan congeladas, así la comparación es con lo que se publicó y no con un cálculo hecho después.
 */
final class Publicador
{
    public function __construct(
        private PDO $db,
        private ProgramaNoOficial $gen,
        private Cliente $programas,
        private Borrados $borrados,
        private string $carpeta,
        private string $analytics = '',
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['', '/programas', '/resultados', '/calendario'] as $sub) {
            if (!is_dir($this->carpeta . $sub)) {
                mkdir($this->carpeta . $sub, 0775, true);
            }
        }
    }

    // ---------------------------------------------------------------- programas

    /**
     * Baja (de nuevo) el programa, marca los borrados, registra los cambios contra la lectura anterior, guarda las
     * predicciones si todavía no se corrió y escribe el HTML.
     * @return ?array{carreras:int, cambios:list<string>, archivo:string} null si no hay programa para ese día
     */
    public function programa(int $hip, string $fecha, bool $refrescar = true, array $distancias = []): ?array
    {
        $d = $this->programas->bajarResultados($hip, $fecha, !$refrescar);
        if ($d['estado'] !== 'ok') {
            return null;
        }
        $prog = (new ParserPrograma())->parsearArchivo($d['archivo']);
        $borrados = $this->borrados->deReunion($hip, $fecha);
        foreach ($prog['carreras'] as &$c) {
            foreach ($c['caballos'] as &$h) {
                $b = $borrados[$c['numero'] . '-' . strtoupper((string)$h['numero'])] ?? null;
                if ($b && !$h['retirado']) {
                    $h['retirado'] = true;
                    $h['borrado'] = $b['motivo'] ?: 'borrado';
                }
            }
            unset($h);
        }
        unset($c);

        $cambios = $this->registrarCambios($hip, $fecha, self::firma($prog));
        $p = $this->gen->armar($prog, $hip, $fecha, $distancias);
        $this->guardarPredicciones($p);

        $fechas = $this->fechasPublicadas();
        $fechas[$fecha] = array_values(array_unique(array_merge($fechas[$fecha] ?? [], [$hip])));
        ksort($fechas);
        $html = $this->gen->html($p, $fechas, '{fecha}_{hipodromo}.html');
        $archivo = sprintf('%s/programas/%s_%d.html', $this->carpeta, $fecha, $hip);
        file_put_contents($archivo, $this->agregarAvisos($html, $p, $borrados));
        file_put_contents(sprintf('%s/calendario/%s_%d.ics', $this->carpeta, $fecha, $hip), self::ics($this->eventos($p)));
        return ['carreras' => count($p['carreras']), 'cambios' => $cambios, 'archivo' => $archivo];
    }

    /** Lo que se compara entre lecturas: hora de cada carrera y, por caballo, nombre, jockey, peso y si corre. */
    public static function firma(array $prog): array
    {
        $f = [];
        foreach ($prog['carreras'] as $c) {
            $caballos = [];
            foreach ($c['caballos'] as $h) {
                $caballos[(string)$h['numero']] = ['nombre' => $h['nombre'], 'jockey' => $h['jockey'], 'peso' => $h['peso'],
                    'corre' => !$h['retirado'], 'borrado' => $h['borrado'] ?? null];
            }
            $f[$c['numero']] = ['hora' => $c['hora'], 'caballos' => $caballos];
        }
        return $f;
    }

    /** Diferencias entre dos firmas, como frases: [carrera, mandil, texto]. */
    public static function diferencias(array $antes, array $ahora): array
    {
        $res = [];
        // Del programa preliminar al definitivo: un solo aviso en vez de una hora nueva por carrera.
        $horario = self::sinHorario($antes) && !self::sinHorario($ahora);
        if ($horario) {
            $res[] = [array_key_first($ahora), null, 'Se publicó el programa definitivo, con horarios y montas'];
        }
        foreach ($ahora as $n => $c) {
            $a = $antes[$n] ?? null;
            if ($a === null) {
                $res[] = [$n, null, 'Se agregó la carrera'];
                continue;
            }
            if ($a['hora'] !== $c['hora'] && !$horario) {
                $res[] = [$n, null, "Cambió la hora: de {$a['hora']} a {$c['hora']}"];
            }
            foreach ($c['caballos'] as $num => $h) {
                $x = $a['caballos'][$num] ?? null;
                $quien = "$num {$h['nombre']}";
                if ($x === null || Personas::clave($x['nombre']) !== Personas::clave($h['nombre'])) {
                    $res[] = [$n, $num, "Se agregó $quien"];
                    continue;
                }
                if ($x['corre'] && !$h['corre']) {
                    $res[] = [$n, $num, "$quien no correrá" . ($h['borrado'] ? " (borrado: {$h['borrado']})" : '')];
                } elseif (!$x['corre'] && $h['corre']) {
                    $res[] = [$n, $num, "$quien vuelve a figurar para correr"];
                }
                if ($h['corre'] && $x['corre'] && $x['jockey'] && $h['jockey'] && Personas::jockey($x['jockey']) !== Personas::jockey($h['jockey'])) {
                    $res[] = [$n, $num, "Cambio de monta en $quien: de {$x['jockey']} a {$h['jockey']}"];
                }
                if ($h['corre'] && $x['peso'] !== null && $h['peso'] !== null && abs((float)$x['peso'] - (float)$h['peso']) >= 0.05) {
                    $res[] = [$n, $num, sprintf('Cambio de peso en %s: de %s a %s kg', $quien, self::kg($x['peso']), self::kg($h['peso']))];
                }
            }
            foreach (array_diff_key($a['caballos'], $c['caballos']) as $num => $x) {
                $res[] = [$n, $num, "Ya no figura $num {$x['nombre']}"];
            }
        }
        foreach (array_diff_key($antes, $ahora) as $n => $_) {
            $res[] = [$n, null, 'Ya no figura la carrera'];
        }
        return $res;
    }

    /** Compara con la lectura anterior, guarda los cambios y la firma nueva. Devuelve los textos de los cambios nuevos. */
    private function registrarCambios(int $hip, string $fecha, array $firma): array
    {
        $st = $this->db->prepare('SELECT firma FROM programa_estado WHERE hipodromo_id = ? AND fecha = ?');
        $st->execute([$hip, $fecha]);
        $antes = $st->fetchColumn();
        $json = json_encode($firma, JSON_UNESCAPED_UNICODE);
        if ($antes === false) {
            $this->db->prepare('INSERT INTO programa_estado (hipodromo_id, fecha, firma, publicado_en, actualizado_en) VALUES (?,?,?,?,?)')
                ->execute([$hip, $fecha, $json, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            return [];
        }
        $dif = self::diferencias(json_decode($antes, true), $firma);
        $ins = $this->db->prepare('INSERT INTO programa_cambio (hipodromo_id, fecha, carrera, mandil, texto, detectado_en) VALUES (?,?,?,?,?,?)');
        foreach ($dif as [$n, $num, $texto]) {
            $ins->execute([$hip, $fecha, $n, $num, mb_substr($texto, 0, 255), date('Y-m-d H:i:s')]);
        }
        $this->db->prepare('UPDATE programa_estado SET firma = ?, actualizado_en = ? WHERE hipodromo_id = ? AND fecha = ?')
            ->execute([$json, date('Y-m-d H:i:s'), $hip, $fecha]);
        return array_map(fn ($d) => "{$d[0]}ª: {$d[2]}", $dif);
    }

    /**
     * Guarda lo que dice el programa de cada caballo que corre. Antes de la reunión se reemplaza en cada lectura;
     * una vez cargados los resultados queda fija. Si nunca se guardó (programa generado después), queda como reconstruida.
     */
    private function guardarPredicciones(array $p): void
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM reunion WHERE hipodromo_id = ? AND fecha = ?');
        $st->execute([$p['hipodromo'], $p['fecha']]);
        $corrida = (int)$st->fetchColumn() > 0 || $p['fecha'] < date('Y-m-d');
        $st = $this->db->prepare('SELECT COUNT(*) FROM prediccion WHERE hipodromo_id = ? AND fecha = ?');
        $st->execute([$p['hipodromo'], $p['fecha']]);
        if ($corrida && (int)$st->fetchColumn() > 0) {
            return;
        }
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM prediccion WHERE hipodromo_id = ? AND fecha = ?')->execute([$p['hipodromo'], $p['fecha']]);
        $ins = $this->db->prepare('INSERT INTO prediccion (hipodromo_id, fecha, carrera, nro_oficial, mandil, caballo, orden, prob, proyeccion, vs_par_hoy, origen, generado_en)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($p['carreras'] as $c) {
            $corren = array_values(array_filter($c['caballos'], fn ($h) => !$h['retirado'] && !$h['suplente']));
            // El mismo orden que el resumen del programa: probabilidad y, sin modelo, vs par de hoy.
            usort($corren, fn ($a, $b) => [$b['prob'] ?? -1, $b['vs_par_hoy'] ?? $b['proyeccion'] ?? -99] <=> [$a['prob'] ?? -1, $a['vs_par_hoy'] ?? $a['proyeccion'] ?? -99]);
            foreach ($corren as $i => $h) {
                $ins->execute([$p['hipodromo'], $p['fecha'], $c['numero'], $c['nro_oficial'], (string)$h['numero'], mb_substr($h['nombre'], 0, 60), $i + 1,
                    $h['prob'], $h['proyeccion'], $h['vs_par_hoy'], $corrida ? 'reconstruida' : 'previa', date('Y-m-d H:i:s')]);
            }
        }
        $this->db->commit();
    }

    /** Barra con enlaces a la web, borrados y cambios, insertada al principio del programa. */
    private function agregarAvisos(string $html, array $p, array $borrados): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $clave = $p['fecha'] . '_' . $p['hipodromo'];
        $o = '<style>' . self::CSS_AVISOS . '</style><div class="gc-barra"><a href="../">GranChance · todos los programas</a>';
        if (is_file("{$this->carpeta}/resultados/$clave.html")) {
            $o .= ' · <a href="../resultados/' . $clave . '.html">Predicción vs resultado</a>';
        }
        $preliminar = self::sinHorario(array_column($p['carreras'], null, 'numero'));
        if (!$preliminar) {
            $o .= ' · <a href="../calendario/' . $clave . '.ics">Agregar las carreras al calendario</a>';
        }
        $o .= ' <small>Actualizado el ' . date('d/m H:i') . '</small></div>';
        $barra = $o;
        $o = '';
        if ($preliminar) {
            $o .= '<div class="gc-aviso"><b>Programa preliminar.</b> Todavía no tiene horarios ni montas: se completan solos cuando HRU publique el programa definitivo.</div>';
        }
        if ($borrados) {
            $st = $this->db->prepare('SELECT MAX(leido_en) FROM borrado WHERE hipodromo_id = ? AND fecha = ?');
            $st->execute([$p['hipodromo'], $p['fecha']]);
            $lista = [];
            foreach ($borrados as $b) {
                $lista[$b['carrera']][] = '<span class="nro-b">' . $e($b['mandil']) . '</span> ' . $e($b['caballo']) . ($b['motivo'] ? ' <small>(' . $e(mb_strtolower($b['motivo'])) . ')</small>' : '');
            }
            ksort($lista);
            $o .= '<div class="gc-aviso"><b>Borrados</b> <small>(leídos ' . date('d/m H:i', strtotime((string)$st->fetchColumn())) . ')</small>: '
                . implode(' · ', array_map(fn ($n, $l) => '<a href="#carrera-' . $n . '">' . $n . 'ª</a> ' . implode(', ', $l), array_keys($lista), $lista)) . '</div>';
        }
        $st = $this->db->prepare('SELECT carrera, texto, detectado_en FROM programa_cambio WHERE hipodromo_id = ? AND fecha = ? ORDER BY detectado_en DESC, carrera, id');
        $st->execute([$p['hipodromo'], $p['fecha']]);
        $cambios = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($cambios) {
            $o .= '<details class="gc-aviso" open><summary><b>Cambios desde que se publicó</b> (' . count($cambios) . ')</summary><ul>';
            foreach ($cambios as $c) {
                $o .= '<li><small>' . date('d/m H:i', strtotime($c['detectado_en'])) . '</small> <a href="#carrera-' . $c['carrera'] . '">' . $c['carrera'] . 'ª</a>: ' . $e($c['texto']) . '</li>';
            }
            $o .= '</ul></details>';
        }
        // El calendario del programa se armó con lo publicado hasta ese momento: se completa con el índice actual.
        $js = "<script>fetch('../indice.json').then(r => r.json()).then(j => { if (typeof NAV === 'undefined') return;"
            . " for (const r of j.reuniones) { if (!r.programa) continue; const hs = NAV.fechas[r.fecha] || (NAV.fechas[r.fecha] = []);"
            . " if (!hs.includes(r.hipodromo)) hs.push(r.hipodromo); NAV.nombres[r.hipodromo] = NAV.nombres[r.hipodromo] || [r.nombre, r.sigla]; } }).catch(() => {});</script>";
        // Cada carrera: enlace para agregarla sola a Google Calendar.
        foreach ($this->eventos($p) as $n => $ev) {
            $html = preg_replace('/(<section class="carrera" id="carrera-' . $n . '"><h2>.*?)<\/h2>/s',
                '$1 <a class="gc-cal" href="' . $e(self::enlaceGoogle($ev)) . '" target="_blank" rel="noopener" title="Agregar a Google Calendar">📅</a></h2>', $html, 1);
        }
        if ($preliminar) {
            // Las horas de relleno no se muestran.
            $html = preg_replace('/(<h2>\d+ª carrera) · \d{1,2}:\d{2} ·/u', '$1 · hora a confirmar ·', $html);
            $html = preg_replace('/(ª) <small>0\d:\d{2}<\/small><\/a>/u', '$1</a>', $html);
        }
        $html = preg_replace('/<\/head>/', self::analytics($this->analytics) . '</head>', $html, 1);
        // La navegación del programa queda fija arriba (y sube 16px): la barra va dentro de ella para que no la tape,
        // y los avisos justo debajo.
        if (str_contains($html, '<nav class="navegacion">')) {
            $html = preg_replace('/<nav class="navegacion">/', '<nav class="navegacion">' . $barra, $html, 1);
            $html = preg_replace('/<\/nav>/', '</nav>' . $o, $html, 1);
        } else {
            $html = preg_replace('/<body>/', '<body>' . $barra . $o, $html, 1);
        }
        return str_replace('</body>', $js . '</body>', $html);
    }

    // ---------------------------------------------------------------- calendario

    private const DURACION_EVENTO = 5;   // minutos

    /**
     * Las carreras de la reunión como eventos (hora de Uruguay). Las que no tienen hora quedan afuera.
     * @return array<int, array{uid:string, inicio:\DateTimeImmutable, fin:\DateTimeImmutable, titulo:string, lugar:string, detalle:string}>
     */
    private function eventos(array $p): array
    {
        $zona = new \DateTimeZone('America/Montevideo');
        $nombre = ProgramaNoOficial::nombreHipodromo((int)$p['hipodromo']);
        $res = [];
        if (self::sinHorario(array_column($p['carreras'], null, 'numero'))) {
            return [];
        }
        foreach ($p['carreras'] as $c) {
            if (!preg_match('/(\d{1,2}):(\d{2})/', (string)$c['hora'], $m)) {
                continue;
            }
            $inicio = (new \DateTimeImmutable($p['fecha'], $zona))->setTime((int)$m[1], (int)$m[2]);
            $res[$c['numero']] = [
                'uid' => sprintf('%s-%d-%d@granchance.com', $p['fecha'], $p['hipodromo'], $c['numero']),
                'inicio' => $inicio,
                'fin' => $inicio->modify('+' . self::DURACION_EVENTO . ' minutes'),
                'titulo' => sprintf('%dª %s · %s', $c['numero'], self::sigla((int)$p['hipodromo']), $c['premio'] ?: 'carrera'),
                'lugar' => $nombre,
                'detalle' => trim(($c['distancia'] ? $c['distancia'] . ' m en ' . $c['superficie'] . '. ' : '') . ($c['condicion'] ?? ''))
                    . "\nhttps://granchance.com/programas/{$p['fecha']}_{$p['hipodromo']}.html#carrera-{$c['numero']}",
            ];
        }
        return $res;
    }

    /**
     * El Programa preliminar (antes de las inscripciones definitivas) trae horas de relleno: la 1ª a la 01:00, la 2ª a
     * las 02:00, y así. Si todas las carreras tienen esa hora, el programa todavía no tiene horario.
     * @param array<int, array{hora:?string}> $carreras número de carrera => carrera
     */
    public static function sinHorario(array $carreras): bool
    {
        foreach ($carreras as $n => $c) {
            if ($c['hora'] !== sprintf('%02d:00', $n)) {
                return false;
            }
        }
        return (bool)$carreras;
    }

    private static function enlaceGoogle(array $ev): string
    {
        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE',
            'text' => $ev['titulo'],
            'dates' => $ev['inicio']->format('Ymd\THis') . '/' . $ev['fin']->format('Ymd\THis'),
            'ctz' => 'America/Montevideo',
            'location' => $ev['lugar'],
            'details' => $ev['detalle'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Calendario iCalendar (RFC 5545) con los eventos; en UTC para no depender de la zona del que lo importa. */
    private static function ics(array $eventos, string $nombre = 'GranChance'): string
    {
        $txt = fn (string $v) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $v);
        $utc = new \DateTimeZone('UTC');
        $l = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GranChance//Programas no oficiales//ES', 'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:' . $txt($nombre), 'X-WR-TIMEZONE:America/Montevideo', 'REFRESH-INTERVAL;VALUE=DURATION:PT12H'];
        foreach ($eventos as $ev) {
            array_push($l, 'BEGIN:VEVENT', 'UID:' . $ev['uid'], 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
                'DTSTART:' . $ev['inicio']->setTimezone($utc)->format('Ymd\THis\Z'),
                'DTEND:' . $ev['fin']->setTimezone($utc)->format('Ymd\THis\Z'),
                'SUMMARY:' . $txt($ev['titulo']), 'LOCATION:' . $txt($ev['lugar']), 'DESCRIPTION:' . $txt($ev['detalle']), 'END:VEVENT');
        }
        $l[] = 'END:VCALENDAR';
        // Líneas de hasta 75 bytes (las siguientes empiezan con un espacio), sin cortar caracteres UTF-8.
        $o = '';
        foreach ($l as $linea) {
            while (strlen($linea) > 75) {
                $corte = 75;
                while ($corte > 0 && (ord($linea[$corte]) & 0xC0) === 0x80) {
                    $corte--;
                }
                $o .= substr($linea, 0, $corte) . "\r\n";
                $linea = ' ' . substr($linea, $corte);
            }
            $o .= $linea . "\r\n";
        }
        return $o;
    }

    /** calendario.ics: las carreras de las reuniones publicadas desde hace una semana en adelante. */
    private function calendarioGeneral(): void
    {
        $desde = date('Y-m-d', strtotime('-7 day'));
        $eventos = '';
        foreach (glob("{$this->carpeta}/calendario/*.ics") ?: [] as $a) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})_\d+\.ics$/', $a, $m) && $m[1] >= $desde
                && preg_match_all('/BEGIN:VEVENT\r\n.*?END:VEVENT\r\n/s', (string)file_get_contents($a), $ev)) {
                $eventos .= implode('', $ev[0]);
            }
        }
        $vacio = self::ics([], 'GranChance · carreras');
        file_put_contents("{$this->carpeta}/calendario.ics", str_replace("END:VCALENDAR\r\n", $eventos . "END:VCALENDAR\r\n", $vacio));
    }

    /** Agrega al programa ya publicado el enlace a su página de resultado (sin regenerarlo). */
    public function enlazarResultado(int $hip, string $fecha): void
    {
        $archivo = sprintf('%s/programas/%s_%d.html', $this->carpeta, $fecha, $hip);
        $html = is_file($archivo) ? (string)file_get_contents($archivo) : '';
        if ($html === '' || str_contains($html, '../resultados/')) {
            return;
        }
        $enlace = ' · <a href="../resultados/' . $fecha . '_' . $hip . '.html">Predicción vs resultado</a>';
        file_put_contents($archivo, preg_replace('/(<div class="gc-barra"><a href="\.\.\/">[^<]*<\/a>)/', '$1' . $enlace, $html, 1));
    }

    /** fecha => [hipódromos] de los programas ya publicados. */
    public function fechasPublicadas(): array
    {
        $res = [];
        foreach (glob($this->carpeta . '/programas/*.html') ?: [] as $a) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d+)\.html$/', $a, $m)) {
                $res[$m[1]][] = (int)$m[2];
            }
        }
        ksort($res);
        return $res;
    }

    // ---------------------------------------------------------------- resultados

    /**
     * Página de predicción contra resultado de una reunión ya corrida, si hay predicciones y resultados.
     * @return ?array resumen de acierto de la reunión (ver medir)
     */
    public function resultado(int $hip, string $fecha): ?array
    {
        $carreras = $this->compararReunion($hip, $fecha);
        if (!$carreras) {
            return null;
        }
        $resumen = self::medir($carreras);
        $resumen['origen'] = $carreras[0]['origen'];
        file_put_contents(sprintf('%s/resultados/%s_%d.html', $this->carpeta, $fecha, $hip), $this->htmlResultado($hip, $fecha, $carreras, $resumen));
        $this->guardarResumen("{$fecha}_$hip", $resumen);
        return $resumen;
    }

    /** Reuniones con predicciones y resultados cargados (para generar las páginas que falten). */
    public function reunionesComparables(?string $desde = null): array
    {
        $st = $this->db->prepare('SELECT DISTINCT p.hipodromo_id, p.fecha FROM prediccion p
                                  JOIN reunion r ON r.hipodromo_id = p.hipodromo_id AND r.fecha = p.fecha
                                  WHERE p.fecha >= ? ORDER BY p.fecha, p.hipodromo_id');
        $st->execute([$desde ?? '2000-01-01']);
        return array_map(fn ($r) => [(int)$r['hipodromo_id'], $r['fecha']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Por carrera: lo predicho (en orden) con el puesto que sacó cada uno, y los primeros del resultado. */
    private function compararReunion(int $hip, string $fecha): array
    {
        $st = $this->db->prepare('SELECT * FROM prediccion WHERE hipodromo_id = ? AND fecha = ? ORDER BY carrera, orden');
        $st->execute([$hip, $fecha]);
        $pred = [];
        foreach ($st as $r) {
            $pred[(int)$r['carrera']][] = $r;
        }
        if (!$pred) {
            return [];
        }
        $st = $this->db->prepare('SELECT c.id, c.numero, c.nro_oficial, c.distancia, c.superficie, c.pista, c.premio, c.tiempo_ganador, c.retirados
                                  FROM carrera c JOIN reunion r ON r.id = c.reunion_id WHERE r.hipodromo_id = ? AND r.fecha = ? ORDER BY c.numero');
        $st->execute([$hip, $fecha]);
        $porNro = $porNumero = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $porNro[(int)$c['nro_oficial']] = $c;
            $porNumero[(int)$c['numero']] = $c;
        }
        $act = $this->db->prepare('SELECT ca.nombre, a.puesto, a.puesto_texto, a.sport FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id
                                   WHERE a.carrera_id = ? ORDER BY a.puesto IS NULL, a.puesto');
        $res = [];
        foreach ($pred as $n => $lista) {
            $c = $porNro[(int)$lista[0]['nro_oficial']] ?? $porNumero[$n] ?? null;
            if ($c === null) {
                continue;
            }
            $act->execute([$c['id']]);
            $llegada = $act->fetchAll(PDO::FETCH_ASSOC);
            $puesto = $sport = [];
            foreach ($llegada as $a) {
                $puesto[Personas::clave($a['nombre'])] = $a['puesto'] !== null ? (int)$a['puesto'] : null;
                $sport[Personas::clave($a['nombre'])] = $a['sport'] > 0 ? (float)$a['sport'] : null;
            }
            foreach ($lista as &$p) {
                $k = Personas::clave($p['caballo']);
                $p['corrio'] = array_key_exists($k, $puesto);
                $p['puesto'] = $puesto[$k] ?? null;
                $p['sport'] = $sport[$k] ?? null;
            }
            unset($p);
            $corrieron = array_values(array_filter($lista, fn ($p) => $p['corrio']));
            $ordenPorClave = [];
            foreach ($corrieron as $i => $p) {
                $ordenPorClave[Personas::clave($p['caballo'])] = $i + 1;      // orden entre los que largaron
            }
            $mandil = [];
            foreach ($lista as $p) {
                $mandil[Personas::clave($p['caballo'])] = $p['mandil'];
            }
            $favorito = null;
            foreach ($llegada as $a) {
                if ($a['sport'] > 0 && ($favorito === null || (float)$a['sport'] < (float)$favorito['sport'])) {
                    $favorito = $a;
                }
            }
            $res[] = ['numero' => $n, 'carrera' => $c, 'prediccion' => $lista, 'corrieron' => $corrieron, 'llegada' => $llegada,
                'orden_de' => $ordenPorClave, 'mandil_de' => $mandil, 'favorito' => $favorito, 'origen' => $lista[0]['origen']];
        }
        return $res;
    }

    /**
     * Acierto de un conjunto de carreras. Se mide con el orden entre los que largaron (si se retiró el primero
     * del programa, pasa a ser primero el segundo).
     */
    public static function medir(array $carreras): array
    {
        $m = ['carreras' => 0, 'ganador' => 0, 'place' => 0, 'ganador_top3' => 0, 'exacta' => 0, 'trifecta' => 0, 'favorito' => 0,
            'retorno' => 0.0, 'aciertos' => []];
        foreach ($carreras as $c) {
            $top = array_slice($c['corrieron'], 0, 3);
            $ganador = $c['llegada'][0] ?? null;
            if (!$top || !$ganador || (int)$ganador['puesto'] !== 1) {
                continue;
            }
            $m['carreras']++;
            $llegada = array_map(fn ($a) => Personas::clave($a['nombre']), array_filter($c['llegada'], fn ($a) => $a['puesto'] !== null && (int)$a['puesto'] <= 3));
            $nuestros = array_map(fn ($p) => Personas::clave($p['caballo']), $top);
            $ok = [];
            if ($top[0]['puesto'] === 1) {
                $m['ganador']++;
                $m['retorno'] += (float)$top[0]['sport'];
                $ok[] = 'ganador';
            }
            if ($top[0]['puesto'] !== null && $top[0]['puesto'] <= 3) {
                $m['place']++;
            }
            if (in_array(Personas::clave($ganador['nombre']), $nuestros, true)) {
                $m['ganador_top3']++;
            }
            $dos = array_slice($nuestros, 0, 2);
            $llegaDos = array_slice(array_values($llegada), 0, 2);
            if (count($dos) === 2 && count($llegaDos) === 2 && !array_diff($dos, $llegaDos)) {
                $m['exacta']++;
                $ok[] = 'exacta';
            }
            if (count($nuestros) === 3 && count($llegada) >= 3 && !array_diff($nuestros, array_slice(array_values($llegada), 0, 3))) {
                $m['trifecta']++;
                $ok[] = 'trifecta';
            }
            if ($c['favorito'] && (int)$c['favorito']['puesto'] === 1) {
                $m['favorito']++;
            }
            $m['aciertos'][$c['numero']] = $ok;
        }
        return $m;
    }

    private function htmlResultado(int $hip, string $fecha, array $carreras, array $r): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $titulo = sprintf('Predicción vs resultado · %s · %s', ProgramaNoOficial::nombreHipodromo($hip), date('d/m/Y', strtotime($fecha)));
        $clave = "{$fecha}_$hip";
        $o = $this->cabeceraHtml($titulo) . '<div class="gc-barra"><a href="../">GranChance · todos los programas</a>'
            . (is_file("{$this->carpeta}/programas/$clave.html") ? ' · <a href="../programas/' . $clave . '.html">Programa no oficial</a>' : '')
            . ' · <a href="estadisticas.html">Acierto acumulado</a></div>';
        $o .= '<h1>' . $e($titulo) . '</h1>';
        if ($r['origen'] === 'reconstruida') {
            $o .= '<p class="nota">Esta predicción se generó después de correrse la reunión (con los antecedentes anteriores a esa fecha), no se publicó antes.</p>';
        }
        $o .= self::tablaAcierto([['', $r]], false);
        $o .= '<p class="nota">El orden es el del programa no oficial (probabilidad del modelo), contando solo a los que largaron. '
            . '<b>Exacta</b> y <b>trifecta</b>: los 2 o 3 primeros del programa llegaron 1º-2º o 1º-2º-3º, en cualquier orden. '
            . '<b>Retorno</b>: lo que se cobraría apostando $1 a ganador al primero del programa en cada carrera.</p>';
        foreach ($carreras as $c) {
            $car = $c['carrera'];
            $ok = $r['aciertos'][$c['numero']] ?? [];
            $o .= '<section class="carrera" id="carrera-' . $c['numero'] . '"><h2>' . $c['numero'] . 'ª carrera · ' . $e($car['distancia']) . ' m'
                . ($car['superficie'] ? ' en ' . $e($car['superficie']) : '') . ($car['pista'] ? ' · pista ' . $e($car['pista']) : '')
                . ' <small>' . $e($car['premio']) . '</small>'
                . ($ok ? ' <span class="ok">' . implode(' · ', array_map(fn ($x) => ['ganador' => '✔ ganador', 'exacta' => '✔ exacta', 'trifecta' => '✔ trifecta'][$x], $ok)) . '</span>' : '')
                . '</h2><div class="dos"><table><thead><tr><th colspan="5">Programa no oficial</th></tr><tr><th>#</th><th>Nº</th><th>Caballo</th><th>Prob.</th><th>Llegó</th></tr></thead><tbody>';
            $i = 0;
            foreach ($c['prediccion'] as $p) {
                $cls = !$p['corrio'] ? 'retirado' : ($p['puesto'] !== null && $p['puesto'] <= 3 ? 'podio p' . $p['puesto'] : '');
                $o .= '<tr class="' . $cls . '"><td>' . ($p['corrio'] ? ++$i : '') . '</td><td>' . $e($p['mandil']) . '</td><td>' . $e($p['caballo']) . '</td><td>'
                    . ($p['prob'] !== null ? number_format(100 * (float)$p['prob'], 0) . ' %' : '') . '</td><td>'
                    . (!$p['corrio'] ? 'no corrió' : ($p['puesto'] !== null ? $p['puesto'] . 'º' : '—')) . '</td></tr>';
            }
            $o .= '</tbody></table><table><thead><tr><th colspan="5">Resultado</th></tr><tr><th>Puesto</th><th>Nº</th><th>Caballo</th><th>Sport</th><th>En el programa</th></tr></thead><tbody>';
            foreach (array_slice($c['llegada'], 0, 5) as $a) {
                $k = Personas::clave($a['nombre']);
                $fav = $c['favorito'] && $a['nombre'] === $c['favorito']['nombre'];
                $o .= '<tr class="' . ($a['puesto'] !== null && $a['puesto'] <= 3 ? 'podio p' . $a['puesto'] : '') . '"><td>' . $e($a['puesto_texto'] ?: $a['puesto']) . '</td><td>'
                    . $e($c['mandil_de'][$k] ?? '') . '</td><td>' . $e($a['nombre']) . ($fav ? ' <small title="Favorito del público">(fav.)</small>' : '') . '</td><td>'
                    . ($a['sport'] > 0 ? number_format((float)$a['sport'], 2, ',', '.') : '') . '</td><td>' . (isset($c['orden_de'][$k]) ? $c['orden_de'][$k] . 'º' : '') . '</td></tr>';
            }
            $o .= '</tbody></table></div>';
            if ($car['tiempo_ganador']) {
                $o .= '<p class="nota">Tiempo: ' . $e($car['tiempo_ganador']) . ($car['retirados'] ? ' · Retirados: ' . $e($car['retirados']) : '') . '</p>';
            }
            $o .= '</section>';
        }
        return $o . '</body></html>';
    }

    // ---------------------------------------------------------------- índice y estadísticas

    private function guardarResumen(string $clave, array $resumen): void
    {
        $archivo = $this->carpeta . '/resultados/resumen.json';
        $todo = is_file($archivo) ? (json_decode((string)file_get_contents($archivo), true) ?: []) : [];
        unset($resumen['aciertos']);
        $todo[$clave] = $resumen;
        ksort($todo);
        file_put_contents($archivo, json_encode($todo, JSON_PRETTY_PRINT));
    }

    /** Escribe indice.json (para index.php) y resultados/estadisticas.html con el acierto acumulado. */
    public function indice(): void
    {
        $resumen = is_file($this->carpeta . '/resultados/resumen.json')
            ? (json_decode((string)file_get_contents($this->carpeta . '/resultados/resumen.json'), true) ?: []) : [];
        $reuniones = [];
        foreach (['programas' => 'programa', 'resultados' => 'resultado'] as $sub => $tipo) {
            foreach (glob("{$this->carpeta}/$sub/*.html") ?: [] as $a) {
                if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d+)\.html$/', $a, $m)) {
                    $reuniones[$m[1] . '_' . $m[2]] ??= ['fecha' => $m[1], 'hipodromo' => (int)$m[2], 'nombre' => ProgramaNoOficial::nombreHipodromo((int)$m[2]),
                        'sigla' => self::sigla((int)$m[2]), 'programa' => null, 'resultado' => null, 'carreras' => null, 'acierto' => null];
                    $reuniones[$m[1] . '_' . $m[2]][$tipo] = "$sub/" . basename($a);
                }
            }
        }
        foreach ($reuniones as $k => &$r) {
            if (isset($resumen[$k])) {
                $r['carreras'] = $resumen[$k]['carreras'];
                $r['acierto'] = ['ganador' => $resumen[$k]['ganador'], 'trifecta' => $resumen[$k]['trifecta'], 'origen' => $resumen[$k]['origen']];
            }
        }
        unset($r);
        krsort($reuniones);
        $this->calendarioGeneral();
        file_put_contents($this->carpeta . '/indice.json', json_encode(['actualizado' => date('c'), 'analytics' => $this->analytics, 'reuniones' => array_values($reuniones)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Estadísticas: total, por hipódromo y por mes; las predicciones reconstruidas van aparte.
        $grupos = [];
        foreach ($resumen as $k => $r) {
            [$fecha, $hip] = explode('_', $k);
            $tipo = $r['origen'] === 'previa' ? 'Publicadas antes de la carrera' : 'Reconstruidas después';
            $grupos[$tipo]['Total'][] = $r;
            $grupos[$tipo][ProgramaNoOficial::nombreHipodromo((int)$hip)][] = $r;
            $grupos[$tipo][substr($fecha, 0, 7)][] = $r;
        }
        $o = $this->cabeceraHtml('Acierto acumulado') . '<div class="gc-barra"><a href="../">GranChance · todos los programas</a></div><h1>Acierto del programa no oficial</h1>'
            . '<p class="nota">Primero del programa: cuántas veces ganó (y su % de carreras), llegó en el marcador o el ganador estaba entre los 3 primeros del programa. '
            . 'Exacta y trifecta sin orden con los 2 y 3 primeros. Retorno por $1 apostado a ganador al primero del programa. Para comparar, cuántas veces ganó el favorito del público.</p>';
        krsort($grupos);
        foreach ($grupos as $tipo => $filas) {
            $o .= '<h2>' . htmlspecialchars($tipo) . '</h2>';
            $total = ['Total' => $filas['Total']];
            unset($filas['Total']);
            uksort($filas, fn ($a, $b) => [ctype_digit($a[0]), $b] <=> [ctype_digit($b[0]), $a]);
            $o .= self::tablaAcierto(array_map(fn ($n, $rs) => [$n, self::sumar($rs)], array_keys($total + $filas), $total + $filas), true);
        }
        file_put_contents($this->carpeta . '/resultados/estadisticas.html', $o . '</body></html>');
    }

    private static function sumar(array $rs): array
    {
        $t = ['reuniones' => count($rs)];
        foreach (['carreras', 'ganador', 'place', 'ganador_top3', 'exacta', 'trifecta', 'favorito', 'retorno'] as $k) {
            $t[$k] = array_sum(array_column($rs, $k));
        }
        return $t;
    }

    /** @param list<array{0:string,1:array}> $filas [etiqueta, medición] */
    private static function tablaAcierto(array $filas, bool $conEtiqueta): string
    {
        $pct = fn ($n, $d) => $d ? sprintf('%d <small>(%s %%)</small>', $n, number_format(100 * $n / $d, 1, ',', '.')) : '';
        $o = '<table class="acierto"><thead><tr>' . ($conEtiqueta ? '<th></th><th>Reuniones</th>' : '')
            . '<th>Carreras</th><th>Ganó el 1º</th><th>1º en el marcador</th><th>Ganador entre los 3 primeros</th><th>Exacta</th><th>Trifecta</th><th>Retorno por $1</th><th>Ganó el favorito</th></tr></thead><tbody>';
        foreach ($filas as [$etiqueta, $r]) {
            $n = $r['carreras'];
            $o .= '<tr>' . ($conEtiqueta ? '<td><b>' . htmlspecialchars($etiqueta) . '</b></td><td>' . $r['reuniones'] . '</td>' : '')
                . '<td>' . $n . '</td><td>' . $pct($r['ganador'], $n) . '</td><td>' . $pct($r['place'], $n) . '</td><td>' . $pct($r['ganador_top3'], $n)
                . '</td><td>' . $pct($r['exacta'], $n) . '</td><td>' . $pct($r['trifecta'], $n) . '</td><td>'
                . ($n ? '$' . number_format($r['retorno'] / $n, 2, ',', '.') : '') . '</td><td>' . $pct($r['favorito'], $n) . '</td></tr>';
        }
        return $o . '</tbody></table>';
    }

    private function cabeceraHtml(string $titulo): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($titulo) . '</title>' . self::analytics($this->analytics) . '<style>' . self::CSS_RESULTADOS . self::CSS_AVISOS . '</style></head><body>';
    }

    /** Etiqueta de Google Analytics 4 (config 'google_analytics', "G-XXXXXXX"); vacío si no está configurado. */
    public static function analytics(string $id): string
    {
        if (!preg_match('/^G-[A-Z0-9]+$/', $id)) {
            return '';
        }
        return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>'
            . "<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', '$id');</script>";
    }

    private static function sigla(int $id): string
    {
        return [1 => 'MRÑ', 4 => 'COL', 8 => 'FLO', 9 => 'FDA', 13 => 'LPD', 16 => 'MEL', 21 => 'PAY', 22 => 'ROC'][$id] ?? (string)$id;
    }

    private static function kg(int|float|string|null $v): string
    {
        return number_format((float)$v, 1, ',', '.');
    }

    private const CSS_AVISOS = <<<'CSS'
.gc-barra { display:flex; flex-wrap:wrap; gap:6px; align-items:baseline; padding:4px 0 6px; }
.gc-barra a { color:var(--acento); text-decoration:none; font-weight:600; }
.gc-aviso { border:1px solid var(--linea); border-left:4px solid var(--menos); border-radius:6px; padding:6px 10px; margin:6px 0; background:var(--cabecera); }
.gc-aviso ul { margin:4px 0 0; padding-left:18px; } .gc-aviso a { color:var(--acento); }
.gc-cal { text-decoration:none; font-size:15px; margin-left:4px; }
.nro-b { display:inline-block; min-width:18px; text-align:center; border-radius:4px; font-weight:700; border:1px solid var(--linea); text-decoration:line-through; }
CSS;

    private const CSS_RESULTADOS = <<<'CSS'
:root { --fondo:#fff; --texto:#1d1d1f; --suave:#6b6b70; --linea:#e3e3e6; --cabecera:#f2f2f4; --mas:#0b7a3e; --menos:#b3261e; --acento:#1f4e8c; --oro:#f6e7a8; --plata:#e4e7ec; --bronce:#f1d5bd; }
@media (prefers-color-scheme: dark) { :root { --fondo:#161618; --texto:#ececef; --suave:#a0a0a8; --linea:#34343a; --cabecera:#232327; --mas:#5fd08f; --menos:#ff8a80; --acento:#8ab4f8; --oro:#5a4b12; --plata:#3a3d44; --bronce:#4f3522; } }
* { box-sizing:border-box; }
body { margin:0; padding:16px; background:var(--fondo); color:var(--texto); font:13px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; }
h1 { font-size:20px; margin:4px 0 8px; } h2 { font-size:16px; margin:0 0 6px; color:var(--acento); }
small, .nota { color:var(--suave); font-weight:normal; } .nota { max-width:1000px; }
.carrera { border-top:3px solid var(--acento); margin-top:22px; padding-top:8px; }
.dos { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start; }
table { border-collapse:collapse; margin:6px 0; font-variant-numeric:tabular-nums; display:block; overflow-x:auto; max-width:100%; }
th { background:var(--cabecera); text-align:left; font-weight:600; }
th, td { border:1px solid var(--linea); padding:2px 6px; white-space:nowrap; }
.p1 td { background:var(--oro); } .p2 td { background:var(--plata); } .p3 td { background:var(--bronce); }
.retirado { opacity:.45; text-decoration:line-through; }
.ok { color:var(--mas); font-size:13px; font-weight:700; margin-left:8px; }
.acierto td:first-child { white-space:nowrap; }
CSS;
}
