<?php
declare(strict_types=1);

namespace GranChance\JP;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Lee el programa (Race Card) de una carrera de la JRA: la misma página que los resultados antes de correrse
 * (jra.jp/JRAEN/AP/kaisai/running). La cabecera la lee ParserResultados; acá, la tabla de anotados, leyendo las columnas
 * por su título (Bk, Hs, Horse, Sex Age, Weight, Jockey, Trainer...), y el pedigrí con propietario y criador.
 */
final class ParserPrograma
{
    public function carrera(string $html): array
    {
        $c = (new ParserResultados())->carrera($html);
        unset($c['actuaciones'], $c['apuestas']);
        $c['caballos'] = [];
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $x = new DOMXPath($doc);
        foreach ($x->query('//table[.//td[contains(@class,"horseHead") and normalize-space(.)="Horse"]]') as $tabla) {
            $cols = [];
            foreach ($x->query('.//tr[td[contains(@class,"horseHead")]][1]/td', $tabla) as $i => $td) {
                $cols[strtolower(preg_replace('/[^A-Za-z]/', '', $td->textContent))] = $i;
            }
            foreach ($x->query('.//tr[.//a[@horseno]]', $tabla) as $tr) {
                if (($h = $this->caballo($x, $tr, $cols, $c['pedigri'])) !== null) {
                    $c['caballos'][$h['codigo']] = $h;
                }
            }
            if ($c['caballos']) {
                break;
            }
        }
        $c['caballos'] = array_values($c['caballos']);
        usort($c['caballos'], fn($a, $b) => [$a['numero'] ?? 99, $a['nombre']] <=> [$b['numero'] ?? 99, $b['nombre']]);
        return $c;
    }

    private function caballo(DOMXPath $x, DOMElement $tr, array $cols, array $pedigri): ?array
    {
        $tds = iterator_to_array($x->query('./td', $tr));
        $a = $x->query('.//a[@horseno]', $tr)->item(0);
        if (!$a instanceof DOMElement) {
            return null;
        }
        $col = function (array $nombres) use ($tds, $cols): string {
            foreach ($nombres as $n) {
                if (isset($cols[$n], $tds[$cols[$n]])) {
                    return trim((string)preg_replace('/\s+/', ' ', html_entity_decode($tds[$cols[$n]]->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
            }
            return '';
        };
        $codigo = trim($a->getAttribute('horseno'));
        $nombre = trim((string)preg_replace('/\s+/', ' ', $a->textContent));
        $pais = null;
        if (preg_match('/^(.*?)\s*\(([A-Z]{2,4})\)$/', $nombre, $m)) {
            [$nombre, $pais] = [$m[1], $m[2]];
        }
        $fila = trim((string)preg_replace('/\s+/', ' ', $tr->textContent));
        $sexoEdad = $col(['sexage', 'sex']);
        $peso = str_replace(',', '.', $col(['weightkg', 'weight', 'wgt']));
        $num = $col(['hs', 'no', 'horseno']);
        $ped = $pedigri[$codigo] ?? [];
        // El Race Card trae el pedigrí, jockey, cuidador, propietario y criador en la misma tabla, de a dos por celda
        // ("Sire<BR>Dam", "Jockey<BR>Trainer"...): la tabla de pedigrí del resultado no existe y lo que leyó ParserResultados no sirve.
        if (isset($cols['siredam'])) {
            $dos = fn (string $k) => isset($cols[$k], $tds[$cols[$k]]) ? ParserResultados::dos($tds[$cols[$k]]) : [null, null];
            [$padre, $madre] = $dos('siredam');
            [$abuelo, $abuela] = $dos('damssiredamsdam');
            [$jockey, $cuidador] = $dos('jockeytrainer');
            [$prop, $criador] = $dos('ownerbreeder');
            $ped = ['padre' => ParserResultados::mayusculas($padre), 'madre' => ParserResultados::mayusculas($madre),
                'abuelo_materno' => ParserResultados::mayusculas($abuelo), 'abuela_materna' => ParserResultados::mayusculas($abuela),
                'jockey' => $jockey, 'cuidador' => $cuidador, 'propietario' => $prop, 'criador' => $criador];
        }
        return [
            'codigo' => $codigo, 'nombre' => ParserResultados::mayusculas($nombre), 'pais' => $pais,
            'numero' => ctype_digit($num) ? (int)$num : null,
            'cuadro' => ctype_digit($col(['bk', 'bracket'])) ? (int)$col(['bk', 'bracket']) : null,
            'sexo' => preg_match('/^([A-Z])\s*(\d+)$/', $sexoEdad, $m) ? $m[1] : null,
            'edad' => isset($m[2]) ? (int)$m[2] : null,
            'peso' => preg_match('/(\d+(?:\.\d)?)/', $peso, $mp) ? (float)$mp[1] : null,
            'jockey' => ($ped['jockey'] ?? null) ?: ($col(['jockey']) ?: null),
            'cuidador' => ($ped['cuidador'] ?? null) ?: ($col(['trainer']) ?: null),
            'propietario' => $ped['propietario'] ?? (($col(['owner']) ?: null)),
            'criador' => $ped['criador'] ?? null,
            'padre' => $ped['padre'] ?? ParserResultados::mayusculas($col(['sire']) ?: null),
            'madre' => $ped['madre'] ?? ParserResultados::mayusculas($col(['dam']) ?: null),
            'abuelo_materno' => $ped['abuelo_materno'] ?? null,
            'retirado' => (bool)preg_match('/\b(Scratched|Excluded|Cancel+ed|SCR|Withdrawn)\b/i', $fila),
        ];
    }
}
