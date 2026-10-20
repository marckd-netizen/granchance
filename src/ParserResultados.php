<?php
declare(strict_types=1);

namespace GranChance;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee el Excel "ResultadoCarreraWeb" (exportación de Crystal Reports) y lo convierte en un arreglo:
 *
 * [hipodromo, nro_reunion, total_apostado, carreras => [[numero, id, premio, distancia, hora, pista,
 *   viento, condicion, bolsa, bolsa_detalle, tiempo_ganador, parciales, retirados, comentario,
 *   apuestas => [...], actuaciones => [...]], ...]]
 *
 * Cada fila de caballo usa columnas fijas (ver COL_*); el resto de las filas se reconoce por su texto.
 */
final class ParserResultados
{
    private const COL_PUESTO = 0, COL_CABALLO = 1, COL_GATERA = 2, COL_PESO_CABALLO = 3, COL_RETIRO = 4, COL_TRATAMIENTO = 5, COL_MARCA = 6,
        COL_MARCA_JO = 7, COL_JOCKEY = 8, COL_PESO_ASIG = 9, COL_PESO_REAL = 10, COL_DIST_ANT = 11,
        COL_DIST_ACUM = 12, COL_SPORT = 13, COL_PEDIGRI = 14, COL_CUIDADOR = 16, COL_TIEMPO = 17,
        COL_STUD = 19, COL_CRIADOR = 21;

    public function parsearArchivo(string $ruta): array
    {
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        return $this->parsearFilas($hoja->toArray(null, false, false, false));
    }

    /** @param array<int, array<int, mixed>> $filas */
    public function parsearFilas(array $filas): array
    {
        $res = ['hipodromo' => null, 'nro_reunion' => null, 'total_apostado' => null, 'carreras' => []];
        $carrera = null;      // carrera en curso (por referencia dentro de $res)
        $grilla = 0;          // filas de la grilla de dividendos ya leídas en la carrera en curso

        foreach ($filas as $f) {
            $c0 = self::txt($f[0] ?? null);

            if ($res['hipodromo'] === null && preg_match('/^Hip[óo]dromo\b/u', $c0)) {
                $res['hipodromo'] = $c0;
                continue;
            }
            $c2 = self::txt($f[2] ?? null);
            if (preg_match('/Reuni[óo]n Nro\.\s*(\d+)/u', $c2, $m)) {
                $res['nro_reunion'] = (int)$m[1];
                continue;
            }
            if (preg_match('/^(\d+)ª Carrera, Nro\.\s*(\d+), Premio:\s*(.*)$/us', $c0, $m)) {
                unset($carrera);
                [$premio, $c1] = [$m[3], self::txt($f[1] ?? null)];
                // Algunas reuniones (p. ej. dic. 2017) traen premio, distancia y condición juntos en la columna A.
                if ($c1 === '' && $c2 === '' && str_contains($premio, "\n")) {
                    $lineas = preg_split('/\R/u', $premio, 3);
                    [$premio, $c1, $c2] = [$lineas[0], trim($lineas[1] ?? ''), trim($lineas[2] ?? '')];
                }
                $res['carreras'][] = $this->cabecera((int)$m[1], (int)$m[2], $premio, $c1, $c2);
                $carrera = &$res['carreras'][array_key_last($res['carreras'])];
                $grilla = 0;
                continue;
            }
            if (str_starts_with($c0, 'TOTAL APOSTADO')) {
                $res['total_apostado'] = self::num($f[1] ?? null);
                continue;
            }
            if (!isset($carrera)) {
                continue;
            }
            if (self::txt($f[self::COL_MARCA_JO] ?? null) === 'JO:') {
                $carrera['actuaciones'][] = $this->actuacion($f);
                continue;
            }
            if (preg_match('/^\(Antidoping del (\d+)º\)$/u', $c0, $m)) {
                foreach ($carrera['actuaciones'] as &$a) {
                    if ($a['puesto'] === (int)$m[1]) {
                        $a['antidoping'] = true;
                    }
                }
                unset($a);
                continue;
            }
            if (str_starts_with($c0, 'Tiempo:')) {
                $this->resumen($carrera, $c0);
                continue;
            }
            // Grilla de dividendos: fila "Nº" con ganador (col 1), segundo (col 3) y tercero (col 5).
            if ($grilla < 3 && preg_match('/^(\d)º$/u', $c0, $m)) {
                $grilla++;
                $p = (int)$m[1];
                foreach ($carrera['actuaciones'] as &$a) {
                    if ($a['puesto'] === $p) {
                        // 0 = esa apuesta no se jugó en la carrera
                        $a['div_segundo'] = self::num($f[3] ?? null) ?: null;
                        $a['div_tercero'] = self::num($f[5] ?? null) ?: null;
                    }
                }
                unset($a);
            }
        }
        unset($carrera);
        foreach ($res['carreras'] as &$carrera) {
            $carrera['actuaciones'] = self::corregirTiempos($carrera['actuaciones'], $carrera['tiempo_ganador_seg']);
        }
        unset($carrera);
        return $res;
    }

