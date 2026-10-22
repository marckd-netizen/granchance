<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Fichas de la web (public/ficha.php): una página por caballo, jockey y cuidador con todas sus carreras (las corridas y
 * las inscripciones de los programas publicados), los premios cobrados, una tabla de puestos por año y otra por distancia.
 * También el buscador. Se arman al pedirlas, leyendo la base. Con $pais ('hk', 'br', 'ar') leen la base de ese país
 * (/ar/caballo/123-nombre...): sin inscripciones (las próximas carreras salen solo en Uruguay), y en Hong Kong sin studs ni criadores.
 */
final class Fichas
{
    public const TIPOS = ['caballo' => 'Caballo', 'jockey' => 'Jockey', 'cuidador' => 'Cuidador', 'stud' => 'Stud', 'criador' => 'Criador'];
    /** Padrillos, madres y abuelos maternos: tipo => [título, columna de caballo]. Están en la base solo como texto, así que van por el nombre. */
    public const LINEAS = ['padrillo' => ['Padrillo', 'padre'], 'madre' => ['Madre', 'madre'], 'abuelo' => ['Abuelo materno', 'abuelo_materno']];
    /** Hijos o nietos que se listan sin ?todas=1. */
    private const CANTIDAD_HIJOS = 100;
    /** Columnas de la lista de carreras según de quién es la ficha. */
    private const COLUMNAS = ['caballo' => ['jockey', 'cuidador', 'stud'], 'jockey' => ['caballo', 'cuidador'], 'cuidador' => ['caballo', 'jockey'],
        'stud' => ['caballo', 'jockey', 'cuidador'], 'criador' => ['caballo', 'stud', 'cuidador'],
        'padrillo' => ['caballo', 'jockey', 'cuidador'], 'madre' => ['caballo', 'jockey', 'cuidador'], 'abuelo' => ['caballo', 'jockey', 'cuidador']];
    /** Reparto habitual de la bolsa entre los 5 primeros, para las carreras que no traen el detalle. */
    private const REPARTO = [1 => 0.548, 2 => 0.202, 3 => 0.135, 4 => 0.077, 5 => 0.038];
    /** Jockeys y cuidadores: sin ?todas=1, la lista de carreras muestra el último año. */
    private const DIAS_LISTA = 365;
    /** Otros países: código => [nombre, número que se suma al id del hipódromo en la web, país de los caballos nacidos ahí]. */
    public const PAISES = ['ar' => ['Argentina', 200, 'ARG'], 'br' => ['Brasil', 300, null], 'hk' => ['Hong Kong', 100, null]];
    /** Reparto habitual de la bolsa del HKJC del 1º al 5º. */
    private const REPARTO_HK = [1 => .57, .22, .115, .06, .035];

    /**
     * @param string $carpeta carpeta pública (para saber qué programas y resultados están publicados)
     * @param ?string $pais null para Uruguay, o una clave de PAISES si $db es la base de ese país
     */
    public function __construct(private PDO $db, private string $carpeta, private ?string $pais = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($pais !== null && !isset(self::PAISES[$pais])) {
            throw new \InvalidArgumentException("País desconocido: $pais");
        }
    }

    /** Las fichas que hay en este país: Hong Kong no publica studs ni criadores. */
    public function tipos(): array
    {
        return $this->pais === 'hk' ? array_diff_key(self::TIPOS, ['stud' => 1, 'criador' => 1]) : self::TIPOS;
    }

