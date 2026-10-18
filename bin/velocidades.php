<?php
// Recalcula las velocidades normales (par) por categoría y los ajustes por pista y viento (tablas de sql/velocidades.sql).
// Conviene correrlo después de bin/diario.php, para que el par incluya las últimas carreras.
// Uso: php bin/velocidades.php [--mostrar=1]   (--mostrar lista los pares y ajustes de ese hipódromo)
require __DIR__ . '/_inicio.php';

use GranChance\Velocidades;

$opc = opciones($argv);
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$db->exec(file_get_contents(__DIR__ . '/../sql/velocidades.sql'));

$r = (new Velocidades($db))->recalcular();
registro("{$r['carreras']} carreras clasificadas, {$r['pares']} pares y {$r['ajustes']} ajustes calculados");

if (isset($opc['mostrar'])) {
    $hip = (int)$opc['mostrar'];
    $min = Velocidades::MIN_CARRERAS;
    echo "\nPar (km/h, 4 primeros, pista seca sin viento) con $min carreras o más:\n";
    $st = $db->prepare("SELECT distancia, superficie, nivel, edad, sexo, carreras, velocidad FROM par_velocidad
                        WHERE hipodromo_id = ? AND carreras >= ? AND edad = '*' ORDER BY superficie, distancia, velocidad DESC");
    $st->execute([$hip, $min]);
    foreach ($st as $p) {
        printf("  %4d %-7s %-16s %5.2f  (%d carreras)\n", $p['distancia'], $p['superficie'], $p['nivel'] === '*' ? 'TODAS' : $p['nivel'], $p['velocidad'], $p['carreras']);
    }
    echo "\nAjustes por pista y viento (km/h respecto del par):\n";
    $st = $db->prepare("SELECT superficie, tramo, pista, viento, carreras, ajuste FROM ajuste_condicion
                        WHERE hipodromo_id = ? AND tramo <> '*' AND carreras >= ? ORDER BY superficie, tramo, pista, viento");
    $st->execute([$hip, $min]);
    foreach ($st as $a) {
        printf("  %-7s %-6s %-8s %-11s %+6.2f  (%d carreras)\n", $a['superficie'], $a['tramo'], $a['pista'], $a['viento'], $a['ajuste'], $a['carreras']);
    }
}