    /**
     * El Excel trae a veces un tiempo imposible: la diferencia con el ganador en lugar del tiempo ("0'00''68" con 4
     * cuerpos), o un dígito mal ("0'00''77" por 0'36''73, "2'44''61" a 13 cuerpos).
     * Las planillas calculan el tiempo de cada uno como el del ganador más 0,17 s por cuerpo: un tiempo que se aparta
     * más de 2 s de esa cuenta se reemplaza por ella (y queda "tiempo_excel" con el original).
     */
    public static function corregirTiempos(array $actuaciones, ?float $tiempoGanador): array
    {
        $fila = null;
        $bases = [];
        foreach ($actuaciones as $a) {
            $cuerpos = self::cuerpos($a['dist_acumulada'] ?? null);
            if ($a['puesto'] === 1 && $a['tiempo_seg']) {
                $fila ??= $a['tiempo_seg'];
            } elseif ($a['puesto'] !== null && $a['tiempo_seg'] && $cuerpos !== null) {
                $bases[] = $a['tiempo_seg'] - 0.17 * $cuerpos;
            }
        }
        $ganador = $fila ?? $tiempoGanador;
        // Si el ganador no concuerda con lo que dicen los demás (su tiempo menos sus cuerpos), no se sabe cuál está mal:
        // en esa carrera solo se corrigen los tiempos imposibles (menos del 80 % del ganador).
        $dudosa = false;
        if ($ganador !== null && count($bases) >= 3) {
            sort($bases);
            $mediana = $bases[intdiv(count($bases), 2)];
            $dudosa = abs($ganador - $mediana) > 2.0 && $mediana > 0.85 * $ganador;
        }
        if (!$ganador) {
            return $actuaciones;
        }
        // Cuerpos de los que llegaron antes: si a uno le figuran menos, los cuerpos están mal, no el tiempo.
        $antes = [];
        foreach ($actuaciones as $a) {
            $cuerpos = self::cuerpos($a['dist_acumulada'] ?? null);
            if ($a['puesto'] !== null && $cuerpos !== null) {
                $antes[$a['puesto']] = max($antes[$a['puesto']] ?? 0.0, $cuerpos);
            }
        }
        foreach ($actuaciones as &$a) {
            if ($a['puesto'] === null || $a['tiempo_seg'] === null) {
                continue;
            }
            $cuerpos = $a['puesto'] === 1 ? 0.0 : self::cuerpos($a['dist_acumulada'] ?? null);
            if ($cuerpos === null) {
                continue;
            }
            $previos = array_filter($antes, fn ($c, $p) => $p < $a['puesto'], ARRAY_FILTER_USE_BOTH);
            $imposible = $a['tiempo_seg'] < 0.8 * $ganador;
            $esperado = round($ganador + 0.17 * $cuerpos, 2);
            if ($imposible || !$dudosa && $cuerpos >= max($previos ?: [0.0]) && abs($a['tiempo_seg'] - $esperado) > 2.0 + 0.02 * $cuerpos) {
                $c = (int)round($esperado * 100);
                $a['tiempo_excel'] = $a['tiempo'];
                $a['tiempo_seg'] = $esperado;
                $a['tiempo'] = sprintf("%d'%02d''%02d", intdiv($c, 6000), intdiv($c % 6000, 100), $c % 100);
            }
        }
        unset($a);
        return $actuaciones;
    }

