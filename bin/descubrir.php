<?php
// Prueba racetrackId del 1 al N en algunas fechas y muestra a qué hipódromo corresponde cada uno.
// No usa la base. Uso: php bin/descubrir.php [--hasta-id=40] [--fechas=2026-10-11,2026-10-12,...]
require __DIR__ . '/_inicio.php';

use GranChance\ParserResultados;

$opc = opciones($argv);
$maxId = (int)($opc['hasta-id'] ?? 40);
// Por defecto, los últimos 14 días: cubre los días de carrera de todos los hipódromos.
$fechas = isset($opc['fechas']) ? explode(',', (string)$opc['fechas'])
    : array_map(fn ($i) => (new DateTimeImmutable("-$i day"))->format('Y-m-d'), range(1, 14));
$cli = cliente($config);
$parser = new ParserResultados();

for ($id = 1; $id <= $maxId; $id++) {
    foreach ($fechas as $fecha) {
        $d = $cli->bajarResultados($id, $fecha);
        if ($d['estado'] === 'ok') {
            $r = $parser->parsearArchivo($d['archivo']);
            registro(sprintf('racetrackId %d = %s (reunión %s del %s, %d carreras)', $id, $r['hipodromo'], $r['nro_reunion'], $fecha, count($r['carreras'])));
            continue 2;
        }
        if ($d['estado'] === 'error') {
            registro("racetrackId $id, $fecha: error {$d['detalle']}");
        }
    }
    registro("racetrackId $id: sin resultados en esas fechas");
}
