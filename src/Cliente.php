<?php
declare(strict_types=1);

namespace GranChance;

/**
 * Baja el Excel de resultados de un hipódromo en una fecha y guarda el crudo en
 * raw/AAAA/MM/AAAA-MM-DD_<hipodromo>.xls, para poder re-procesar sin volver a pedirlo.
 */
final class Cliente
{
    private float $ultimoPedido = 0.0;

    public function __construct(
        private string $urlPlantilla,
        private string $carpetaCrudos,
        private float $pausaSegundos = 2.0,
        private int $reintentos = 3,
    ) {
    }

    /**
     * @return array{estado: 'ok'|'vacio'|'error', archivo: ?string, bytes: int, detalle: ?string}
     */
    public function bajarResultados(int $hipodromo, string $fecha, bool $usarCache = true): array
    {
        $archivo = sprintf('%s/%s/%s_%d.xls', $this->carpetaCrudos, str_replace('-', '/', substr($fecha, 0, 7)), $fecha, $hipodromo);
        if ($usarCache && is_file($archivo) && filesize($archivo) > 0) {
            return ['estado' => 'ok', 'archivo' => $archivo, 'bytes' => (int)filesize($archivo), 'detalle' => 'cache'];
        }

        $url = strtr($this->urlPlantilla, ['{hipodromo}' => (string)$hipodromo, '{fecha}' => $fecha]);
        $detalle = null;
        for ($intento = 1; $intento <= $this->reintentos; $intento++) {
            $this->esperarTurno();
            [$codigo, $cuerpo, $error, $destino] = $this->get($url);
            // Sin carreras ese día (o hipódromo inexistente), el servicio redirige a
            // hipica.maronas.com.uy/RacingInfo/Errors?errorCode=ED0003 en vez de devolver vacío.
            if ($error === null && $codigo >= 300 && $codigo < 400) {
                parse_str((string)parse_url((string)$destino, PHP_URL_QUERY), $q);
                return ['estado' => 'vacio', 'archivo' => null, 'bytes' => 0, 'detalle' => $q['errorCode'] ?? "HTTP $codigo"];
            }
            if ($error === null && $codigo === 200) {
                // El servicio responde vacío cuando ese día no hubo carreras en ese hipódromo.
                if (!self::esExcel($cuerpo)) {
                    return ['estado' => 'vacio', 'archivo' => null, 'bytes' => strlen($cuerpo), 'detalle' => null];
                }
                if (!is_dir(dirname($archivo))) {
                    mkdir(dirname($archivo), 0775, true);
                }
                file_put_contents($archivo, $cuerpo);
                return ['estado' => 'ok', 'archivo' => $archivo, 'bytes' => strlen($cuerpo), 'detalle' => null];
            }
            $detalle = $error ?? "HTTP $codigo";
            sleep($intento * 5);
        }
        return ['estado' => 'error', 'archivo' => null, 'bytes' => 0, 'detalle' => $detalle];
    }

    /** Excel 97-2003 (OLE2) o .xlsx (zip). */
    private static function esExcel(string $cuerpo): bool
    {
        return str_starts_with($cuerpo, "\xD0\xCF\x11\xE0") || str_starts_with($cuerpo, "PK\x03\x04");
    }

    private function esperarTurno(): void
    {
        $falta = $this->ultimoPedido + $this->pausaSegundos - microtime(true);
        if ($falta > 0) {
            usleep((int)($falta * 1e6));
        }
        $this->ultimoPedido = microtime(true);
    }

    /** @return array{0:int,1:string,2:?string,3:?string} */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => 'GranChance/1.0 (estadisticas hipicas)',
        ]);
        $cuerpo = curl_exec($ch);
        $error = $cuerpo === false ? curl_error($ch) : null;
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $destino = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        return [$codigo, $cuerpo === false ? '' : (string)$cuerpo, $error, $destino];
    }
}
