<?php
// Muestra en JSON lo que se lee de una carrera de Japón (desde el crudo si ya se bajó), sin tocar la base.
// Uso: php bin/jp_parsear.php --fecha=2015-12-27 --hipodromo=06 --carrera=10
//      (hipódromo: 01 Sapporo, 02 Hakodate, 03 Fukushima, 04 Niigata, 05 Tokyo, 06 Nakayama, 07 Chukyo, 08 Kyoto, 09 Hanshin, 10 Kokura)
require __DIR__ . '/jp_comun.php';

use GranChance\JP\ParserResultados;

$opc = opciones($argv);
$cli = clienteJp($jp);
$parser = new ParserResultados();
$fecha = (string)$opc['fecha'];
$hip = sprintf('%02d', (int)($opc['hipodromo'] ?? 5));
$r = array_values(array_filter($parser->dia($cli->dia($fecha)), fn($x) => $x['hipodromo'] === $hip))[0] ?? null;
if ($r === null) {
    fwrite(STDERR, "La JRA no tiene reunión en ese hipódromo ese día.\n");
    exit(1);
}
echo json_encode($parser->carrera($cli->carrera($r, (int)($opc['carrera'] ?? 1))), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