    public static function slug(string $nombre): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', Personas::clave($nombre)), '-');
    }

    public static function url(string $tipo, int $id, string $nombre, ?string $pais = null): string
    {
        return ($pais ? "/$pais" : '') . "/$tipo/$id-" . self::slug($nombre);
    }

    /** El nombre de un padrillo, madre o abuelo en la dirección, con el país si lo trae ("Roy (USA)" → roy-usa). */
    public static function slugLinea(string $nombre): string
    {
        return self::slug(strtr($nombre, '()', '  '));
    }

    public static function urlLinea(string $tipo, string $nombre, ?string $pais = null): string
    {
        return ($pais ? "/$pais" : '') . "/$tipo/" . self::slugLinea($nombre);
    }

    /** El nombre del padrillo, la madre o el abuelo materno con el enlace a su ficha. */
    public static function enlazarLinea(string $tipo, ?string $nombre, ?string $pais = null): string
    {
        $n = htmlspecialchars((string)$nombre, ENT_QUOTES);
        return self::slugLinea((string)$nombre) !== '' ? '<a href="' . htmlspecialchars(self::urlLinea($tipo, (string)$nombre, $pais), ENT_QUOTES) . '">' . $n . '</a>' : $n;
    }

    /**
     * Lo que cobró el caballo en Argentina: el Stud Book detalla el premio de cada puesto ("1º $ 1,987,443 | 2º $ 473,214 | ...").
     * [pesos, dólares] (los dólares con el cambio que usa el Stud Book para la bolsa), o null.
     */
    public static function premioAr(?string $bolsa, ?string $dolares, ?string $detalle, ?int $puesto): ?array
    {
        if (!$puesto || $puesto > 5 || !preg_match('/(?:^|\|)\s*' . $puesto . '\D{1,3}\$\s*([\d.,]+)/u', (string)$detalle, $m)) {
            return null;
        }
        $pesos = (int)preg_replace('/\D/', '', $m[1]);
        return [$pesos, (float)$bolsa > 0 ? $pesos * (float)$dolares / (float)$bolsa : 0.0];
    }

    /** Premio cobrado según el país: [moneda, monto] o null. */
    private function cobrado(array $a): ?array
    {
        return match ($this->pais) {
            'ar' => ($p = self::premioAr($a['bolsa'], $a['bolsa_dolares'], $a['bolsa_detalle'], $a['puesto'])) ? ['$', $p[0]] : null,
            'br' => null,
            'hk' => $a['puesto'] && $a['puesto'] <= 5 && (float)$a['bolsa'] > 0 ? ['HK$', (int)round((float)$a['bolsa'] * self::REPARTO_HK[$a['puesto']])] : null,
            default => self::premio($a['bolsa'], $a['bolsa_detalle'], $a['puesto']),
        };
    }

    /** Nombre del hipódromo (id de la base de este país). */
    private function hipodromo(int $id): string
    {
        if ($this->pais === null) {
            return ProgramaNoOficial::nombreHipodromo($id);
        }
        if (!isset($this->hipodromos)) {
            $this->hipodromos = $this->db->query('SELECT id, nombre FROM hipodromo')->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $web = ProgramaNoOficial::nombreHipodromo($this->web($id));
        return str_starts_with($web, 'Hipódromo ') ? (string)($this->hipodromos[$id] ?? $web) : $web;
    }

    /** @var array<int,string> */
    private array $hipodromos;

    /** El número del hipódromo en la web (programas/FECHA_NNN.html). */
    private function web(int $id): int
    {
        return $id + ($this->pais ? self::PAISES[$this->pais][1] : 0);
    }

    /**
     * Lo que cobró el caballo por su puesto: [moneda, monto], moneda '$' o 'U$S'. La condición trae el detalle
     * ("$ 165.866 al 1º, $ 61.109 al 2º..."); si no, se reparte la bolsa como es habitual (estimado).
     */
    public static function premio(?string $bolsa, ?string $detalle, ?int $puesto): ?array
    {
        if (!$puesto || $puesto > 5) {
            return null;
        }
        if ($detalle && preg_match_all('/(U\$S|\$)\s*([\d.,]+)\s*al\s*(\d)/u', $detalle, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $moneda, $monto, $p]) {
                if ((int)$p === $puesto) {
                    return [$moneda, (int)preg_replace('/[.,]/', '', $monto)];
                }
            }
            return null;
        }
        return (float)$bolsa > 0 ? ['$', (int)round((float)$bolsa * self::REPARTO[$puesto])] : null;
    }

    // ---------------------------------------------------------------- páginas

    /**
     * HTML de la ficha, o null si no existe. Los padrillos, las madres y los abuelos maternos (LINEAS) no tienen id: $id es
     * su nombre como va en la dirección (/padrillo/village-king), y la ficha suma las carreras de sus hijos (o nietos).
     */
    public function pagina(string $tipo, int|string $id, bool $todas = false, ?string $hipodromo = null): ?string
    {
        $linea = isset(self::LINEAS[$tipo]);
        if ($linea) {
            $nombres = $this->nombres($tipo, (string)$id);
            if (!$nombres) {
                return null;
            }
            $ficha = ['nombre' => $nombres[0]];
            $en = 'ca.' . self::LINEAS[$tipo][1] . ' IN (' . implode(',', array_fill(0, count($nombres), '?')) . ')';
            $corridas = $this->corridas($en, $nombres);
            $proximas = $this->pais ? [] : $this->proximas($en, $nombres);
        } else {
            $id = (int)$id;
            if ($tipo === 'caballo') {
                $st = $this->db->prepare('SELECT * FROM caballo WHERE id = ?');
                $st->execute([$id]);
                $ficha = $st->fetch(PDO::FETCH_ASSOC);
                $todas = true;
            } elseif (isset($this->tipos()[$tipo])) {
                $st = $this->db->prepare('SELECT * FROM persona WHERE id = ? AND tipo = ?');
                $st->execute([$id, $tipo]);
                $ficha = $st->fetch(PDO::FETCH_ASSOC);
            } else {
                return null;
            }
            if (!$ficha) {
                return null;
            }
            $col = ['caballo' => 'a.caballo_id', 'jockey' => 'a.jockey_id', 'cuidador' => 'a.cuidador_id', 'stud' => 'a.stud_id', 'criador' => 'ca.criador_id'][$tipo];
            $corridas = $this->corridas("$col = ?", [$id]);
            $proximas = $this->pais ? [] : $this->proximas(str_replace('a.', 'i.', $col) . ' = ?', [$id]);
        }
        $hk = $this->pais === 'hk';
        // Filtro por hipódromo (?h=maronas), como en la portada.
        $hips = [];
        foreach (array_merge(array_column($corridas, 'hipodromo_id'), array_column($proximas, 'hipodromo_id')) as $h) {
            $hips[(int)$h] = $this->hipodromo((int)$h);
        }
        asort($hips);
        $filtro = $hipodromo !== null ? array_search($hipodromo, array_map([self::class, 'slug'], $hips), true) : false;
        if ($filtro !== false) {
            $corridas = array_values(array_filter($corridas, fn ($a) => (int)$a['hipodromo_id'] === $filtro));
            $proximas = array_values(array_filter($proximas, fn ($a) => (int)$a['hipodromo_id'] === $filtro));
        }
        $local = $this->pais ? self::PAISES[$this->pais][2] : 'URU';
        $titulo = $tipo === 'caballo' && $ficha['pais'] && $ficha['pais'] !== $local && !str_ends_with($ficha['nombre'], ')') ? "{$ficha['nombre']} ({$ficha['pais']})" : $ficha['nombre'];

        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $etiqueta = $linea ? self::LINEAS[$tipo][0] : self::TIPOS[$tipo];
        $o = '<h1>' . $e($titulo) . ' <small>' . $etiqueta . ($this->pais ? ' · ' . self::PAISES[$this->pais][0] : '') . '</small></h1>';
        if ($tipo === 'caballo') {
            $sexo = ['h' => 'hembra', 'm' => 'macho', 'c' => 'castrado'][strtolower((string)$ficha['sexo'])] ?? mb_strtolower((string)$ficha['sexo']);
            $o .= '<p>' . $e(trim(self::pelaje($ficha['pelaje']) . ' ' . $sexo)) . ($ficha['anio_nac'] ? ', nacido en ' . (int)$ficha['anio_nac'] : '')
                . ($ficha['fecha_nac'] ?? null ? ' (' . date('d/m/Y', strtotime($ficha['fecha_nac'])) . ')' : '')
                . ($ficha['padre'] ? ' · Por ' . self::enlazarLinea('padrillo', $ficha['padre'], $this->pais) : '') . ($ficha['madre'] ? ' y ' . self::enlazarLinea('madre', $ficha['madre'], $this->pais) : '')
                . ($ficha['abuelo_materno'] ? ' (' . self::enlazarLinea('abuelo', $ficha['abuelo_materno'], $this->pais) . ')' : '');
            if ($corridas) {
                $u = $corridas[0];
                $o .= ' · Último jockey: ' . $this->enlace('jockey', $u['jockey_id'], $u['jockey']) . ' · Cuidador: ' . $this->enlace('cuidador', $u['cuidador_id'], $u['cuidador'])
                    . ($hk ? '' : ' · Stud: ' . $this->chaquetilla($u['stud']) . $this->enlace('stud', $u['stud_id'], $u['stud']));
            }
            if ($ficha['criador_id'] ?? null) {
                $st = $this->db->prepare('SELECT nombre FROM persona WHERE id = ?');
                $st->execute([$ficha['criador_id']]);
                $o .= ' · Criador: ' . $this->enlace('criador', $ficha['criador_id'], (string)$st->fetchColumn());
            }
            $o .= '</p>';
        } elseif ($linea) {
            $o .= $this->parientes($tipo, $nombres);
        } elseif ($tipo === 'stud' && ($ch = $this->chaquetilla($ficha['nombre'], 60))) {
            $o .= '<p>' . $ch . ' <small>' . htmlspecialchars((string)$this->colores($ficha['nombre'])) . '</small></p>';
        }
        if (count($hips) > 1) {
            $o .= '<div class="filtro"><a href="?"' . ($filtro === false ? ' class="actual"' : '') . '>Todos</a>';
            foreach ($hips as $h => $n) {
                $o .= '<a href="?h=' . self::slug($n) . '"' . ($filtro === $h ? ' class="actual"' : '') . '>' . $e($n) . '</a>';
            }
            $o .= '</div>';
        }
        $premios = $this->pais !== 'br';      // el JCB no publica la bolsa
        $o .= self::resumen($corridas, $premios);
        if (!$this->pais) {
            $o .= '<h2>Próximas carreras</h2>' . ($proximas ? $this->tablaProximas($proximas, $tipo)
                : '<p class="nota">' . ($linea ? 'Sus ' . ($tipo === 'abuelo' ? 'nietos' : 'hijos') . ' no figuran' : 'No figura')
                    . ' en los programas publicados (salen hasta 15 días antes de cada reunión).</p>');
        }
        if ($linea) {
            $o .= $this->descendencia($tipo, $en, $nombres, $corridas, $filtro !== false, $todas, $premios);
        }
        if ($corridas) {
            // Lado a lado solo en la ficha del caballo: las de jockeys y cuidadores tienen muchas filas y montos grandes.
            $o .= '<div class="' . ($tipo === 'caballo' ? 'dos' : 'una') . '"><div><h2>Puestos por año</h2>' . self::tablaPuestos($corridas, fn ($a) => substr($a['fecha'], 0, 4), 'Año', false, false, $premios) . '</div>'
                . '<div><h2>Puestos por distancia</h2>' . self::tablaPuestos($corridas, fn ($a) => ($a['distancia'] ? $a['distancia'] . ' m' : 'sin dato') . ($this->cesped($a) ? ' césped' : ''), 'Distancia', true, false, $premios) . '</div></div>';
            // Por hipódromo, con el césped de Maroñas aparte de la arena (en los otros países, en todos los hipódromos).
            $o .= '<h2>Puestos por hipódromo y pista</h2>' . self::tablaPuestos($corridas, fn ($a) => $this->hipodromo((int)$a['hipodromo_id'])
                . ($this->pais ? ' · ' . ($a['superficie'] ?: 'sin dato') : ((int)$a['hipodromo_id'] === 1 ? ($this->cesped($a) ? ' · césped' : ' · arena') : '')), 'Hipódromo', false, true, $premios);
            $lista = $corridas;
            $desde = date('Y-m-d', strtotime('-' . self::DIAS_LISTA . ' day'));
            if (!$todas) {
                $lista = array_values(array_filter($corridas, fn ($a) => $a['fecha'] >= $desde));
            }
            $o .= '<h2>Carreras corridas' . (!$todas ? ' en el último año' : '') . '</h2>';
            if (!$todas && count($lista) < count($corridas)) {
                $o .= '<p class="nota">' . count($lista) . ' de ' . count($corridas) . '. <a href="?todas=1' . ($filtro !== false ? '&amp;h=' . self::slug($hips[$filtro]) : '') . '">Ver todas</a></p>';
            }
            $o .= $this->tablaCorridas($lista, $tipo);
            $o .= '<p class="nota">' . match ($this->pais) {
                    'ar' => 'Premio cobrado: lo que paga la carrera al puesto del caballo (del 1º al 5º), en pesos, según el Stud Book. ',
                    'br' => 'El JCB no publica los premios. ',
                    'hk' => 'Premio cobrado: lo que paga la carrera al puesto del caballo (del 1º al 5º), en dólares de Hong Kong, estimado con el reparto habitual del HKJC. ',
                    default => 'Premio cobrado: lo que paga la carrera al puesto del caballo (del 1º al 5º), según la condición; si la condición no trae el detalle, '
                        . 'se estima con el reparto habitual de la bolsa. ',
                } . ($this->pais !== 'br' && in_array($tipo, ['jockey', 'cuidador'], true) ? 'Son los premios de los caballos, no la parte del ' . $tipo . '.' : '')
                . ($tipo === 'criador' ? 'Son las carreras y los premios de los caballos que crió.' : '')
                . ($linea ? 'Son las carreras y los premios de sus ' . ($tipo === 'abuelo' ? 'nietos por línea materna' : 'hijos')
                    . '. Van por el nombre, como figura en la base: si dos ' . ($tipo === 'madre' ? 'yeguas' : 'padrillos') . ' se llaman igual, sus hijos salen juntos.' : '') . '</p>';
        } else {
            $o .= '<p>No tiene carreras corridas registradas.</p>';
        }
        return $this->documento($titulo . ' · ' . $etiqueta . ($this->pais ? ' · ' . self::PAISES[$this->pais][0] : ''), $o);
    }

    /**
     * Cómo figura en la base el padrillo, la madre o el abuelo materno de la dirección ($slug), el más usado primero
     * (mayúsculas y tildes aparte, que la base no distingue). Vacío si no tiene hijos, o nietos, en la base.
     */
    public function nombres(string $tipo, string $slug): array
    {
        $col = self::LINEAS[$tipo][1];
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            return [];
        }
        // El LIKE (que no distingue mayúsculas ni tildes) trae los candidatos: las letras en orden, con cualquier cosa entre
        // ellas ("Harlan's Holiday" → harlans-holiday). El nombre se compara después, como en la dirección.
        $st = $this->db->prepare("SELECT $col FROM caballo WHERE $col LIKE ? GROUP BY $col ORDER BY COUNT(*) DESC");
        $st->execute(['%' . implode('%', str_split(str_replace('-', '', $slug))) . '%']);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn ($n) => self::slugLinea((string)$n) === $slug));
    }

    /**
     * Debajo del nombre del padrillo, la madre o el abuelo: su ficha de caballo si corrió y está en la base, de quién es
     * hija la madre (el abuelo materno de sus hijos) y, si el padrillo también es abuelo materno (o al revés), la otra ficha.
     */
    private function parientes(string $tipo, array $nombres): string
    {
        $partes = [];
        // Corrió: un solo caballo con ese nombre (sin el país, que en Argentina va en el nombre del padrillo) y nacido antes que sus hijos.
        $sin = array_values(array_unique(array_map(fn ($n) => trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $n)), $nombres)));
        $col = self::LINEAS[$tipo][1];
        $en = fn (string $c, array $v) => "$c IN (" . implode(',', array_fill(0, count($v), '?')) . ')';
        $st = $this->db->prepare('SELECT id, nombre FROM caballo WHERE ' . $en('nombre', $sin) . ' AND (anio_nac IS NULL OR anio_nac <
            (SELECT COALESCE(MIN(anio_nac), 9999) FROM caballo WHERE ' . $en($col, $nombres) . '))');
        $st->execute([...$sin, ...$nombres]);
        $propia = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($propia) === 1) {
            $partes[] = 'Su campaña: ' . $this->enlace('caballo', $propia[0]['id'], $propia[0]['nombre']);
        }
        if ($tipo === 'madre') {
            // Su padre es el abuelo materno de sus hijos.
            $st = $this->db->prepare('SELECT abuelo_materno FROM caballo WHERE ' . $en('madre', $nombres) . " AND abuelo_materno <> '' GROUP BY abuelo_materno ORDER BY COUNT(*) DESC LIMIT 1");
            $st->execute($nombres);
            if ($padre = $st->fetchColumn()) {
                $partes[] = 'Hija de ' . self::enlazarLinea($this->nombres('padrillo', self::slugLinea($padre)) ? 'padrillo' : 'abuelo', $padre, $this->pais);
            }
        } else {
            $otro = $tipo === 'padrillo' ? 'abuelo' : 'padrillo';
            $st = $this->db->prepare('SELECT COUNT(*) FROM caballo WHERE ' . $en(self::LINEAS[$otro][1], $nombres));
            $st->execute($nombres);
            if ($n = (int)$st->fetchColumn()) {
                $partes[] = '<a href="' . htmlspecialchars(self::urlLinea($otro, $nombres[0], $this->pais), ENT_QUOTES) . '">'
                    . ($otro === 'abuelo' ? "Abuelo materno de $n caballos" : "Padrillo de $n caballos") . '</a>';
            }
        }
        return $partes ? '<p>' . implode(' · ', $partes) . '</p>' : '';
    }

    /**
     * Los hijos del padrillo o de la madre, o los nietos del abuelo materno (con sus hijas antes), con lo que corrió cada uno.
     * @param bool $filtrado si hay filtro por hipódromo: solo los que corrieron ahí
     */
    private function descendencia(string $tipo, string $en, array $nombres, array $corridas, bool $filtrado, bool $todas, bool $premios): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $st = $this->db->prepare("SELECT ca.id, ca.nombre, ca.pais, ca.sexo, ca.anio_nac, ca.padre, ca.madre FROM caballo ca WHERE $en");
        $st->execute($nombres);
        $hijos = $st->fetchAll(PDO::FETCH_ASSOC);
        $por = [];
        foreach ($corridas as $a) {
            $por[(int)$a['caballo_id']][] = $a;
        }
        $local = $this->pais ? self::PAISES[$this->pais][2] : 'URU';
        $fila = function (array $as): array {
            return ['corridas' => count($as), 'ganadas' => count(array_filter($as, fn ($a) => $a['puesto'] === 1)),
                'top3' => count(array_filter($as, fn ($a) => $a['puesto'] !== null && $a['puesto'] <= 3)), 'ultima' => $as ? max(array_column($as, 'fecha')) : null,
                'premios' => self::sumarPremios($as)];
        };
        foreach ($hijos as &$h) {
            $h += $fila($por[(int)$h['id']] ?? []);
        }
        unset($h);
        if ($filtrado) {
            $hijos = array_values(array_filter($hijos, fn ($h) => $h['corridas'] > 0));
        }
        $orden = fn ($a, $b) => [$b['ganadas'], $b['top3'], $b['corridas'], $a['nombre']] <=> [$a['ganadas'], $a['top3'], $a['corridas'], $b['nombre']];
        usort($hijos, $orden);
        $numeros = fn (array $r) => '<td class="n">' . $r['corridas'] . '</td><td class="n">' . $r['ganadas'] . '</td><td class="n">' . self::pct($r['ganadas'], $r['corridas'])
            . '</td><td class="n">' . $r['top3'] . '</td><td class="n">' . self::pct($r['top3'], $r['corridas']) . '</td>'
            . ($premios ? '<td class="n">' . ($r['premios'] ? self::plata($r['premios'], '<br>') : '') . '</td>' : '')
            . '<td>' . ($r['ultima'] ? date('d/m/y', strtotime($r['ultima'])) : '') . '</td>';
        $cabeza = '<th class="n">Carreras</th><th class="n">1º</th><th class="n">% gan.</th><th class="n">1º a 3º</th><th class="n">% 1º a 3º</th>'
            . ($premios ? '<th class="n">Premios</th>' : '') . '<th>Última</th>';
        $o = '';
        if ($tipo === 'abuelo') {
            // Sus hijas: las madres de sus nietos, con lo que corrieron los hijos de cada una.
            $hijas = [];
            foreach ($hijos as $h) {
                $k = self::slugLinea((string)$h['madre']);
                $hijas[$k]['nombre'] ??= (string)$h['madre'];
                $hijas[$k]['hijos'][] = $h;
            }
            foreach ($hijas as &$m) {
                $m += $fila(array_merge(...array_map(fn ($h) => $por[(int)$h['id']] ?? [], $m['hijos'])));
            }
            unset($m);
            uasort($hijas, $orden);
            $o .= '<h2>Sus hijas madres (' . count($hijas) . ')</h2><div class="ancha"><table class="hijos"><thead><tr><th>Madre</th><th class="n">Hijos</th>' . $cabeza . '</tr></thead><tbody>';
            foreach ($hijas as $m) {
                $o .= '<tr><td>' . self::enlazarLinea('madre', $m['nombre'], $this->pais) . '</td><td class="n">' . count($m['hijos']) . '</td>' . $numeros($m) . '</tr>';
            }
            $o .= '</tbody></table></div>';
        }
        $quienes = $tipo === 'abuelo' ? 'Nietos' : 'Hijos';
        $o .= '<h2>' . $quienes . ' (' . count($hijos) . ')</h2>';
        if (!$todas && count($hijos) > self::CANTIDAD_HIJOS) {
            $o .= '<p class="nota">Los ' . self::CANTIDAD_HIJOS . ' con más carreras ganadas. <a href="?todas=1#' . strtolower($quienes) . '">Ver todos</a></p>';
            $hijos = array_slice($hijos, 0, self::CANTIDAD_HIJOS);
        }
        $o .= '<div class="ancha" id="' . strtolower($quienes) . '"><table class="hijos"><thead><tr><th>Caballo</th><th>Sexo</th><th>Nac.</th>'
            . ($tipo !== 'padrillo' ? '<th>Padre</th>' : '') . ($tipo !== 'madre' ? '<th>Madre</th>' : '') . $cabeza . '</tr></thead><tbody>';
        foreach ($hijos as $h) {
            $sexo = $this->pais === 'hk' ? (string)$h['sexo'] : strtoupper(mb_substr((string)$h['sexo'], 0, 1));
            $o .= '<tr><td>' . $this->enlace('caballo', $h['id'], $h['nombre']) . ($h['pais'] && $h['pais'] !== $local && !str_ends_with($h['nombre'], ')') ? ' <small>(' . $e($h['pais']) . ')</small>' : '')
                . '</td><td>' . $e($sexo) . '</td><td>' . ($h['anio_nac'] ? (int)$h['anio_nac'] : '') . '</td>'
                . ($tipo !== 'padrillo' ? '<td>' . self::enlazarLinea('padrillo', $h['padre'], $this->pais) . '</td>' : '')
                . ($tipo !== 'madre' ? '<td>' . self::enlazarLinea('madre', $h['madre'], $this->pais) . '</td>' : '') . $numeros($h) . '</tr>';
        }
        return $o . '</tbody></table></div>';
    }

    /** Las carreras corridas que cumplen $condicion (sobre actuacion a, caballo ca), de la más reciente a la más vieja. */
    private function corridas(string $condicion, array $args): array
    {
        // Las columnas que cambian de un país a otro: el dividendo a ganador, los studs (no en Hong Kong) y los que no largaron
        // (en Argentina y Brasil figuran como retirados; en Hong Kong, con puesto WV, WX...).
        $hk = $this->pais === 'hk';
        $sport = in_array($this->pais, ['hk', 'br'], true) ? 'cuota' : 'sport';
        $largo = fn (string $t) => match ($this->pais) {
            'ar', 'br' => " AND $t.retiro_motivo IS NULL",
            'hk' => " AND ($t.puesto_texto IS NULL OR $t.puesto_texto NOT LIKE 'W%')",
            default => '',
        };
        $st = $this->db->prepare("SELECT r.fecha, r.hipodromo_id, c.id AS carrera_id, c.numero, c.premio AS nombre_carrera, c.distancia, c.superficie, c.pista,
                c.bolsa, c.bolsa_detalle" . ($this->pais === 'ar' ? ', c.bolsa_dolares' : '') . ", a.puesto, a.puesto_texto, a.peso_asignado, a.$sport AS sport, a.tiempo,
                ca.id AS caballo_id, ca.nombre AS caballo, j.id AS jockey_id, j.nombre AS jockey, cu.id AS cuidador_id, cu.nombre AS cuidador,
                " . ($hk ? 'NULL AS stud_id, NULL AS stud' : 's.id AS stud_id, s.nombre AS stud') . ", (SELECT COUNT(*) FROM actuacion x WHERE x.carrera_id = c.id" . $largo('x') . ") AS corredores
            FROM actuacion a JOIN carrera c ON c.id = a.carrera_id JOIN reunion r ON r.id = c.reunion_id JOIN caballo ca ON ca.id = a.caballo_id
            LEFT JOIN persona j ON j.id = a.jockey_id LEFT JOIN persona cu ON cu.id = a.cuidador_id " . ($hk ? '' : 'LEFT JOIN persona s ON s.id = a.stud_id') . "
            WHERE $condicion" . $largo('a') . " ORDER BY r.fecha DESC, c.numero DESC");
        $st->execute($args);
        $corridas = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($corridas as &$a) {
            $a['puesto'] = $a['puesto'] !== null ? (int)$a['puesto'] : null;
            $a['cobrado'] = $this->cobrado($a);
        }
        unset($a);
        return $corridas;
    }

    /** @param array<string, Fichas> $otras fichas de los otros países (clave de PAISES => Fichas), para buscar también ahí */
    public function buscar(string $q, array $otras = []): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $q = trim(mb_substr($q, 0, 60));
        $o = '<h1>Buscar</h1><form class="buscar" action="/buscar"><input name="q" value="' . $e($q) . '" placeholder="Caballo, jockey, cuidador, stud, criador, padrillo o madre" autofocus> <button>Buscar</button></form>';
        if (mb_strlen($q) >= 2) {
            $todas = [];
            foreach (['Uruguay' => $this] + array_combine(array_map(fn ($p) => self::PAISES[$p][0], array_keys($otras)), $otras) as $pais => $fichas) {
                if ($filas = $fichas->encontrar($q)) {
                    $todas[] = ($otras ? '<h2>' . $e($pais) . '</h2>' : '') . '<ul class="resultados">' . implode('', $filas) . '</ul>';
                }
            }
            $o .= $todas ? implode('', $todas) : '<p>No se encontró nada con «' . $e($q) . '».</p>';
        }
        return $this->documento('Buscar', $o);
    }

    /** Lo que coincide con la búsqueda en esta base: elementos <li> con el enlace a cada ficha. */
    public function encontrar(string $q): array
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $local = $this->pais ? self::PAISES[$this->pais][2] : 'URU';
        $st = $this->db->prepare('SELECT ca.id, ca.nombre, ca.pais, ca.anio_nac, MAX(r.fecha) AS ultima FROM caballo ca
            LEFT JOIN actuacion a ON a.caballo_id = ca.id LEFT JOIN carrera c ON c.id = a.carrera_id LEFT JOIN reunion r ON r.id = c.reunion_id
            WHERE ca.nombre LIKE ? GROUP BY ca.id ORDER BY ultima DESC LIMIT 50');
        $st->execute([$like]);
        $filas = [];
        foreach ($st as $c) {
            $filas[] = '<li>' . $this->enlace('caballo', $c['id'], $c['nombre']) . ' <small>' . ($c['pais'] && $c['pais'] !== $local && !str_ends_with($c['nombre'], ')') ? $e($c['pais']) . ' ' : '')
                . ($c['anio_nac'] ? (int)$c['anio_nac'] : '') . ($c['ultima'] ? ' · última carrera ' . date('d/m/Y', strtotime($c['ultima'])) : '') . '</small></li>';
        }
        foreach (array_keys(array_diff_key($this->tipos(), ['caballo' => 1])) as $t) {
            $st = $this->db->prepare($t === 'criador'
                ? 'SELECT p.id, p.nombre, COUNT(a.carrera_id) AS n FROM persona p LEFT JOIN caballo ca ON ca.criador_id = p.id LEFT JOIN actuacion a ON a.caballo_id = ca.id
                   WHERE p.tipo = ? AND p.nombre LIKE ? GROUP BY p.id ORDER BY n DESC LIMIT 30'
                : "SELECT p.id, p.nombre, COUNT(a.carrera_id) AS n FROM persona p LEFT JOIN actuacion a ON a.{$t}_id = p.id
                   WHERE p.tipo = ? AND p.nombre LIKE ? GROUP BY p.id ORDER BY n DESC LIMIT 30");
            $st->execute([$t, $like]);
            foreach ($st as $p) {
                $filas[] = '<li>' . $this->enlace($t, $p['id'], $p['nombre']) . ' <small>' . self::TIPOS[$t] . ' · ' . (int)$p['n'] . ' carreras</small></li>';
            }
        }
        // Padrillos, madres y abuelos maternos (estos, si no salieron ya como padrillos).
        $padrillos = [];
        foreach (self::LINEAS as $t => [$titulo, $col]) {
            $st = $this->db->prepare("SELECT $col AS nombre, COUNT(*) AS n FROM caballo WHERE $col LIKE ? GROUP BY $col ORDER BY n DESC LIMIT 20");
            $st->execute([$like]);
            foreach ($st as $p) {
                $slug = self::slugLinea((string)$p['nombre']);
                if ($slug === '' || ($t === 'abuelo' && isset($padrillos[$slug]))) {
                    continue;
                }
                $padrillos[$slug] = true;
                $filas[] = '<li>' . self::enlazarLinea($t, $p['nombre'], $this->pais) . ' <small>' . $titulo . ' · ' . (int)$p['n'] . ($t === 'abuelo' ? ' nieto' : ' hijo') . ((int)$p['n'] === 1 ? '' : 's') . '</small></li>';
            }
        }
        return $filas;
    }

    // ---------------------------------------------------------------- inscripciones (para las próximas carreras)

    /**
     * Guarda los inscriptos de un programa publicado, con el caballo, jockey y cuidador ya identificados en la base.
     * @param array $p programa armado por ProgramaNoOficial::armar()
     */
    public function guardarInscripciones(array $p): void
    {
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM inscripcion WHERE hipodromo_id = ? AND fecha = ?')->execute([$p['hipodromo'], $p['fecha']]);
        $ins = $this->db->prepare('INSERT IGNORE INTO inscripcion (hipodromo_id, fecha, carrera, mandil, caballo_id, caballo, jockey_id, jockey, cuidador_id, cuidador,
            stud_id, stud, peso, hora, nombre_carrera, distancia, superficie, retirado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($p['carreras'] as $c) {
            foreach ($c['caballos'] as $h) {
                $ins->execute([$p['hipodromo'], $p['fecha'], $c['numero'], mb_substr((string)$h['numero'], 0, 4), $this->idCaballo($h),
                    mb_substr($h['nombre'], 0, 60), $this->idPersona('jockey', $h['jockey']), $h['jockey'] ? mb_substr($h['jockey'], 0, 120) : null,
                    $this->idPersona('cuidador', $h['cuidador']), $h['cuidador'] ? mb_substr($h['cuidador'], 0, 120) : null,
                    $this->idPersona('stud', $h['stud']), $h['stud'] ? mb_substr($h['stud'], 0, 120) : null,
                    $h['peso'], mb_substr((string)$c['hora'], 0, 5), $c['premio'] ? mb_substr($c['premio'], 0, 150) : null, $c['distancia'], $c['superficie'],
                    $h['retirado'] ? 1 : 0]);
            }
        }
        $this->db->commit();
    }

    /** Enlaces de un programa: "carrera-mandil" => ['caballo' => url, 'jockey' => url, ..., 'criador' => [nombre, url]]. */
    public function enlacesPrograma(int $hip, string $fecha): array
    {
        $st = $this->db->prepare('SELECT i.carrera, i.mandil, i.caballo_id, i.caballo, i.jockey_id, i.jockey, i.cuidador_id, i.cuidador, i.stud_id, i.stud,
                cr.id AS criador_id, cr.nombre AS criador
            FROM inscripcion i LEFT JOIN caballo ca ON ca.id = i.caballo_id LEFT JOIN persona cr ON cr.id = ca.criador_id
            WHERE i.hipodromo_id = ? AND i.fecha = ?');
        $st->execute([$hip, $fecha]);
        $res = [];
        foreach ($st as $i) {
            $res[$i['carrera'] . '-' . strtoupper($i['mandil'])] = [
                'caballo' => $i['caballo_id'] ? self::url('caballo', (int)$i['caballo_id'], $i['caballo']) : null,
                'jockey' => $i['jockey_id'] ? self::url('jockey', (int)$i['jockey_id'], $i['jockey']) : null,
                'cuidador' => $i['cuidador_id'] ? self::url('cuidador', (int)$i['cuidador_id'], $i['cuidador']) : null,
                'stud' => $i['stud_id'] ? self::url('stud', (int)$i['stud_id'], $i['stud']) : null,
                'criador' => $i['criador_id'] ? [$i['criador'], self::url('criador', (int)$i['criador_id'], $i['criador'])] : null,
            ];
        }
        return $res;
    }

    private ?\PDOStatement $stCaballo = null;
    /** @var array<string, array<string,int>> tipo => clave => id */
    private array $personas = [];

    private function idCaballo(array $h): ?int
    {
        $this->stCaballo ??= $this->db->prepare('SELECT id FROM caballo WHERE nombre = ? AND pais = ? AND anio_nac = ?');
        $this->stCaballo->execute([$h['nombre'], $h['pais'], $h['anio_nac'] ?? 0]);
        $id = $this->stCaballo->fetchColumn();
        return $id ? (int)$id : null;
    }

    /** El jockey, cuidador o stud del programa en la tabla persona; si hay varios con la misma clave, el que corrió más recientemente. */
    private function idPersona(string $tipo, ?string $nombre): ?int
    {
        if (!$nombre) {
            return null;
        }
        if (!isset($this->personas[$tipo])) {
            $st = $this->db->prepare("SELECT p.id, p.nombre FROM persona p LEFT JOIN actuacion a ON a.{$tipo}_id = p.id
                WHERE p.tipo = ? GROUP BY p.id ORDER BY MAX(a.carrera_id) IS NULL DESC, MAX(a.carrera_id)");
            $st->execute([$tipo]);
            $this->personas[$tipo] = [];
            foreach ($st as $p) {
                $this->personas[$tipo][$this->clavePersona($tipo, $p['nombre'])] = (int)$p['id'];   // el último pisa
            }
        }
        return $this->personas[$tipo][$this->clavePersona($tipo, $nombre)] ?? null;
    }

    private function clavePersona(string $tipo, string $nombre): string
    {
        return $tipo === 'jockey' ? Personas::jockey($nombre) : Personas::clave($nombre);
    }

    /** @param string $condicion sobre la inscripción (i) y su caballo (ca) */
    private function proximas(string $condicion, array $args): array
    {
        $st = $this->db->prepare("SELECT i.* FROM inscripcion i LEFT JOIN caballo ca ON ca.id = i.caballo_id
            WHERE $condicion AND i.fecha >= ? ORDER BY i.fecha, i.hora, i.carrera");
        try {
            $st->execute([...$args, date('Y-m-d')]);
        } catch (\PDOException) {
            return [];      // todavía no se publicó ningún programa
        }
        // Las de hoy que ya tienen resultado figuran entre las corridas.
        $corridas = $this->db->prepare('SELECT 1 FROM reunion WHERE hipodromo_id = ? AND fecha = ?');
        return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), function ($i) use ($corridas) {
            $corridas->execute([$i['hipodromo_id'], $i['fecha']]);
            return !$corridas->fetchColumn();
        }));
    }

    // ---------------------------------------------------------------- tablas

    private function cesped(array $a): bool
    {
        return $this->pais ? str_starts_with(mb_strtolower((string)$a['superficie']), 'c')
            : Clasificador::superficie($a['superficie'], (int)$a['hipodromo_id']) === 'césped';
    }

    /** Columnas de la lista de carreras (Hong Kong no tiene studs). */
    private function columnas(string $tipo): array
    {
        return array_values(array_intersect(self::COLUMNAS[$tipo], array_keys($this->tipos())));
    }

    private static function resumen(array $corridas, bool $premios = true): string
    {
        $n = count($corridas);
        if (!$n) {
            return '';
        }
        $g = count(array_filter($corridas, fn ($a) => $a['puesto'] === 1));
        $pl = count(array_filter($corridas, fn ($a) => $a['puesto'] !== null && $a['puesto'] <= 3));
        return '<p class="resumen"><b>' . $n . '</b> carreras · <b>' . $g . '</b> ganadas (' . self::pct($g, $n) . ') · <b>' . $pl . '</b> en el marcador (' . self::pct($pl, $n) . ')'
            . ($premios ? ' · Premios: <b>' . self::plata(self::sumarPremios($corridas)) . '</b>' : '') . ' · Desde ' . date('d/m/Y', strtotime(end($corridas)['fecha'])) . '</p>';
    }

    private static function tablaPuestos(array $corridas, \Closure $grupo, string $titulo, bool $ordenNumerico = false, bool $porCantidad = false, bool $premios = true): string
    {
        $g = [];
        foreach ($corridas as $a) {
            $g[$grupo($a)][] = $a;
        }
        if ($porCantidad) {
            uasort($g, fn ($a, $b) => count($b) <=> count($a));
        } else {
            $ordenNumerico ? uksort($g, fn ($a, $b) => [(int)$a, $a] <=> [(int)$b, $b]) : krsort($g);
        }
        $o = '<table class="puestos"><thead><tr><th>' . $titulo . '</th><th>Corrió</th><th>1º</th><th>2º</th><th>3º</th><th>4º</th><th>5º</th><th>Otros</th><th>% gan.</th>' . ($premios ? '<th>Premios</th>' : '') . '</tr></thead><tbody>';
        $fila = function (string $etiqueta, array $as, bool $total = false) use ($premios): string {
            $n = count($as);
            $p = array_count_values(array_map(fn ($a) => $a['puesto'] !== null && $a['puesto'] <= 5 ? $a['puesto'] : 0, $as));
            $o = '<tr' . ($total ? ' class="total"' : '') . '><td>' . htmlspecialchars($etiqueta) . '</td><td>' . $n . '</td>';
            for ($i = 1; $i <= 5; $i++) {
                $o .= '<td>' . ($p[$i] ?? 0) . '</td>';
            }
            return $o . '<td>' . ($p[0] ?? 0) . '</td><td>' . self::pct($p[1] ?? 0, $n) . '</td>' . ($premios ? '<td>' . self::plata(self::sumarPremios($as), '<br>') . '</td>' : '') . '</tr>';
        };
        foreach ($g as $k => $as) {
            $o .= $fila((string)$k, $as);
        }
        return $o . $fila('Total', $corridas, true) . '</tbody></table>';
    }

    private function tablaCorridas(array $lista, string $tipo): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $premios = $this->pais !== 'br';
        $o = '<div class="ancha"><table class="corridas"><thead><tr><th>Fecha</th><th>Hip.</th><th>Carrera</th><th>Dist.</th><th>Pista</th><th>Puesto</th>'
            . implode('', array_map(fn ($c) => '<th>' . self::TIPOS[$c] . '</th>', $this->columnas($tipo)))
            . '<th>Kg</th><th>' . (['hk' => 'Cuota', 'br' => 'Rateio'][$this->pais ?? ''] ?? 'Sport') . '</th>' . ($premios ? '<th>Premio cobrado</th>' : '') . '</tr></thead><tbody>';
        foreach ($lista as $a) {
            $clave = $a['fecha'] . '_' . $this->web((int)$a['hipodromo_id']);
            $carrera = $a['numero'] . 'ª ' . $e($a['nombre_carrera']);
            if (is_file("{$this->carpeta}/resultados/$clave.html")) {
                $carrera = '<a href="/resultados/' . $clave . '.html#carrera-' . $a['numero'] . '">' . $carrera . '</a>';
            }
            $o .= '<tr class="' . ($a['puesto'] !== null && $a['puesto'] <= 3 ? 'p' . $a['puesto'] : '') . '"><td>' . date('d/m/y', strtotime($a['fecha'])) . '</td><td>'
                . $e($this->hipodromo((int)$a['hipodromo_id'])) . '</td><td class="nom">' . $carrera . '</td><td>' . $e($a['distancia']) . ($a['superficie'] === 'césped' ? ' c' : '')
                . '</td><td>' . $e($a['pista']) . '</td><td><b>' . ($a['puesto'] !== null ? $a['puesto'] . 'º' : $e($a['puesto_texto'])) . '</b><small>/' . (int)$a['corredores'] . '</small></td>'
                . implode('', array_map(fn ($c) => '<td>' . $this->enlace($c, $a[$c . '_id'], $a[$c]) . '</td>', $this->columnas($tipo)))
                . '<td>' . ($a['peso_asignado'] !== null ? number_format((float)$a['peso_asignado'], 1, ',', '.') : '') . '</td><td>'
                . ($a['sport'] > 0 ? number_format((float)$a['sport'], 2, ',', '.') : '') . '</td>'
                . ($premios ? '<td>' . ($a['cobrado'] ? $a['cobrado'][0] . ' ' . number_format($a['cobrado'][1], 0, ',', '.') : '') . '</td>' : '') . '</tr>';
        }
        return $o . '</tbody></table></div>';
    }

    private function tablaProximas(array $lista, string $tipo): string
    {
        $e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $o = '<div class="ancha"><table class="corridas"><thead><tr><th>Fecha</th><th>Hora</th><th>Hip.</th><th>Carrera</th><th>Dist.</th><th>Nº</th>'
            . implode('', array_map(fn ($c) => '<th>' . self::TIPOS[$c] . '</th>', array_diff(self::COLUMNAS[$tipo], ['criador'])))
            . '<th>Kg</th><th></th></tr></thead><tbody>';
        foreach ($lista as $i) {
            $clave = $i['fecha'] . '_' . $i['hipodromo_id'];
            $carrera = $i['carrera'] . 'ª ' . $e($i['nombre_carrera']);
            if (is_file("{$this->carpeta}/programas/$clave.html")) {
                $carrera = '<a href="/programas/' . $clave . '.html#carrera-' . $i['carrera'] . '">' . $carrera . '</a>';
            }
            $hora = preg_match('/^0\d:/', (string)$i['hora']) ? 'a confirmar' : $i['hora'];   // programa preliminar
            $o .= '<tr' . ($i['retirado'] ? ' class="retirado"' : '') . '><td>' . date('d/m/y', strtotime($i['fecha'])) . '</td><td>' . $e($hora) . '</td><td>'
                . ProgramaNoOficial::nombreHipodromo((int)$i['hipodromo_id']) . '</td><td class="nom">' . $carrera . '</td><td>' . $e($i['distancia']) . '</td><td>' . $e($i['mandil']) . '</td>'
                . implode('', array_map(fn ($c) => '<td>' . ($c === 'jockey' && $i['retirado'] ? 'No correrá' : ($c === 'jockey' && !$i['jockey'] ? 'a confirmar'
                    : $this->enlace($c, $i[$c . '_id'], $i[$c]))) . '</td>', array_diff(self::COLUMNAS[$tipo], ['criador'])))
                . '<td>' . ($i['peso'] !== null ? number_format((float)$i['peso'], 1, ',', '.') : '') . '</td><td>' . $this->calendario($i) . '</td></tr>';
        }
        return $o . '</tbody></table></div>';
    }

    /** 📅 que agrega la carrera a Google Calendar (5 minutos), como en el programa; nada si todavía no tiene hora. */
    private function calendario(array $i): string
    {
        if ($i['retirado'] || !preg_match('/^(\d{2}):(\d{2})$/', (string)$i['hora'], $m) || (int)$m[1] < 8) {
            return '';
        }
        $zona = new \DateTimeZone('America/Montevideo');
        $inicio = (new \DateTimeImmutable($i['fecha'], $zona))->setTime((int)$m[1], (int)$m[2]);
        $url = Publicador::enlaceGoogle([
            'titulo' => sprintf('%dª %s · %s', $i['carrera'], Publicador::sigla((int)$i['hipodromo_id']), $i['caballo']),
            'inicio' => $inicio,
            'fin' => $inicio->modify('+5 minutes'),
            'lugar' => ProgramaNoOficial::nombreHipodromo((int)$i['hipodromo_id']),
            'detalle' => trim(($i['nombre_carrera'] ?? '') . ($i['distancia'] ? " · {$i['distancia']} m" : '') . ($i['jockey'] ? " · Jockey: {$i['jockey']}" : ''))
                . "\nhttps://granchance.com/programas/{$i['fecha']}_{$i['hipodromo_id']}.html#carrera-{$i['carrera']}",
        ]);
        return '<a class="cal" href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener" title="Agregar a Google Calendar">📅</a>';
    }

    private function enlace(string $tipo, int|string|null $id, ?string $nombre): string
    {
        return self::enlazar($tipo, $id ? (int)$id : null, $nombre, $this->pais);
    }

    /** El nombre con el enlace a su ficha (si tiene id), para las fichas y los programas de cada país (a.ficha: como texto, subrayado de puntos). */
    public static function enlazar(string $tipo, ?int $id, ?string $nombre, ?string $pais = null): string
    {
        $n = htmlspecialchars((string)$nombre, ENT_QUOTES);
        return $id ? '<a class="ficha" href="' . htmlspecialchars(self::url($tipo, $id, (string)$nombre, $pais), ENT_QUOTES) . '">' . $n . '</a>' : $n;
    }

    private ?array $colores = null;

    /** Colores del stud según el Stud Book (tabla stud_colores, bin/studs.php). */
    private function colores(?string $stud): ?string
    {
        if ($this->colores === null) {
            try {
                $this->colores = $this->db->query('SELECT clave, colores FROM stud_colores')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (\PDOException) {
                $this->colores = [];
            }
        }
        return $stud ? ($this->colores[Personas::clave($stud)] ?? null) : null;
    }

    private function chaquetilla(?string $stud, int $alto = 22): string
    {
        $c = $this->colores($stud);
        return $c ? '<span class="chaquetilla" title="' . htmlspecialchars($c, ENT_QUOTES) . '">' . Chaquetilla::svg($c, $alto) . '</span> ' : '';
    }

    /** @return array<string,int> moneda => total */
    private static function sumarPremios(array $corridas): array
    {
        $t = [];
        foreach ($corridas as $a) {
            if ($a['cobrado']) {
                $t[$a['cobrado'][0]] = ($t[$a['cobrado'][0]] ?? 0) + $a['cobrado'][1];
            }
        }
        return $t;
    }

    private static function plata(array $t, string $separador = ' + '): string
    {
        if (!$t) {
            return '—';
        }
        ksort($t);
        return implode($separador, array_map(fn ($m, $v) => $m . ' ' . number_format($v, 0, ',', '.'), array_keys($t), $t));
    }

    private static function pct(int $n, int $d): string
    {
        return $d ? number_format(100 * $n / $d, 1, ',', '.') . ' %' : '';
    }

    private static function pelaje(?string $p): string
    {
        return ['z' => 'Zaino', 'a' => 'Alazán', 't' => 'Tordillo', 'ts' => 'Tostado', 'ar' => 'Arabe', 'zc' => 'Zaino colorado', 'zn' => 'Zaino negro',
            'o' => 'Oscuro', 'r' => 'Rosillo', 'b' => 'Bayo', 'g' => 'Gateado', 'm' => 'Moro'][strtolower((string)$p)] ?? (string)$p;
    }

    // ---------------------------------------------------------------- documento

    private function documento(string $titulo, string $cuerpo): string
    {
        $indice = is_file($this->carpeta . '/indice.json') ? json_decode((string)file_get_contents($this->carpeta . '/indice.json'), true) : [];
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($titulo) . ' · GranChance</title>' . Publicador::analytics((string)($indice['analytics'] ?? '')) . '<style>' . self::CSS . '</style></head><body>'
            . '<nav class="barra"><span><a href="/">GranChance · todos los programas</a> · <a href="/ranking">Rankings</a></span> <form action="/buscar"><input name="q" placeholder="Buscar caballo, jockey, stud…"></form></nav>'
            . $cuerpo . '</body></html>';
    }

    public const CSS = <<<'CSS'
:root { --fondo:#fff; --texto:#1d1d1f; --suave:#6b6b70; --linea:#e3e3e6; --cabecera:#f2f2f4; --acento:#1f4e8c; --oro:#fff4cc; --plata:#eef0f3; --bronce:#f8e6d6; }
@media (prefers-color-scheme: dark) { :root { --fondo:#161618; --texto:#ececef; --suave:#a0a0a8; --linea:#34343a; --cabecera:#232327; --acento:#8ab4f8; --oro:#3d3518; --plata:#2c2f35; --bronce:#3b2c20; } }
* { box-sizing:border-box; }
body { margin:0 auto; max-width:1100px; padding:16px; background:var(--fondo); color:var(--texto); font:14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
h1 { font-size:22px; margin:8px 0 4px; } h1 small { font-size:13px; color:var(--suave); font-weight:400; } h2 { font-size:16px; margin:20px 0 6px; color:var(--acento); }
a { color:var(--acento); text-decoration:none; } a:hover { text-decoration:underline; }
.barra { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; border-bottom:1px solid var(--linea); padding-bottom:8px; }
.barra a { font-weight:600; }
input { font:inherit; padding:4px 8px; border:1px solid var(--linea); border-radius:6px; background:var(--fondo); color:var(--texto); min-width:220px; }
button { font:inherit; padding:4px 10px; border:1px solid var(--linea); border-radius:6px; background:var(--cabecera); color:var(--texto); }
.nota, small { color:var(--suave); } .nota { font-size:12px; }
.resumen { background:var(--cabecera); border-radius:6px; padding:6px 10px; }
.dos { display:flex; flex-wrap:wrap; gap:0 24px; } .dos > div { flex:1 1 480px; min-width:0; overflow-x:auto; }
td.nom { white-space:normal; min-width:200px; }
.ancha { overflow-x:auto; }
table { border-collapse:collapse; width:100%; font-variant-numeric:tabular-nums; }
th { background:var(--cabecera); text-align:left; font-weight:600; }
th, td { border-bottom:1px solid var(--linea); padding:4px 6px; white-space:nowrap; }
.puestos td:not(:first-child), .puestos th:not(:first-child) { text-align:right; }
tr.total td { font-weight:700; }
tr.p1 td { background:var(--oro); } tr.p2 td { background:var(--plata); } tr.p3 td { background:var(--bronce); }
tr.retirado td { text-decoration:line-through; color:var(--suave); }
.filtro { display:flex; flex-wrap:wrap; gap:6px; margin:10px 0; }
.filtro a { border:1px solid var(--linea); border-radius:14px; padding:2px 10px; background:var(--cabecera); }
.filtro a.actual { background:var(--acento); color:var(--fondo); }
.chaquetilla svg { vertical-align:middle; }
a.cal { text-decoration:none; font-size:15px; }
.resultados { padding-left:18px; } .resultados li { margin:3px 0; }
.hijos .n { text-align:right; }
CSS;
}
