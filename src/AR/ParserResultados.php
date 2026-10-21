<?php
declare(strict_types=1);

namespace GranChance\AR;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Lee las páginas del Stud Book Argentino: el calendario de un mes, los resultados de una reunión
 * (/reuniones/detalle/ID/SLUG, todas las carreras en una página) y la ficha de un ejemplar.
 */
final class ParserResultados
{
    /** @return list<array{sb_id: int, slug: string, fecha: string, hipodromo: string, sigla: ?string, nombre: string, carreras: ?int}> */
    public function calendario(string $html): array
    {
        $x = self::xpath($html);
        $reuniones = [];
        foreach ($x->query('//td[@data-anio and @data-mes and @data-dia]') as $td) {
            /** @var DOMElement $td */
            $fecha = sprintf('%04d-%02d-%02d', $td->getAttribute('data-anio'), $td->getAttribute('data-mes'), $td->getAttribute('data-dia'));
            foreach ($x->query('.//a[contains(@href,"/reuniones/detalle/")]', $td) as $a) {
                /** @var DOMElement $a */
                if (!preg_match('#/reuniones/detalle/(\d+)/((\d{8})-(.+)-\d+)$#', $a->getAttribute('href'), $m)) {
                    continue;
                }
                $datos = self::texto($x->query('.//div[contains(@class,"box-dato")]', $a)->item(0));
                $reuniones[] = [
                    'sb_id' => (int)$m[1], 'slug' => $m[2], 'fecha' => $fecha, 'hipodromo' => $m[4],
                    'sigla' => self::texto($x->query('.//div[contains(@class,"box-hipo")]/div', $a)->item(0)) ?: null,
                    'nombre' => self::texto($x->query('.//span[contains(@class,"label")]', $a)->item(0)) ?: $m[4],
                    'carreras' => preg_match('#^(\d+)#', $datos, $mc) ? (int)$mc[1] : null,
                ];
            }
        }
        return $reuniones;
    }

    /**
     * Toda la reunión: fecha, número de reunión y las carreras con sus actuaciones.
     * @return array{fecha: ?string, nro_reunion: ?int, hipodromo: ?string, carreras: list<array>}
     */
    public function reunion(string $html): array
    {
        $x = self::xpath($html);
        $sub = self::texto($x->query('//h4[contains(@class,"subtitle-reunion")]')->item(0));
        $r = ['fecha' => null, 'nro_reunion' => null, 'hipodromo' => null, 'carreras' => []];
        if (preg_match('#^(\d\d)/(\d\d)/(\d{4})\s+(.*?)(?:\s*\|.*)?$#u', $sub, $m)) {
            $r['fecha'] = "$m[3]-$m[2]-$m[1]";
            $r['hipodromo'] = trim(preg_replace('#^Hip[oó]dromo\s+(de\s+)?#iu', '', $m[4]));
        }
        if (preg_match('#REUNION\s+(\d+)#i', self::texto($x->query('//h3[contains(@class,"reunion")]')->item(0)), $m)) {
            $r['nro_reunion'] = (int)$m[1];
        }
        // id de cada carrera en el Stud Book, de la botonera de arriba: <a href="#r1" id="b-174290">
        $ids = [];
        foreach ($x->query('//a[starts-with(@href,"#r") and starts-with(@id,"b-")]') as $a) {
            $ids[(int)substr($a->getAttribute('href'), 2)] = (int)substr($a->getAttribute('id'), 2);
        }
        // Cada carrera va desde su título (h2 id="rN") hasta el título siguiente: se corta el HTML ahí y se lee cada trozo.
        $trozos = preg_split('#(?=<h2\b[^>]*carrera-cabecera-titulo)#', $html);
        array_shift($trozos);
        foreach ($trozos as $t) {
            $c = $this->carrera($t, $r['fecha']);
            if ($c !== null) {
                $c['nro_oficial'] = $ids[$c['numero']] ?? null;
                $r['carreras'][] = $c;
            }
        }
        return $r;
    }