    /** Distancia al ganador en cuerpos: "2 1/4 Cpos." -> 2.25, "Cza." -> 0.2; null si no llegó o no figura. */
    public static function cuerpos(?string $d): ?float
    {
        $d = trim((string)$d);
        $cortas = ['Hoc.' => 0.05, '1/2 Cza.' => 0.1, 'Cza.' => 0.2, '1/2 Pzo.' => 0.25, 'Pzo.' => 0.4, 'Empate' => 0.0];
        if (isset($cortas[$d]) || str_starts_with($d, 'm') && str_ends_with($d, 'nima')) {
            return $cortas[$d] ?? 0.0;
        }
        if (!preg_match('#^(?:(\d+)\s*)?(?:(\d+)/(\d+)\s*)?Cpos?\.$#u', $d, $m) || ($m[1] ?? '') === '' && ($m[2] ?? '') === '') {
            return null;
        }
        return (float)($m[1] ?: 0) + (($m[2] ?? '') !== '' ? (int)$m[2] / (int)$m[3] : 0);
    }

    private function cabecera(int $numero, int $id, string $premio, string $c1, string $c2): array
    {
        $car = [
            'numero' => $numero, 'id' => $id, 'premio' => trim(rtrim(trim($premio), '- ')),
            'distancia' => null, 'hora' => null, 'superficie' => null, 'pista' => null, 'viento' => null,
            'condicion' => null, 'bolsa' => null, 'bolsa_detalle' => null,
            'tiempo_ganador' => null, 'tiempo_ganador_seg' => null, 'parciales' => null, 'retirados' => null, 'comentario' => null,
            'apuestas' => [], 'actuaciones' => [],
        ];
        // "1100 Mts., 16/10/2026 14:00 Pista seca. Viento En contra"
        if (preg_match('/^(\d+)\s*Mts\./u', $c1, $m)) {
            $car['distancia'] = (int)$m[1];
        }
        // "16/10/2026 14:00", o en formato inglés (2016): "6/3/2016 5:40 PM" = 3 de junio, 17:40
        if (preg_match('#(\d{1,2})/(\d{1,2})/(\d{4})\s+(\d{1,2}):(\d{2})(?:\s*([AP])M)?#i', $c1, $m)) {
            [$dia, $mes, $hora] = [(int)$m[1], (int)$m[2], (int)$m[4]];
            if (!empty($m[6])) {
                [$dia, $mes] = [$mes, $dia];
                $hora = $hora % 12 + (strtoupper($m[6]) === 'P' ? 12 : 0);
            }
            $car['hora'] = sprintf('%s-%02d-%02d %02d:%s:00', $m[3], $mes, $dia, $hora, $m[5]);
        }
        if (preg_match('/Pista\s+([^.]+)\./u', $c1, $m)) {
            // "seca", o en 2018 "arena - seca" / "cesped - seca" / "- seca" (superficie y estado)
            $p = explode('-', $m[1], 2);
            if (count($p) === 2) {
                $car['superficie'] = trim($p[0]) !== '' ? str_replace('cesped', 'césped', trim($p[0])) : null;
                $car['pista'] = trim($p[1]);
            } else {
                $car['pista'] = trim($m[1]);
            }
        }
        if (preg_match('/Viento\s+(.+)$/u', $c1, $m)) {
            $car['viento'] = trim($m[1]);
        }
        // "Condición: ... \nBolsa: $ 97.650,00; $ 78.750 al 1º, ..."
        if (preg_match('/Condici[óo]n:\s*(.*?)\s*(?:\n\s*Bolsa:\s*(.*))?$/us', $c2, $m)) {
            $car['condicion'] = $m[1] !== '' ? $m[1] : null;
            if (!empty($m[2])) {
                $partes = explode(';', $m[2], 2);
                $car['bolsa'] = self::num($partes[0]);
                $car['bolsa_detalle'] = isset($partes[1]) ? trim($partes[1]) : null;
            }
        }
        return $car;
    }

