<?php
// Carga diaria de Brasil: vuelve a pedir el raceday y las reuniones de los últimos días (por defecto 3) sin usar los crudos,
// para tomar las reuniones nuevas, las correcciones y los resultados completos que el JCB publica más tarde.
// Uso: php bin/br_diario.php [--dias=3]
// Cron sugerido: 0 8 * * * php /ruta/granchance/bin/br_diario.php >> /ruta/granchance/raw/br/diario.log 2>&1
require __DIR__ . '/br_comun.php';

use GranChance\BR\{ParserResultados, Repositorio};

$opc = opciones($argv);
$dias = max(1, (int)($opc['dias'] ?? 3));
$desde = (new DateTimeImmutable("-$dias day"))->format('Y-m-d');
$hasta = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
$cli = clienteBr($br);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($br['db']);

cargarDias($cli, $parser, $repo, $br, $desde, $hasta, false);
foreach ($repo->reuniones($desde, $hasta, true) as $r) {
    procesarReunion($cli, $parser, $repo, $br, $r, false);
}
