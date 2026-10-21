<?php
declare(strict_types=1);

namespace GranChance\BR;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Lee las páginas de apostas.jcb.com.br: el raceday (pestañas de las reuniones del día y el programa de una reunión con
 * todos los inscriptos) y el resultado completo de cada páreo.
 */
final class ParserResultados
{
    /**
     * Pestañas del raceday y la reunión que muestra. La pestaña activa no trae su idReuniao en el enlace:
     * se toma de los enlaces de la página.
     * @return array{fecha: ?string, reuniones: list<array{hipodromo: string, nro: int, id: ?int, activa: bool}>}
     */
    public function dia(string $html): array
    {
        $x = self::xpath($html);
        $r = ['fecha' => self::cabecera($x)['fecha'], 'reuniones' => []];
        $idActiva = self::idReunion($html);
        foreach ($x->query('//div[@id="start"]/ul[contains(@class,"tabs")]/li') as $li) {
            $partes = preg_split('/\s*\n\s*|\s{2,}/', trim(self::textoBr($li)));
            $nombre = trim((string)($partes[0] ?? ''));
            $nro = (int)($partes[1] ?? 0);
            $href = (string)$x->evaluate('string(./a/@href)', $li);
            $activa = str_contains((string)$li->getAttribute('class'), 'active');
            $id = preg_match('/idReuniao=(\d+)/', $href, $m) ? (int)$m[1] : ($activa ? $idActiva : null);
            $r['reuniones'][] = ['hipodromo' => $nombre, 'nro' => $nro, 'id' => $id, 'activa' => $activa];
        }
        return $r;
    }

    /** Programa de la reunión que muestra la página (la pestaña activa). */
    public function reunion(string $html): array
    {
        $x = self::xpath($html);
        $cab = self::cabecera($x);
        $r = $cab + [
            'id' => self::idReunion($html),
            'total_apostado' => null, 'penetrometro_vf' => null, 'penetrometro_pr' => null, 'carreras' => [],
        ];
        foreach ($x->query('//div[contains(@class,"race-info")]//div[contains(@class,"item")]') as $item) {
            $titulo = self::texto($x->query('./h4', $item)->item(0));
            if (str_starts_with($titulo, 'Penetr')) {
                foreach ($x->query('./span[strong]', $item) as $sp) {
                    $clave = self::texto($x->query('./strong', $sp)->item(0)) === 'PR' ? 'penetrometro_pr' : 'penetrometro_vf';
                    $r[$clave] = preg_match('/(\d+(?:,\d+)?)\s*cm/', self::texto($sp), $m) ? (float)str_replace(',', '.', $m[1]) : null;
                }
            } elseif (str_starts_with($titulo, 'Já apostados')) {
                $r['total_apostado'] = self::reales(self::texto($x->query('./span', $item)->item(0)));
            }
        }
        foreach ($x->query('//div[@id="matches"]//div[contains(@class,"collapsible-item")]') as $item) {
            $r['carreras'][] = $this->carreraPrograma($x, $item);
        }
        return $r;
    }

