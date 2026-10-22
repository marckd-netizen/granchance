<?php
declare(strict_types=1);

namespace GranChance\JP;

/**
 * Baja las páginas del sitio en inglés de la JRA (jra.jp/JRAEN, "JRA Race Card & Results"): el calendario de cada año,
 * las carreras de cada día y la página de cada carrera (programa antes de correrse, resultados después).
 * Todo se pide por POST, como lo hace el sitio. El crudo queda en raw/jp/AAAA/MM/AAAA-MM-DD_<hipódromo>/carrera_NN.html.gz,
 * comprimido y sin scripts ni estilos (~4 KB cada uno), para poder re-procesar sin volver a pedirlo.
 */
final class Cliente
{
    private const BASE = 'https://jra.jp/JRAEN/AP/kaisai/';
    private float $ultimoPedido = 0.0;

    public function __construct(
        private string $carpetaCrudos,
        private float $pausaSegundos = 1.0,
        private int $reintentos = 3,
    ) {
    }

    /** Días de carreras del año. Los años cerrados se guardan; el actual se pide siempre. */
    public function calendario(int $anio): string
    {
        $archivo = sprintf('%s/calendario/%04d.html.gz', $this->carpetaCrudos, $anio);
        return $this->pagina('kaisaiRaceSearch', ['raceYear' => $anio, 'command' => 'GO'], $archivo, $anio < (int)date('Y'));
    }

    /** Carreras de un día en todos los hipódromos (código, reunión, día, número y hora de cada una). */
    public function dia(string $fecha, bool $usarCache = true): string
    {
        $archivo = sprintf('%s/%s/%s.html.gz', $this->carpetaCrudos, str_replace('-', '/', substr($fecha, 0, 7)), $fecha);
        return $this->pagina('raceSelect', ['raceYmd' => str_replace('-', '', $fecha)], $archivo, $usarCache);
    }

    /**
     * Página de una carrera: resultados oficiales si ya se corrió, el programa (Race Card) si no.
     * @param array{fecha: string, hipodromo: string, kai: string, hi: string} $r
     */
    public function carrera(array $r, int $nro, bool $usarCache = true): string
    {
        $datos = [
            'raceYmd' => str_replace('-', '', $r['fecha']), 'raceJoCd' => $r['hipodromo'],
            'raceKai' => $r['kai'], 'raceHi' => $r['hi'], 'raceNo' => sprintf('%02d', $nro),
        ];
        return $this->pagina('running', $datos, $this->carpeta($r['fecha'], $r['hipodromo']) . sprintf('/carrera_%02d.html.gz', $nro), $usarCache);
    }

    /** El programa de una carrera: se pide siempre (cambia hasta la largada) y se guarda como programa_NN.html.gz. */
    public function programa(array $r, int $nro): string
    {
        $datos = [
            'raceYmd' => str_replace('-', '', $r['fecha']), 'raceJoCd' => $r['hipodromo'],
            'raceKai' => $r['kai'], 'raceHi' => $r['hi'], 'raceNo' => sprintf('%02d', $nro),
        ];
        return $this->pagina('running', $datos, $this->carpeta($r['fecha'], $r['hipodromo']) . sprintf('/programa_%02d.html.gz', $nro), false);
    }

    public function carpeta(string $fecha, string $hip): string
    {
        return sprintf('%s/%s/%s_%s', $this->carpetaCrudos, str_replace('-', '/', substr($fecha, 0, 7)), $fecha, $hip);
    }

    private function pagina(string $accion, array $datos, string $archivo, bool $usarCache): string
    {
        if ($usarCache && is_file($archivo) && filesize($archivo) > 0) {
            return (string)gzdecode((string)file_get_contents($archivo));
        }
        $detalle = null;
        for ($intento = 1; $intento <= $this->reintentos; $intento++) {
            $this->esperarTurno();
            [$codigo, $cuerpo, $error] = $this->post(self::BASE . $accion, $datos);
            if ($error === null && $codigo === 200 && stripos($cuerpo, '</html>') !== false) {
                $html = self::recortar($cuerpo);
                if (!is_dir(dirname($archivo))) {
                    mkdir(dirname($archivo), 0775, true);
                }
                file_put_contents($archivo, gzencode($html, 9));
                return $html;
            }
            $detalle = $error ?? "HTTP $codigo";
            sleep($intento * 5);
        }
        throw new \RuntimeException("$accion " . http_build_query($datos) . ": $detalle");
    }

    /** Saca scripts, estilos, comentarios y espacios: la página pesa ~60 KB y lo que se usa, ~30 KB (4 KB comprimido). */
    private static function recortar(string $html): string
    {
        // El sitio declara ISO-8859-1; los nombres son ASCII salvo alguna letra con tilde.
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        $html = (string)preg_replace('#<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->|<link\b[^>]*>#si', '', $html);
        $html = (string)preg_replace('/[ \t]+/', ' ', $html);
        return (string)preg_replace('/\s*\n\s*/', "\n", $html);
    }

    private function esperarTurno(): void
    {
        $falta = $this->ultimoPedido + $this->pausaSegundos - microtime(true);
        if ($falta > 0) {
            usleep((int)($falta * 1e6));
        }
        $this->ultimoPedido = microtime(true);
    }

    /** @return array{0:int,1:string,2:?string} */
    private function post(string $url, array $datos): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($datos),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'GranChance/1.0 (estadisticas hipicas)',
        ]);
        $cuerpo = curl_exec($ch);
        $error = $cuerpo === false ? curl_error($ch) : null;
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$codigo, $cuerpo === false ? '' : (string)$cuerpo, $error];
    }
}