    private function actuacion(array $f): array
    {
        $puestoTxt = self::txt($f[self::COL_PUESTO] ?? null);
        $a = [
            // "0º" = no corrió (retirado): queda en puesto_texto, sin puesto numérico.
            'puesto' => preg_match('/^(\d+)º$/u', $puestoTxt, $m) && $m[1] !== '0' ? (int)$m[1] : null,
            'puesto_texto' => $puestoTxt,
            'nombre' => null, 'pais' => null, 'pelaje' => null, 'sexo' => null, 'anio_nac' => null,
            'gatera' => preg_match('/(\d+)/', self::txt($f[self::COL_GATERA] ?? null), $m) ? (int)$m[1] : null,
            'peso_caballo' => self::entero($f[self::COL_PESO_CABALLO] ?? null) ?: null,
            'retiro_motivo' => self::nulo($f[self::COL_RETIRO] ?? null),   // si lo retiraron de la carrera, y por qué
            'tratamiento' => self::nulo($f[self::COL_TRATAMIENTO] ?? null),  // tratamiento veterinario declarado
            'marca' => self::nulo($f[self::COL_MARCA] ?? null),   // columna G: c careta, m muserola, cm ambas
            'jockey' => self::nulo($f[self::COL_JOCKEY] ?? null),
            'peso_asignado' => self::num($f[self::COL_PESO_ASIG] ?? null),
            'peso_real' => self::num($f[self::COL_PESO_REAL] ?? null),
            'dist_anterior' => self::nulo($f[self::COL_DIST_ANT] ?? null),
            'dist_acumulada' => self::nulo($f[self::COL_DIST_ACUM] ?? null),
            'sport' => self::num($f[self::COL_SPORT] ?? null),
            'div_segundo' => null, 'div_tercero' => null,
            'padre' => null, 'madre' => null, 'abuelo_materno' => null,
            'cuidador' => self::nulo($f[self::COL_CUIDADOR] ?? null),
            'tiempo' => self::nulo($f[self::COL_TIEMPO] ?? null),
            'tiempo_seg' => null,
            'stud' => self::nulo($f[self::COL_STUD] ?? null),
            'criador' => self::nulo($f[self::COL_CRIADOR] ?? null),
            'antidoping' => false,
        ];
        // "QUERIDO STORM t m 21" / "TORDO CARO (BRZ) t m 19": nombre, pelaje, sexo, año (2 dígitos)
        $cab = self::txt($f[self::COL_CABALLO] ?? null);
        if (preg_match('/^(.+?)\s+(\S{1,4})\s+([mh])\s+(\d{2})$/u', $cab, $m)) {
            [$a['nombre'], $a['pelaje'], $a['sexo']] = [$m[1], $m[2], $m[3]];
            $a['anio_nac'] = 2000 + (int)$m[4];
            if ($a['anio_nac'] > (int)date('Y')) {
                $a['anio_nac'] -= 100;
            }
        } else {
            $a['nombre'] = $cab;
        }
        // Nacionalidad entre paréntesis cuando no es uruguayo: "TORDO CARO (BRZ)"
        // (se saca del nombre: la identidad del caballo es nombre + país + año).
        $a['pais'] = 'URU';
        if (preg_match('/\s*\(([A-Z]{2,4})\)$/u', $a['nombre'], $m)) {
            $a['pais'] = $m[1];
            $a['nombre'] = substr($a['nombre'], 0, -strlen($m[0]));
        }
        if ($a['puesto'] === null) {
            // Retirado: el Excel rellena tiempo 0'00''00, peso real 0 y dividendo 0.
            [$a['tiempo'], $a['peso_real'], $a['sport']] = [null, $a['peso_real'] ?: null, $a['sport'] ?: null];
        }
        $a['tiempo_seg'] = self::segundos($a['tiempo']);
        // "Saratoga Storm y Mal Querida por Southern Lawyer" ("e" delante de i/hi)
        $ped = self::txt($f[self::COL_PEDIGRI] ?? null);
        if (preg_match('/^(.+?)\s+(?:y|e)\s+(.+)\s+por\s+(.+)$/u', $ped, $m)) {
            [$a['padre'], $a['madre'], $a['abuelo_materno']] = [$m[1], $m[2], $m[3]];
        }
        return $a;
    }

