<?php
// Carga común de los scripts bin/jp_*.php (Japón, JRA): config, cliente, parser y la función que baja una reunión.
declare(strict_types=1);
require __DIR__ . '/_inicio.php';

use GranChance\JP\{Cliente, ParserResultados, Repositorio};

if (!isset($config['jp'])) {
    fwrite(STDERR, "Falta la sección 'jp' en config.php (ver config.example.php).\n");
    exit(1);
}
$jp = $config['jp'];

function clienteJp(array $jp): Cliente
{
    return new Cliente($jp['carpeta_crudos'], (float)$jp['pausa_segundos']);
}

/**
 * Lee el calendario de cada año entre dos fechas y anota las reuniones (hipódromo, reunión, día y horas) de cada día
 * que todavía no estaba. Los días de la última semana y los que vienen se vuelven a pedir siempre.
 */
function cargarCalendario(Cliente $cli, ParserResultados $parser, Repositorio $repo, DateTimeImmutable $desde, DateTimeImmutable $hasta): void
{
    [$d, $h] = [$desde->format('Y-m-d'), $hasta->format('Y-m-d')];
    $anotados = $repo->diasAnotados($d, $h);
    $reciente = date('Y-m-d', strtotime('-7 day'));
    for ($a = (int)$desde->format('Y'); $a <= (int)$hasta->format('Y'); $a++) {
        foreach ($parser->calendario($cli->calendario($a)) as $fecha) {
            if ($fecha < $d || $fecha > $h || (isset($anotados[$fecha]) && $fecha < $reciente)) {
                continue;
            }
            $reuniones = $parser->dia($cli->dia($fecha, $fecha < $reciente));
            if ($repo->registrarDia($reuniones) > 0) {
                registro(sprintf('Calendario %s: %s', $fecha, implode(', ', array_map(fn($r) => ParserResultados::HIPODROMOS[$r['hipodromo']] ?? $r['hipodromo'], $reuniones))));
            }
        }
    }
}

/**
 * Baja la página de cada carrera de una reunión, la lee y la guarda (solo las que ya tienen resultado oficial).
 * @param array{hipodromo: string, fecha: string, kai: string, hi: string, carreras: int} $r
 */
function procesarReunion(Cliente $cli, ParserResultados $parser, Repositorio $repo, array $r, bool $usarCache): string
{
    [$hip, $fecha] = [$r['hipodromo'], $r['fecha']];
    $d = ['estado' => 'ok', 'bytes' => 0, 'detalle' => null];
    $nombre = ParserResultados::HIPODROMOS[$hip] ?? $hip;
    try {
        $carreras = [];
        $sin = [];
        for ($n = 1; $n <= max(1, $r['carreras']); $n++) {
            $pagina = $cli->carrera($r, $n, $usarCache);
            $d['bytes'] += strlen($pagina);
            $c = str_contains($pagina, 'NO DATA') ? null : $parser->carrera($pagina);
            if ($c === null || !$c['oficial'] || $c['actuaciones'] === []) {
                $sin[] = $n;
                if ($usarCache) {
                    @unlink($cli->carpeta($fecha, $hip) . sprintf('/carrera_%02d.html.gz', $n));   // todavía sin resultado: pedirla de nuevo la próxima vez
                }
                continue;
            }
            if ($c['fecha'] !== $fecha || $c['hipodromo'] !== $hip || $c['numero'] !== $n) {
                throw new \RuntimeException("la página de la carrera $n muestra {$c['fecha']} {$c['hipodromo']} {$c['numero']}R");
            }
            $carreras[] = $c;
        }
        for ($intento = 1; ; $intento++) {
            try {
                $repo->guardarReunion($r, $carreras, $cli->carpeta($fecha, $hip));
                break;
            } catch (\PDOException $e) {
                if ($intento >= 3 || !in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
                    throw $e;
                }
                usleep(500000 * $intento);
            }
        }
        $d['detalle'] = sprintf('%d carreras', count($carreras)) . ($sin === [] ? '' : ', sin resultado: ' . implode(' ', $sin));
        if ($carreras === []) {
            $d['estado'] = 'vacio';
        }
        registro("$fecha $nombre: {$d['detalle']}");
    } catch (\Throwable $e) {
        $d = ['estado' => 'error', 'bytes' => 0, 'detalle' => $e->getMessage()];
        registro("$fecha $nombre: ERROR: {$e->getMessage()}");
    }
    $repo->registrarDescarga($hip, $fecha, $d);
    return $d['estado'];
}
