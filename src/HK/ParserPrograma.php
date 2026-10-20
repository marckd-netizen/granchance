<?php
declare(strict_types=1);

namespace GranChance\HK;

use DOMDocument;
use DOMXPath;

/**
 * Lee el programa oficial (Race Card) de una carrera del HKJC: la carrera (hora, superficie, riel, distancia, going,
 * bolsa, banda de rating y clase) y cada caballo con su código, peso, jockey (con el descargo del aprendiz), gatera,
 * cuidador, rating, peso del caballo, últimas 6, equipo, padre, madre e importación.
 */
final class ParserPrograma
{
    private const LB_KG = 0.45359237;

    public function carrera(string $html): array
    {
        $x = self::xpath($html);
        $cab = '';
        foreach ($x->query('//div[contains(@class,"f_fs13")]') as $d) {
            if (preg_match('/Race \d+ -/', $d->textContent)) {
                foreach ($d->childNodes as $n) {
                    $cab .= $n->nodeName === 'br' ? "\n" : $n->textContent;
                }
                break;
            }
        }
        $lineas = array_map(fn ($l) => trim(html_entity_decode(str_replace("\u{a0}", ' ', $l), ENT_QUOTES | ENT_HTML5, 'UTF-8')), explode("\n", $cab));
        if (!preg_match('/^Race (\d+) - (.+)$/', $lineas[0] ?? '', $m)) {
            throw new \RuntimeException('No se encontró la cabecera de la carrera');
        }
        $c = ['numero' => (int)$m[1], 'premio' => trim($m[2]), 'fecha' => null, 'hipodromo' => null, 'hora' => null,
            'superficie' => null, 'recorrido' => null, 'distancia' => null, 'pista' => null, 'bolsa' => null, 'rating' => null, 'clase' => null];
        if (preg_match('/^\w+, (\w+ \d+, \d{4}), (Sha Tin|Happy Valley), (\d{1,2}:\d{2})/', $lineas[1] ?? '', $m)) {
            $c['fecha'] = date('Y-m-d', strtotime($m[1]));
            $c['hipodromo'] = $m[2] === 'Sha Tin' ? 'ST' : 'HV';
            $c['hora'] = $m[3];
        }
        $l = $lineas[2] ?? '';
        $c['superficie'] = str_starts_with($l, 'Turf') ? 'césped' : 'sintética';
        if (preg_match('/"([^"]+)" Course/', $l, $m)) {
            $c['recorrido'] = $m[1];
        }
        if (preg_match('/(\d{3,4})M\b/', $l, $m)) {
            $c['distancia'] = (int)$m[1];
        }
        $partes = array_map('trim', explode(',', $l));
        $c['pista'] = strtoupper(end($partes)) ?: null;
        $l = $lineas[3] ?? '';
        if (preg_match('/Prize Money: \$([\d,]+)/', $l, $m)) {
            $c['bolsa'] = (float)str_replace(',', '', $m[1]);
        }
        if (preg_match('/Rating: ([\d-]+)/', $l, $m)) {
            $c['rating'] = $m[1];
        }
        $partes = array_map('trim', explode(',', $l));
        $c['clase'] = end($partes) ?: null;
        $c['caballos'] = [];
        foreach ($x->query('//tr[td/a[contains(@href,"horseid=")]]') as $tr) {
            $td = [];
            foreach ($x->query('./td', $tr) as $celda) {
                $td[] = trim(preg_replace('/\s+/', ' ', str_replace("\u{a0}", ' ', $celda->textContent)));
            }
            if (count($td) < 20) {
                continue;       // tabla de suplentes (Stand-by Starters): otras columnas
            }
            $codigo = null;
            if (preg_match('/horseid=([A-Z0-9_]+)/', (string)$x->evaluate('string(.//a[contains(@href,"horseid=")]/@href)', $tr), $m)) {
                $codigo = $m[1];
            }
            $retirado = str_contains($td[3] ?? '', 'Scratched');
            $nombre = trim(preg_replace('/\(Scratched\)/', '', $td[3] ?? ''));
            $jockey = $td[6] ?? '';
            $descargo = preg_match('/\((-\d+)\)/', $jockey, $m) ? (int)$m[1] : 0;
            $jockey = trim(preg_replace('/\s*\(-?\d+\)/', '', $jockey));
            $lb = ctype_digit($td[5] ?? '') ? (int)$td[5] : null;
            $c['caballos'][] = [
                'numero' => $td[0] ?? '', 'ultimas6' => $td[1] ?? '', 'codigo' => $codigo, 'nombre' => $nombre, 'marca' => $td[4] ?? '',
                'retirado' => $retirado, 'peso_lb' => $lb,
                // El peso del programa es el del handicap; el aprendiz lleva menos ("(-7)" junto al jockey).
                'peso' => $lb !== null ? round(($lb + $descargo) * self::LB_KG, 1) : null, 'descargo' => $descargo,
                'jockey' => $retirado ? null : $jockey, 'sobrepeso' => self::guion($td[7] ?? ''), 'gatera' => ctype_digit($td[8] ?? '') ? (int)$td[8] : null,
                'cuidador' => self::guion($td[9] ?? ''), 'rating_intl' => self::guion($td[10] ?? ''),
                'rating' => ctype_digit($td[11] ?? '') ? (int)$td[11] : null, 'rating_cambio' => self::guion($td[12] ?? ''),
                'peso_caballo_lb' => ctype_digit($td[13] ?? '') ? (int)$td[13] : null, 'peso_caballo_cambio' => self::guion($td[14] ?? ''),
                'mejor_tiempo' => self::guion($td[15] ?? ''), 'edad' => ctype_digit($td[16] ?? '') ? (int)$td[16] : null,
                'sexo' => self::guion($td[18] ?? ''), 'premios_temporada' => self::guion($td[19] ?? ''), 'prioridad' => self::guion($td[20] ?? ''),
                'dias' => ctype_digit($td[21] ?? '') ? (int)$td[21] : null, 'equipo' => self::guion($td[22] ?? ''),
                'propietario' => self::guion($td[23] ?? ''), 'padre' => self::guion($td[24] ?? ''), 'madre' => self::guion($td[25] ?? ''),
                'importacion' => self::guion($td[26] ?? ''),
            ];
        }
        return $c;
    }

    /** Cantidad de carreras de la reunión, de los enlaces a cada carrera. */
    public function cantidadCarreras(string $html): int
    {
        preg_match_all('#\?racedate=[^"\']*RaceNo=(\d+)#i', $html, $m);
        return $m[1] === [] ? 1 : max(array_map('intval', $m[1]));
    }

    private static function guion(string $v): ?string
    {
        return $v === '' || $v === '-' ? null : $v;
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }
}