    private function carrera(string $trozo, ?string $fecha): ?array
    {
        $x = self::xpath('<html><head><meta charset="utf-8"></head><body>' . $trozo . '</body></html>');
        $h2 = $x->query('//h2[contains(@class,"carrera-cabecera-titulo")]')->item(0);
        if (!$h2 instanceof DOMElement || !preg_match('#^r(\d+)$#', $h2->getAttribute('id'), $m)) {
            return null;
        }
        $c = [
            'numero' => (int)$m[1], 'nro_oficial' => null, 'hora' => null, 'premio' => null, 'grupo' => null, 'distancia' => null,
            'superficie' => null, 'recorrido' => null, 'pista' => null, 'categoria' => null, 'condicion' => null,
            'bolsa' => null, 'bolsa_dolares' => null, 'bolsa_detalle' => null, 'tiempo_ganador' => null, 'tiempo_ganador_seg' => null,
            'retirados' => null, 'ganador' => null, 'actuaciones' => [],
        ];
        $hora = self::texto($x->query('.//span[contains(@class,"carrera-cabecera-hora")]', $h2)->item(0));
        if ($fecha !== null && preg_match('#^(\d{1,2}):(\d\d)$#', $hora, $mh)) {
            $c['hora'] = sprintf('%s %02d:%02d:00', $fecha, $mh[1], $mh[2]);
        }
        $c['premio'] = self::texto($x->query('//span[contains(@class,"carrera-cabecera-premio-nombre")]')->item(0)) ?: null;
        if ($c['premio'] !== null && preg_match('#\((G[123]|L)\)|\b(G[123])\b#', $c['premio'], $mg)) {
            $c['grupo'] = $mg[1] ?: $mg[2];
        }
        $dist = self::texto($x->query('//span[contains(@class,"carrera-distancia-grupo")]')->item(0));
        if (preg_match('#(\d[\d.]*)\s*mts#', $dist, $md)) {
            $c['distancia'] = (int)str_replace('.', '', $md[1]);
        }
        $tiempo = self::texto($x->query('//span[contains(@class,"carrera-distancia-tiempo")]')->item(0));
        if ($tiempo !== '') {
            $c['tiempo_ganador'] = $tiempo;
            $c['tiempo_ganador_seg'] = self::segundos($tiempo);
        }
        foreach ($x->query('//table[contains(@class,"carrera-cabecera-datos")]//td') as $td) {
            $t = self::texto($td);
            if (str_starts_with($t, 'Condición:')) {
                $c['condicion'] = trim(substr($t, strlen('Condición:')));
            }
        }
        $meta = fn(string $clase) => self::texto($x->query("//span[contains(@class,\"carrera-cabecera-metadato-$clase\")]/span[contains(@class,\"metadato-valor\")]")->item(0));
        $pista = $meta('pista');
        if (preg_match('#^(.*?)\s*(?:\((.*)\))?$#u', $pista, $mp) && $pista !== '') {
            $sup = mb_strtolower(trim($mp[1]));
            $c['superficie'] = match (true) {
                str_starts_with($sup, 'c') => 'césped',
                str_starts_with($sup, 'a') => 'arena',
                default => mb_substr($sup, 0, 10),
            };
            $c['recorrido'] = ($mp[2] ?? '') !== '' ? $mp[2] : null;
        }
        $c['pista'] = $meta('estado') ?: null;
        $c['categoria'] = $meta('categoria') ?: null;
        if ($c['grupo'] === null && $c['categoria'] !== null && preg_match('#Grupo (I{1,3})\b|Listado#', $c['categoria'], $mg)) {
            $c['grupo'] = isset($mg[1]) ? 'G' . strlen($mg[1]) : 'L';
        }

        $titulo = $x->query('//span[contains(@class,"carrera-premios-titulo-principal")]')->item(0);
        if ($titulo !== null) {
            $c['bolsa'] = self::importe(self::texto($x->query('.//span[contains(@class,"p-peso")]', $titulo)->item(0)));
            $c['bolsa_dolares'] = self::importe(self::texto($x->query('.//span[contains(@class,"p-dolar")]', $titulo)->item(0)));
        }
        $premios = [];
        $tabPremios = $x->query('//div[contains(@class,"carrera-cabecera-premios")]//table')->item(0);
        if ($tabPremios !== null) {
            $pos = array_map(fn($n) => self::texto($n), iterator_to_array($x->query('.//th', $tabPremios)));
            foreach ($x->query('.//tbody//td', $tabPremios) as $i => $td) {
                $premios[] = ($pos[$i] ?? '?') . ' ' . self::texto($x->query('.//span[contains(@class,"p-peso")]', $td)->item(0));
            }
        }
        $bonus = self::texto($x->query('//span[contains(@class,"carrera-premios-bonus")]')->item(0));
        if ($bonus !== '') {
            $bonus = trim(preg_replace('#\s*U\$S.*$#u', '', $bonus));
            $premios[] = $bonus;
        }
        $c['bolsa_detalle'] = $premios === [] ? null : mb_substr(implode(' | ', $premios), 0, 255);

        $c['ganador'] = $this->ganador($x);

        foreach ($x->query('//table[.//th[normalize-space()="Ejemplar"] and .//th[normalize-space()="Pago"]]') as $tab) {
            $c['actuaciones'] = array_merge($c['actuaciones'], $this->resultados($x, $tab, $fecha));
        }
        $retirados = [];
        foreach ($x->query('//table[contains(@class,"labels-no-destacado") and .//th[normalize-space()="Ejemplar"]]') as $tab) {
            foreach ($this->retirados($x, $tab) as $a) {
                $c['actuaciones'][] = $a;
                $retirados[] = $a['gatera'] ?? $a['nombre'];
            }
        }
        $c['retirados'] = $retirados === [] ? null : mb_substr(implode(' ', $retirados), 0, 255);
        return $c;
    }

