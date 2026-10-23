<?php
// Programa no oficial de una reunión de Japón: baja el programa (Race Card) de cada carrera de la JRA y lo cruza con la base
// (antecedentes, velocidades, modelo). Antes, php bin/jp_modelo.php (coeficientes en modelo_coef de granchance_jp).
// Uso: php bin/jp_programa.php --fecha=AAAA-MM-DD --hipodromo=05 [--salida=archivo.html]
//      (hipódromo con el código de la JRA: 05 Tokyo, 06 Nakayama, 08 Kyoto, 09 Hanshin...; por defecto raw/jp/programas/AAAA-MM-DD_<código>.html)
require __DIR__ . '/jp_comun.php';
ini_set('memory_limit', '-1');

use GranChance\CampaniaExterior;
use GranChance\JP\{Modelo, ParserPrograma, ParserResultados, ProgramaNoOficial, Velocidades};

$opc = opciones($argv);
$fecha = (string)($opc['fecha'] ?? date('Y-m-d', strtotime('+1 day')));
$hip = sprintf('%02d', (int)($opc['hipodromo'] ?? 0));
$cli = clienteJp($jp);
$r = array_values(array_filter((new ParserResultados())->dia($cli->dia($fecha, false)), fn ($x) => $x['hipodromo'] === $hip))[0] ?? null;
if ($r === null) {
    fwrite(STDERR, "La JRA no tiene reunión en el hipódromo $hip el $fecha.\n");
    exit(1);
}
$parser = new ParserPrograma();
$carreras = [];
foreach (array_keys($r['carreras']) as $n) {
    $html = $cli->programa($r, $n);
    if (!str_contains($html, 'NO DATA') && ($c = $parser->carrera($html))['caballos'] !== []) {
        $carreras[] = $c;
    }
}
if ($carreras === []) {
    fwrite(STDERR, "La JRA todavía no publicó el programa (sale el día anterior, cerca del mediodía de Japón).\n");
    exit(1);
}
registro(sprintf('%s %s: %d carreras en el programa', $fecha, ParserResultados::HIPODROMOS[$hip], count($carreras)));

$db = new PDO($jp['db']['dsn'], $jp['db']['usuario'], $jp['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo: php bin/jp_modelo.php\n");
    exit(1);
}
$prog = new ProgramaNoOficial($db, $vel, $modelo, CampaniaExterior::desdeConfig($config, 'jp'));
$p = $prog->armar($carreras, $fecha);
$salida = (string)($opc['salida'] ?? $jp['carpeta_crudos'] . "/programas/{$fecha}_$hip.html");
if (!is_dir(dirname($salida))) {
    mkdir(dirname($salida), 0775, true);
}
file_put_contents($salida, $prog->html($p, date('d/m/Y H:i')));
registro("Programa no oficial: $salida");
foreach ($p['carreras'] as $c) {
    $orden = array_filter($c['caballos'], fn ($h) => $h['prob'] !== null);
    usort($orden, fn ($a, $b) => $b['prob'] <=> $a['prob']);
    printf("%dª %s %dm %s %s: %s\n", $c['numero'], $c['hora'], $c['distancia'], $c['superficie'], $c['nivel'],
        implode(', ', array_map(fn ($h) => sprintf('%s %s %.0f%%', $h['numero'], $h['nombre'], 100 * $h['prob']), array_slice($orden, 0, 4))));
}
