<?php
// Programa no oficial de una reunión de Brasil: baja el raceday del JCB de la reunión y lo cruza con la base
// (antecedentes, velocidades, modelo). Antes, php bin/br_modelo.php (coeficientes en modelo_coef de granchance_br).
// Uso: php bin/br_programa.php --fecha=AAAA-MM-DD --reunion=ID_JCB [--salida=archivo.html]
//      (el ID es el idReuniao del raceday; sin --reunion, la primera reunión brasileña de ese día)
//      (por defecto raw/br/programas/AAAA-MM-DD_<sigla>.html)
require __DIR__ . '/br_comun.php';
ini_set('memory_limit', '-1');

use GranChance\CampaniaExterior;
use GranChance\BR\{Modelo, ParserResultados, ProgramaNoOficial, Velocidades};

$opc = opciones($argv);
$fecha = (string)($opc['fecha'] ?? date('Y-m-d', strtotime('+1 day')));
$db = new PDO($br['db']['dsn'], $br['db']['usuario'], $br['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$cli = clienteBr($br);
$parser = new ParserResultados();
$id = (int)($opc['reunion'] ?? 0);
if (!$id) {
    $dia = $parser->dia($cli->dia($fecha, false));
    foreach ($dia['reuniones'] as $r) {
        if ($dia['fecha'] === $fecha && $r['id'] && hipodromoBr($br, $r['hipodromo']) !== null) {
            $id = (int)$r['id'];
            break;
        }
    }
    if (!$id) {
        fwrite(STDERR, "No hay reuniones brasileñas el $fecha en el raceday.\n");
        exit(1);
    }
}
$prog = $parser->reunion($cli->reunion($fecha, $id, false));
$nombre = hipodromoBr($br, (string)$prog['hipodromo']);
$hip = $nombre === null ? false : $db->query('SELECT id FROM hipodromo WHERE nombre = ' . $db->quote($nombre))->fetchColumn();
if ($prog['fecha'] !== $fecha || !$hip) {
    fwrite(STDERR, "El JCB muestra la reunión del {$prog['fecha']} en {$prog['hipodromo']}, no la pedida.\n");
    exit(1);
}
registro(sprintf('%s %s: %d páreos en el programa', $fecha, $prog['hipodromo'], count($prog['carreras'])));

$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo: php bin/br_modelo.php\n");
    exit(1);
}
$gen = new ProgramaNoOficial($db, $vel, $modelo, CampaniaExterior::desdeConfig($config, 'br'));
$hip = (int)$hip;
$p = $gen->armar($prog, $hip);
$salida = (string)($opc['salida'] ?? $br['carpeta_crudos'] . "/programas/{$fecha}_" . ProgramaNoOficial::SIGLAS[$hip] . '.html');
if (!is_dir(dirname($salida))) {
    mkdir(dirname($salida), 0775, true);
}
file_put_contents($salida, $gen->html($p, date('d/m/Y H:i')));
registro("Programa no oficial: $salida");
foreach ($p['carreras'] as $c) {
    $orden = array_filter($c['caballos'], fn ($h) => $h['prob'] !== null);
    usort($orden, fn ($a, $b) => $b['prob'] <=> $a['prob']);
    printf("%dº %s %dm %s: %s\n", $c['numero'], $c['hora'], $c['distancia'], $c['nivel'],
        implode(', ', array_map(fn ($h) => sprintf('%s %s %.0f%%', $h['numero'], $h['nombre'], 100 * $h['prob']), array_slice($orden, 0, 4))));
}