    /** El recuadro del ganador: nacimiento, padres, abuelo materno y criador. */
    private function ganador(DOMXPath $x): ?array
    {
        $p = $x->query('//p[contains(@class,"ganador-compacto-datos")]')->item(0);
        if ($p === null) {
            return null;
        }
        $g = ['fecha_nac' => null, 'padre' => null, 'madre' => null, 'abuelo_materno' => null, 'criador' => null];
        if (preg_match('#(\d\d)/(\d\d)/(\d{4})#', self::texto($p), $m)) {
            $g['fecha_nac'] = "$m[3]-$m[2]-$m[1]";
        }
        $ej = [];
        foreach ($x->query('.//a[contains(@href,"/ejemplares/perfil/")]', $p) as $a) {
            $ej[] = self::enlace($a);
        }
        [$g['padre'], $g['madre'], $g['abuelo_materno']] = [$ej[0] ?? null, $ej[1] ?? null, $ej[2] ?? null];
        $cr = $x->query('.//a[contains(@href,"/criadores/perfil/")]', $p)->item(0);
        $g['criador'] = $cr === null ? null : self::enlace($cr);
        return $g;
    }

    /** Filas de la tabla RESULTADOS. Las columnas se ubican por el encabezado, que cambió con los años (H y T se agregaron después). */
    private function resultados(DOMXPath $x, DOMNode $tab, ?string $fecha): array
    {
        $cols = [];
        $vistos = [];
        foreach ($x->query('.//thead//th', $tab) as $i => $th) {
            $t = rtrim(self::texto($th), '.');
            $n = $vistos[$t] = ($vistos[$t] ?? 0) + 1;
            $cols[$i] = match ($t) {
                'P' => $n === 1 ? 'puesto' : 'pelaje',
                'Kg' => $n === 1 ? 'peso_caballo' : 'peso_asignado',
                'O' => 'gatera', 'Ejemplar' => 'ejemplar', 'S' => 'sexo', 'E' => 'edad', 'H' => 'marca', 'T' => 'tratamiento',
                'Jockey' => 'jockey', 'Entrenador', 'Cuidador' => 'cuidador', 'Caballeriza' => 'stud',
                'Cpos' => 'dist_anterior', 'Acum' => 'dist_acumulada', 'Pago' => 'sport',
                default => 'otro_' . $t,
            };
        }
        $filas = [];
        foreach ($x->query('.//tbody/tr', $tab) as $tr) {
            $tds = iterator_to_array($x->query('./td', $tr));
            if (count($tds) < 5) {
                continue;
            }
            $a = self::actuacionVacia();
            foreach ($tds as $i => $td) {
                $col = $cols[$i] ?? null;
                $v = self::texto($td);
                switch ($col) {
                    case 'puesto':
                        $a['puesto_texto'] = $v !== '' ? $v : null;
                        $a['puesto'] = ctype_digit($v) && (int)$v > 0 && (int)$v < 99 ? (int)$v : null;
                        break;
                    case 'ejemplar':
                        $e = $x->query('.//a[contains(@href,"/ejemplares/perfil/")]', $td)->item(0);
                        if ($e !== null) {
                            $l = self::enlace($e);
                            [$a['sb_id'], $a['slug'], $a['nombre']] = [$l['codigo'], $l['slug'], $l['nombre']];
                        } else {
                            $a['nombre'] = $v;
                        }
                        $b = $x->query('.//img[contains(@src,"/banderas/")]/@src', $td)->item(0);
                        if ($b !== null && preg_match('#/(\d+)\.\w+$#', $b->nodeValue, $mb)) {
                            $a['bandera'] = (int)$mb[1];
                        }
                        break;
                    case 'jockey': case 'cuidador': case 'stud':
                        $l = $x->query('.//a', $td)->item(0);
                        $a[$col] = $l !== null ? self::enlace($l) : ($v !== '' ? ['codigo' => null, 'slug' => null, 'nombre' => $v] : null);
                        break;
                    case 'gatera': case 'edad': case 'peso_caballo':
                        $a[$col] = ctype_digit($v) && (int)$v > 0 ? (int)$v : null;
                        break;
                    case 'peso_asignado': case 'sport':
                        $a[$col] = is_numeric(str_replace(',', '.', $v)) && (float)str_replace(',', '.', $v) > 0 ? (float)str_replace(',', '.', $v) : null;
                        break;
                    case 'sexo': case 'pelaje': case 'marca': case 'tratamiento': case 'dist_anterior': case 'dist_acumulada':
                        $a[$col] = $v !== '' ? $v : null;
                        break;
                }
            }
            $a['ganador'] = $a['puesto'] === 1;
            $a['anio_nac'] = self::anioNacimiento($fecha, $a['edad']);
            $filas[] = $a;
        }
        // Los que no llegaron figuran con un código (RD rodó, DM desmontó, NL no largó, DP distanciado) o con un número
        // mayor que la cantidad de participantes (27, 28, 29, 36): no tienen puesto.
        foreach ($filas as &$a) {
            if ($a['puesto'] !== null && $a['puesto'] > count($filas)) {
                $a['puesto'] = null;
            }
        }
        unset($a);
        // Hasta ~2018 la columna Cpos de cada fila es la distancia con el caballo de atrás (el ganador la tiene); después,
        // con el de adelante (el ganador la tiene vacía). Se guarda siempre la distancia con el de adelante.
        if ($filas !== [] && $filas[0]['puesto'] === 1 && $filas[0]['dist_anterior'] !== null) {
            for ($i = count($filas) - 1; $i >= 0; $i--) {
                $filas[$i]['dist_anterior'] = $i === 0 ? null : $filas[$i - 1]['dist_anterior'];
            }
        }
        return $filas;
    }

