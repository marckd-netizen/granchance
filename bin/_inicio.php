<?php
// Carga común de los scripts de bin/: autoload, config y helpers de línea de comandos.
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use GranChance\{Cliente, ParserResultados, Repositorio};

$rutaConfig = __DIR__ . '/../config.php';
if (!is_file($rutaConfig)) {
    fwrite(STDERR, "Falta config.php (copiar config.example.php y completarlo).\n");
    exit(1);
}
$config = require $rutaConfig;

function opciones(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $o[$m[1]] = $m[2] ?? true;
        }
    }
    return $o;
}

function hipodromos(array $opc, array $config): array
{
    return isset($opc['hipodromos']) ? array_map('intval', explode(',', (string)$opc['hipodromos'])) : $config['hipodromos'];
}

function cliente(array $config): Cliente
{
    return new Cliente($config['url_resultados'], $config['carpeta_crudos'], (float)$config['pausa_segundos']);
}

function registro(string $msg): void
{
    echo date('Y-m-d H:i:s'), ' ', $msg, "\n";
}

/** Baja, lee y guarda un (hipódromo, fecha). Devuelve el estado de la descarga. */
function procesarDia(Cliente $cli, ParserResultados $parser, Repositorio $repo, int $hip, string $fecha, bool $usarCache): string
{
    $d = $cli->bajarResultados($hip, $fecha, $usarCache);
    if ($d['estado'] === 'ok') {
        try {
            $r = $parser->parsearArchivo($d['archivo']);
            $n = $repo->guardarReunion($hip, $fecha, $r, $d['archivo']);
            $d['detalle'] = "$n carreras";
            registro("$fecha hip $hip: {$r['hipodromo']}, $n carreras");
        } catch (\Throwable $e) {
            $d['estado'] = 'error';
            $d['detalle'] = 'lectura: ' . $e->getMessage();
            registro("$fecha hip $hip: ERROR al leer {$d['archivo']}: {$e->getMessage()}");
        }
    } elseif ($d['estado'] === 'error') {
        registro("$fecha hip $hip: ERROR de descarga: {$d['detalle']}");
    }
    $repo->registrarDescarga($hip, $fecha, $d);
    return $d['estado'];
}
