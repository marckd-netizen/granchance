<?php
// Muestra en JSON lo que se lee de un páreo de Brasil (desde el crudo si ya se bajó), sin tocar la base.
// Uso: php bin/br_parsear.php --fecha=2015-01-03 --reuniao=42007195 [--pareo=1]
require __DIR__ . '/br_comun.php';

use GranChance\BR\ParserResultados;

$opc = opciones($argv);
$cli = clienteBr($br);
$parser = new ParserResultados();
[$fecha, $id, $nro] = [(string)$opc['fecha'], (int)$opc['reuniao'], (int)($opc['pareo'] ?? 1)];
$prog = $parser->reunion($cli->reunion($fecha, $id));
$c = array_values(array_filter($prog['carreras'], fn($c) => $c['numero'] === $nro))[0] ?? null;
if ($c === null) {
    fwrite(STDERR, "La reunión no tiene el páreo $nro.\n");
    exit(1);
}
$c['resultado'] = $parser->resultado($cli->resultado($fecha, $id, $nro));
unset($prog['carreras']);
echo json_encode($prog + ['pareo' => $c], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
