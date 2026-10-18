<?php
declare(strict_types=1);

namespace GranChance;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee el Excel "Programa" (docType=Programa) del servicio de HRU.
 *
 * Cabecera de cada carrera: hora | premio | Nro. | condición+bolsa+record+apuestas | superficie. Las columnas se
 * corren según el año (en 2018 empieza en A, después en B), así que se ubica la hora y el resto se lee relativo a ella.
 * El programa no trae la distancia: se deduce después del récord de la distancia (ver ProgramaNoOficial).
 * Los caballos se leen según la fila de títulos "Nº | EQUINOS | K | P | S | E | JOCKEY | CUIDADOR | STUD | PEDIGREE | O/P".
 */
final class ParserPrograma
{
    private const TITULOS = ['Nº' => 'numero', 'EQUINOS' => 'caballo', 'K' => 'peso', 'P' => 'pelaje', 'S' => 'sexo', 'E' => 'edad',
        'JOCKEY' => 'jockey', 'CUIDADOR' => 'cuidador', 'STUD' => 'stud', 'O/P' => 'gatera'];

    public function parsearArchivo(string $ruta): array
    {
        return $this->parsearFilas(IOFactory::load($ruta)->getActiveSheet()->toArray(null, false, false, false));
    }

    /**
     * @return array{nro_reunion:?int, carreras: list<array>}
     */
    public function parsearFilas(array $filas): array
    {
        $res = ['nro_reunion' => null, 'carreras' => []];
        $columnas = null;
        $n = 0;
        foreach ($filas as $f) {
            $f = array_map(fn ($v) => trim((string)$v), $f);
            $txt = implode(' ', array_filter($f, fn ($v) => $v !== ''));
            if ($res['nro_reunion'] === null && preg_match('/Programa del D[ií]a.*Reuni[óo]n N[º°o]\s*(\d+)/u', $txt, $m)) {
                $res['nro_reunion'] = (int)$m[1];
                continue;
            }
            $cab = self::cabecera($f);
            if ($cab !== null) {
                $res['carreras'][] = $cab + ['numero' => ++$n, 'caballos' => []];
                $columnas = null;
                continue;
            }
            if (in_array('EQUINOS', $f, true)) {
                $columnas = [];
                foreach ($f as $i => $v) {
                    if (isset(self::TITULOS[$v])) {
                        $columnas[self::TITULOS[$v]] = $i;
                    } elseif (str_starts_with($v, 'PEDIGREE')) {
                        $columnas['pedigri'] = $i;
                    }
                }
                continue;
            }
            if ($columnas !== null && $res['carreras'] && preg_match('/^\d+[A-Za-z]?$/', $f[$columnas['numero']] ?? '')) {
                $res['carreras'][array_key_last($res['carreras'])]['caballos'][] = self::caballo($f, $columnas);
            }
        }
        return $res;
    }

    private static function cabecera(array $f): ?array
    {
        foreach ($f as $i => $v) {
            if (!preg_match('/^\d{1,2}:\d{2}$/', $v) || !is_numeric($f[$i + 2] ?? '')) {
                continue;
            }
            $texto = $f[$i + 3] ?? '';
            $sup = null;
            foreach (array_slice($f, $i + 4) as $w) {
                if (preg_match('/^(arena|c[ée]sped)$/iu', $w)) {
                    $sup = str_starts_with(mb_strtolower($w), 'c') ? 'césped' : 'arena';
                }
            }
            $premio = preg_replace('/^PREMIO:\s*/u', '', $f[$i + 1] ?? '');
            return [
                'hora' => $v,
                'premio' => $premio,
                'nro_oficial' => (int)$f[$i + 2],
                'condicion' => preg_match('/CONDICI[ÓO]N:\s*(.*?)(?:\n\s*\n|BOLSA:|$)/us', $texto, $m) ? trim($m[1]) : null,
                'bolsa' => preg_match('/BOLSA:\s*(.*)/u', $texto, $m) ? trim($m[1]) : null,
                'record' => preg_match('/RECORD:\s*([^\n]*?)(?:\s+-\s+RECORD EN PISTA.*)?$/um', $texto, $m) ? trim($m[1]) : null,
                'apuestas' => preg_match('/APUESTAS:\s*(.*)/u', $texto, $m) ? trim($m[1]) : null,
                'superficie' => $sup,
            ];
        }
        return null;
    }

    private static function caballo(array $f, array $col): array
    {
        $v = fn (string $k) => isset($col[$k]) ? ($f[$col[$k]] ?? '') : '';
        $nombre = $v('caballo');
        // "(s)": suplente, corre solo si se retira alguno.
        $suplente = (bool)preg_match('/\s*\(s\)$/iu', $nombre);
        $nombre = preg_replace('/\s*\(s\)$/iu', '', $nombre);
        $pais = 'URU';
        if (preg_match('/^(.*?)\s*\(([A-Z]{2,4})\)$/u', $nombre, $m)) {
            [$nombre, $pais] = [$m[1], $m[2]];
        }
        $jockey = $v('jockey');
        $descargo = null;
        if (preg_match('/^(.*?)\s*\((-?\d+)\)$/u', $jockey, $m)) {
            [$jockey, $descargo] = [$m[1], (int)$m[2]];
        }
        $retirado = (bool)preg_match('/^No Correr[aá]/iu', $jockey);
        return [
            'numero' => $v('numero'),
            'nombre' => $nombre,
            'pais' => $pais,
            'peso' => is_numeric(str_replace(',', '.', $v('peso'))) ? (float)str_replace(',', '.', $v('peso')) : null,
            'pelaje' => $v('pelaje'),
            'sexo' => $v('sexo'),
            'edad' => (int)$v('edad'),
            'jockey' => $retirado ? null : $jockey,
            'descargo' => $descargo,
            'retirado' => $retirado,
            'suplente' => $suplente,
            'cuidador' => preg_replace('/\s*\([A-Z]{2}\)$/u', '', $v('cuidador')),
            'stud' => $v('stud'),
            'pedigri' => $v('pedigri'),
            'gatera' => (int)$v('gatera') ?: null,
        ];
    }
}
