<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Campaña en otros países de los caballos de un programa: cada país tiene su base, y un caballo que viaja (de Argentina
 * a Uruguay, de Japón a Hong Kong...) figura en las dos con distinto id. Es el mismo si coinciden el nombre (sin el país
 * que va al final: "SNAP DECISION-ARG", "GIULIA(USA)", "CIENCIA NACIONAL (USA)"), el padre y la madre, y el año de
 * nacimiento difiere en uno como mucho (Brasil y el hemisferio norte lo cuentan distinto: LONESTAR es de 2014 en
 * Uruguay y de 2015 en Brasil). El nombre solo no alcanza: HANNAH MONTANA 2012 de Uruguay es hija de Smarty Jones, la
 * de Argentina de Glick.
 *
 * En el programa, las carreras de afuera se entrelazan por fecha con las del país en la tabla de antecedentes (las
 * últimas 6, sin ajuste ni índices); en la ficha del caballo, la campaña de cada país va aparte. Se busca por el nombre
 * (indexado en todas las bases), así sirve también para el que debuta en el país viniendo de afuera. Con las bases
 * de octubre de 2026 hay unos 470 caballos en dos países (Uruguay y Brasil 220, Uruguay y Argentina 82, Argentina y
 * Brasil 51, Hong Kong y Japón 121). Es solo para leer: el índice y la probabilidad siguen con la campaña del país,
 * porque cada país tiene su propia escala de velocidades.
 */
final class CampaniaExterior
{
    public const PAISES = ['uy' => 'Uruguay', 'ar' => 'Argentina', 'br' => 'Brasil', 'hk' => 'Hong Kong', 'jp' => 'Japón'];
    /** Cómo se marca el país en la columna del hipódromo del programa. */
    private const CODIGOS = ['uy' => 'URU', 'ar' => 'ARG', 'br' => 'BRA', 'hk' => 'HK', 'jp' => 'JPN'];
    /** Sufijos de país que van al final del nombre con guion ("SNAP DECISION-ARG"); entre paréntesis se saca cualquiera. */
    private const SUFIJOS = 'ARG|URU|BRZ|BRA|USA|CHI|PER|GB|IRE|FR|FRA|GER|AUS|NZ|JPN|SAF|CAN|ITY|ITA|SPA|ECU|VEN|PAN|COL|MEX';

    /** @var array<string, PDO|false> bases ya abiertas (false si no se pudo) */
    private array $pdo = [];

    /**
     * @param array<string, array{dsn: string, usuario: string, clave: string}> $bases país => conexión
     * @param string $local el país del programa (no se busca en su propia base)
     */
    public function __construct(private array $bases, private string $local)
    {
    }

    /** Las bases de los países que están en config.php (Uruguay en 'db', los demás en 'ar', 'br', 'hk', 'jp'). */
    public static function desdeConfig(array $config, string $local): self
    {
        $bases = ['uy' => $config['db']];
        foreach (['ar', 'br', 'hk', 'jp'] as $p) {
            if (isset($config[$p]['db'])) {
                $bases[$p] = $config[$p]['db'];
            }
        }
        return new self($bases, $local);
    }

    /** Nombre (o padre, o madre) para comparar entre países: sin el país del final, sin acentos, espacios ni signos. */
    public static function clave(?string $nombre): string
    {
        $s = strtoupper(self::sinAcentos(trim((string)$nombre)));
        $s = preg_replace('/\s*\([^)]*\)\s*,?$/', '', $s);
        $s = preg_replace('/\s*-\s*(' . self::SUFIJOS . ')$/', '', $s);
        return preg_replace('/[^A-Z0-9]/', '', $s);
    }

