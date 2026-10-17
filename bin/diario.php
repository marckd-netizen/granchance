<?php
// Carga diaria: vuelve a pedir los últimos días (por defecto ayer y anteayer) para tomar resultados tardíos o corregidos.
// Uso: php bin/diario.php [--dias=2] [--hipodromos=1,2]
// Cron sugerido: 15 7 * * * php /ruta/granchance/bin/diario.php >> /ruta/granchance/raw/diario.log 2>&1
require __DIR__ . '/_inicio.php';

use GranChance\{ParserResultados, Repositorio};

$opc = opciones($argv);
$dias = max(1, (int)($opc['dias'] ?? 2));
$cli = cliente($config);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($config['db']);

for ($i = $dias; $i >= 1; $i--) {
    $fecha = (new DateTimeImmutable("-$i day"))->format('Y-m-d');
    foreach (hipodromos($opc, $config) as $hip) {
        procesarDia($cli, $parser, $repo, $hip, $fecha, false);   // sin caché: puede haber correcciones
    }
}