    /** "Tiempo: 1'06''68 / Parciales: ...\nRetirados: 0 / $30,00 Exacta: 5/4 $1.038,00 / ...\nComentario: ..." */
    private function resumen(array &$car, string $txt): void
    {
        foreach (preg_split('/\R/u', $txt) as $linea) {
            $linea = trim($linea);
            if (preg_match('/^Tiempo:\s*(.*?)\s*\/\s*Parciales:\s*(.*)$/u', $linea, $m)) {
                $car['tiempo_ganador'] = $m[1] ?: null;
                $car['tiempo_ganador_seg'] = self::segundos($car['tiempo_ganador']);
                $car['parciales'] = $m[2] ?: null;
            } elseif (str_starts_with($linea, 'Retirados:')) {
                $partes = array_map('trim', explode(' / ', $linea));
                $car['retirados'] = trim(substr(array_shift($partes), strlen('Retirados:')));
                foreach ($partes as $p) {
                    if ($ap = $this->apuesta($p)) {
                        $car['apuestas'][] = $ap;
                    }
                }
            } elseif (str_starts_with($linea, 'Comentario:')) {
                $car['comentario'] = trim(substr($linea, strlen('Comentario:'))) ?: null;
            }
        }
    }

    /** "$30,00 Exacta: 5/4 $1.038,00" o "$30,00 Pick 3: sin acierto (pozo $2.018,00)" */
    private function apuesta(string $p): ?array
    {
        if (!preg_match('/^\$\s*([\d.,]+)\s+(.+?):\s*(.*)$/u', $p, $m)) {
            return null;
        }
        $ap = ['tipo' => $m[2], 'monto_base' => self::num($m[1]), 'combinacion' => null, 'dividendo' => null, 'pozo' => null];
        if (preg_match('/^([\d\/\-]+)\s+\$\s*([\d.,]+)/u', $m[3], $r)) {
            $ap['combinacion'] = $r[1];
            $ap['dividendo'] = self::num($r[2]);
        } elseif (preg_match('/pozo\s+\$\s*([\d.,]+)/u', $m[3], $r)) {
            $ap['pozo'] = self::num($r[1]);
        }
        return $ap;
    }

    /** "1'06''68" (minutos'segundos''centésimas) -> 66.68 */
    public static function segundos(?string $t): ?float
    {
        if ($t === null || !preg_match("/^(\d+)'(\d{1,2})''(\d{1,2})$/", $t, $m)) {
            return null;
        }
        $seg = round((int)$m[1] * 60 + (int)$m[2] + (int)str_pad($m[3], 2, '0') / 100, 2);
        return $seg > 0 ? $seg : null;   // 0'00''00 = sin tiempo registrado
    }

    private static function txt(mixed $v): string
    {
        return $v === null ? '' : trim((string)$v);
    }

    private static function nulo(mixed $v): ?string
    {
        $t = self::txt($v);
        return ($t === '' || $t === '-') ? null : $t;
    }

    private static function entero(mixed $v): ?int
    {
        $n = self::num($v);
        return $n === null ? null : (int)round($n);
    }

    /** Números del informe: "3,60", "$ 97.650,00", "55,3", o celdas numéricas ya convertidas. */
    public static function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        $t = preg_replace('/[^\d.,\-]/', '', self::txt($v));
        if ($t === '' || $t === '-') {
            return null;
        }
        // Hay reuniones con formato uruguayo (97.650,00) y otras con formato inglés (254,538.00):
        // si aparecen los dos separadores, el último es el decimal; uno solo en grupos de 3 es de miles.
        $coma = strrpos($t, ',');
        $punto = strrpos($t, '.');
        if ($coma !== false && $punto !== false) {
            $t = $coma > $punto ? str_replace(['.', ','], ['', '.'], $t) : str_replace(',', '', $t);
        } elseif (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $t)) {
            $t = str_replace(['.', ','], '', $t);
        } else {
            $t = str_replace(',', '.', $t);
        }
        return is_numeric($t) ? (float)$t : null;
    }
}
