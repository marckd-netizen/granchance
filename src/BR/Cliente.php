<?php
declare(strict_types=1);

namespace GranChance\BR;

/**
 * Baja las páginas de apostas.jcb.com.br (raceday de cada día, cada reunión y el resultado completo de cada páreo) y guarda
 * el crudo en raw/br/..., comprimido y sin menús ni scripts, para poder re-procesar sin volver a pedirlo.
 */
final class Cliente
{
    private const BASE = 'https://apostas.jcb.com.br/pt-br/';
    private float $ultimoPedido = 0.0;

    public function __construct(
        private string $carpetaCrudos,
        private float $pausaSegundos = 0.5,
        private int $reintentos = 3,
    ) {
    }

    /**
     * Raceday de un día: las pestañas de las reuniones de ese día y el detalle de la primera. Si ese día no hay carreras,
     * muestra la próxima reunión. Los días pasados se guardan en raw/br/dias/AAAA/AAAA-MM-DD.html.gz.
     */
    public function dia(string $fecha, bool $usarCache = true): string
    {
        $archivo = sprintf('%s/dias/%s/%s.html.gz', $this->carpetaCrudos, substr($fecha, 0, 4), $fecha);
        return $this->pagina(self::BASE . "raceday?dia=$fecha", $archivo, $usarCache && $fecha < date('Y-m-d'), 'id="start"');
    }

    /** Raceday de una reunión (programa con todos los inscriptos, cuotas, pesos y los primeros puestos). */
    public function reunion(string $fecha, int $id, bool $usarCache = true): string
    {
        return $this->pagina(self::BASE . "raceday?dia=$fecha&idReuniao=$id", $this->carpeta($fecha, $id) . '/reuniao.html.gz', $usarCache, 'id="start"');
    }

    /** Guarda como crudo de la reunión la página del día, cuando la reunión es la pestaña activa (evita pedirla de nuevo). */
    public function guardarReunion(string $fecha, int $id, string $html): void
    {
        self::escribir($this->carpeta($fecha, $id) . '/reuniao.html.gz', self::recortar($html, 'id="start"'));
    }

    /** Resultado completo de un páreo: orden de llegada con cuerpos, tiempo, criador, chamada y dividendos. */
    public function resultado(string $fecha, int $id, int $nro, bool $usarCache = true): string
    {
        $url = self::BASE . "corridas/resultadocompleto?idReuniao=$id&numeroPareo=$nro";
        return $this->pagina($url, $this->carpeta($fecha, $id) . sprintf('/pareo_%02d.html.gz', $nro), $usarCache, 'class="match-select"');
    }

    public function carpeta(string $fecha, int $id): string
    {
        return sprintf('%s/%s/%s_%d', $this->carpetaCrudos, str_replace('-', '/', substr($fecha, 0, 7)), $fecha, $id);
    }

    private function pagina(string $url, string $archivo, bool $usarCache, string $contenedor): string
    {
        if ($usarCache && is_file($archivo) && filesize($archivo) > 0) {
            return self::recortar((string)gzdecode((string)file_get_contents($archivo)), $contenedor);
        }
        $detalle = null;
        for ($intento = 1; $intento <= $this->reintentos; $intento++) {
            $this->esperarTurno();
            [$codigo, $cuerpo, $error] = $this->get($url);
            if ($error === null && $codigo === 200 && str_contains($cuerpo, '</html>')) {
                $html = self::recortar($cuerpo, $contenedor);
                self::escribir($archivo, $html);
                return $html;
            }
            $detalle = $error ?? "HTTP $codigo";
            sleep($intento * 5);
        }
        throw new \RuntimeException("$url: $detalle");
    }

    private static function escribir(string $archivo, string $html): void
    {
        if (!is_dir(dirname($archivo))) {
            mkdir(dirname($archivo), 0775, true);
        }
        file_put_contents($archivo, gzencode($html, 9));
    }

    /**
     * Saca scripts, estilos, los menús, el carrito de apuestas, los videos y el pie: la página completa pesa
     * 200-400 KB y lo que se usa, menos de la mitad. Es idempotente (se aplica también a los crudos guardados enteros).
     */
    private static function recortar(string $html, string $contenedor): string
    {
        $html = (string)preg_replace('#<script\b.*?</script>|<style\b.*?</style>|<noscript\b.*?</noscript>#si', '', $html);
        $i = strpos($html, $contenedor);
        if ($i !== false) {
            $i = (int)strrpos(substr($html, 0, $i), '<');
            $html = '<html><head><meta charset="utf-8"></head><body>' . substr($html, $i);
        }
        foreach (['<div id="videochart"', 'class="footer-linha-logos-topo'] as $fin) {
            $f = strpos($html, $fin);
            if ($f !== false) {
                $html = substr($html, 0, (int)strrpos(substr($html, 0, $f + 1), '<')) . '</body></html>';
                break;
            }
        }
        return $html;
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
            CURLOPT_USERAGENT => 'GranChance/1.0 (estadisticas hipicas)',
        ]);
        $cuerpo = curl_exec($ch);
        $error = $cuerpo === false ? curl_error($ch) : null;
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$codigo, $cuerpo === false ? '' : (string)$cuerpo, $error];
    }
}
