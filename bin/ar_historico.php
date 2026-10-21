<?php
// Carga histórica de Argentina. Lee el calendario del Stud Book mes a mes y baja cada reunión de todos los hipódromos.
// Reanudable: saltea las reuniones ya bajadas.
// Uso: php bin/ar_historico.php [--desde=2015-01-01] [--hasta=AAAA-MM-DD] [--reintentar] [--parte=0/3]
//   --reintentar  vuelve a procesar también las reuniones ya bajadas (desde los crudos guardados)
//   --parte=i/n   reparte las reuniones entre n procesos en paralelo (este hace la i-ésima parte)
require __DIR__ . '/ar_comun.php';

use GranChance\AR\{ParserResultados, Repositorio};

$opc = opciones($argv);
$desde = new DateTimeImmutable($opc['desde'] ?? '2015-01-01');
$hasta = new DateTimeImmutable($opc['hasta'] ?? 'yesterday');
[$parte, $partes] = array_map('intval', explode('/', (string)($opc['parte'] ?? '0/1')) + [1 => 1]);
$cli = clienteAr($ar);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($ar['db']);
$cuenta = ['ok' => 0, 'vacio' => 0, 'error' => 0];

cargarCalendarioAr($cli, $parser, $repo, $desde, $hasta);
foreach ($repo->reuniones($desde->format('Y-m-d'), $hasta->format('Y-m-d'), isset($opc['reintentar']), $parte, $partes) as $r) {
    $cuenta[procesarReunionAr($cli, $parser, $repo, $r, true)]++;
}
registro(sprintf('Fin: %d reuniones, %d sin resultados, %d errores', $cuenta['ok'], $cuenta['vacio'], $cuenta['error']));
