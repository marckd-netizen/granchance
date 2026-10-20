<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Pronósticos de la prensa del concurso de Maroñas (servicios.maronas.com.uy): para cada reunión de Maroñas y de Las
 * Piedras, el favorito, el enemigo y la sorpresa de unos 20 periodistas en cada carrera. Se guardan en la tabla
 * prensa_pronostico y se combinan con la probabilidad del Modelo:
 *
 *     puntaje = b_modelo · log(prob. del modelo) + b_favorito · F + b_enemigo · E + b_sorpresa · S
 *
 * donde F, E y S son la parte de los periodistas que dio a ese caballo de favorito, enemigo o sorpresa. Los b se
 * ajustan por hipódromo con las carreras que el modelo no vio (bin/prensa.php --ajustar) y se guardan en prensa_coef.
 * Medido en Maroñas de julio de 2025 a octubre de 2026: el modelo solo acierta el ganador en el 28,5 % y la trifecta
 * con sus 3 primeros en el 10,5 %; combinado con la prensa, 31,4 % y 13,7 %.
 */
final class Prensa
{
    public const URLS = [
        1 => 'https://servicios.maronas.com.uy/pronosticos/PGUIPronosticos/WPronosticosTop.aspx',
        13 => 'https://servicios.maronas.com.uy/concursoPrensaLP/PGUIPronosticos/WPronosticosTop.aspx',
    ];
    public const VARIABLES = ['modelo', 'favorito', 'enemigo', 'sorpresa'];
    private const TIPOS = ['Favorito', 'Enemigo', 'Sorpresa'];
    private const CONSENSO = 'Promedios';   // fila del concurso con el consenso de todos: no se cuenta como un periodista más

    /** @var array<int, array{0:string, 1:array<string,string>}> hipódromo => [página, campos del formulario] */
    private array $sesion = [];
    /** @var array<int, array<string, float>> hipódromo => variable => coeficiente */
    private array $coef = [];

    public function __construct(private PDO $db)
    {
        $this->db->exec(file_get_contents(__DIR__ . '/../sql/velocidades.sql'));
        foreach ($this->db->query('SELECT hipodromo_id, variable, coeficiente FROM prensa_coef', PDO::FETCH_ASSOC) as $r) {
            $this->coef[(int)$r['hipodromo_id']][$r['variable']] = (float)$r['coeficiente'];
        }
    }

    public function tieneCoeficientes(int $hip): bool
    {
        return isset($this->coef[$hip]);
    }

