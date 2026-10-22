<?php
// Entrena el modelo de Japón (src/JP/Modelo.php: el de Uruguay con los datos de la JRA) y guarda los coeficientes
// en modelo_coef de granchance_jp. Las velocidades (src/JP/Velocidades.php) se calculan en el momento.
// Uso: php bin/jp_modelo.php [--desde=2016-01-01] [--probar=AAAA-MM-DD]   (por defecto mide y calibra con los últimos 2 años)
require __DIR__ . '/jp_comun.php';
ini_set('memory_limit', '-1');

use GranChance\JP\{Modelo, Velocidades};

$opc = opciones($argv);
$db = new PDO($jp['db']['dsn'], $jp['db']['usuario'], $jp['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$vel = new Velocidades($db);
$v = $vel->recalcular();
$nombres = GranChance\JP\ParserResultados::HIPODROMOS;
registro(sprintf('Velocidades: %d carreras, %d pares, %d ajustes de pista, %d de semana de la reunión; respecto de Tokyo: %s',
    $v['carreras'], $v['pares'], $v['ajustes'], $v['variantes'],
    implode(', ', array_map(fn ($h, $e) => sprintf('%s %+.2f', $nombres[sprintf('%02d', $h)] ?? $h, $e), array_keys($v['equivalencias']), $v['equivalencias']))));
$m = new Modelo($db, $vel);
$r = $m->entrenar((string)($opc['desde'] ?? '2016-01-01'), isset($opc['probar']) ? (string)$opc['probar'] : null);
registro("Modelo entrenado con {$r['carreras']} carreras");
registro(sprintf('Desde %s (%d carreras): el de más probabilidad ganó el %.1f %%; ordenando solo por proyección, el %.1f %%; el favorito de las cuotas, el %.1f %% (escala %.2f)',
    $r['prueba'], $r['carreras_prueba'], 100 * $r['acierto'], 100 * $r['acierto_proyeccion'], 100 * $r['favorito'], $r['escala']));
registro(sprintf('Con los 3 de más probabilidad: trifecta sin orden en el %.1f %% de las carreras, exacta sin orden en el %.1f %%',
    100 * $r['trifecta'], 100 * $r['exacta']));
foreach ($r['calibracion'] ?? [] as $c) {
    printf("  dada %4.1f %% → ganaron %4.1f %% (%d caballos)\n", 100 * $c['dada'], 100 * $c['real'], $c['caballos']);
}
foreach ($m->coeficientes() as $var => $b) {
    printf("  %-20s %+.3f\n", $var, $b);
}
