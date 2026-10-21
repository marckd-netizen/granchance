<?php
// Carga histórica de Brasil. Recorre el raceday de apostas.jcb.com.br día por día, anota las reuniones de los hipódromos
// brasileños y baja cada una (programa y resultado completo de cada páreo). Reanudable: saltea los días y reuniones ya bajados.
// Uso: php bin/br_historico.php [--desde=2015-01-01] [--hasta=AAAA-MM-DD] [--reintentar]
//   --reintentar  vuelve a procesar también las reuniones ya bajadas (desde los crudos guardados)
require __DIR__ . '/br_comun.php';

use GranChance\BR\{ParserResultados, Repositorio};

$opc = opciones($argv);
$desde = (new DateTimeImmutable($opc['desde'] ?? '2015-01-01'))->format('Y-m-d');
$hasta = (new DateTimeImmutable($opc['hasta'] ?? 'yesterday'))->format('Y-m-d');
$cli = clienteBr($br);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($br['db']);
$cuenta = ['ok' => 0, 'vacio' => 0, 'error' => 0];

cargarDias($cli, $parser, $repo, $br, $desde, $hasta, true);
foreach ($repo->reuniones($desde, $hasta, isset($opc['reintentar'])) as $r) {
    $cuenta[procesarReunion($cli, $parser, $repo, $br, $r, true)]++;
}
registro(sprintf('Fin: %d reuniones, %d sin resultados, %d errores', $cuenta['ok'], $cuenta['vacio'], $cuenta['error']));
