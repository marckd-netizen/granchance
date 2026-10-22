<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Rankings de la web (/ranking/caballos, /ranking/jockeys, ...): los mejores según premios, ganadas, % de ganadas,
 * % entre los 3 primeros o velocidad promedio, con filtros por año y mes, distancias, hipódromos, superficie y estado
 * de la pista (y sexo y pelaje para caballos y potrillos). Se arman al pedirlos, leyendo la base. Con ?pais=hong-kong leen
 * la base de Hong Kong (config 'hk'), que no tiene studs ni criadores ni año de nacimiento;
 * con ?pais=brasil, la de Brasil (config 'br'), que tiene todo menos los premios (el JCB no publica la bolsa);
 * con ?pais=argentina, la de Argentina (config 'ar'); con ?pais=japon, la de Japón (config 'jp'), sin pelajes (la JRA no
 * los publica en inglés). Enlazan a las fichas de cada país (/ar/caballo/...).
 */
final class Rankings
{
    /** de => [título, expresión que agrupa, ficha a la que enlaza] */
    public const TIPOS = [
        'caballos' => ['Caballos', 'a.caballo_id', 'caballo'],
        'potrillos' => ['Potrillos', 'a.caballo_id', 'caballo'],
        'jockeys' => ['Jockeys', 'a.jockey_id', 'jockey'],
        'cuidadores' => ['Cuidadores', 'a.cuidador_id', 'cuidador'],
        'studs' => ['Studs', 'a.stud_id', 'stud'],
        'criadores' => ['Criadores', 'ca.criador_id', 'criador'],
        'padrillos' => ['Padrillos', 'ca.padre', 'padrillo'],
        'madres' => ['Madres', 'ca.madre', 'madre'],
        'abuelos' => ['Abuelos maternos', 'ca.abuelo_materno', 'abuelo'],
    ];
    private const COLUMNAS = ['nombre' => 'Nombre', 'caballos' => 'Caballos', 'corridas' => 'Carreras', 'ganadas' => '1º', 'pct_gan' => '% gan.',
        'top3' => '1º a 3º', 'pct_top3' => '% 1º a 3º', 'vel' => 'Vel. prom.', 'premios' => 'Premios', 'ultima' => 'Última'];
    private const PELAJES = ['z' => 'Zaino', 'a' => 'Alazán', 't' => 'Tordillo', 'b' => 'Bayo', 'ro' => 'Rosillo'];
    private const ESTADOS = ['seca', 'húmeda', 'pesada', 'fangosa'];
    private const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    private const CANTIDAD = 100;
    /** Rankings que no existen en Hong Kong: el HKJC no publica studs, criadores ni el año de nacimiento. */
    private const SIN_HK = ['potrillos', 'studs', 'criadores'];
    /** Reparto habitual de la bolsa del HKJC del 1º al 5º. */
    private const REPARTO_HK = [1 => .57, .22, .115, .06, .035];
    /** Pelajes de Brasil: el JCB los escribe en masculino o femenino según el sexo (Castanho, Castanha). */
    private const PELAJES_BR = ['Castanh' => 'Castaño', 'Alaz' => 'Alazán', 'Tordilh' => 'Tordillo', 'Pret' => 'Negro'];
    private const ESTADOS_BR = ['leve', 'macia', 'pesada', 'encharcada', 'úmida', 'seca'];
    private const SEXOS_BR = ['m' => 'Machos', 'h' => 'Hembras', 'c' => 'Castrados'];
    /** Pelajes de Argentina: el Stud Book los abrevia (Z, A, T, ZC...). */
    private const PELAJES_AR = ['z' => 'Zaino', 'a' => 'Alazán', 't' => 'Tordillo', 'zc' => 'Zaino colorado', 'zd' => 'Zaino doradillo',
        'at' => 'Alazán tostado', 'zn' => 'Zaino negro'];
    private const ESTADOS_AR = ['normal', 'húmeda', 'pesada', 'fangosa'];
    /** Japón: la JRA usa 4 estados, los mismos en césped y arena (Firm/Standard, Good, Yielding/Muddy, Soft/Sloppy). */
    private const ESTADOS_JP = ['normal' => ['Firm', 'Standard'], 'algo pesada' => ['Good'], 'pesada' => ['Yielding', 'Muddy'], 'fangosa' => ['Soft', 'Sloppy']];
    private const SEXOS_JP = ['C' => 'Potros', 'F' => 'Potrancas', 'H' => 'Enteros', 'M' => 'Yeguas', 'G' => 'Castrados'];
    private const PAISES = ['hong-kong' => 'Hong Kong', 'brasil' => 'Brasil', 'argentina' => 'Argentina', 'japon' => 'Japón'];
    /** ?pais= => país de las fichas (Fichas::PAISES). */
    private const FICHAS = ['hong-kong' => 'hk', 'brasil' => 'br', 'argentina' => 'ar', 'japon' => 'jp'];

