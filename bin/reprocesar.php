<?php
// Vuelve a leer los Excel ya bajados (raw/) y re-carga esas reuniones, sin pedir nada al servicio.
// Sirve después de corregir el lector. Uso: php bin/reprocesar.php [--desde=AAAA-MM-DD] [--hasta=AAAA-MM-DD]
require __DIR__ . '/_inicio.php';

use GranChance\{ParserResultados, Repositorio};

$opc = opciones($argv);
$cli = cliente($config);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($config['db']);
$cuenta = ['ok' => 0, 'vacio' => 0, 'error' => 0];

$archivos = glob($config['carpeta_crudos'] . '/*/*/*.xls');
sort($archivos);
foreach ($archivos as $archivo) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})_(\d+)\.xls$/', $archivo, $m)) {
        continue;
    }
    [, $fecha, $hip] = $m;
    if ((isset($opc['desde']) && $fecha < $opc['desde']) || (isset($opc['hasta']) && $fecha > $opc['hasta'])) {
        continue;
    }
    $cuenta[procesarDia($cli, $parser, $repo, (int)$hip, $fecha, true)]++;
}
registro(sprintf('Fin: %d reuniones, %d errores', $cuenta['ok'], $cuenta['error']));
