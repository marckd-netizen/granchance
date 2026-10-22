<?php
// Carga histórica de Japón. Lee el calendario de la JRA año por año, anota las reuniones de cada día y baja cada carrera.
// Reanudable: saltea las reuniones ya bajadas.
// Uso: php bin/jp_historico.php [--desde=2015-01-01] [--hasta=AAAA-MM-DD] [--reintentar] [--parte=0/4]
//   --reintentar  vuelve a procesar también las reuniones ya bajadas (desde los crudos guardados)
//   --parte=i/n   reparte las reuniones entre n procesos en paralelo (este hace la i-ésima); el calendario lo carga el 0
require __DIR__ . '/jp_comun.php';

use GranChance\JP\{ParserResultados, Repositorio};

$opc = opciones($argv);
$desde = new DateTimeImmutable($opc['desde'] ?? '2015-01-01');
$hasta = new DateTimeImmutable($opc['hasta'] ?? 'yesterday');
[$parte, $partes] = array_map('intval', explode('/', (string)($opc['parte'] ?? '0/1')) + [1 => 1]);
$cli = clienteJp($jp);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($jp['db']);
$cuenta = ['ok' => 0, 'vacio' => 0, 'error' => 0];

if ($parte === 0) {
    cargarCalendario($cli, $parser, $repo, $desde, $hasta);
}
foreach ($repo->reuniones($desde->format('Y-m-d'), $hasta->format('Y-m-d'), isset($opc['reintentar'])) as $i => $r) {
    if ($i % max(1, $partes) === $parte) {
        $cuenta[procesarReunion($cli, $parser, $repo, $r, true)]++;
    }
}
registro(sprintf('Fin: %d reuniones, %d sin resultados, %d errores', $cuenta['ok'], $cuenta['vacio'], $cuenta['error']));
