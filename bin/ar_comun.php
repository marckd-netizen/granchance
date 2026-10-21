<?php
// Carga común de los scripts bin/ar_*.php (Argentina, Stud Book Argentino): config, cliente y la función que baja una reunión.
declare(strict_types=1);
require __DIR__ . '/_inicio.php';

use GranChance\AR\{Cliente, ParserResultados, Repositorio, SinDatos};

if (!isset($config['ar'])) {
    fwrite(STDERR, "Falta la sección 'ar' en config.php (ver config.example.php).\n");
    exit(1);
}
$ar = $config['ar'];

function clienteAr(array $ar): Cliente
{
    return new Cliente($ar['carpeta_crudos'], (float)$ar['pausa_segundos']);
}

/** Lee el calendario de cada mes entre dos fechas y anota las reuniones nuevas. */
function cargarCalendarioAr(Cliente $cli, ParserResultados $parser, Repositorio $repo, DateTimeImmutable $desde, DateTimeImmutable $hasta): void
{
    for ($mes = $desde->modify('first day of this month'); $mes <= $hasta; $mes = $mes->modify('+1 month')) {
        [$a, $m] = [(int)$mes->format('Y'), (int)$mes->format('n')];
        $reuniones = $parser->calendario($cli->calendario($a, $m));
        $nuevas = $repo->registrarCalendario($reuniones);
        if ($nuevas > 0) {
            registro(sprintf('Calendario %04d-%02d: %d reuniones (%d nuevas)', $a, $m, count($reuniones), $nuevas));
        }
    }
}

/**
 * Baja la página de resultados de una reunión, la lee y la guarda.
 * @param array{sb_id: int, slug: string, hipodromo_id: int, fecha: string} $r
 */
function procesarReunionAr(Cliente $cli, ParserResultados $parser, Repositorio $repo, array $r, bool $usarCache): string
{
    $d = ['estado' => 'ok', 'bytes' => 0, 'detalle' => null];
    try {
        $html = $cli->reunion((int)$r['sb_id'], $r['slug'], $usarCache);
        $d['bytes'] = strlen($html);
        $res = $parser->reunion($html);
        for ($intento = 1; ; $intento++) {
            try {
                $n = $repo->guardarReunion($r, $res, $cli->archivoReunion((int)$r['sb_id'], $r['slug']));
                break;
            } catch (\PDOException $e) {
                if ($intento >= 6 || !in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
                    throw $e;
                }
                usleep(500000 * $intento);
            }
        }
        $d['detalle'] = "$n carreras";
        if ($n === 0) {
            $d['estado'] = 'vacio';     // reunión suspendida
        }
        registro("{$r['slug']}: $n carreras");
    } catch (SinDatos $e) {
        $d = ['estado' => 'vacio', 'bytes' => 0, 'detalle' => $e->getMessage()];
        registro("{$r['slug']}: sin resultados ({$e->getMessage()})");
    } catch (\Throwable $e) {
        $d = ['estado' => 'error', 'bytes' => 0, 'detalle' => $e->getMessage()];
        registro("{$r['slug']}: ERROR: {$e->getMessage()}");
    }
    $repo->registrarDescarga((int)$r['sb_id'], $d);
    return $d['estado'];
}