    /** Tabla RETIRADOS: número, ejemplar con "(21 H Z, PADRE - MADRE)", entrenador y caballeriza. */
    private function retirados(DOMXPath $x, DOMNode $tab): array
    {
        $filas = [];
        foreach ($x->query('.//tbody/tr', $tab) as $tr) {
            $tds = iterator_to_array($x->query('./td', $tr));
            if (count($tds) < 2) {
                continue;
            }
            $a = self::actuacionVacia();
            $a['retiro_motivo'] = 'retirado';
            $a['puesto_texto'] = 'Ret.';
            $v = self::texto($tds[0]);
            $a['gatera'] = ctype_digit($v) ? (int)$v : null;
            $e = $x->query('.//a[contains(@href,"/ejemplares/perfil/")]', $tds[1])->item(0);
            if ($e !== null) {
                $l = self::enlace($e);
                [$a['sb_id'], $a['slug'], $a['nombre']] = [$l['codigo'], $l['slug'], $l['nombre']];
            }
            if (preg_match('#\((\d\d)\s+([A-Z])\s+([A-Z]+),\s*(.+?)\s+-\s+(.+)\)\s*$#u', self::texto($tds[1]), $m)) {
                $anio = 2000 + (int)$m[1];
                $a['anio_nac'] = $anio > (int)date('Y') ? $anio - 100 : $anio;
                [$a['sexo'], $a['pelaje']] = [$m[2], $m[3]];
                $a['padre_nombre'] = $m[4];
                $a['madre_nombre'] = $m[5];
            }
            foreach ([2 => 'cuidador', 3 => 'stud'] as $i => $col) {
                $l = isset($tds[$i]) ? $x->query('.//a', $tds[$i])->item(0) : null;
                $a[$col] = $l !== null ? self::enlace($l) : null;
            }
            if ($a['sb_id'] !== null) {
                $filas[] = $a;
            }
        }
        return $filas;
    }

