<?php
declare(strict_types=1);

namespace GranChance\HK;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Lee las páginas del HKJC: el calendario (Fixtures), los resultados de una carrera (localresults) y
 * Sectional Time & Position de la reunión (displaysectionaltime&All=True).
 */
final class ParserResultados
{
    private const LB_KG = 0.45359237;

    /** @return list<array{fecha: string, hipodromo: string, turno: ?string, pistas: ?string}> */
    public function calendario(string $html, int $anio, int $mes): array
    {
        $x = self::xpath($html);
        // Un mes sin carreras (agosto, entre temporadas) muestra el calendario del mes siguiente.
        $titulo = trim($x->evaluate('string(//table//thead//td[@colspan="7"])'));
        if (!preg_match('#^(\d{1,2})/(\d{4})$#', $titulo, $mt) || (int)$mt[1] !== $mes || (int)$mt[2] !== $anio) {
            return [];
        }
        $reuniones = [];
        foreach ($x->query('//td[contains(concat(" ", @class, " "), " calendar ")]') as $td) {
            $dia = trim($x->evaluate('string(.//span[contains(@class,"f_fs14")])', $td));
            $alts = [];
            foreach ($x->query('.//img/@alt', $td) as $a) {
                $alts[] = trim($a->nodeValue);
            }
            $hip = array_values(array_intersect($alts, ['ST', 'HV']));
            if ($hip === [] || !ctype_digit($dia)) {
                continue;       // otro tipo de evento (simulcast, ensayos)
            }
            $reuniones[] = [
                'fecha' => sprintf('%04d-%02d-%02d', $anio, $mes, (int)$dia),
                'hipodromo' => $hip[0],
                'turno' => array_values(array_intersect($alts, ['D', 'N', 'T']))[0] ?? null,
                'pistas' => array_values(array_intersect($alts, ['turf', 'awt', 'mixed']))[0] ?? null,
            ];
        }
        return $reuniones;
    }

    /** Cantidad de carreras de la reunión, de los enlaces a cada carrera de la página de resultados. */
    public function cantidadCarreras(string $html): int
    {
        preg_match_all('#localresults\?[^"\']*RaceNo=(\d+)#i', $html, $m);
        return $m[1] === [] ? 1 : max(array_map('intval', $m[1]));
    }

