<?php
// Carga común de los scripts bin/hk_*.php (Hong Kong): config, cliente, parser y la función que baja una reunión.
declare(strict_types=1);
require __DIR__ . '/_inicio.php';

use GranChance\HK\{Cliente, ParserResultados, Repositorio};

if (!isset($config['hk'])) {
    fwrite(STDERR, "Falta la sección 'hk' en config.php (ver config.example.php).\n");
    exit(1);
}
$hk = $config['hk'];

function clienteHk(array $hk): Cliente
{
    return new Cliente($hk['carpeta_crudos'], (float)$hk['pausa_segundos']);
}

/** Lee el calendario de cada mes entre dos fechas y anota las reuniones nuevas. */
function cargarCalendario(Cliente $cli, ParserResultados $parser, Repositorio $repo, DateTimeImmutable $desde, DateTimeImmutable $hasta): void
{
    for ($mes = $desde->modify('first day of this month'); $mes <= $hasta; $mes = $mes->modify('+1 month')) {
        [$a, $m] = [(int)$mes->format('Y'), (int)$mes->format('n')];
        $reuniones = $parser->calendario($cli->calendario($a, $m), $a, $m);
        $nuevas = $repo->registrarCalendario($reuniones);
        if ($nuevas > 0) {
            registro(sprintf('Calendario %04d-%02d: %d reuniones (%d nuevas)', $a, $m, count($reuniones), $nuevas));
        }
    }
}

/**
 * Baja los resultados de cada carrera y los parciales de una reunión, los lee y los guarda.
 * @param array{hipodromo: string, fecha: string, turno: ?string, pistas: ?string} $r
 */
function procesarReunion(Cliente $cli, ParserResultados $parser, Repositorio $repo, array $r, bool $usarCache): string
{
    [$hip, $fecha] = [$r['hipodromo'], $r['fecha']];
    $d = ['estado' => 'ok', 'bytes' => 0, 'detalle' => null];
    try {
        $html = $cli->resultado($fecha, $hip, 1, $usarCache);
        $muestra = $html === null ? null : $parser->reunion($html);
        if ($html === null || $muestra === null || $muestra['fecha'] !== $fecha || $muestra['hipodromo'] !== $hip) {
            // Reunión suspendida (tifón) o trasladada: el HKJC no tiene resultados para ese hipódromo y fecha.
            $d = ['estado' => 'vacio', 'bytes' => 0, 'detalle' => $muestra === null ? 'No information' : "la página muestra {$muestra['fecha']} {$muestra['hipodromo']}"];
            registro("$fecha $hip: sin resultados ({$d['detalle']})");
        } else {
            $carreras = [];
            $total = $parser->cantidadCarreras($html);
            for ($n = 1; $n <= $total; $n++) {
                $pagina = $n === 1 ? $html : $cli->resultado($fecha, $hip, $n, $usarCache);
                if ($pagina === null) {
                    continue;       // carrera suspendida
                }
                $d['bytes'] += strlen($pagina);
                $carreras[] = $parser->carrera($pagina);
            }
            $paginaParciales = $cli->parciales($fecha, $hip, $usarCache);
            $parciales = $paginaParciales === null ? [] : $parser->parciales($paginaParciales);
            for ($intento = 1; ; $intento++) {
                try {
                    $repo->guardarReunion($r, $carreras, $parciales, $cli->carpeta($fecha, $hip));
                    break;
                } catch (\PDOException $e) {
                    if ($intento >= 3 || !in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
                        throw $e;
                    }
                    usleep(500000 * $intento);
                }
            }
            $d['detalle'] = sprintf('%d carreras, parciales de %d', count($carreras), count($parciales));
            registro("$fecha $hip: {$d['detalle']}");
        }
    } catch (\Throwable $e) {
        $d = ['estado' => 'error', 'bytes' => 0, 'detalle' => $e->getMessage()];
        registro("$fecha $hip: ERROR: {$e->getMessage()}");
    }
    $repo->registrarDescarga($hip, $fecha, $d);
    return $d['estado'];
}