    /** Ficha del ejemplar: país, sexo, pelaje, fecha de nacimiento, padres (con su id) y criador. */
    public function ficha(string $html): array
    {
        $x = self::xpath($html);
        $f = ['pais' => null, 'sexo' => null, 'pelaje' => null, 'fecha_nac' => null, 'padre' => null, 'madre' => null, 'abuelo_materno' => null, 'criador' => null];
        // <p><img src="/img/banderas/10.png"> Argentina | Hembra | Alazan | SP</p> y en el párrafo siguiente "10/10/2010 (16 años)".
        $p = $x->query('//p[img[contains(@src,"/banderas/")]]')->item(0);
        if ($p !== null) {
            $partes = array_map('trim', explode('|', self::texto($p)));
            $f['pais'] = $partes[0] !== '' ? self::pais($partes[0]) : null;
            $f['sexo'] = ['Macho' => 'M', 'Hembra' => 'H', 'Castrado' => 'C'][$partes[1] ?? ''] ?? null;
            $f['pelaje'] = isset($partes[2]) ? mb_substr($partes[2], 0, 20) : null;
            $sig = $p->nextSibling;
            while ($sig !== null && !($sig instanceof DOMElement)) {
                $sig = $sig->nextSibling;
            }
            if ($sig !== null && preg_match('#(\d\d)/(\d\d)/(\d{4})#', self::texto($sig), $m) && $m[3] !== '0001') {
                $f['fecha_nac'] = "$m[3]-$m[2]-$m[1]";
            }
        }
        // Los enlaces a ejemplares antes de la bandera del país (después vienen los del inbreeding): padre, madre, abuelo materno.
        $i = strpos($html, '/banderas/');
        $xc = $i === false ? $x : self::xpath(substr($html, 0, $i));
        $ej = [];
        foreach ($xc->query('//a[contains(@href,"/ejemplares/perfil/")]') as $a) {
            $l = self::enlace($a);
            if ($l['codigo'] !== null) {
                $ej[] = $l;
            }
            if (count($ej) === 3) {
                break;
            }
        }
        [$f['padre'], $f['madre'], $f['abuelo_materno']] = [$ej[0] ?? null, $ej[1] ?? null, $ej[2] ?? null];
        $cr = $x->query('//a[contains(@href,"/criadores/perfil/")]')->item(0);
        $f['criador'] = $cr === null ? null : self::enlace($cr);
        return $f;
    }

