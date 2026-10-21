<?php
// Carga diaria de Argentina: vuelve a pedir las reuniones de los últimos días (por defecto 3) sin usar los crudos,
// para tomar correcciones (distanciados, retirados).
// Uso: php bin/ar_diario.php [--dias=3]
// Cron sugerido: 0 8 * * * php /ruta/granchance/bin/ar_diario.php >> /ruta/granchance/raw/ar/diario.log 2>&1
require __DIR__ . '/ar_comun.php';

use GranChance\AR\{ParserResultados, Repositorio};

$opc = opciones($argv);
$dias = max(1, (int)($opc['dias'] ?? 3));
$desde = new DateTimeImmutable("-$dias day");
$hasta = new DateTimeImmutable('yesterday');
$cli = clienteAr($ar);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($ar['db']);

cargarCalendarioAr($cli, $parser, $repo, $desde, new DateTimeImmutable('today'));
foreach ($repo->reuniones($desde->format('Y-m-d'), $hasta->format('Y-m-d'), true) as $r) {
    procesarReunionAr($cli, $parser, $repo, $r, false);
}