    /** Hipódromo y fecha que muestra la página ("Race Meeting: 14/10/2015 Happy Valley"). */
    public function reunion(string $html): ?array
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{a0}", ' ', $t);
        if (!preg_match('#Race Meeting:\s*(\d\d)/(\d\d)/(\d{4})\s+(Sha Tin|Happy Valley)#', $t, $m)) {
            return null;
        }
        return ['fecha' => "$m[3]-$m[2]-$m[1]", 'hipodromo' => $m[4] === 'Sha Tin' ? 'ST' : 'HV'];
    }

    public function carrera(string $html): array
    {
        $x = self::xpath($html);
        $tab = $x->query('//div[contains(@class,"race_tab")]//table')->item(0);
        if ($tab === null) {
            throw new \RuntimeException('no se encontró la tabla de la carrera');
        }
        $titulo = self::texto($x->query('.//thead//td', $tab)->item(0));
        preg_match('#RACE\s+(\d+)\s*(?:\((\d+)\))?#i', $titulo, $mt);
        $c = [
            'numero' => (int)($mt[1] ?? 0), 'nro_oficial' => isset($mt[2]) && $mt[2] !== '' ? (int)$mt[2] : null,
            'premio' => null, 'distancia' => null, 'superficie' => null, 'recorrido' => null, 'pista' => null,
            'clase' => null, 'rating' => null, 'condicion' => null, 'bolsa' => null, 'bolsa_detalle' => null,
            'tiempo_ganador' => null, 'tiempo_ganador_seg' => null, 'parciales' => null, 'parciales_tramos' => [],
            'retirados' => null, 'comentario' => null, 'actuaciones' => [], 'apuestas' => [], 'padre_ganador' => null, 'madre_ganador' => null,
            'suspendida' => str_contains($html, 'This race has been abandoned or not offered'),
        ];

        $acum = [];
        $tramos = [];
        foreach ($x->query('./tbody/tr', $tab) as $tr) {
            $tds = iterator_to_array($x->query('./td', $tr));
            if (count($tds) < 2) {
                continue;
            }
            $izq = self::texto($tds[0]);
            $etiqueta = rtrim(self::texto($tds[1]), ' :');
            $valores = array_slice($tds, 2);
            switch ($etiqueta) {
                case 'Going':
                    $c['condicion'] = $izq;
                    self::condicion($izq, $c);
                    $c['pista'] = self::texto($valores[0] ?? null);
                    break;
                case 'Course':
                    $c['premio'] = $izq;
                    self::recorrido(self::texto($valores[0] ?? null), $c);
                    break;
                case 'Time':
                    $c['bolsa_detalle'] = $izq;
                    if (preg_match('#HK\$\s*([\d,]+)#', $izq, $mb)) {
                        $c['bolsa'] = (float)str_replace(',', '', $mb[1]);
                    }
                    foreach ($valores as $v) {
                        $acum[] = trim(self::texto($v), '()');
                    }
                    break;
                case 'Sectional Time':
                    foreach ($valores as $v) {
                        $tramos[] = self::tramo($x, $v);
                    }
                    break;
            }
        }
        $acum = array_values(array_filter($acum, fn($t) => $t !== ''));
        foreach ($tramos as $i => $t) {
            $c['parciales_tramos'][] = ['tramo' => $i + 1, 'tiempo_acum' => self::segundos($acum[$i] ?? null), 'tiempo' => $t['tiempo'], 'sub200' => $t['sub200']];
        }
        $c['parciales'] = implode(' ', array_map(fn($t) => $t['tiempo'] ?? '-', $tramos)) ?: null;
        if ($acum !== []) {
            $c['tiempo_ganador'] = end($acum);
            $c['tiempo_ganador_seg'] = self::segundos($c['tiempo_ganador']);
        }

        $retirados = [];
        foreach ($x->query('//div[contains(@class,"performance") and not(contains(@class,"race_incident_report"))]//table/tbody/tr') as $tr) {
            $a = $this->actuacion($x, $tr);
            if ($a === null) {
                continue;
            }
            if ($a['puesto'] === null && str_starts_with((string)$a['puesto_texto'], 'W')) {
                $retirados[] = $a['numero'] ?? $a['nombre'];
            }
            $c['actuaciones'][] = $a;
        }
        $c['retirados'] = $retirados === [] ? null : implode(' ', $retirados);

        // Suspendida: el aviso del HKJC o, en algunas, solo "RACE N (0)" sin tiempos.
        $c['suspendida'] = $c['suspendida'] || ($c['tiempo_ganador'] === null && array_filter($c['actuaciones'], fn($a) => $a['tiempo'] !== null) === []);
        $c['apuestas'] = $this->dividendos($x);
        // Racing Incident Report: hasta ~2024 es un texto corrido; después, una fila por caballo.
        $porCaballo = [];
        foreach ($x->query('//div[contains(@class,"race_incident_report")]//table/tbody/tr') as $tr) {
            $tds = iterator_to_array($x->query('./td', $tr));
            if (count($tds) >= 4 && ($t = self::texto($tds[3])) !== '') {
                $porCaballo[self::texto($tds[1]) . '|' . self::texto($x->query('.//a', $tds[2])->item(0) ?? $tds[2])] = $t;
            }
        }
        if ($porCaballo !== []) {
            $lineas = [];
            foreach ($c['actuaciones'] as &$a) {
                $a['incidente'] = $porCaballo[$a['numero'] . '|' . $a['nombre']] ?? null;
                if ($a['incidente'] !== null && $a['incidente'] !== 'No report.') {
                    $lineas[] = "{$a['nombre']}: {$a['incidente']}";
                }
            }
            unset($a);
            $c['comentario'] = $lineas === [] ? null : implode("\n", $lineas);
        }
        $inc = $x->query('//div[contains(@class,"race_incident_report")]//p[contains(@class,"info_p")]')->item(0);
        if ($inc !== null) {
            $partes = [];
            foreach ($inc->childNodes as $n) {
                $partes[] = $n->nodeName === 'br' ? "\n" : $n->textContent;
            }
            $c['comentario'] = trim((string)preg_replace('/[ \t]+/', ' ', implode('', $partes))) ?: null;
        }
        foreach ($x->query('//table[contains(@class,"hourse_breed_tab")]//p') as $p) {
            $t = self::texto($p);
            if (preg_match('#^Sire\s*:\s*(.+)$#', $t, $m)) {
                $c['padre_ganador'] = trim($m[1]);
            } elseif (preg_match('#^Dam\s*:\s*(.+)$#', $t, $m)) {
                $c['madre_ganador'] = trim($m[1]);
            }
        }
        return $c;
    }

    /** @return array<int, list<array>> número de carrera => filas de parciales por caballo */
    public function parciales(string $html): array
    {
        $x = self::xpath($html);
        $carreras = [];
        foreach ($x->query('//div[starts-with(@id,"Race")]') as $div) {
            if (!preg_match('#^Race(\d+)$#', $div->getAttribute('id'), $m)) {
                continue;
            }
            $filas = [];
            foreach ($x->query('.//table[contains(@class,"race_table")]/tbody/tr', $div) as $tr) {
                $tds = iterator_to_array($x->query('./td', $tr));
                if (count($tds) < 4) {
                    continue;
                }
                $enlace = $x->query('.//a', $tds[2])->item(0);
                $codigo = $enlace instanceof DOMElement && preg_match('#horseid=([A-Z0-9_]+)#i', $enlace->getAttribute('href'), $mh) ? strtoupper($mh[1]) : null;
                $tramos = [];
                foreach (array_slice($tds, 3, -1) as $i => $td) {
                    $pos = $x->query('.//p[contains(@class,"f_clear")]', $td)->item(0);
                    if ($pos === null) {
                        continue;       // tramo que la carrera no tiene
                    }
                    $ps = $x->query('.//p', $td);
                    $t = $ps->length > 1 ? self::tramo($x, $ps->item(1)) : ['tiempo' => null, 'sub200' => null];
                    $posicion = self::entero(self::texto($x->query('.//span', $pos)->item(0)));
                    if ($posicion === null && $t['tiempo'] === null) {
                        continue;       // retirado, o tramo que no llegó a correr
                    }
                    $tramos[] = [
                        'tramo' => $i + 1,
                        'posicion' => $posicion,
                        'margen' => self::texto($x->query('.//i', $pos)->item(0)) ?: null,
                        'tiempo' => $t['tiempo'],
                        'sub200' => $t['sub200'],
                    ];
                }
                $filas[] = ['codigo' => $codigo, 'numero' => self::entero(self::texto($tds[1])), 'puesto_texto' => self::texto($tds[0]), 'tramos' => $tramos];
            }
            $carreras[(int)$m[1]] = $filas;
        }
        return $carreras;
    }

    /** Ficha del caballo: país, pelaje y sexo, tipo de importación, propietario, padre, madre y abuelo materno. */
    public function ficha(string $html): array
    {
        $x = self::xpath($html);
        $campos = [];
        foreach ($x->query('//table[contains(@class,"table_eng_text")]//tr') as $tr) {
            $tds = iterator_to_array($x->query('./td', $tr));
            if (count($tds) === 3 && self::texto($tds[1]) === ':') {
                $campos[self::texto($tds[0])] = self::texto($tds[2]);
            }
        }
        $origen = $campos['Country of Origin'] ?? $campos['Country of Origin / Age'] ?? null;
        [$pelaje, $sexo] = array_pad(array_map('trim', explode('/', $campos['Colour / Sex'] ?? '')), 2, null);
        $v = fn(?string $t) => $t === null || $t === '' ? null : $t;
        return [
            'pais' => $v(trim(explode('/', (string)$origen)[0])),
            'pelaje' => $v($pelaje),
            'sexo' => $v($sexo),
            'tipo_importacion' => $v($campos['Import Type'] ?? null),
            'propietario' => $v(substr($campos['Owner'] ?? '', 0, 255)),
            'padre' => $v($campos['Sire'] ?? null),
            'madre' => $v($campos['Dam'] ?? null),
            'abuelo_materno' => $v($campos["Dam's Sire"] ?? null),
        ];
    }

    private function actuacion(DOMXPath $x, DOMNode $tr): ?array
    {
        $tds = iterator_to_array($x->query('./td', $tr));
        if (count($tds) < 12) {
            return null;
        }
        $enlace = $x->query('.//a', $tds[2])->item(0);
        $textoCaballo = self::texto($tds[2]);
        preg_match('#^(.*?)\s*\(([A-Z0-9]+)\)\s*$#', $textoCaballo, $mc);
        $codigo = $enlace instanceof DOMElement && preg_match('#horseid=([A-Z0-9_]+)#i', $enlace->getAttribute('href'), $mh) ? strtoupper($mh[1]) : null;
        $nombre = $mc[1] ?? $textoCaballo;
        $marca = $mc[2] ?? null;
        if ($codigo === null && $marca !== null) {
            $codigo = 'MARCA_' . $marca;    // sin enlace a la ficha: se identifica por la marca
        }
        $puestoTexto = self::texto($tds[0]);
        $posiciones = [];
        foreach ($x->query('.//div/div', $tds[9]) as $d) {
            if (($t = self::texto($d)) !== '') {
                $posiciones[] = $t;
            }
        }
        $pesoLb = self::entero(self::texto($tds[5]));
        $pesoCabLb = self::entero(self::texto($tds[6]));
        $tiempo = self::texto($tds[10]);
        $cuota = str_replace(',', '', self::texto($tds[11]));
        return [
            'codigo' => $codigo, 'marca' => $marca, 'nombre' => $nombre,
            'puesto' => preg_match('#^\d+#', $puestoTexto, $mp) ? (int)$mp[0] : null,
            'puesto_texto' => $puestoTexto ?: null,
            'numero' => self::entero(self::texto($tds[1])),
            'jockey' => self::persona($x, $tds[3], 'jockeyid'),
            'cuidador' => self::persona($x, $tds[4], 'trainerid'),
            'peso_asignado_lb' => $pesoLb,
            'peso_asignado' => $pesoLb === null ? null : round($pesoLb * self::LB_KG, 1),
            'peso_caballo_lb' => $pesoCabLb,
            'peso_caballo' => $pesoCabLb === null ? null : (int)round($pesoCabLb * self::LB_KG),
            'gatera' => self::entero(self::texto($tds[7])),
            'dist_acumulada' => self::texto($tds[8]) ?: null,
            'posiciones' => $posiciones === [] ? null : implode(' ', $posiciones),
            'tiempo' => preg_match('#\d#', $tiempo) ? $tiempo : null,
            'tiempo_seg' => self::segundos($tiempo),
            'cuota' => is_numeric($cuota) ? (float)$cuota : null,
            'incidente' => null,
        ];
    }

    private function dividendos(DOMXPath $x): array
    {
        $tabla = $x->query('//div[contains(@class,"dividend_tab")]//table')->item(0);
        if ($tabla === null) {
            return [];
        }
        $apuestas = [];
        $tipo = null;
        $orden = [];
        foreach ($x->query('./tbody/tr', $tabla) as $tr) {
            $tds = iterator_to_array($x->query('./td', $tr));
            if ($tds === []) {
                continue;
            }
            if (str_contains($tds[0]->getAttribute('class'), 'fontXi') || count($tds) >= 3) {
                $tipo = self::texto(array_shift($tds));
            }
            if ($tipo === null || count($tds) < 2) {
                continue;
            }
            $orden[$tipo] = ($orden[$tipo] ?? 0) + 1;
            $div = str_replace(',', '', self::texto($tds[1]));
            $apuestas[] = [
                'tipo' => substr($tipo, 0, 30), 'orden' => $orden[$tipo], 'monto_base' => 10.0,
                'combinacion' => self::texto($tds[0]) ?: null,
                'dividendo' => is_numeric($div) ? (float)$div : null,
                'detalle' => is_numeric($div) ? null : (self::texto($tds[1]) ?: null),
                'pozo' => null,
            ];
        }
        return $apuestas;
    }

    /** "Class 5 - 1200M - (40-15)", "Group One - 2000M", "Griffin Race - 1000M"... */
    private static function condicion(string $t, array &$c): void
    {
        if (preg_match('#(\d{3,4})\s*M\b#', $t, $m)) {
            $c['distancia'] = (int)$m[1];
        }
        if (preg_match('#\((\d+-\d+)\)#', $t, $m)) {
            $c['rating'] = $m[1];
        }
        $clase = trim((string)preg_replace('#\s*-\s*\d{3,4}\s*M\b.*$#', '', $t));
        $c['clase'] = $clase !== '' ? substr($clase, 0, 40) : null;
    }

    /** "TURF - "B" Course", "ALL WEATHER TRACK" */
    private static function recorrido(string $t, array &$c): void
    {
        if ($t === '') {
            return;
        }
        $c['superficie'] = stripos($t, 'ALL WEATHER') !== false ? 'sintética' : (stripos($t, 'TURF') !== false ? 'césped' : null);
        if (preg_match('#"([^"]+)"#', $t, $m)) {
            $c['recorrido'] = $m[1];
        }
    }

    /** Celda de tiempo de tramo: "23.40" y, en carreras nuevas, los parciales de 200 m debajo. */
    private static function tramo(DOMXPath $x, DOMNode $celda): array
    {
        $sub = [];
        foreach ($x->query('.//div | ./span', $celda) as $n) {
            $sub[] = self::texto($n);
            $n->parentNode->removeChild($n);
        }
        preg_match_all('#\d+\.\d+#', implode(' ', $sub), $ms);
        $t = self::texto($celda);
        return ['tiempo' => is_numeric($t) ? (float)$t : null, 'sub200' => $ms[0] === [] ? null : implode(' ', $ms[0])];
    }

    /** @return array{nombre: string, codigo: ?string}|null */
    private static function persona(DOMXPath $x, DOMNode $td, string $param): ?array
    {
        $nombre = self::texto($td);
        if ($nombre === '' || $nombre === '---') {
            return null;
        }
        $a = $x->query('.//a', $td)->item(0);
        $codigo = $a instanceof DOMElement && preg_match('#' . $param . '=([A-Z0-9]+)#i', $a->getAttribute('href'), $m) ? strtoupper($m[1]) : null;
        return ['nombre' => $nombre, 'codigo' => $codigo];
    }

    /** "1:10.42" o "59.31" a segundos. */
    public static function segundos(?string $t): ?float
    {
        if ($t === null || !preg_match('#^(?:(\d+):)?(\d+(?:\.\d+)?)$#', trim($t), $m)) {
            return null;
        }
        return round((int)$m[1] * 60 + (float)$m[2], 2);
    }

    private static function entero(string $t): ?int
    {
        return preg_match('#^\d+$#', $t) ? (int)$t : null;
    }

    private static function texto(?DOMNode $n): string
    {
        if ($n === null) {
            return '';
        }
        return trim((string)preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $n->textContent)));
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!str_contains(substr($html, 0, 2000), 'charset')) {
            $html = '<meta charset="utf-8">' . $html;
        }
        $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }
}