    /** true mientras se arma un ranking de Hong Kong ($this->db es entonces la base de Hong Kong). */
    private bool $hk = false;
    /** true mientras se arma un ranking de Brasil ($this->db es entonces la base de Brasil). */
    private bool $br = false;
    /** true mientras se arma un ranking de Argentina ($this->db es entonces la base de Argentina). */
    private bool $ar = false;
    /** true mientras se arma un ranking de Japón ($this->db es entonces la base de Japón). */
    private bool $jp = false;
    /** @var array<string, PDO> ?pais= => base de ese país */
    private array $otras;

    public function __construct(private PDO $db, private string $carpeta, ?PDO $dbHk = null, ?PDO $dbBr = null, ?PDO $dbAr = null, ?PDO $dbJp = null)
    {
        $this->otras = array_filter(['hong-kong' => $dbHk, 'brasil' => $dbBr, 'argentina' => $dbAr, 'japon' => $dbJp]);
        foreach ([$this->db, ...array_values($this->otras)] as $base) {
            $base->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    /** El ?pais= del ranking que se está armando (null en Uruguay). */
    private function pais(): ?string
    {
        return $this->hk ? 'hong-kong' : ($this->br ? 'brasil' : ($this->ar ? 'argentina' : ($this->jp ? 'japon' : null)));
    }

    private function qPais(string $sep = '?'): string
    {
        return $this->pais() ? $sep . 'pais=' . $this->pais() : '';
    }

    /** Índice de rankings (/ranking). */
    public function indice(): string
    {
        $o = '<h1>Rankings</h1>' . ($this->otras ? '<h2>Uruguay</h2>' : '') . '<ul class="resultados">';
        foreach (self::TIPOS as $de => [$titulo]) {
            $o .= '<li><a href="/ranking/' . $de . '">' . $titulo . '</a></li>';
        }
        $o .= '</ul>';
        foreach (array_keys($this->otras) as $pais) {
            $o .= '<h2>' . self::PAISES[$pais] . '</h2><ul class="resultados">';
            foreach (self::TIPOS as $de => [$titulo]) {
                if ($pais !== 'hong-kong' || !in_array($de, self::SIN_HK, true)) {
                    $o .= '<li><a href="/ranking/' . $de . '?pais=' . $pais . '">' . $titulo . '</a></li>';
                }
            }
            $o .= '</ul>';
        }
        return $this->documento('Rankings', $o);
    }

    /** @param array $q parámetros GET */
    public function pagina(string $de, array $q): ?string
    {
        $pais = (string)($q['pais'] ?? '');
        if (isset(self::PAISES[$pais])) {
            if (!isset($this->otras[$pais]) || ($pais === 'hong-kong' && in_array($de, self::SIN_HK, true))) {
                return null;
            }
            [$uy, $this->db, $this->hk, $this->br, $this->ar, $this->jp] = [$this->db, $this->otras[$pais], $pais === 'hong-kong', $pais === 'brasil', $pais === 'argentina', $pais === 'japon'];
            try {
                return $this->armar($de, $q);
            } finally {
                [$this->db, $this->hk, $this->br, $this->ar, $this->jp] = [$uy, false, false, false, false];
            }
        }
        return $this->armar($de, $q);
    }

    private function armar(string $de, array $q): ?string
    {
        if (!isset(self::TIPOS[$de])) {
            return null;
        }
        [$titulo, $grupo, $ficha] = self::TIPOS[$de];
        $f = $this->filtros($de, $q);
        [$where, $args] = $this->where($de, $f);

        $nombre = match ($de) {
            'caballos', 'potrillos' => 'ca.nombre',
            'padrillos', 'madres', 'abuelos' => $grupo,
            default => 'p.nombre',
        };
        $joinPersona = in_array($de, ['jockeys', 'cuidadores', 'studs', 'criadores'], true) ? "JOIN persona p ON p.id = $grupo" : '';
        $extra = in_array($de, ['caballos', 'potrillos'], true) ? ', MAX(ca.sexo) AS sexo, MAX(ca.pelaje) AS pelaje, MAX(ca.anio_nac) AS anio_nac, MAX(ca.pais) AS pais' : '';
        $st = $this->db->prepare("SELECT $grupo AS clave, MAX($nombre) AS nombre, COUNT(*) AS corridas, SUM(a.puesto = 1) AS ganadas,
                SUM(a.puesto BETWEEN 1 AND 3) AS top3, COUNT(DISTINCT a.caballo_id) AS caballos, MAX(r.fecha) AS ultima,
                AVG(CASE WHEN a.tiempo_seg > 0 AND c.distancia / a.tiempo_seg * 3.6 BETWEEN 20 AND 80 THEN c.distancia / a.tiempo_seg * 3.6 END) AS vel $extra
            FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id JOIN caballo ca ON ca.id = a.caballo_id $joinPersona
            WHERE $grupo IS NOT NULL AND $grupo <> '' AND $where
            GROUP BY $grupo HAVING COUNT(*) >= ?");
        $st->execute([...$args, $f['min']]);
        $filas = [];
        foreach ($st as $r) {
            $r['pct_gan'] = $r['ganadas'] / $r['corridas'];
            $r['pct_top3'] = $r['top3'] / $r['corridas'];
            $r['premios'] = 0;
            $r['premios_usd'] = 0;
            $filas[(string)$r['clave']] = $r;
        }
        // Premios: lo que cobró cada caballo por su puesto (ver Fichas::premio), sumado por grupo. En pesos; los
        // de las carreras en dólares van aparte.
        $st = $this->db->prepare("SELECT $grupo AS clave, c.bolsa, c.bolsa_detalle, " . ($this->ar ? 'c.bolsa_dolares, ' : '') . "a.puesto
            FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id JOIN caballo ca ON ca.id = a.caballo_id
            WHERE a.puesto BETWEEN 1 AND 5 AND $grupo IS NOT NULL AND $where");
        $st->execute($args);
        foreach ($st as $r) {
            if ($this->ar) {
                // "1º $ 1,987,443 | 2º $ 473,214 | ...": en pesos, y además en dólares con el cambio que usa el Stud Book para la bolsa.
                if (isset($filas[(string)$r['clave']]) && preg_match('/(?:^|\|)\s*' . (int)$r['puesto'] . '\D{1,3}\$\s*([\d.,]+)/u', (string)$r['bolsa_detalle'], $m)) {
                    $pesos = (int)preg_replace('/\D/', '', $m[1]);
                    $filas[(string)$r['clave']]['premios'] += $pesos;
                    $filas[(string)$r['clave']]['premios_usd'] += (float)$r['bolsa'] > 0 ? $pesos * (float)$r['bolsa_dolares'] / (float)$r['bolsa'] : 0;
                }
                continue;
            }
            $p = match (true) {
                $this->hk => (float)$r['bolsa'] > 0 ? ['HK$', (int)round((float)$r['bolsa'] * self::REPARTO_HK[(int)$r['puesto']])] : null,
                // La JRA publica lo que paga a cada uno de los 5 primeros, en yenes.
                $this->jp => ($y = (int)(explode(' ', (string)$r['bolsa_detalle'])[(int)$r['puesto'] - 1] ?? 0)) > 0 ? ['¥', $y] : null,
                default => Fichas::premio($r['bolsa'], $r['bolsa_detalle'], (int)$r['puesto']),
            };
            if (isset($filas[(string)$r['clave']]) && $p) {
                $filas[(string)$r['clave']][$p[0] === 'U$S' ? 'premios_usd' : 'premios'] += $p[1];
            }
        }
        $filas = array_values($filas);
        $col = $f['orden'];
        usort($filas, fn ($a, $b) => ($col === 'nombre' ? strcoll((string)$a['nombre'], (string)$b['nombre']) : ($a[$col] <=> $b[$col]))
            * ($f['dir'] === 'asc' ? 1 : -1) ?: $b['ganadas'] <=> $a['ganadas']);
        $total = count($filas);
        if (!$f['todos']) {
            $filas = array_slice($filas, 0, self::CANTIDAD);
        }

        $lugar = $this->pais() ? ' de ' . self::PAISES[$this->pais()] : ($this->otras ? ' de Uruguay' : '');
        $o = '<h1>Ranking de ' . mb_strtolower($titulo) . $lugar . '</h1>' . $this->menu($de) . $this->formulario($de, $f);
        if ($de === 'potrillos') {
            $o .= '<p class="nota">Potrillos: la generación que en ' . $f['generacion_anio'] . ' tiene 3 años (nacidos del 1/7/' . $f['generacion']
                . ' al 30/6/' . ($f['generacion'] + 1) . ', que la base registra como nacidos en ' . $f['generacion'] . ').</p>';
        }
        $o .= '<p class="nota">' . $total . ' en total' . ($total > count($filas) ? ', se muestran los primeros ' . count($filas)
            . '. <a href="' . $this->url($de, $f, ['todos' => 1]) . '">Ver todos</a>' : '') . '. Tocá un título de columna para ordenar.</p>';
        $o .= $this->tabla($de, $ficha, $filas, $f);
        $o .= '<p class="nota">Vel. prom.: velocidad promedio en km/h de cada caballo en sus carreras (distancia sobre su tiempo'
            . ($this->br || $this->ar ? '; ' . ($this->br ? 'el JCB' : 'el Stud Book') . ' publica solo el del ganador, el de los demás se estima con los cuerpos que le sacó' : '') . ').'
            . ($this->br ? ' El JCB no publica los premios.' : ' Premios: lo que pagó la carrera al puesto de cada caballo, del 1º al 5º, '
                . match (true) {
                    $this->hk => 'en dólares de Hong Kong, estimado con el reparto habitual del HKJC (57 %, 22 %, 11,5 %, 6 % y 3,5 % de la bolsa).',
                    $this->ar => 'en pesos según el Stud Book, y debajo en dólares con el cambio que usa el Stud Book para cada carrera.',
                    $this->jp => 'en yenes, según la JRA.',
                    default => 'según la condición (estimado con el reparto habitual si la condición no trae el detalle).',
                }) . '</p>';
        return $this->documento('Ranking de ' . mb_strtolower($titulo) . $lugar, $o);
    }

    // ---------------------------------------------------------------- filtros

    private function filtros(string $de, array $q): array
    {
        $lista = fn ($v) => array_values(array_filter(array_map('strval', is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v])), fn ($x) => $x !== ''));
        $anioActual = (int)date('Y');
        $f = [
            'anio' => isset($q['anio']) ? ($q['anio'] === 'todos' ? null : (int)$q['anio']) : $anioActual,
            'mes' => isset($q['mes']) && (int)$q['mes'] >= 1 && (int)$q['mes'] <= 12 ? (int)$q['mes'] : null,
            'hip' => array_map('intval', $lista($q['hip'] ?? null)),
            'dist' => array_map('intval', $lista($q['dist'] ?? null)),
            'sup' => in_array($q['sup'] ?? '', ['arena', 'cesped'], true) ? $q['sup'] : null,
            'estado' => in_array($q['estado'] ?? '', $this->estados(), true) ? $q['estado'] : null,
            'sexo' => isset($this->sexos()[$q['sexo'] ?? '']) ? $q['sexo'] : null,
            'pelaje' => isset($this->pelajes()[$q['pelaje'] ?? '']) ? $q['pelaje'] : null,
            'min' => max(1, min(1000, (int)($q['min'] ?? 1))),
            'orden' => isset($this->columnas()[$q['orden'] ?? '']) ? $q['orden'] : ($this->br ? 'ganadas' : 'premios'),
            'dir' => ($q['dir'] ?? '') === 'asc' ? 'asc' : 'desc',
            'todos' => !empty($q['todos']),
        ];
        $f['generacion_anio'] = $f['anio'] ?? $anioActual;
        $f['generacion'] = $f['generacion_anio'] - 3;
        return $f;
    }

    private function where(string $de, array $f): array
    {
        $w = ['1 = 1'];
        $args = [];
        if ($f['anio']) {
            $w[] = 'r.fecha BETWEEN ? AND ?';
            array_push($args, sprintf('%d-%02d-01', $f['anio'], $f['mes'] ?? 1),
                $f['mes'] ? date('Y-m-t', strtotime(sprintf('%d-%02d-01', $f['anio'], $f['mes']))) : "{$f['anio']}-12-31");
        } elseif ($f['mes']) {
            $w[] = 'MONTH(r.fecha) = ?';
            $args[] = $f['mes'];
        }
        foreach (['hip' => 'r.hipodromo_id', 'dist' => 'c.distancia'] as $k => $columna) {
            if ($f[$k]) {
                $w[] = "$columna IN (" . implode(',', array_fill(0, count($f[$k]), '?')) . ')';
                array_push($args, ...$f[$k]);
            }
        }
        if ($this->jp && $f['sup']) {
            // La JRA: Turf, Dirt y Jump (obstáculos, que van con el césped).
            $w[] = $f['sup'] === 'cesped' ? "c.superficie IN ('Turf', 'Jump')" : "c.superficie = 'Dirt'";
        } elseif ($f['sup'] === 'cesped') {
            $w[] = "c.superficie LIKE 'c%'";
        } elseif ($f['sup'] === 'arena') {
            $w[] = "(c.superficie IS NULL OR c.superficie NOT LIKE 'c%')";
        }
        if ($f['estado'] && $this->jp) {
            $valores = self::ESTADOS_JP[$f['estado']] ?? ['-'];
            $w[] = 'c.pista IN (' . implode(',', array_fill(0, count($valores), '?')) . ')';
            array_push($args, ...$valores);
        } elseif ($f['estado']) {
            // En Uruguay la pista puede traer más texto ("seca y rápida"), en Brasil el "variante"; en Hong Kong el going es uno de una lista.
            $w[] = $this->hk ? 'c.pista = ?' : 'c.pista LIKE ?';
            $args[] = $this->hk ? $f['estado'] : '%' . $f['estado'] . '%';
        }
        if (in_array($de, ['caballos', 'potrillos'], true)) {
            if ($f['sexo']) {
                $w[] = 'ca.sexo = ?';
                $args[] = $f['sexo'];
            }
            if ($f['pelaje']) {
                $w[] = $this->br ? 'ca.pelaje LIKE ?' : 'ca.pelaje = ?';
                $args[] = $f['pelaje'] . ($this->br ? '%' : '');
            }
        }
        if ($de === 'potrillos') {
            $w[] = 'ca.anio_nac = ?';
            $args[] = $f['generacion'];
        }
        return [implode(' AND ', $w), $args];
    }

    private function formulario(string $de, array $f): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $sel = fn (bool $c) => $c ? ' selected' : '';
        $anios = $this->db->query('SELECT DISTINCT YEAR(fecha) FROM reunion ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN);
        $o = '<form class="filtros" method="get"><input type="hidden" name="orden" value="' . $f['orden'] . '"><input type="hidden" name="dir" value="' . $f['dir'] . '">'
            . ($this->pais() ? '<input type="hidden" name="pais" value="' . $this->pais() . '">' : '');
        $o .= '<label>Año <select name="anio"><option value="todos"' . $sel($f['anio'] === null) . '>Todos</option>';
        foreach ($anios as $a) {
            $o .= '<option' . $sel($f['anio'] === (int)$a) . '>' . (int)$a . '</option>';
        }
        $o .= '</select></label><label>Mes <select name="mes"><option value="">Todos</option>';
        foreach (self::MESES as $n => $m) {
            $o .= '<option value="' . $n . '"' . $sel($f['mes'] === $n) . '>' . $m . '</option>';
        }
        $o .= '</select></label><label>Superficie <select name="sup"><option value="">Todas</option><option value="arena"' . $sel($f['sup'] === 'arena') . '>' . ($this->hk ? 'Sintética' : 'Arena') . '</option>'
            . '<option value="cesped"' . $sel($f['sup'] === 'cesped') . '>Césped</option></select></label>';
        $o .= '<label>Pista <select name="estado"><option value="">Todas</option>';
        foreach ($this->estados() as $s) {
            // En Brasil el valor es el del JCB (leve, macia...) y se muestra en español.
            $o .= '<option value="' . $e($s) . '"' . $sel($f['estado'] === $s) . '>' . $e($this->br ? BR\Traduccion::pista($s) : $s) . '</option>';
        }
        $o .= '</select></label>';
        if (in_array($de, ['caballos', 'potrillos'], true)) {
            $o .= '<label>Sexo <select name="sexo"><option value="">Todos</option>';
            foreach ($this->sexos() as $k => $s) {
                $o .= '<option value="' . $e($k) . '"' . $sel($f['sexo'] === (string)$k) . '>' . $e($s) . '</option>';
            }
            $o .= '</select></label>';
            if ($this->pelajes()) {
                $o .= '<label>Pelaje <select name="pelaje"><option value="">Todos</option>';
                foreach ($this->pelajes() as $k => $p) {
                    $o .= '<option value="' . $e($k) . '"' . $sel($f['pelaje'] === (string)$k) . '>' . $e($p) . '</option>';
                }
                $o .= '</select></label>';
            }
        }
        $o .= '<label>Mínimo de carreras <input type="number" name="min" min="1" max="1000" value="' . $f['min'] . '"></label>';
        $o .= '<fieldset><legend>Hipódromos</legend>';
        foreach ($this->db->query('SELECT DISTINCT hipodromo_id FROM reunion ORDER BY hipodromo_id')->fetchAll(PDO::FETCH_COLUMN) as $h) {
            $o .= '<label class="chip"><input type="checkbox" name="hip[]" value="' . (int)$h . '"' . (in_array((int)$h, $f['hip'], true) ? ' checked' : '') . '>'
                . $e($this->nombreHipodromo((int)$h)) . '</label>';
        }
        $o .= '</fieldset><fieldset><legend>Distancias</legend>';
        foreach ($this->db->query('SELECT distancia FROM carrera WHERE distancia > 0 GROUP BY distancia HAVING COUNT(*) >= 20 ORDER BY distancia')->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $o .= '<label class="chip"><input type="checkbox" name="dist[]" value="' . (int)$d . '"' . (in_array((int)$d, $f['dist'], true) ? ' checked' : '') . '>' . (int)$d . '</label>';
        }
        $o .= '</fieldset><button>Aplicar</button> <a href="/ranking/' . $de . $this->qPais() . '">Limpiar filtros</a></form>';
        // Los menús aplican solos al cambiar; las casillas, con el botón (para poder marcar varias).
        return $o . '<script>document.querySelectorAll(".filtros select").forEach(s => s.addEventListener("change", () => s.form.submit()));</script>';
    }

    private function tabla(string $de, ?string $ficha, array $filas, array $f): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $cols = $this->columnas();
        if (in_array($de, ['caballos', 'potrillos'], true)) {
            unset($cols['caballos']);
        }
        $o = '<div class="ancha"><table class="ranking"><thead><tr><th>#</th>';
        foreach ($cols as $k => $t) {
            $dir = $f['orden'] === $k && $f['dir'] === 'desc' ? 'asc' : ($k === 'nombre' && $f['orden'] !== $k ? 'asc' : 'desc');
            $marca = $f['orden'] === $k ? ($f['dir'] === 'desc' ? ' ▼' : ' ▲') : '';
            $o .= '<th class="' . ($k === 'nombre' ? '' : 'num') . ($f['orden'] === $k ? ' activa' : '') . '"><a href="' . $this->url($de, $f, ['orden' => $k, 'dir' => $dir, 'todos' => $f['todos'] ?: null]) . '">'
                . $t . $marca . '</a></th>';
            if ($k === 'nombre' && in_array($de, ['caballos', 'potrillos'], true)) {
                $o .= '<th>Sexo</th><th>Pelaje</th><th class="num">Edad</th>';
            }
        }
        $o .= '</tr></thead><tbody>';
        foreach ($filas as $i => $r) {
            $nombre = $e($r['nombre']);
            $nombre = isset(Fichas::LINEAS[$ficha]) ? Fichas::enlazarLinea($ficha, (string)$r['nombre'], self::FICHAS[$this->pais()] ?? null)
                : '<a href="' . $e(Fichas::url($ficha, (int)$r['clave'], (string)$r['nombre'], self::FICHAS[$this->pais()] ?? null)) . '">' . $nombre . '</a>';
            $o .= '<tr><td class="num">' . ($i + 1) . '</td><td>' . $nombre . (!empty($r['pais']) && $r['pais'] !== ($this->ar ? 'ARG' : ($this->jp ? 'JPN' : 'URU')) && !str_ends_with((string)$r['nombre'], ')') ? ' <small>(' . $e($r['pais']) . ')</small>' : '') . '</td>';
            if (in_array($de, ['caballos', 'potrillos'], true)) {
                $o .= '<td>' . $e($this->hk || $this->jp ? $r['sexo'] : (['m' => 'M', 'h' => 'H', 'c' => 'C'][strtolower((string)$r['sexo'])] ?? '')) . '</td><td>' . $e($this->br ? BR\Traduccion::pelaje($r['pelaje']) : (($this->ar ? self::PELAJES_AR : self::PELAJES)[strtolower((string)$r['pelaje'])] ?? $r['pelaje'])) . '</td><td class="num">'
                    . ($r['anio_nac'] ? (int)date('Y') - (int)$r['anio_nac'] : '') . '</td>';
            } else {
                $o .= '<td class="num">' . (int)$r['caballos'] . '</td>';
            }
            $o .= '<td class="num">' . (int)$r['corridas'] . '</td><td class="num">' . (int)$r['ganadas'] . '</td><td class="num">' . self::pct($r['pct_gan']) . '</td>'
                . '<td class="num">' . (int)$r['top3'] . '</td><td class="num">' . self::pct($r['pct_top3']) . '</td>'
                . '<td class="num">' . ($r['vel'] !== null ? number_format((float)$r['vel'], 2, ',', '.') : '') . '</td>'
                . (isset($cols['premios']) ? '<td class="num">' . ($r['premios'] ? ($this->hk ? 'HK$ ' : ($this->jp ? '¥ ' : '$ ')) . number_format($r['premios'], 0, ',', '.') : '') . ($r['premios_usd'] ? '<br><small>U$S ' . number_format($r['premios_usd'], 0, ',', '.') . '</small>' : '') . '</td>' : '')
                . '<td class="num">' . date('d/m/y', strtotime($r['ultima'])) . '</td></tr>';
        }
        return $o . '</tbody></table></div>';
    }

    private function url(string $de, array $f, array $cambios): string
    {
        $q = ['anio' => $f['anio'] ?? 'todos', 'mes' => $f['mes'], 'hip' => $f['hip'] ?: null, 'dist' => $f['dist'] ?: null, 'sup' => $f['sup'],
            'estado' => $f['estado'], 'sexo' => $f['sexo'], 'pelaje' => $f['pelaje'], 'min' => $f['min'] > 1 ? $f['min'] : null,
            'orden' => $f['orden'], 'dir' => $f['dir'], 'todos' => $f['todos'] ? 1 : null, 'pais' => $this->pais()];
        $q = array_filter(array_merge($q, $cambios), fn ($v) => $v !== null && $v !== '');
        return htmlspecialchars('/ranking/' . $de . '?' . http_build_query($q), ENT_QUOTES);
    }

    private function menu(string $actual): string
    {
        $o = '<div class="filtro">';
        foreach (self::TIPOS as $de => [$titulo]) {
            if (!$this->hk || !in_array($de, self::SIN_HK, true)) {
                $o .= '<a href="/ranking/' . $de . $this->qPais() . '"' . ($de === $actual ? ' class="actual"' : '') . '>' . $titulo . '</a>';
            }
        }
        // El mismo ranking en los otros países (o el índice, si allá no existe).
        foreach ([null, ...array_keys($this->otras)] as $pais) {
            if ($this->otras && $pais !== $this->pais()) {
                $url = $pais === 'hong-kong' && in_array($actual, self::SIN_HK, true) ? '/ranking' : '/ranking/' . $actual . ($pais ? '?pais=' . $pais : '');
                $o .= ' · <a href="' . $url . '">Ver ' . ($pais ? self::PAISES[$pais] : 'Uruguay') . '</a>';
            }
        }
        return $o . '</div>';
    }

    /** Estados de la pista para el filtro: en Hong Kong, los going que figuran en la base (GOOD, GOOD TO FIRM...). */
    private function estados(): array
    {
        if ($this->jp) {
            return array_keys(self::ESTADOS_JP);
        }
        return $this->hk ? $this->db->query("SELECT pista FROM carrera WHERE pista <> '' GROUP BY pista ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_COLUMN)
            : ($this->br ? self::ESTADOS_BR : ($this->ar ? self::ESTADOS_AR : self::ESTADOS));
    }

    /** @return array<string,string> valor => texto */
    private function sexos(): array
    {
        if ($this->br) {
            return self::SEXOS_BR;
        }
        if ($this->jp) {
            return self::SEXOS_JP;
        }
        if (!$this->hk) {
            return ['m' => 'Machos', 'h' => 'Hembras'];
        }
        $v = $this->db->query("SELECT DISTINCT sexo FROM caballo WHERE sexo <> '' ORDER BY sexo")->fetchAll(PDO::FETCH_COLUMN);
        return array_combine($v, $v);
    }

    /** @return array<string,string> valor => texto */
    private function pelajes(): array
    {
        if ($this->br) {
            return self::PELAJES_BR;
        }
        if ($this->ar) {
            return self::PELAJES_AR;
        }
        if ($this->jp) {
            return [];      // la JRA no publica el pelaje en inglés
        }
        if (!$this->hk) {
            return self::PELAJES;
        }
        $v = $this->db->query("SELECT DISTINCT pelaje FROM caballo WHERE pelaje <> '' ORDER BY pelaje")->fetchAll(PDO::FETCH_COLUMN);
        return array_combine($v, $v);
    }

    private function nombreHipodromo(int $id): string
    {
        if (!$this->pais()) {
            return ProgramaNoOficial::nombreHipodromo($id);
        }
        $st = $this->db->prepare('SELECT nombre FROM hipodromo WHERE id = ?');
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    }

    /** Columnas de la tabla: Brasil no tiene premios. */
    private function columnas(): array
    {
        return $this->br ? array_diff_key(self::COLUMNAS, ['premios' => 1]) : self::COLUMNAS;
    }

    private static function pct(float $v): string
    {
        return number_format(100 * $v, 1, ',', '.') . ' %';
    }

    private function documento(string $titulo, string $cuerpo): string
    {
        $indice = is_file($this->carpeta . '/indice.json') ? json_decode((string)file_get_contents($this->carpeta . '/indice.json'), true) : [];
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($titulo) . ' · GranChance</title>' . Publicador::analytics((string)($indice['analytics'] ?? '')) . '<style>' . Fichas::CSS . self::CSS . '</style></head><body>'
            . '<nav class="barra"><span><a href="/">GranChance · todos los programas</a> · <a href="/ranking">Rankings</a></span> <form action="/buscar"><input name="q" placeholder="Buscar caballo, jockey, stud…"></form></nav>'
            . $cuerpo . '</body></html>';
    }

    private const CSS = <<<'CSS'
.filtros { display:flex; flex-wrap:wrap; gap:8px 14px; align-items:flex-end; background:var(--cabecera); border-radius:6px; padding:8px 10px; margin:8px 0; }
.filtros label { display:flex; flex-direction:column; font-size:12px; color:var(--suave); gap:2px; }
.filtros select, .filtros input[type=number] { font:inherit; font-size:14px; color:var(--texto); background:var(--fondo); border:1px solid var(--linea); border-radius:6px; padding:3px 6px; min-width:0; }
.filtros input[type=number] { width:80px; }
.filtros input[type=checkbox] { min-width:0; margin:0; }
.filtros fieldset { border:0; padding:0; margin:0; flex-basis:100%; display:flex; flex-wrap:wrap; gap:4px; }
.filtros legend { font-size:12px; color:var(--suave); padding:0; margin-bottom:2px; width:100%; }
.filtros label.chip { flex-direction:row; align-items:center; gap:3px; font-size:13px; color:var(--texto); background:var(--fondo); border:1px solid var(--linea); border-radius:12px; padding:1px 8px; }
.ranking td.num, .ranking th.num { text-align:right; }
.ranking th a { color:inherit; } .ranking th.activa { color:var(--acento); }
CSS;
}
