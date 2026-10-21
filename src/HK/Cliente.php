<?php
declare(strict_types=1);

namespace GranChance\HK;

/**
 * Baja las páginas del HKJC (calendario, resultados de cada carrera y Sectional Time & Position) y guarda el crudo en
 * raw/hk/AAAA/MM/AAAA-MM-DD_<ST|HV>/..., comprimido y sin menús ni scripts, para poder re-procesar sin volver a pedirlo.
 */
final class Cliente
{
    private const BASE = 'https://racing.hkjc.com/en-us/local/information/';
    private float $ultimoPedido = 0.0;

    public function __construct(
        private string $carpetaCrudos,
        private float $pausaSegundos = 1.0,
        private int $reintentos = 3,
    ) {
    }

    /** Calendario del mes (Fixtures). Los meses pasados se guardan; el actual y los futuros se piden siempre. */
    public function calendario(int $anio, int $mes): string
    {
        $archivo = sprintf('%s/calendario/%04d-%02d.html.gz', $this->carpetaCrudos, $anio, $mes);
        $cerrado = sprintf('%04d-%02d', $anio, $mes) < date('Y-m');
        return $this->pagina(self::BASE . sprintf("fixture?calyear=%04d&calmonth=%02d", $anio, $mes), $archivo, $cerrado, 'fixture');
    }

    /** Resultados de una carrera. Devuelve null si el HKJC responde "No information". */
    public function resultado(string $fecha, string $hip, int $nro, bool $usarCache = true): ?string
    {
        $url = self::BASE . 'localresults?racedate=' . str_replace('-', '/', $fecha) . "&Racecourse=$hip&RaceNo=$nro";
        $html = $this->pagina($url, $this->carpeta($fecha, $hip) . sprintf('/carrera_%02d.html.gz', $nro), $usarCache, 'localResults');
        return str_contains($html, 'performance') ? $html : null;
    }

    /** Sectional Time & Position de todas las carreras de la reunión en una sola página. */
    public function parciales(string $fecha, string $hip, bool $usarCache = true): ?string
    {
        [$a, $m, $d] = explode('-', $fecha);
        $url = self::BASE . "displaysectionaltime?racedate=$d/$m/$a&All=True";
        $html = $this->pagina($url, $this->carpeta($fecha, $hip) . '/parciales.html.gz', $usarCache, 'dispalySectionalTime');
        return str_contains($html, 'race_table') ? $html : null;
    }

    /**
     * Programa oficial (Race Card) de una carrera: se pide siempre (cambia hasta la largada: retirados, jockeys, pesos),
     * y queda guardado en la carpeta de la reunión como programa_NN.html.gz.
     */
    public function programa(string $fecha, string $hip, int $nro): string
    {
        $url = self::BASE . 'racecard?racedate=' . str_replace('-', '/', $fecha) . "&Racecourse=$hip&RaceNo=$nro";
        return $this->pagina($url, $this->carpeta($fecha, $hip) . sprintf('/programa_%02d.html.gz', $nro), false, 'commContent');
    }

    /** Ficha de un caballo (país, pelaje, sexo, padres, propietario). */
    public function caballo(string $codigo, bool $usarCache = true): string
    {
        $archivo = sprintf('%s/caballos/%s.html.gz', $this->carpetaCrudos, $codigo);
        return $this->pagina(self::BASE . 'horse?horseid=' . urlencode($codigo), $archivo, $usarCache, 'commContent');
    }

    public function carpeta(string $fecha, string $hip): string
    {
        return sprintf('%s/%s/%s_%s', $this->carpetaCrudos, str_replace('-', '/', substr($fecha, 0, 7)), $fecha, $hip);
    }

    private function pagina(string $url, string $archivo, bool $usarCache, string $contenedor): string
    {
        if ($usarCache && is_file($archivo) && filesize($archivo) > 0) {
            return (string)gzdecode((string)file_get_contents($archivo));
        }
        $detalle = null;
        for ($intento = 1; $intento <= $this->reintentos; $intento++) {
            $this->esperarTurno();
            [$codigo, $cuerpo, $error] = $this->get($url);
            if ($error === null && $codigo === 200 && str_contains($cuerpo, '</html>')) {
                $html = self::recortar($cuerpo, $contenedor);
                if (!is_dir(dirname($archivo))) {
                    mkdir(dirname($archivo), 0775, true);
                }
                file_put_contents($archivo, gzencode($html, 9));
                return $html;
            }
            $detalle = $error ?? "HTTP $codigo";
            sleep($intento * 5);
        }
        throw new \RuntimeException("$url: $detalle");
    }

    /**
     * Saca scripts, estilos, la lista de fechas del selector y todo lo anterior al contenedor de datos:
     * la página completa pesa ~200 KB y lo que se usa, ~30 KB.
     */
    private static function recortar(string $html, string $contenedor): string
    {
        $html = (string)preg_replace('#<script\b.*?</script>|<style\b.*?</style>|<option\b.*?</option>#si', '', $html);
        $i = strpos($html, 'class="' . $contenedor);
        if ($i !== false) {
            $html = '<html><head><meta charset="utf-8"></head><body><div ' . substr($html, $i);
        }
        $f = strpos($html, '<footer');
        if ($f !== false) {
            $html = substr($html, 0, $f) . '</body></html>';
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
            CURLOPT_FOLLOWLOCATION => true,         // las fechas viejas redirigen a /archive/
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