    private function carreraPrograma(DOMXPath $x, DOMNode $item): array
    {
        $h = $x->query('./h2', $item)->item(0);
        $span = fn(string $clase) => self::texto($x->query('.//span[contains(concat(" ", @class, " "), " ' . $clase . ' ")]', $h)->item(0));
        // Tipo de prova: "Normal", "Claiming D", "Grupo I"... (en 2015 solo el código en un comentario, o "Não Disponível").
        $tipo = $x->query('.//span[contains(@class,"match-type")]', $h)->item(0);
        $tipoProva = null;
        if ($tipo !== null) {
            $tipoProva = self::nulo(self::texto($x->query('./span[contains(@class,"type")]', $tipo)->item(0)));
            foreach ($x->query('./comment()', $tipo) as $com) {
                $tipoProva ??= self::nulo($com->nodeValue);
            }
        }
        [$superficie, $pista] = self::pista(self::texto($x->query('.//span[contains(@class,"match-course")]/span[contains(@class,"type")]', $h)->item(0)));
        $c = [
            'numero' => (int)preg_replace('/\D/', '', $span('match-number')),
            'hora_programa' => preg_match('/^(\d{1,2}):(\d\d)$/', $span('match-hour'), $m) ? sprintf('%02d:%s:00', $m[1], $m[2]) : null,
            'premio' => self::nulo(trim((string)$x->evaluate('string(.//span[contains(@class,"match-title")]/@title)', $h))) ?? self::nulo($span('match-title')),
            'tipo_prova' => $tipoProva,
            'distancia' => self::entero(str_replace('.', '', $span('distance'))),
            'superficie' => $superficie, 'pista' => $pista,
            'estado' => self::nulo($span('match-status')),
            'actuaciones' => [],
        ];
        foreach ($x->query('.//table[contains(@class,"table-races")]/tbody/tr', $item) as $tr) {
            $a = $this->filaPrograma($x, $tr);
            if ($a !== null) {
                $c['actuaciones'][] = $a;
            }
        }
        return $c;
    }

    private function filaPrograma(DOMXPath $x, DOMNode $tr): ?array
    {
        $td = [];
        foreach ($x->query('./td', $tr) as $celda) {
            $td[] = self::texto($celda);
        }
        if (count($td) < 19 || $td[7] === '') {
            return null;
        }
        $puesto = self::entero($td[0]);
        [$jockey, $aprendiz] = self::jockey($td[11]);
        [$asignado, $real] = self::pesos($td[12], $aprendiz !== null);
        return [
            'numero' => self::entero($td[2]),
            'puesto' => $puesto !== null && $puesto < 99 && preg_match('/^\d+º?$/u', $td[1]) ? $puesto : null,
            'puesto_texto' => self::nulo($td[1]),
            'forfait' => $td[3] === 'True' || str_contains((string)$tr->getAttribute('class'), 'not-running'),
            'estreante' => $td[4] === 'True',
            'cuota' => self::decimal($td[6]),
            'nombre' => $td[7],
            'peso_caballo' => preg_match('/^(\d{3})\s*kg/', $td[8], $m) ? (int)$m[1] : null,
            'edad' => self::entero($td[9]),
            'sexo' => self::nulo($td[10]),
            'jockey' => $jockey, 'aprendiz' => $aprendiz,
            'peso_texto' => self::nulo(trim(str_replace('kg', '', $td[12]))),
            'peso_asignado' => $asignado, 'peso_real' => $real,
            'cuidador' => self::nulo($td[13]), 'stud' => self::nulo($td[14]),
            'padre' => self::nulo($td[15]), 'madre' => self::nulo($td[16]), 'abuelo_materno' => self::nulo($td[17]),
            'pelaje' => self::nulo($td[18]),
        ];
    }