    /** El nombre sin el país del final, para buscarlo con LIKE 'NOMBRE%' (la base no distingue mayúsculas ni acentos). */
    private static function prefijo(string $nombre): string
    {
        $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($nombre));
        $s = preg_replace('/\s*-\s*(' . self::SUFIJOS . ')$/i', '', $s);
        return addcslashes(trim($s), '%_\\');
    }

    private static function sinAcentos(string $s): string
    {
        $t = class_exists(\Transliterator::class) ? \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC') : null;
        return $t ? $t->transliterate($s) : strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ã' => 'a', 'õ' => 'o',
            'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'ç' => 'c', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O',
            'Ú' => 'U', 'Ã' => 'A', 'Õ' => 'O', 'Â' => 'A', 'Ê' => 'E', 'Ô' => 'O', 'Ç' => 'C', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    private function db(string $pais): ?PDO
    {
        if (!array_key_exists($pais, $this->pdo)) {
            $c = $this->bases[$pais];
            try {
                $this->pdo[$pais] = new PDO($c['dsn'], $c['usuario'], $c['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (\PDOException) {
                // Sin la base de ese país (otro servidor, todavía no cargada): el programa sale igual, sin esa campaña.
                $this->pdo[$pais] = false;
            }
        }
        return $this->pdo[$pais] ?: null;
    }

    /**
     * Las carreras del caballo en los otros países antes de $fecha, de la más reciente a la más vieja.
     * Sin padre o madre no se busca (sin el pedigrí no se sabe si es el mismo).
     * @return list<array{pais: string, nombre_pais: string, caballo_id: int, nombre: string, total: int, carreras: list<array>}>
     */
    public function buscar(string $nombre, ?int $anioNac, ?string $padre, ?string $madre, string $fecha): array
    {
        $clave = self::clave($nombre);
        $cp = self::clave($padre);
        $cm = self::clave($madre);
        $pre = self::prefijo($nombre);
        if ($clave === '' || $cp === '' || $cm === '' || $pre === '') {
            return [];
        }
        $res = [];
        foreach (array_keys($this->bases) as $pais) {
            if ($pais === $this->local || !($db = $this->db($pais))) {
                continue;
            }
            $st = $db->prepare('SELECT id, nombre, anio_nac, padre, madre FROM caballo WHERE nombre LIKE ?');
            $st->execute([$pre . '%']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ca) {
                if (self::clave($ca['nombre']) !== $clave || self::clave($ca['padre']) !== $cp || self::clave($ca['madre']) !== $cm
                    || ($anioNac && $ca['anio_nac'] && abs($anioNac - (int)$ca['anio_nac']) > 1)) {
                    continue;
                }
                $carreras = $this->carreras($pais, $db, (int)$ca['id'], $fecha);
                if (!$carreras) {
                    continue;
                }
                // Brasil a veces tiene el mismo caballo dos veces ("ITAPERUNA", "ITAPERUNA (L)"): una sola campaña,
                // con el enlace a la ficha que tiene más carreras.
                $x = $res[$pais] ?? null;
                if (!$x || count($carreras) > $x['propias']) {
                    $x = ['caballo_id' => (int)$ca['id'], 'nombre' => $ca['nombre'], 'propias' => count($carreras)] + ($x ?? []);
                }
                foreach ($carreras as $c) {
                    $x['carreras'][sprintf('%s %02d %s', $c['fecha'], $c['numero'], $c['hipodromo'])] = $c;
                }
                $res[$pais] = $x;
            }
        }
        foreach ($res as $pais => &$x) {
            krsort($x['carreras']);
            $x = ['pais' => $pais, 'nombre_pais' => self::PAISES[$pais], 'caballo_id' => $x['caballo_id'], 'nombre' => $x['nombre'],
                'total' => count($x['carreras']), 'carreras' => array_values($x['carreras'])];
        }
        unset($x);
        $res = array_values($res);
        // Primero el país donde corrió más recientemente.
        usort($res, fn ($a, $b) => $b['carreras'][0]['fecha'] <=> $a['carreras'][0]['fecha']);
        return $res;
    }

    /** Lo que se muestra de cada carrera, igual en todos los países (cada base nombra distinto algunas columnas). */
    private function carreras(string $pais, PDO $db, int $caballo, string $fecha): array
    {
        $div = in_array($pais, ['uy', 'ar'], true) ? 'a.sport' : 'a.cuota';
        $cpos = $pais === 'jp' ? 'a.dist_anterior' : 'a.dist_acumulada';
        $kg = in_array($pais, ['hk', 'jp'], true) ? 'a.peso_asignado' : 'COALESCE(a.peso_real, a.peso_asignado)';
        $cat = match ($pais) {
            'ar' => 'COALESCE(c.grupo, c.categoria)',
            'br' => 'c.tipo_prova',
            'hk' => 'c.clase',
            'jp' => 'COALESCE(c.grado, c.clase)',
            default => 'NULL',
        };
        $sigla = $pais === 'hk' ? 'h.codigo' : 'NULL';
        $st = $db->prepare(
            "SELECT a.carrera_id, r.fecha, r.hipodromo_id, h.nombre AS hipodromo, $sigla AS sigla, c.numero, c.premio, $cat AS categoria,
                    c.distancia, c.superficie, c.pista, c.tiempo_ganador, a.puesto, a.puesto_texto, $cpos AS cuerpos, $kg AS kilos,
                    $div AS dividendo, a.gatera, a.tiempo, a.tiempo_seg, a.peso_caballo, j.nombre AS jockey,
                    (SELECT COUNT(*) FROM actuacion x WHERE x.carrera_id = a.carrera_id) AS corredores
             FROM actuacion a
             JOIN carrera c ON c.id = a.carrera_id
             JOIN reunion r ON r.id = c.reunion_id
             JOIN hipodromo h ON h.id = r.hipodromo_id
             LEFT JOIN persona j ON j.id = a.jockey_id
             WHERE a.caballo_id = ? AND r.fecha < ?
             ORDER BY r.fecha DESC, c.numero DESC"
        );
        $st->execute([$caballo, $fecha]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) {
            return [];
        }
        // El ganador y el segundo de cada carrera, con los kilos que cargaron.
        $podio = [];
        $ids = array_column($filas, 'carrera_id');
        $st = $db->prepare(sprintf("SELECT a.carrera_id, a.puesto, ca.nombre, $kg AS peso FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id
            WHERE a.carrera_id IN (%s) AND a.puesto IN (1, 2) ORDER BY ca.nombre", implode(',', array_fill(0, count($ids), '?'))));
        $st->execute($ids);
        foreach ($st as $p) {
            $podio[(int)$p['carrera_id']][(int)$p['puesto']] ??= ['nombre' => $p['nombre'], 'peso' => $p['peso']];
        }
        $res = [];
        foreach ($filas as $f) {
            $f['superficie'] = self::superficie($f['superficie']);
            $f['sigla'] = $f['sigla'] ?? self::sigla($pais, (int)$f['hipodromo_id'], $f['hipodromo']);
            $f['primero'] = $podio[(int)$f['carrera_id']][1]['nombre'] ?? null;
            $f['primero_peso'] = $podio[(int)$f['carrera_id']][1]['peso'] ?? null;
            $f['segundo'] = $podio[(int)$f['carrera_id']][2]['nombre'] ?? null;
            $f['segundo_peso'] = $podio[(int)$f['carrera_id']][2]['peso'] ?? null;
            $f['velocidad'] = $f['tiempo_seg'] !== null && $f['distancia'] ? Velocidades::kmh((int)$f['distancia'], (float)$f['tiempo_seg']) : null;
            // La categoría, si el nombre del premio no la trae ya ("Gran Premio Jockey Club (G1)").
            $cat = $f['categoria'] && mb_stripos((string)$f['premio'], $f['categoria']) === false ? $f['categoria'] . ' · ' : '';
            $f['carrera'] = trim($cat . (string)$f['premio'], ' ·');
            $res[] = $f;
        }
        return $res;
    }

    /** La sigla del hipódromo como la usa el programa de su país (SIS, GAV, TOK...); si no tiene, el nombre. */
    private static function sigla(string $pais, int $id, string $nombre): string
    {
        $s = match ($pais) {
            'uy' => ProgramaNoOficial::siglaHipodromo($id),
            'ar' => AR\ProgramaNoOficial::SIGLAS[$id] ?? null,
            'br' => BR\ProgramaNoOficial::SIGLAS[$id] ?? null,
            'jp' => JP\ProgramaNoOficial::SIGLAS[$id] ?? null,
            default => null,
        };
        return $s ?? $nombre;
    }

    private static function superficie(?string $s): ?string
    {
        return match (mb_strtolower((string)$s)) {
            'turf', 'césped', 'cesped', 'grama' => 'césped',
            'dirt', 'arena', 'areia' => 'arena',
            'sintética', 'sintetica', 'awt' => 'sintética',
            'jump' => 'obstáculos',
            '' => null,
            default => $s,
        };
    }

    /** "Argentina (12)": dónde más corrió, para la nota del programa. */
    public static function resumen(array $exterior): string
    {
        return implode(', ', array_map(fn ($x) => $x['nombre_pais'] . ' (' . $x['total'] . ')', $exterior));
    }

    /**
     * Las últimas $n carreras del caballo, las del país y las de afuera entrelazadas por fecha, para la tabla de
     * antecedentes del programa. Las de afuera llevan 'exterior' (el país) y se dibujan con fila().
     */
    public static function mezclar(array $antecedentes, array $exterior, int $n): array
    {
        $todas = $antecedentes;
        foreach ($exterior as $x) {
            foreach (array_slice($x['carreras'], 0, $n) as $c) {
                $todas[] = $c + ['exterior' => $x['pais'], 'nombre_pais' => $x['nombre_pais']];
            }
        }
        if (count($todas) > count($antecedentes)) {
            usort($todas, fn ($a, $b) => $b['fecha'] <=> $a['fecha']);
        }
        return array_slice($todas, 0, $n);
    }

    /**
     * Una carrera de afuera en la tabla de antecedentes del programa de otro país, con las mismas columnas ($columnas:
     * los encabezados de esa tabla). Sin ajuste ni índices: cada país tiene su escala de velocidades.
     */
    public static function fila(array $c, array $columnas, \Closure $e): string
    {
        $num = fn ($v, int $dec) => $v !== null && (float)$v > 0 ? number_format((float)$v, $dec, ',', '.') : '';
        $o = '<tr class="exterior" title="' . $e('Corrió en ' . $c['nombre_pais'] . ' (' . $c['hipodromo'] . '). Sin índice: cada país tiene su escala de velocidades.') . '">';
        foreach ($columnas as $col) {
            $o .= '<td' . (in_array($col, ['Categoría', 'Nivel', 'Clase'], true) ? ' class="cat"' : '') . '>' . match ($col) {
                'Fecha' => date('d/m/y', strtotime($c['fecha'])),
                'Hip.' => $e($c['sigla']) . ' <small>' . self::CODIGOS[$c['exterior']] . '</small>',
                'P.' => $e($c['puesto'] ?? $c['puesto_texto']) . '/' . $c['corredores'],
                'Ganador' => $e($c['primero']) . ProgramaNoOficial::kilosPodio($c['primero_peso']),
                'Segundo' => $e($c['segundo']) . ProgramaNoOficial::kilosPodio($c['segundo_peso']),
                'Cpos.' => $e($c['cuerpos']),
                'Dist.' => $e($c['distancia']),
                'Sup.' => $e(mb_substr((string)$c['superficie'], 0, 1)),
                'Pista', 'Going' => $e($c['pista']),
                'T. ganador' => $e($c['tiempo_ganador']),
                'T. final' => $e($c['tiempo']),
                'Vel.' => $num($c['velocidad'], 2),
                'Categoría', 'Nivel', 'Clase' => $e($c['carrera']),
                'Jockey' => $e($c['jockey']),
                'Kg' => $num($c['kilos'], 1),
                'Gat.', 'Nº' => $e($c['gatera']),
                'Div.', 'Sport', 'Cuota' => $num($c['dividendo'], 2),
                'Peso cab.' => $e($c['peso_caballo'] ?: ''),
                default => '',
            } . '</td>';
        }
        return $o . '</tr>';
    }

    /**
     * La campaña en cada uno de los otros países, para la ficha del caballo: el resumen y todas las carreras, con el
     * enlace a su ficha en ese país.
     */
    public static function htmlFicha(array $exterior, \Closure $e): string
    {
        $o = '';
        foreach ($exterior as $x) {
            $n = $x['total'];
            $g = count(array_filter($x['carreras'], fn ($c) => (int)$c['puesto'] === 1));
            $pl = count(array_filter($x['carreras'], fn ($c) => $c['puesto'] !== null && (int)$c['puesto'] >= 1 && (int)$c['puesto'] <= 3));
            $pct = fn (int $k) => number_format(100 * $k / $n, 0) . ' %';
            $o .= '<h2 id="campania-' . $x['pais'] . '">Campaña en ' . $e($x['nombre_pais']) . '</h2>'
                . '<p class="resumen"><b>' . $n . '</b> carreras · <b>' . $g . '</b> ganadas (' . $pct($g) . ') · <b>' . $pl . '</b> en el marcador (' . $pct($pl) . ')'
                . ' · Desde ' . date('d/m/Y', strtotime(end($x['carreras'])['fecha'])) . ' · Ficha en ' . $e($x['nombre_pais']) . ': '
                . Fichas::enlazar('caballo', $x['caballo_id'], $x['nombre'], $x['pais'] === 'uy' ? null : $x['pais']) . '</p>';
            $o .= '<div class="ancha"><table class="corridas"><thead><tr><th>Fecha</th><th>Hip.</th><th>Carrera</th><th>Dist.</th><th>Pista</th><th>Puesto</th>'
                . '<th>Ganador</th><th>Jockey</th><th>Kg</th><th>Div.</th></tr></thead><tbody>';
            foreach ($x['carreras'] as $c) {
                $p = $c['puesto'] !== null ? (int)$c['puesto'] : null;
                $o .= '<tr class="' . ($p !== null && $p >= 1 && $p <= 3 ? 'p' . $p : '') . '"><td>' . date('d/m/y', strtotime($c['fecha'])) . '</td><td>' . $e($c['hipodromo'])
                    . '</td><td class="nom">' . $c['numero'] . 'ª ' . $e($c['carrera']) . '</td><td>' . $e($c['distancia']) . ($c['superficie'] === 'césped' ? ' c' : '')
                    . '</td><td>' . $e($c['pista']) . '</td><td><b>' . ($p ? $p . 'º' : $e($c['puesto_texto'])) . '</b><small>/' . (int)$c['corredores'] . '</small></td><td>'
                    . $e($c['primero']) . '</td><td>' . $e($c['jockey']) . '</td><td>' . ($c['kilos'] !== null ? number_format((float)$c['kilos'], 1, ',', '.') : '')
                    . '</td><td>' . ($c['dividendo'] > 0 ? number_format((float)$c['dividendo'], 2, ',', '.') : '') . '</td></tr>';
            }
            $o .= '</tbody></table></div>';
        }
        return $o;
    }
}
