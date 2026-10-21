<?php
// Muestra en JSON lo que se lee de una reunión de Argentina (desde el crudo si ya se bajó), sin tocar la base.
// Uso: php bin/ar_parsear.php --id=16141 --slug=20150102-san-isidro-1 [--carrera=1]
require __DIR__ . '/ar_comun.php';

use GranChance\AR\ParserResultados;

$opc = opciones($argv);
$r = (new ParserResultados())->reunion(clienteAr($ar)->reunion((int)$opc['id'], (string)$opc['slug']));
if (isset($opc['carrera'])) {
    $r['carreras'] = array_values(array_filter($r['carreras'], fn($c) => $c['numero'] === (int)$opc['carrera']));
}
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