    /** Resultado completo de un páreo. */
    public function resultado(string $html): array
    {
        $x = self::xpath($html);
        $sel = $x->query('//div[contains(@class,"match-select")]')->item(0);
        $titulo = self::texto($x->query('.//strong', $sel)->item(0));
        $c = [
            'numero' => preg_match('/^(\d+)º/u', $titulo, $m) ? (int)$m[1] : null,
            'hora' => preg_match('/(\d{1,2})h(\d\d)/', $titulo, $m) ? sprintf('%02d:%s:00', $m[1], $m[2]) : null,
            'premio' => null, 'total_apostado' => null, 'distancia' => null, 'superficie' => null, 'pista' => null,
            'condicion' => null, 'recorde' => null, 'comentario' => null, 'parelhas' => null,
            'llegada' => [], 'apuestas' => [],
        ];
        $premio = $x->query('//div[contains(@class,"prizes")]/div[contains(@class,"left")]/p')->item(0);
        if ($premio !== null) {
            $c['premio'] = self::nulo(trim((string)strtok(trim(self::textoBr($premio)), "\n")));
            $c['total_apostado'] = self::reales(self::texto($x->query('.//strong/span', $premio)->item(0)));
        }
        $c['distancia'] = self::entero(str_replace('.', '', self::texto($x->query('//li[contains(@class,"distance")]')->item(0))));
        [$c['superficie'], $c['pista']] = self::pista(self::texto($x->query('//li[contains(@class,"course")]')->item(0)));
        foreach ($x->query('//div[@id="match-info"]//p[strong] | //div[@id="final-assessment"]//p[strong]') as $p) {
            $etiqueta = rtrim(self::texto($x->query('./strong', $p)->item(0)), ': ');
            $valor = self::nulo(trim(substr(self::texto($p), strlen(self::texto($x->query('./strong', $p)->item(0))))));
            match ($etiqueta) {
                'Chamada' => $c['condicion'] = $valor,
                'Recorde' => $c['recorde'] = $valor === null ? null : mb_substr($valor, 0, 120),
                'Ocorrências Veterinárias', 'Ocorrências' => $c['comentario'] = trim(($c['comentario'] === null ? '' : $c['comentario'] . "\n") . "$etiqueta: $valor"),
                'Parelhas' => $c['parelhas'] = $valor,
                default => $c['comentario'] = $valor === null ? $c['comentario'] : trim(($c['comentario'] === null ? '' : $c['comentario'] . "\n") . "$etiqueta: $valor"),
            };
        }
        foreach ($x->query('//div[contains(@class,"list-horses") and contains(@class,"placings")]/ul/li') as $li) {
            $q = fn(string $xp) => self::texto($x->query($xp, $li)->item(0));
            $criador = null;
            foreach ($x->query('.//ul[contains(@class,"horse-info")]/li', $li) as $info) {
                if (str_starts_with(self::texto($info), 'Criador:')) {
                    $criador = self::nulo(self::texto($x->query('./strong', $info)->item(0)));
                }
            }
            $posicion = $q('.//p[contains(@class,"horse-position")]');
            [$jockey] = self::jockey($q('.//p[contains(@class,"jockey-info")]/strong'));
            $c['llegada'][] = [
                'numero' => self::entero($q('./a//span[contains(@class,"horse-number")]')),
                'parelha' => self::nulo($q('./a//span[contains(@class,"horse-team")]')),
                'puesto' => self::entero($q('./span[contains(@class,"placing")]')),
                'nombre' => $q('./p[contains(@class,"horse-name")]'),
                'posicion' => $posicion === '-' ? null : self::nulo($posicion),
                'favorito' => $x->query('.//div[contains(@class,"favorite")]', $li)->length > 0,
                'cuota' => self::decimal($q('./a//span[@class="value"]')),
                'criador' => $criador,
                'jockey' => $jockey,
            ];
        }
        $orden = [];
        foreach ($x->query('//div[@id="complete-result"]//table/tbody/tr') as $tr) {
            $td = [];
            foreach ($x->query('./td', $tr) as $celda) {
                $td[] = self::texto($celda);
            }
            if (count($td) < 3 || $td[0] === '') {
                continue;
            }
            $tipo = mb_substr($td[0], 0, 30);
            $orden[$tipo] = ($orden[$tipo] ?? 0) + 1;
            $div = self::decimal($td[2]);
            $c['apuestas'][] = [
                'tipo' => $tipo, 'orden' => $orden[$tipo], 'combinacion' => self::nulo(mb_substr($td[1], 0, 60)),
                'dividendo' => $div, 'detalle' => $div === null ? self::nulo(mb_substr($td[2], 0, 60)) : null,
            ];
        }
        return $c;
    }

