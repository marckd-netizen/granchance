<?php
// Carga histórica. Reanudable: saltea los (hipódromo, fecha) ya consultados.
// Uso: php bin/historico.php --desde=2016-01-01 [--hasta=AAAA-MM-DD] [--hipodromos=1,2] [--reintentar]
//   --reintentar  vuelve a consultar también los días que dieron vacío
require __DIR__ . '/_inicio.php';

use GranChance\{ParserResultados, Repositorio};

$opc = opciones($argv);
$desde = new DateTimeImmutable($opc['desde'] ?? '2016-01-01');
$hasta = new DateTimeImmutable($opc['hasta'] ?? 'yesterday');
$cli = cliente($config);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($config['db']);
$cuenta = ['ok' => 0, 'vacio' => 0, 'error' => 0];

for ($dia = $desde; $dia <= $hasta; $dia = $dia->modify('+1 day')) {
    $fecha = $dia->format('Y-m-d');
    foreach (hipodromos($opc, $config) as $hip) {
        if (!isset($opc['reintentar']) && $repo->yaConsultado($hip, $fecha)) {
            continue;
        }
        $cuenta[procesarDia($cli, $parser, $repo, $hip, $fecha, true)]++;
    }
}
registro(sprintf('Fin: %d reuniones, %d días sin carreras, %d errores', $cuenta['ok'], $cuenta['vacio'], $cuenta['error']));
