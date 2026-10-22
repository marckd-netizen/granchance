<?php
declare(strict_types=1);

namespace GranChance\JP;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Lee las páginas del sitio en inglés de la JRA: el calendario del año, las carreras de un día y la página de cada carrera.
 */
final class ParserResultados
{
    /** Códigos de hipódromo de la JRA (raceJoCd). */
    public const HIPODROMOS = [
        '01' => 'Sapporo', '02' => 'Hakodate', '03' => 'Fukushima', '04' => 'Niigata', '05' => 'Tokyo',
        '06' => 'Nakayama', '07' => 'Chukyo', '08' => 'Kyoto', '09' => 'Hanshin', '10' => 'Kokura',
    ];

    /** @return list<string> días de carreras del año (AAAA-MM-DD), en orden */
    public function calendario(string $html): array
    {
        preg_match_all("#displayRace\('SelectRace',\s*'(\d{4})(\d\d)(\d\d)'\)#", $html, $m, PREG_SET_ORDER);
        $dias = array_values(array_unique(array_map(fn($x) => "$x[1]-$x[2]-$x[3]", $m)));
        sort($dias);
        return $dias;
    }

    /**
     * Reuniones de un día: hipódromo, reunión del año (kai), día de la reunión (hi) y cada carrera con su hora y su rótulo
     * ("11R OPN (G3) T1600").
     * @return list<array{fecha: string, hipodromo: string, kai: string, hi: string, carreras: array<int, array{hora: ?string, rotulo: ?string}>}>
     */
    public function dia(string $html): array
    {
        $reuniones = [];
        $re = "#(?:<TD class=\"startTime\">\s*([\d:]*)\s*</TD>\s*<TD[^>]*>\s*)?<A class=\"uline\" href=\"javascript:displayRunning\('SelectRunning',\s*"
            . "'(\d{4})(\d\d)(\d\d)','(\d\d)','(\d\d)','(\d\d)','(\d\d)'\)\">\s*<FONT[^>]*>(.*?)</FONT>#si";
        preg_match_all($re, $html, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $clave = "$x[5]|$x[6]|$x[7]";
            $reuniones[$clave] ??= ['fecha' => "$x[2]-$x[3]-$x[4]", 'hipodromo' => $x[5], 'kai' => $x[6], 'hi' => $x[7], 'carreras' => []];
            $nro = (int)$x[8];
            $rotulo = trim(html_entity_decode(strip_tags($x[9]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // Cada carrera figura en la grilla (con hora) y las principales, además, arriba con su nombre.
            if ($x[1] !== '' || !isset($reuniones[$clave]['carreras'][$nro])) {
                $reuniones[$clave]['carreras'][$nro] = ['hora' => $x[1] !== '' ? $x[1] : ($reuniones[$clave]['carreras'][$nro]['hora'] ?? null), 'rotulo' => $rotulo];
            }
        }
        foreach ($reuniones as &$r) {
            ksort($r['carreras']);
        }
        unset($r);
        uasort($reuniones, fn($a, $b) => $a['hipodromo'] <=> $b['hipodromo']);
        return array_values($reuniones);
    }

    /**
     * Una carrera: cabecera (fecha, distancia, superficie, estado de la pista, tiempo, hora, condición, premios), cada caballo
     * con su puesto, posiciones en los 4 codos, tiempo, cuota y pedigrí, los parciales del puntero cada 200 m y los dividendos.
     * Antes de correrse la misma página trae el programa (sin puestos): 'oficial' queda en false.
     */
    public function carrera(string $html): array
    {
        $x = self::xpath($html);
        $t = self::limpiar($x->evaluate('string(//body)'));
        $c = [
            'hipodromo' => null, 'numero' => null, 'fecha' => null, 'premio' => null, 'grado' => null, 'distancia' => null,
            'superficie' => null, 'pista' => null, 'clima' => null, 'hora' => null, 'condicion' => null, 'clase' => null, 'edad' => null,
            'peso_tipo' => null, 'sexo' => null, 'marcas' => null, 'bolsa' => null, 'premios' => [], 'premio_base' => null, 'bolsa_detalle' => null,
            'tiempo_ganador' => null, 'tiempo_ganador_seg' => null, 'parciales' => null, 'parciales_tramos' => [], 'retirados' => null,
            'oficial' => false, 'actuaciones' => [], 'apuestas' => [],
        ];
        if (!preg_match('/\b(SAPPORO|HAKODATE|FUKUSHIMA|NIIGATA|TOKYO|NAKAYAMA|CHUKYO|KYOTO|HANSHIN|KOKURA) (\d{1,2})R\b(?! \d)/', $t, $m)) {
            throw new \RuntimeException('no se encontró el hipódromo y la carrera');
        }
        $c['hipodromo'] = (string)array_search(ucfirst(strtolower($m[1])), self::HIPODROMOS, true);
        $c['numero'] = (int)$m[2];
        $titulo = self::limpiar($x->evaluate('string(//font[@size="4"]/b[contains(., "' . $m[0] . '")]/ancestor::tr[1]/following-sibling::tr[1])'));
        if ($titulo !== '') {
            $c['premio'] = $titulo;
            if (preg_match('/\((J\s*\.\s*)?(G[1-3]|L|OP|Jpn[1-3])\)\s*$/i', $titulo, $mg)) {
                $c['grado'] = ($mg[1] !== '' ? 'J.' : '') . strtoupper($mg[2]);
            }
        }
        if (preg_match('/([A-Z][a-z]+ \d{1,2}, \d{4}), (\d{3,4})m, (Turf|Dirt|Jump) ([A-Za-z]+)?,?\s*([A-Za-z ]+?)?,\s*Post time (\d{1,2}:\d\d)/', $t, $m)) {
            $c['fecha'] = date('Y-m-d', strtotime($m[1]));
            $c['distancia'] = (int)$m[2];
            $c['superficie'] = $m[3];
            $c['pista'] = ($m[4] ?? '') !== '' ? $m[4] : null;
            $c['clima'] = trim($m[5] ?? '') !== '' ? trim($m[5]) : null;
            $c['hora'] = strlen($m[6]) === 4 ? '0' . $m[6] : $m[6];
        } elseif (preg_match('/([A-Z][a-z]+ \d{1,2}, \d{4}), (\d{3,4})m, (Turf|Dirt|Jump)/', $t, $m)) {
            [$c['fecha'], $c['distancia'], $c['superficie']] = [date('Y-m-d', strtotime($m[1])), (int)$m[2], $m[3]];
        }
        if (preg_match('/\n?([^\n]*?)Value of race: ([\d,]+) Yen/', $t, $m)) {
            $c['condicion'] = trim($m[1], " ,");
            $c['bolsa'] = (float)str_replace(',', '', $m[2]);
            self::condicion($c['condicion'], $c);
        }
        // Premios del 1º al 5º: la fila "Total (Yen)".
        foreach ($x->query('//tr[td[1][normalize-space(.)="Total (Yen)"]]/td[position() > 1]') as $i => $td) {
            if ($i < 5) {
                $c['premios'][] = (float)str_replace(',', '', self::texto($td));
            }
        }
        // El premio base del 1º (sin las inscripciones) marca la clase de las Allowance (1, 2 y 3 Win).
        $base = self::texto($x->query('//tr[td[1][normalize-space(.)="Added Money (Yen)"]]/td[2]')->item(0));
        $c['premio_base'] = $base !== '' ? (float)str_replace(',', '', $base) : null;
        $c['bolsa_detalle'] = $c['premios'] ? implode(' ', array_map(fn($p) => number_format($p, 0, '', ''), $c['premios'])) : null;

        $tabla = $x->query('//td[@class="horseHead" and normalize-space(.)="FP"]/ancestor::table[1]')->item(0);
        $c['oficial'] = $x->query('//td[@class="head3" and normalize-space(.)="Official"]')->length > 0;
        $ped = $this->pedigri($x);
        if ($tabla !== null && $c['oficial']) {
            foreach ($x->query('./tr[td[@class="raceHorse"]] | ./tbody/tr[td[@class="raceHorse"]]', $tabla) as $tr) {
                $a = $this->actuacion($x, $tr);
                if ($a !== null) {
                    $a += $ped[$a['codigo']] ?? self::SIN_PEDIGRI;
                    $c['actuaciones'][] = $a;
                }
            }
        }
        $retirados = [];
        foreach ($c['actuaciones'] as $a) {
            if ($a['puesto'] === null && $a['cuota'] === null) {
                $retirados[] = $a['numero'];
            }
            if ($a['puesto'] === 1 && $c['tiempo_ganador'] === null) {
                [$c['tiempo_ganador'], $c['tiempo_ganador_seg']] = [$a['tiempo'], $a['tiempo_seg']];
            }
        }
        $c['retirados'] = $retirados ? implode(' ', $retirados) : null;

        $acum = self::lapsos($x, 'Pas.tm');
        $parc = self::lapsos($x, 'Lap tm');
        foreach ($parc as $i => $p) {
            $c['parciales_tramos'][] = ['tramo' => $i + 1, 'tiempo_acum' => $acum[$i] ?? null, 'tiempo' => $p];
        }
        $c['parciales'] = $parc ? implode(' ', array_map(fn($p) => number_format($p, 1, '.', ''), $parc)) : null;
        $c['apuestas'] = $this->apuestas($x);
        $c['pedigri'] = $ped;
        return $c;
    }

    private const SIN_PEDIGRI = ['padre' => null, 'madre' => null, 'abuelo_materno' => null, 'abuela_materna' => null,
        'jockey' => null, 'cuidador' => null, 'propietario' => null, 'criador' => null];

    /** "MIX DES, Weight for Age, 3-Year-Olds, Maiden": marcas, tipo de peso, edad y clase. */
    private static function condicion(string $cond, array &$c): void
    {
        $partes = array_map('trim', explode(',', $cond));
        $marcas = array_shift($partes) ?? '';
        $c['marcas'] = $marcas !== '' ? $marcas : null;
        if (preg_match('/\b(F&M|C&F)\b/', $marcas, $m)) {
            $c['sexo'] = $m[1] === 'F&M' ? 'hembras' : 'machos y hembras';
        }
        foreach ($partes as $p) {
            if (preg_match('/^(Special Weight|Weight for Age|Handicap|Allowance Weight|Set Weight)$/i', $p)) {
                $c['peso_tipo'] = $p;
            } elseif (preg_match('/Year-Olds/i', $p)) {
                $c['edad'] = $p;
            } elseif ($p !== '') {
                $c['clase'] = $p;
            }
        }
    }

    /** Fila de un caballo en la tabla de resultados. */
    private function actuacion(DOMXPath $x, DOMElement $tr): ?array
    {
        $tds = iterator_to_array($x->query('./td', $tr));
        if (count($tds) < 13) {
            return null;
        }
        $enlace = $x->query('.//a[@horseno]', $tds[3])->item(0);
        $nombre = self::texto($tds[3]);
        $pais = null;
        if (preg_match('/^(.*?)\s*\(([A-Z]{2,4})\)$/', $nombre, $m)) {
            [$nombre, $pais] = [$m[1], $m[2]];
        }
        $fp = self::texto($tds[0]);
        $puesto = preg_match('/^(\d+)(st|nd|rd|th)/', $fp, $m) ? (int)$m[1] : null;
        $sexoEdad = self::texto($tds[4]);
        $tiempo = self::texto($tds[10]);
        if ($fp === '' && $tiempo !== '' && self::segundos($tiempo) === null) {
            [$fp, $tiempo] = [$tiempo, ''];     // los que no llegaron: el motivo va en la columna del tiempo (FF, SC...)
        }
        $cuota = str_replace(',', '', self::texto($tds[13] ?? null));
        $pos = array_map(fn($i) => self::texto($tds[$i]), [6, 7, 8, 9]);
        return [
            'codigo' => $enlace instanceof DOMElement ? trim($enlace->getAttribute('horseno')) : null,
            'nombre' => $nombre, 'pais' => $pais,
            'puesto' => $puesto, 'puesto_texto' => $puesto !== null ? (string)$puesto . (str_contains($fp, 'DH') ? ' DH' : '') : ($fp !== '' ? $fp : null),
            'cuadro' => ctype_digit(self::texto($tds[1])) ? (int)self::texto($tds[1]) : null,
            'numero' => ctype_digit(self::texto($tds[2])) ? (int)self::texto($tds[2]) : null,
            'sexo' => preg_match('/^([A-Z])(\d+)$/', $sexoEdad, $m) ? $m[1] : null,
            'edad' => isset($m[2]) ? (int)$m[2] : null,
            'peso_asignado' => is_numeric(self::texto($tds[5])) ? (float)self::texto($tds[5]) : null,
            'posiciones' => implode(' ', array_filter($pos, fn($p) => $p !== '')) ?: null,
            'tiempo' => $tiempo !== '' ? $tiempo : null,
            'tiempo_seg' => self::segundos($tiempo),
            'margen' => self::texto($tds[11]) !== '' ? self::texto($tds[11]) : null,
            'favorito' => ctype_digit(self::texto($tds[12])) ? (int)self::texto($tds[12]) : null,
            'cuota' => is_numeric($cuota) ? (float)$cuota : null,
        ];
    }

    /** @return array<string, array> horseno => padre, madre, abuelo materno, abuela materna, jockey, cuidador, propietario y criador */
    private function pedigri(DOMXPath $x): array
    {
        $res = [];
        $tabla = $x->query('//td[@class="horseHead" and contains(., "Sire")]/ancestor::table[1]')->item(0);
        if ($tabla === null) {
            return $res;
        }
        foreach ($x->query('.//tr[td[@class="raceHorse"]]', $tabla) as $tr) {
            $tds = iterator_to_array($x->query('./td', $tr));
            $a = $x->query('.//a[@horseno]', $tr)->item(0);
            if (count($tds) < 6 || !$a instanceof DOMElement) {
                continue;
            }
            [$padre, $madre] = self::dos($tds[2]);
            [$abuelo, $abuela] = self::dos($tds[3]);
            [$jockey, $cuidador] = self::dos($tds[4]);
            [$prop, $criador] = self::dos($tds[5]);
            $res[trim($a->getAttribute('horseno'))] = ['padre' => $padre, 'madre' => $madre, 'abuelo_materno' => $abuelo, 'abuela_materna' => $abuela,
                'jockey' => $jockey, 'cuidador' => $cuidador, 'propietario' => $prop, 'criador' => $criador];
        }
        return $res;
    }

    /** Las dos líneas de una celda ("Heart's Cry<BR>Nasuno Fiona"). */
    private static function dos(DOMNode $td): array
    {
        $partes = [];
        $actual = '';
        foreach ($td->childNodes as $n) {
            if ($n->nodeName === 'br') {
                $partes[] = $actual;
                $actual = '';
            } else {
                $actual .= $n->textContent;
            }
        }
        $partes[] = $actual;
        $partes = array_map(fn($p) => ($p = self::limpiar($p)) === '' ? null : $p, $partes);
        return [$partes[0] ?? null, $partes[1] ?? null];
    }

    /** Tiempos de la fila "Pas.tm" (acumulados) o "Lap tm" (de cada 200 m), en segundos. */
    private static function lapsos(DOMXPath $x, string $rotulo): array
    {
        $res = [];
        foreach ($x->query('//td[@class="lapHead" and starts-with(normalize-space(.), "' . $rotulo . '")]/following-sibling::td[1]//td[@class="lap"]') as $td) {
            $v = self::texto($td);
            if (is_numeric($v)) {
                $res[] = (float)$v;
            }
        }
        return $res;
    }

    /** Dividendos por 100 yenes de cada apuesta, con lo apostado (Sold) como pozo. */
    private function apuestas(DOMXPath $x): array
    {
        $cab = $x->query('//tr[td[@class="blankHead"] and td[@class="head1"]]')->item(0);
        if ($cab === null) {
            return [];
        }
        $tipos = [];
        foreach ($x->query('./td[@class="head1"]', $cab) as $td) {
            $tipos[] = self::texto($td);
        }
        $vendido = [];
        $filas = [];
        for ($tr = $cab->nextSibling; $tr !== null; $tr = $tr->nextSibling) {
            if (!$tr instanceof DOMElement || $tr->nodeName !== 'tr') {
                continue;
            }
            $tds = iterator_to_array($x->query('./td', $tr));
            $primera = $tds ? self::texto($tds[0]) : '';
            if (str_starts_with($primera, 'Sold')) {
                foreach (array_slice($tds, 1) as $i => $td) {
                    $vendido[$i] = (float)str_replace(',', '', self::texto($td));
                }
                continue;
            }
            if (str_starts_with($primera, 'PAY-OFF')) {
                array_shift($tds);
            }
            $filas[] = $tds;
        }
        $res = [];
        foreach ($filas as $fila) {
            foreach (array_chunk($fila, 2) as $i => $par) {
                if (!isset($tipos[$i], $par[1])) {
                    continue;
                }
                $comb = trim((string)preg_replace('/\s*-\s*/', '-', self::limpiar($par[0]->textContent)));
                $div = str_replace(',', '', self::texto($par[1]));
                if ($comb === '' && $div === '') {
                    continue;
                }
                $orden = count(array_filter($res, fn($r) => $r['tipo'] === $tipos[$i])) + 1;
                $res[] = ['tipo' => $tipos[$i], 'orden' => $orden, 'monto_base' => 100.0, 'combinacion' => $comb !== '' ? $comb : null,
                    'dividendo' => is_numeric($div) ? (float)$div : null, 'detalle' => is_numeric($div) ? null : ($div !== '' ? mb_substr($div, 0, 60) : null),
                    'pozo' => $orden === 1 ? ($vendido[$i] ?? null) : null];
            }
        }
        return $res;
    }

    /** "2:33.0" o "59.8" → segundos. */
    public static function segundos(?string $t): ?float
    {
        $t = trim((string)$t);
        if (preg_match('/^(?:(\d+):)?(\d{1,2})\.(\d)$/', $t, $m)) {
            return (int)$m[1] * 60 + (int)$m[2] + (int)$m[3] / 10;
        }
        return null;
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    private static function texto(?DOMNode $n): string
    {
        return $n === null ? '' : self::limpiar($n->textContent);
    }

    private static function limpiar(string $s): string
    {
        $s = str_replace("\u{a0}", ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim((string)preg_replace('/[ \t]+/', ' ', (string)preg_replace('/\s*\n\s*/', "\n", $s)));
    }
}
