<?php
// Carga común de los scripts bin/br_*.php (Brasil, apostas.jcb.com.br): config, cliente, parser y las funciones que bajan un día y una reunión.
declare(strict_types=1);
require __DIR__ . '/_inicio.php';

use GranChance\BR\{Cliente, ParserResultados, Repositorio};

if (!isset($config['br'])) {
    fwrite(STDERR, "Falta la sección 'br' en config.php (ver config.example.php).\n");
    exit(1);
}
$br = $config['br'];

function clienteBr(array $br): Cliente
{
    return new Cliente($br['carpeta_crudos'], (float)$br['pausa_segundos']);
}

/** Nombre del hipódromo como se guarda: "Gávea Int" y "Gávea Extra" son reuniones aparte en la Gávea. */
function hipodromoBr(array $br, string $pestana): ?string
{
    $nombre = $br['alias'][$pestana] ?? $pestana;
    return in_array($nombre, $br['hipodromos'], true) ? $nombre : null;
}

/**
 * Lee el raceday de un día y anota sus reuniones brasileñas (las pestañas de hipódromos de config 'hipodromos'; el resto
 * son reuniones extranjeras que el JCB transmite). Si ese día no hay carreras, la página muestra la próxima reunión:
 * devuelve la fecha de esa reunión, para saltar los días intermedios.
 */
function procesarDiaBr(Cliente $cli, ParserResultados $parser, Repositorio $repo, array $br, string $fecha, string $hasta, bool $usarCache): string
{
    $html = $cli->dia($fecha, $usarCache);
    $d = $parser->dia($html);
    $fechaReunion = $d['fecha'] ?? $fecha;
    if ($fechaReunion > $hasta) {
        return $fechaReunion;       // reunión futura: el día se vuelve a consultar en la próxima carga
    }
    $repo->registrarDia($fecha, $d['fecha'], $d['reuniones']);
    $nuevas = [];
    foreach ($d['reuniones'] as $r) {
        $hip = hipodromoBr($br, $r['hipodromo']);
        if ($hip === null || $r['id'] === null) {
            continue;
        }
        if ($r['activa'] && (!$usarCache || !is_file($cli->carpeta($fechaReunion, $r['id']) . '/reuniao.html.gz'))) {
            $cli->guardarReunion($fechaReunion, $r['id'], $html);
        }
        if ($repo->registrarReunion($r['id'], $hip, $fechaReunion, $r['nro'])) {
            $nuevas[] = "{$r['hipodromo']} {$r['nro']}";
        }
    }
    if ($nuevas !== []) {
        registro("$fechaReunion: " . implode(', ', $nuevas));
    }
    return $fechaReunion;
}

/** Recorre los días entre dos fechas (saltando los que no tienen carreras) y anota las reuniones. Con caché, los días ya bajados se leen del crudo. */
function cargarDias(Cliente $cli, ParserResultados $parser, Repositorio $repo, array $br, string $desde, string $hasta, bool $usarCache): void
{
    for ($f = $desde; $f <= $hasta;) {
        $fr = procesarDiaBr($cli, $parser, $repo, $br, $f, $hasta, $usarCache);
        $f = (new DateTimeImmutable(max($f, $fr)))->modify('+1 day')->format('Y-m-d');
    }
}

/**
 * Baja el programa de una reunión y el resultado completo de cada páreo, los lee y los guarda.
 * @param array{id: int, hipodromo: string, fecha: string, nro: ?int} $r
 */
function procesarReunion(Cliente $cli, ParserResultados $parser, Repositorio $repo, array $br, array $r, bool $usarCache): string
{
    $d = ['estado' => 'ok', 'bytes' => 0, 'detalle' => null];
    $etiqueta = "{$r['fecha']} {$r['hipodromo']} {$r['nro']}";
    try {
        $html = $cli->reunion($r['fecha'], $r['id'], $usarCache);
        $d['bytes'] += strlen($html);
        $prog = $parser->reunion($html);
        if ($prog['fecha'] !== $r['fecha'] || hipodromoBr($br, (string)$prog['hipodromo']) !== $r['hipodromo']) {
            throw new \RuntimeException("la página muestra {$prog['fecha']} {$prog['hipodromo']}");
        }
        $resultados = [];
        foreach ($prog['carreras'] as $c) {
            if (!str_contains($html, "resultadocompleto?idReuniao={$r['id']}&amp;numeroPareo={$c['numero']}\"")) {
                continue;       // páreo sin resultado (suspendido o reunión futura)
            }
            $pagina = $cli->resultado($r['fecha'], $r['id'], $c['numero'], $usarCache);
            $d['bytes'] += strlen($pagina);
            $resultados[$c['numero']] = $parser->resultado($pagina);
        }
        for ($intento = 1; ; $intento++) {
            try {
                $n = $repo->guardarReunion($r, $prog, $resultados, $cli->carpeta($r['fecha'], $r['id']));
                break;
            } catch (\PDOException $e) {
                if ($intento >= 3 || !in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
                    throw $e;
                }
                usleep(500000 * $intento);
            }
        }
        $d['detalle'] = sprintf('%d páreos, %d con resultado completo', $n, count($resultados));
        if ($n === 0) {
            $d['estado'] = 'vacio';     // reunión suspendida o sin resultados
        }
        registro("$etiqueta: {$d['detalle']}");
    } catch (\Throwable $e) {
        $d = ['estado' => 'error', 'bytes' => 0, 'detalle' => $e->getMessage()];
        registro("$etiqueta: ERROR: {$e->getMessage()}");
    }
    $repo->registrarDescarga($r['id'], $d);
    return $d['estado'];
}
