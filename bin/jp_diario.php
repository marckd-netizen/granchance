<?php
// Carga diaria de Japón: vuelve a pedir las reuniones de los últimos días (por defecto 3) sin usar los crudos,
// para tomar correcciones (la JRA corrige puestos y dividendos tras una revisión).
// Uso: php bin/jp_diario.php [--dias=3]
// Cron sugerido: 30 10 * * * php /ruta/granchance/bin/jp_diario.php >> /ruta/granchance/raw/jp/diario.log 2>&1
require __DIR__ . '/jp_comun.php';

use GranChance\JP\{ParserResultados, Repositorio};

$opc = opciones($argv);
$dias = max(1, (int)($opc['dias'] ?? 3));
$desde = new DateTimeImmutable("-$dias day");
$hasta = new DateTimeImmutable('today');
$cli = clienteJp($jp);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($jp['db']);

cargarCalendario($cli, $parser, $repo, $desde, $hasta->modify('+7 day'));
foreach ($repo->reuniones($desde->format('Y-m-d'), $hasta->format('Y-m-d'), true) as $r) {
    procesarReunion($cli, $parser, $repo, $r, false);
}
