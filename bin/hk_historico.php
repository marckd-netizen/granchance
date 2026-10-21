<?php
// Carga histórica de Hong Kong. Lee el calendario oficial (Fixtures) mes a mes y baja cada reunión de Sha Tin y Happy Valley.
// Reanudable: saltea las reuniones ya bajadas.
// Uso: php bin/hk_historico.php [--desde=2015-01-01] [--hasta=AAAA-MM-DD] [--reintentar]
//   --reintentar  vuelve a procesar también las reuniones ya bajadas (desde los crudos guardados)
require __DIR__ . '/hk_comun.php';

use GranChance\HK\{ParserResultados, Repositorio};

$opc = opciones($argv);
$desde = new DateTimeImmutable($opc['desde'] ?? '2015-01-01');
$hasta = new DateTimeImmutable($opc['hasta'] ?? 'yesterday');
$cli = clienteHk($hk);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($hk['db']);
$cuenta = ['ok' => 0, 'vacio' => 0, 'error' => 0];

cargarCalendario($cli, $parser, $repo, $desde, $hasta);
foreach ($repo->reuniones($desde->format('Y-m-d'), $hasta->format('Y-m-d'), isset($opc['reintentar'])) as $r) {
    $cuenta[procesarReunion($cli, $parser, $repo, $r, true)]++;
}
registro(sprintf('Fin: %d reuniones, %d sin resultados, %d errores', $cuenta['ok'], $cuenta['vacio'], $cuenta['error']));