    private static function pais(string $nombre): string
    {
        // Tabla de /sb/abreviaturas del Stud Book.
        $n = mb_strtoupper(strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
        return [
            'ARGELIA' => 'ALG', 'ARGENTINA' => 'ARG', 'AUSTRALIA' => 'AUS', 'BELGICA' => 'BEL', 'BOLIVIA' => 'BOL', 'BRASIL' => 'BRZ',
            'BULGARIA' => 'BUL', 'CANADA' => 'CAN', 'CHILE' => 'CHI', 'COLOMBIA' => 'COL', 'CUBA' => 'CUB', 'DINAMARCA' => 'DEN',
            'ECUADOR' => 'ECU', 'EGIPTO' => 'EGY', 'EMIRATOS ARABES' => 'EMA', 'FRANCIA' => 'FR', 'GRAN BRETAÑA' => 'GB', 'ALEMANIA' => 'GER',
            'HOLANDA' => 'HOL', 'IRLANDA' => 'IRE', 'ITALIA' => 'ITY', 'JAPON' => 'JPN', 'KUWAIT' => 'KUW', 'LUXEMBURGO' => 'LUX',
            'MALASIA' => 'MAL', 'MEXICO' => 'MEX', 'NORUEGA' => 'NGA', 'NUEVA ZELANDA' => 'NZ', 'PANAMA' => 'PAN', 'PARAGUAY' => 'PAR',
            'PERU' => 'PER', 'POLONIA' => 'POL', 'PUERTO RICO' => 'PR', 'REPUBLICA DOMINICANA' => 'RDA', 'SUDAFRICA' => 'SAF',
            'ARABIA SAUDITA' => 'SDA', 'SINGAPUR' => 'SGP', 'ESPAÑA' => 'SPA', 'SUECIA' => 'SWE', 'TURQUIA' => 'TUR', 'URUGUAY' => 'URU',
            'ESTADOS UNIDOS' => 'USA', 'ESTADOS UNIDOS DE AMERICA' => 'USA', 'VENEZUELA' => 'VEN',
        ][$n] ?? mb_substr($n, 0, 4);
    }

    private static function actuacionVacia(): array
    {
        return [
            'sb_id' => null, 'slug' => null, 'nombre' => null, 'bandera' => null, 'puesto' => null, 'puesto_texto' => null, 'gatera' => null,
            'sexo' => null, 'pelaje' => null, 'edad' => null, 'anio_nac' => null, 'peso_caballo' => null, 'marca' => null, 'tratamiento' => null,
            'jockey' => null, 'peso_asignado' => null, 'cuidador' => null, 'stud' => null, 'dist_anterior' => null, 'dist_acumulada' => null,
            'sport' => null, 'retiro_motivo' => null, 'padre_nombre' => null, 'madre_nombre' => null, 'ganador' => false,
        ];
    }

    /** Las edades cumplen el 1º de julio: un potrillo nacido en septiembre de 2010 tiene 4 años hasta junio de 2015. */
    public static function anioNacimiento(?string $fecha, ?int $edad): ?int
    {
        if ($fecha === null || $edad === null) {
            return null;
        }
        return (int)substr($fecha, 0, 4) - $edad - ((int)substr($fecha, 5, 2) < 7 ? 1 : 0);
    }

    /** @return array{codigo: ?string, slug: ?string, nombre: string} de un enlace /tipo/perfil/ID/slug */
    private static function enlace(DOMElement $a): array
    {
        $href = $a->getAttribute('href');
        preg_match('#/([0-9A-Za-z]+)/([^/?\#]+)$#', $href, $m);
        return ['codigo' => $m[1] ?? null, 'slug' => $m[2] ?? null, 'nombre' => self::texto($a)];
    }

    /** 1'38"35/100 → 98.35; 58"20/100 → 58.20 */
    public static function segundos(string $t): ?float
    {
        if (preg_match('#^(?:(\d+)\')?\s*(\d+)"\s*(\d+)/100#', $t, $m)) {
            return round((int)($m[1] ?: 0) * 60 + (int)$m[2] + (int)$m[3] / 100, 2);
        }
        if (preg_match('#^(?:(\d+)\')?\s*(\d+)"\s*(\d+)/5#', $t, $m)) {
            return round((int)($m[1] ?: 0) * 60 + (int)$m[2] + (int)$m[3] / 5, 2);
        }
        return null;
    }

    /** "$ 96,880" → 96880; "U$S 11,452" → 11452 */
    private static function importe(string $t): ?float
    {
        if (!preg_match('#([\d.,]+)#', $t, $m)) {
            return null;
        }
        $n = (float)str_replace([',', '.'], '', $m[1]);
        return $n > 0 ? $n : null;
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
        $d = new DOMDocument();
        libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        return new DOMXPath($d);
    }
}
