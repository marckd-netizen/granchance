<?php
// Muestra en JSON lo que se lee de una carrera de Hong Kong (desde el crudo si ya se bajó), sin tocar la base.
// Uso: php bin/hk_parsear.php --fecha=2015-10-14 --hipodromo=HV [--carrera=1]
require __DIR__ . '/hk_comun.php';

use GranChance\HK\ParserResultados;

$opc = opciones($argv);
$cli = clienteHk($hk);
$parser = new ParserResultados();
$html = $cli->resultado((string)$opc['fecha'], strtoupper((string)($opc['hipodromo'] ?? 'ST')), (int)($opc['carrera'] ?? 1));
if ($html === null) {
    fwrite(STDERR, "Sin resultados.\n");
    exit(1);
}
$c = $parser->carrera($html);
$par = $cli->parciales((string)$opc['fecha'], strtoupper((string)($opc['hipodromo'] ?? 'ST')));
$c['parciales_caballos'] = $par === null ? [] : ($parser->parciales($par)[$c['numero']] ?? []);
echo json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