    /** "1m 48s 4/10c" (décimos), "2m 08s 02c", "1m 07s 19c", "59s 3/10c" → segundos. */
    public static function segundos(?string $t): ?float
    {
        if ($t === null || !preg_match('/^(?:(\d+)m\s*)?(\d+)s(?:\s*(\d+)(?:\/(\d+))?c?)?/', trim($t), $m)) {
            return null;
        }
        $s = (int)($m[1] ?? 0) * 60 + (int)$m[2];
        if (($m[3] ?? '') !== '') {
            $s += isset($m[4]) && $m[4] !== '' ? (int)$m[3] / (int)$m[4] : (int)$m[3] / 100;
        }
        return $s > 0 ? round($s, 2) : null;      // "0s c": sin tiempo
    }

    /**
     * Cuerpos al ganador: "3 3/4" → 3.75, "1/2" → 0.5, "Focinho"/"Mínima" → 0.05, "Cabeça" → 0.1, "Pescoço" → 0.25,
     * "Paleta" → 0.5; null si no se puede medir ("Vários", "Desclas").
     */
    public static function cuerpos(?string $t): ?float
    {
        $t = trim((string)$t);
        $fijos = ['Focinho' => 0.05, 'Foc.' => 0.05, 'Mínima' => 0.05, 'Mín.' => 0.05, 'Cabeça' => 0.1, 'Cab.' => 0.1,
            'Pescoço' => 0.25, 'Pesc.' => 0.25, 'Paleta' => 0.5, 'Pal.' => 0.5];
        if (isset($fijos[$t])) {
            return $fijos[$t];
        }
        if ($t === '' || !preg_match('#^(\d+)?\s*(?:(\d)/(\d))?$#', $t, $m)) {
            return null;
        }
        return (float)($m[1] ?? 0) + (isset($m[2]) && $m[2] !== '' ? (int)$m[2] / (int)$m[3] : 0.0);
    }

    /** Año de nacimiento (generación): en Brasil los caballos cumplen años el 1º de julio. */
    public static function anioNacimiento(string $fecha, ?int $edad): int
    {
        if ($edad === null || $edad <= 0) {
            return 0;
        }
        [$a, $m] = [(int)substr($fecha, 0, 4), (int)substr($fecha, 5, 2)];
        return ($m >= 7 ? $a : $a - 1) - $edad;
    }

    /** País de origen cuando el nombre lo indica: "SNAP DECISION-ARG", "AYAYAY (USA)", "GATO FANTASMA(EX)" no. */
    public static function pais(string $nombre): ?string
    {
        return preg_match('/(?:-|\s?\()(ARG|USA|URU|CHI|GB|IRE|FR|FRA|GER|AUS|NZ|PER|JPN|ITY|ITA|SAF|CAN|POR|SPA)\)?$/', $nombre, $m) ? $m[1] : null;
    }

    /** "J.MOURA Ap1" → ["J.MOURA", "Ap1"]. */
    private static function jockey(string $t): array
    {
        $t = trim($t);
        if ($t === '') {
            return [null, null];
        }
        return preg_match('/^(.*?)\s+(Ap\s?\d)$/i', $t, $m) ? [trim($m[1]), str_replace(' ', '', $m[2])] : [$t, null];
    }

    /**
     * "57 kg" → [57, 57]; "57/6 kg" → [57, 56]: después de la barra, lo que carga (descargo de aprendiz o sobrepeso), con
     * solo la última cifra (la decena es la más cercana al asignado; con aprendiz, se prefiere el descargo);
     * "60/56 kg" → [60, 56]; "55,5/6 kg" → [55.5, 56]; "58/2 kg" de un Ap4 → [58, 52].
     * @return array{0: ?float, 1: ?float}
     */
    private static function pesos(string $t, bool $aprendiz): array
    {
        if (!preg_match('/^(\d+(?:[,.]\d+)?)(?:\s*\/\s*(\d+(?:[,.]\d+)?))?/', trim($t), $m)) {
            return [null, null];
        }
        $asig = (float)str_replace(',', '.', $m[1]);
        if (!isset($m[2]) || $m[2] === '') {
            return [$asig, $asig];
        }
        $b = (float)str_replace(',', '.', $m[2]);
        if ($b >= 10) {
            return [$asig, $b];
        }
        $real = null;
        foreach ([-10, 0, 10] as $d) {
            $c = floor($asig / 10) * 10 + $d + $b;
            // Con aprendiz se prefiere el descargo (hasta 7 kg) antes que un sobrepeso.
            $dist = abs($c - $asig) + ($aprendiz && $c > $asig ? 3 : 0);
            if ($real === null || $dist < abs($real - $asig) + ($aprendiz && $real > $asig ? 3 : 0)) {
                $real = $c;
            }
        }
        return [$asig, $real];
    }