    /** ¿Hay pronósticos guardados de esa reunión? */
    public function tiene(int $hip, string $fecha): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM prensa_pronostico WHERE hipodromo_id = ? AND fecha = ? LIMIT 1');
        $st->execute([$hip, $fecha]);
        return (bool)$st->fetchColumn();
    }

    // ---------------------------------------------------------------- bajar

    /** Fechas (AAAA-MM-DD) que tiene el concurso de ese hipódromo, la más reciente primero. */
    public function fechas(int $hip): array
    {
        [$pagina] = $this->sesion($hip);
        preg_match_all('/<option[^>]*value="(\d+)\/(\d+)\/(\d{4})[^"]*"/', $pagina, $m, PREG_SET_ORDER);
        return array_map(fn ($x) => sprintf('%04d-%02d-%02d', $x[3], $x[2], $x[1]), $m);
    }

    /**
     * Baja los pronósticos de una reunión y los guarda (reemplaza los que hubiera). Devuelve cuántos guardó; 0 si el
     * concurso no tiene esa fecha o la página no responde.
     */
    public function bajar(int $hip, string $fecha): int
    {
        if (!isset(self::URLS[$hip])) {
            return 0;
        }
        [$pagina, $campos] = $this->sesion($hip);
        $valor = null;
        if (preg_match_all('/<option([^>]*)value="([^"]*)"/', $pagina, $m, PREG_SET_ORDER)) {
            foreach ($m as $o) {
                if (preg_match('#^(\d+)/(\d+)/(\d{4})#', $o[2], $d) && sprintf('%04d-%02d-%02d', $d[3], $d[2], $d[1]) === $fecha) {
                    $valor = $o[2];
                    $elegida = str_contains($o[1], 'selected');
                }
            }
        }
        if ($valor === null) {
            return 0;
        }
        if (!($elegida ?? false)) {
            $pagina = $this->pedir(self::URLS[$hip], $campos + ['__EVENTTARGET' => 'ddl_reunion', '__EVENTARGUMENT' => '', 'ddl_reunion' => $valor]);
            if ($pagina === null) {
                return 0;
            }
            $this->sesion[$hip] = [$pagina, self::campos($pagina)];
        }
        $filas = self::leer($pagina);
        $this->db->prepare('DELETE FROM prensa_pronostico WHERE hipodromo_id = ? AND fecha = ?')->execute([$hip, $fecha]);
        $ins = $this->db->prepare('INSERT INTO prensa_pronostico (hipodromo_id, fecha, carrera, pronosticador, tipo, caballo) VALUES (?,?,?,?,?,?)');
        foreach ($filas as [$carrera, $quien, $tipo, $caballo]) {
            $ins->execute([$hip, $fecha, $carrera, $quien, $tipo, $caballo]);
        }
        return count($filas);
    }

    /** Página del concurso → [carrera, pronosticador, tipo, caballo (cortado a 9 letras)]. */
    public static function leer(string $pagina): array
    {
        preg_match_all('/class=\s*"td_nomMedio\d"[^>]*>([^<]*)</', $pagina, $m);
        $quienes = array_merge([self::CONSENSO], array_map(fn ($q) => trim(html_entity_decode($q)), $m[1]));
        $i = strpos($pagina, 'tabla_carrerasPronosticos');
        if ($i === false) {
            return [];
        }
        $tabla = substr($pagina, $i, (strpos($pagina, '</table>', $i) ?: strlen($pagina)) - $i);
        preg_match_all('/<tr>(.*?)<\/tr>/s', $tabla, $filas);
        if (!$filas[1]) {
            return [];
        }
        preg_match_all('/>Carrera (\d+)</', $filas[1][0], $carreras);
        $res = [];
        foreach (array_slice($filas[1], 1) as $n => $fila) {
            $quien = $quienes[intdiv($n, 3)] ?? null;
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $fila, $celdas);
            foreach ($celdas[1] as $k => $celda) {
                $caballo = trim(html_entity_decode(strip_tags($celda)), " \u{a0}");
                if ($quien !== null && $caballo !== '' && isset($carreras[1][$k])) {
                    $res[] = [(int)$carreras[1][$k], $quien, self::TIPOS[$n % 3], $caballo];
                }
            }
        }
        return $res;
    }

    private function sesion(int $hip): array
    {
        if (!isset($this->sesion[$hip])) {
            $pagina = $this->pedir(self::URLS[$hip]) ?? '';
            $this->sesion[$hip] = [$pagina, self::campos($pagina)];
        }
        return $this->sesion[$hip];
    }

    private static function campos(string $pagina): array
    {
        preg_match_all('/<input[^>]*name="(__[A-Z]+)"[^>]*value="([^"]*)"/', $pagina, $m, PREG_SET_ORDER);
        $c = [];
        foreach ($m as $x) {
            $c[$x[1]] = html_entity_decode($x[2]);
        }
        return $c;
    }

    private function pedir(string $url, ?array $post = null): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_USERAGENT => 'Mozilla/5.0 (granchance)',
            CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => true]);
        if ($post !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
        }
        for ($intento = 1; $intento <= 3; $intento++) {
            $r = curl_exec($ch);
            if ($r !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                return $r;
            }
            sleep(3 * $intento);
        }
        return null;
    }

    // ---------------------------------------------------------------- combinar

    /**
     * Votos de la prensa en una carrera, por caballo: [favorito, enemigo, sorpresa] como parte de los periodistas, y el
     * consenso del concurso (qué le dio la fila "Promedios"). La página corta los nombres a 9 letras, así que se cruzan
     * por el comienzo del nombre.
     * @param array<int|string, string> $nombres  clave => nombre del caballo
     * @return array<int|string, array{favorito:float, enemigo:float, sorpresa:float, consenso:?string, periodistas:int}>|null  null si no hay pronósticos
     */
    public function votos(int $hip, string $fecha, int $carrera, array $nombres): ?array
    {
        $st = $this->db->prepare('SELECT pronosticador, tipo, caballo FROM prensa_pronostico WHERE hipodromo_id = ? AND fecha = ? AND carrera = ?');
        $st->execute([$hip, $fecha, $carrera]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) {
            return null;
        }
        $porClave = [];
        foreach ($nombres as $k => $n) {
            $porClave[self::clave($n)] = $k;
        }
        $periodistas = count(array_unique(array_column(array_filter($filas, fn ($f) => $f['pronosticador'] !== self::CONSENSO), 'pronosticador')));
        $res = array_map(fn () => ['favorito' => 0.0, 'enemigo' => 0.0, 'sorpresa' => 0.0, 'consenso' => null, 'periodistas' => $periodistas], $nombres);
        foreach ($filas as $f) {
            $k = $porClave[self::clave($f['caballo'])] ?? null;
            if ($k === null) {
                continue;
            }
            if ($f['pronosticador'] === self::CONSENSO) {
                $res[$k]['consenso'] = $f['tipo'];
            } elseif ($periodistas) {
                $res[$k][strtolower($f['tipo'])] += 1 / $periodistas;
            }
        }
        return $res;
    }

    /** Combina las probabilidades del modelo con los votos; devuelve las nuevas probabilidades (suman 1). */
    public function combinar(int $hip, array $prob, array $votos): array
    {
        $b = $this->coef[$hip] ?? null;
        if ($b === null || !$prob) {
            return $prob;
        }
        $s = [];
        foreach ($prob as $k => $p) {
            $v = $votos[$k] ?? ['favorito' => 0.0, 'enemigo' => 0.0, 'sorpresa' => 0.0];
            $s[$k] = $b['modelo'] * log(max($p, 1e-4)) + $b['favorito'] * $v['favorito'] + $b['enemigo'] * $v['enemigo'] + $b['sorpresa'] * $v['sorpresa'];
        }
        $max = max($s);
        $e = array_map(fn ($x) => exp($x - $max), $s);
        $suma = array_sum($e);
        return array_map(fn ($x) => $x / $suma, $e);
    }

    /** Primeras 9 letras del nombre, sin tildes, en minúscula (como los corta la página del concurso). */
    public static function clave(string $nombre): string
    {
        $n = preg_replace('/\s*\([A-Z]{3}\)\s*$/u', '', trim($nombre));
        $n = strtr(mb_strtolower($n), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return rtrim(mb_substr($n, 0, 9));
    }

    // ---------------------------------------------------------------- ajustar

    /**
     * Ajusta los coeficientes de un hipódromo con carreras que el modelo no vio.
     * @param list<array{carrera:int, prob:float, votos:array, gano:bool}> $filas  un corredor por fila
     * @return array<string, float>
     */
    public static function ajustar(array $filas): array
    {
        $porCarrera = [];
        foreach ($filas as $f) {
            $porCarrera[$f['carrera']][] = [log(max($f['prob'], 1e-4)), $f['votos']['favorito'], $f['votos']['enemigo'], $f['votos']['sorpresa'], $f['gano'] ? 1 : 0];
        }
        $b = [1.0, 0.0, 0.0, 0.0];
        for ($it = 0; $it < 50; $it++) {
            $g = array_fill(0, 4, 0.0);
            $H = array_fill(0, 4, array_fill(0, 4, 0.0));
            foreach ($porCarrera as $cs) {
                $s = array_map(fn ($c) => $b[0] * $c[0] + $b[1] * $c[1] + $b[2] * $c[2] + $b[3] * $c[3], $cs);
                $max = max($s);
                $e = array_map(fn ($x) => exp($x - $max), $s);
                $suma = array_sum($e);
                $p = array_map(fn ($x) => $x / $suma, $e);
                $media = array_fill(0, 4, 0.0);
                foreach ($cs as $i => $c) {
                    for ($j = 0; $j < 4; $j++) {
                        $media[$j] += $p[$i] * $c[$j];
                        $g[$j] += $c[4] * $c[$j];
                    }
                }
                for ($j = 0; $j < 4; $j++) {
                    $g[$j] -= $media[$j];
                }
                foreach ($cs as $i => $c) {
                    for ($j = 0; $j < 4; $j++) {
                        for ($l = 0; $l < 4; $l++) {
                            $H[$j][$l] += $p[$i] * ($c[$j] - $media[$j]) * ($c[$l] - $media[$l]);
                        }
                    }
                }
            }
            for ($j = 0; $j < 4; $j++) {
                $H[$j][$j] += 0.01;
                $g[$j] -= 0.01 * $b[$j];
            }
            $paso = Modelo::resolver($H, $g);
            $b = array_map(fn ($x, $d) => $x + $d, $b, $paso);
            if (max(array_map('abs', $paso)) < 1e-6) {
                break;
            }
        }
        return array_combine(self::VARIABLES, $b);
    }

    public function guardar(int $hip, array $coef): void
    {
        $this->db->prepare('DELETE FROM prensa_coef WHERE hipodromo_id = ?')->execute([$hip]);
        $st = $this->db->prepare('INSERT INTO prensa_coef (hipodromo_id, variable, coeficiente) VALUES (?,?,?)');
        foreach ($coef as $v => $b) {
            $st->execute([$hip, $v, $b]);
        }
        $this->coef[$hip] = $coef;
    }
}
