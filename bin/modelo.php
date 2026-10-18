<?php
// Entrena el modelo de probabilidad de ganar (src/Modelo.php) con todas las carreras desde 2019 y guarda los coeficientes.
// Conviene correrlo después de bin/velocidades.php. Tarda unos minutos.
// Uso: php bin/modelo.php [--probar=2024-01-01]   (mide el acierto y calibra desde esa fecha; por defecto, los últimos 2 años)
require __DIR__ . '/_inicio.php';
ini_set('memory_limit', '-1');

use GranChance\{Modelo, Velocidades};

$opc = opciones($argv);
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$db->exec(file_get_contents(__DIR__ . '/../sql/velocidades.sql'));
$vel = new Velocidades($db);
$vel->cargar();
$m = new Modelo($db, $vel);
$r = $m->entrenar('2019-01-01', isset($opc['probar']) ? (string)$opc['probar'] : null);
registro("Modelo entrenado con {$r['carreras']} carreras");
registro(sprintf('Desde %s (%d carreras): el de más probabilidad ganó el %.1f %%; ordenando solo por proyección, el %.1f %% (escala %.2f)',
    $r['prueba'], $r['carreras_prueba'], 100 * $r['acierto'], 100 * $r['acierto_proyeccion'], $r['escala']));
foreach ($r['calibracion'] ?? [] as $c) {
    printf("  dada %4.1f %% → ganaron %4.1f %% (%d caballos)\n", 100 * $c['dada'], 100 * $c['real'], $c['caballos']);
}
foreach ($m->coeficientes() as $v => $b) {
    printf("  %-20s %+.3f\n", $v, $b);
}
