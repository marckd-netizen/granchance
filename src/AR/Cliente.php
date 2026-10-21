<?php
declare(strict_types=1);

namespace GranChance\AR;

/**
 * Baja las páginas del Stud Book Argentino (calendario de reuniones, resultados de una reunión y ficha de un ejemplar)
 * y guarda el crudo en raw/ar/..., comprimido y sin menús ni scripts, para poder re-procesar sin volver a pedirlo.
 */
final class Cliente
{
    private const BASE = 'https://www.studbook.org.ar/';
    // Con un User-Agent que no parezca un navegador, el sitio responde 403.
    private const AGENTE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    private float $ultimoPedido = 0.0;

    public function __construct(
        private string $carpetaCrudos,
        private float $pausaSegundos = 1.0,
        private int $reintentos = 3,
    ) {
    }

    /** Calendario del mes (/reuniones?anio=&mes=). Los meses cerrados se guardan; el actual y los futuros se piden siempre. */
    public function calendario(int $anio, int $mes): string
    {
        $archivo = sprintf('%s/calendario/%04d-%02d.html.gz', $this->carpetaCrudos, $anio, $mes);
        $cerrado = sprintf('%04d-%02d', $anio, $mes) < date('Y-m');
        return $this->pagina(self::BASE . sprintf('reuniones?anio=%04d&mes=%02d', $anio, $mes), $archivo, $cerrado, 'class="calendario', 'cal-dia-');
    }

    /**
     * Resultados de todas las carreras de una reunión. $slug es la parte final del enlace del calendario
     * ("20150102-san-isidro-1"); sin él, el sitio responde 404.
     */
    public function reunion(int $id, string $slug, bool $usarCache = true): string
    {
        return $this->pagina(self::BASE . "reuniones/detalle/$id/$slug", $this->archivoReunion($id, $slug), $usarCache, '<section class="carreras', 'carrera-cabecera-titulo');
    }

    public function archivoReunion(int $id, string $slug): string
    {
        // 20150102-san-isidro-1 → raw/ar/2015/01/20150102-san-isidro-1_16141.html.gz
        return sprintf('%s/%s/%s/%s_%d.html.gz', $this->carpetaCrudos, substr($slug, 0, 4), substr($slug, 4, 2), $slug, $id);
    }

    /** Ficha de un ejemplar (país, nacimiento, padres, criador). También pide el slug del enlace ("title-holder"). */
    public function ejemplar(int $id, string $slug, bool $usarCache = true): string
    {
        $archivo = sprintf('%s/ejemplares/%03d/%d.html.gz', $this->carpetaCrudos, intdiv($id, 1000), $id);
        return $this->pagina(self::BASE . "ejemplares/perfil/$id/$slug", $archivo, $usarCache, null, '/banderas/');
    }

    private function pagina(string $url, string $archivo, bool $usarCache, ?string $desde, string $marca): string
    {
        if ($usarCache && is_file($archivo) && filesize($archivo) > 0) {
            return (string)gzdecode((string)file_get_contents($archivo));
        }
        $detalle = null;
        for ($intento = 1; $intento <= $this->reintentos; $intento++) {
            $this->esperarTurno();
            [$codigo, $cuerpo, $error] = $this->get($url);
            if ($error === null && $codigo === 200 && str_contains($cuerpo, '</html>')) {
                if (!str_contains($cuerpo, $marca)) {
                    throw new SinDatos("$url: la página no trae datos");
                }
                $html = self::recortar($cuerpo, $desde);
                if (!is_dir(dirname($archivo))) {
                    mkdir(dirname($archivo), 0775, true);
                }
                file_put_contents($archivo, gzencode($html, 9));
                return $html;
            }
            if ($codigo === 404) {
                throw new SinDatos("$url: HTTP 404");
            }
            $detalle = $error ?? "HTTP $codigo";
            sleep($intento * 5);
        }
        throw new \RuntimeException("$url: $detalle");
    }

    /** Saca scripts, estilos, opciones de selectores, el menú y el pie: la página pesa ~400 KB y lo que se usa, ~300 KB (30 KB comprimido). */
    private static function recortar(string $html, ?string $desde): string
    {
        $html = (string)preg_replace('#<script\b.*?</script>|<style\b.*?</style>|<option\b.*?</option>|<!--.*?-->#si', '', $html);
        $i = $desde === null ? false : strpos($html, $desde);
        if ($i === false) {
            $i = stripos($html, '<body');
        }
        if ($i !== false) {
            $html = '<html><head><meta charset="utf-8"></head><body>' . substr($html, $i);
        }
        $f = strpos($html, '<footer');
        if ($f !== false) {
            $html = substr($html, 0, $f) . '</body></html>';
        }
        return (string)preg_replace('#[ \t]*\n\s*#', "\n", $html);
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
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::AGENTE,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: es-AR,es;q=0.9'],
        ]);
        $cuerpo = curl_exec($ch);
        $error = $cuerpo === false ? curl_error($ch) : null;
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$codigo, $cuerpo === false ? '' : (string)$cuerpo, $error];
    }
}
