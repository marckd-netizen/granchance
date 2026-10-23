<?php
// Programa no oficial de una reunión de Hong Kong: baja el Race Card de cada carrera del HKJC y lo cruza con la base
// (antecedentes, velocidades, modelo). Antes, php bin/hk_modelo.php (coeficientes en modelo_coef de granchance_hk).
// Uso: php bin/hk_programa.php --fecha=AAAA-MM-DD --hipodromo=HV|ST [--salida=archivo.html]
//      (por defecto raw/hk/programas/AAAA-MM-DD_<HV|ST>.html)
require __DIR__ . '/hk_comun.php';
ini_set('memory_limit', '-1');

use GranChance\CampaniaExterior;
use GranChance\HK\{Modelo, ParserPrograma, ProgramaNoOficial, Velocidades};

$opc = opciones($argv);
$fecha = (string)($opc['fecha'] ?? date('Y-m-d', strtotime('+1 day')));
$hip = strtoupper((string)($opc['hipodromo'] ?? ''));
if (!in_array($hip, ['ST', 'HV'], true)) {
    fwrite(STDERR, "Indicar --hipodromo=ST o --hipodromo=HV\n");
    exit(1);
}
$db = new PDO($hk['db']['dsn'], $hk['db']['usuario'], $hk['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$cli = clienteHk($hk);
$parser = new ParserPrograma();
$primera = $cli->programa($fecha, $hip, 1);
$carreras = [];
for ($n = 1, $total = $parser->cantidadCarreras($primera); $n <= $total; $n++) {
    $c = $parser->carrera($n === 1 ? $primera : $cli->programa($fecha, $hip, $n));
    if ($c['fecha'] !== $fecha || $c['hipodromo'] !== $hip) {
        fwrite(STDERR, "El HKJC muestra el programa del {$c['fecha']} en {$c['hipodromo']}, no el pedido.\n");
        exit(1);
    }
    $carreras[] = $c;
}
registro(sprintf('%s %s: %d carreras en el programa', $fecha, $hip, count($carreras)));

$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo: php bin/hk_modelo.php\n");
    exit(1);
}
$prog = new ProgramaNoOficial($db, $vel, $modelo, CampaniaExterior::desdeConfig($config, 'hk'));
$p = $prog->armar($carreras, $fecha);
$salida = (string)($opc['salida'] ?? $hk['carpeta_crudos'] . "/programas/{$fecha}_$hip.html");
if (!is_dir(dirname($salida))) {
    mkdir(dirname($salida), 0775, true);
}
file_put_contents($salida, $prog->html($p, date('d/m/Y H:i')));
registro("Programa no oficial: $salida");
foreach ($p['carreras'] as $c) {
    $orden = array_filter($c['caballos'], fn ($h) => $h['prob'] !== null);
    usort($orden, fn ($a, $b) => $b['prob'] <=> $a['prob']);
    printf("%dª %s %dm %s: %s\n", $c['numero'], $c['hora'], $c['distancia'], $c['clase'],
        implode(', ', array_map(fn ($h) => sprintf('%s %s %.0f%%', $h['numero'], $h['nombre'], 100 * $h['prob']), array_slice($orden, 0, 4))));
}