    /** "Grama macia variante" → ["césped", "macia variante"]; "Areia pesada" → ["arena", "pesada"]. */
    private static function pista(string $t): array
    {
        $t = trim($t);
        if ($t === '') {
            return [null, null];
        }
        [$primera, $resto] = array_pad(preg_split('/\s+/', $t, 2), 2, '');
        $sup = match (mb_strtolower($primera)) {
            'grama' => 'césped',
            'areia' => 'arena',
            'sintética', 'sintetica' => 'sintética',
            default => null,
        };
        $resto = $sup === null ? $t : trim($resto);
        return [$sup, $resto === '' ? null : mb_strtolower($resto)];
    }

    /** "Reunião 195 - Cidade Jardim - 03/01/2015" */
    private static function cabecera(DOMXPath $x): array
    {
        $t = self::texto($x->query('//div[contains(@class,"race-navigation")]/h3')->item(0));
        if (!preg_match('#Reunião\s+(\d+)\s*-\s*(.*?)\s*-\s*(\d\d)/(\d\d)/(\d{4})#u', $t, $m)) {
            return ['nro_reunion' => null, 'hipodromo' => null, 'fecha' => null];
        }
        return ['nro_reunion' => (int)$m[1], 'hipodromo' => trim($m[2]), 'fecha' => "$m[5]-$m[4]-$m[3]"];
    }

    private static function idReunion(string $html): ?int
    {
        return preg_match('/(?:resultadocompleto|resultadodasacumuladasespeciais|parciaisdasacumuladas|comparador|programa)\?idReuniao=(\d+)/', $html, $m) ? (int)$m[1] : null;
    }

    private static function reales(string $t): ?float
    {
        return preg_match('/R\$\s*([\d.]+,\d+|[\d.]+)/', $t, $m) ? (float)str_replace(['.', ','], ['', '.'], $m[1]) : null;
    }

    private static function decimal(string $t): ?float
    {
        $t = trim($t);
        return preg_match('/^\d{1,3}(?:\.\d{3})*(?:,\d+)?$|^\d+(?:,\d+)?$/', $t) ? (float)str_replace(['.', ','], ['', '.'], $t) : null;
    }

    private static function entero(string $t): ?int
    {
        return preg_match('/(\d+)/', $t, $m) ? (int)$m[1] : null;
    }

    private static function nulo(?string $t): ?string
    {
        $t = $t === null ? '' : trim($t);
        return $t === '' || $t === 'N/D' ? null : $t;
    }

    private static function texto(?DOMNode $n): string
    {
        if ($n === null) {
            return '';
        }
        return trim((string)preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $n->textContent)));
    }

    /** Texto con los <br> como saltos de línea. */
    private static function textoBr(DOMNode $n): string
    {
        $t = '';
        foreach ($n->childNodes as $h) {
            if ($h->nodeName === 'br') {
                $t .= "\n";
            } elseif ($h->hasChildNodes()) {
                $t .= self::textoBr($h);
            } elseif ($h->nodeType === XML_TEXT_NODE) {
                $t .= $h->nodeValue;
            }
        }
        return (string)preg_replace('/[ \t\r]+/', ' ', str_replace("\u{a0}", ' ', $t));
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
