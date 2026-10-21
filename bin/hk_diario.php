<?php
// Carga diaria de Hong Kong: vuelve a pedir las reuniones de los últimos días (por defecto 3) sin usar los crudos,
// para tomar correcciones y el Racing Incident Report definitivo.
// Uso: php bin/hk_diario.php [--dias=3]
// Cron sugerido: 30 7 * * * php /ruta/granchance/bin/hk_diario.php >> /ruta/granchance/raw/hk/diario.log 2>&1
require __DIR__ . '/hk_comun.php';

use GranChance\HK\{ParserResultados, Repositorio};

$opc = opciones($argv);
$dias = max(1, (int)($opc['dias'] ?? 3));
$desde = new DateTimeImmutable("-$dias day");
$hasta = new DateTimeImmutable('yesterday');
$cli = clienteHk($hk);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($hk['db']);

cargarCalendario($cli, $parser, $repo, $desde, new DateTimeImmutable('today'));
foreach ($repo->reuniones($desde->format('Y-m-d'), $hasta->format('Y-m-d'), true) as $r) {
    procesarReunion($cli, $parser, $repo, $r, false);
}
