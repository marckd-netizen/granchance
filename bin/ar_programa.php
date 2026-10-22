<?php
// Programa no oficial de una reunión de Argentina: baja la página de la reunión del Stud Book (con el programa) y la
// cruza con la base (antecedentes, velocidades, modelo). Antes, php bin/ar_modelo.php (coeficientes en modelo_coef de granchance_ar).
// Uso: php bin/ar_programa.php --id=21449 [--salida=archivo.html]
//      (id del Stud Book de la reunión, ver la tabla descarga o el enlace del calendario: /reuniones/detalle/21449/...)
//      (por defecto raw/ar/programas/AAAA-MM-DD_<sigla>.html)
require __DIR__ . '/ar_comun.php';
ini_set('memory_limit', '-1');

use GranChance\AR\{Modelo, ParserResultados, ProgramaNoOficial, Velocidades};

$opc = opciones($argv);
$db = new PDO($ar['db']['dsn'], $ar['db']['usuario'], $ar['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$r = $db->query('SELECT sb_id, slug, hipodromo_id, fecha FROM descarga WHERE sb_id = ' . (int)($opc['id'] ?? 0))->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    fwrite(STDERR, "La reunión no está en descarga (php bin/ar_diario.php o ar_publicar.php leen el calendario).\n");
    exit(1);
}
$prog = (new ParserResultados())->reunion(clienteAr($ar)->programa((int)$r['sb_id'], $r['slug']));
registro(sprintf('%s %s: %d carreras en el programa', $r['fecha'], $r['slug'], count($prog['carreras'])));

$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo: php bin/ar_modelo.php\n");
    exit(1);
}
$gen = new ProgramaNoOficial($db, $vel, $modelo);
$hip = (int)$r['hipodromo_id'];
$p = $gen->armar($prog, $hip);
$salida = (string)($opc['salida'] ?? $ar['carpeta_crudos'] . "/programas/{$r['fecha']}_" . (ProgramaNoOficial::SIGLAS[$hip] ?? $hip) . '.html');
if (!is_dir(dirname($salida))) {
    mkdir(dirname($salida), 0775, true);
}
file_put_contents($salida, $gen->html($p, date('d/m/Y H:i')));
registro("Programa no oficial: $salida");
foreach ($p['carreras'] as $c) {
    $orden = array_filter($c['caballos'], fn ($h) => $h['prob'] !== null);
    usort($orden, fn ($a, $b) => $b['prob'] <=> $a['prob']);
    printf("%dª %s %dm %s: %s\n", $c['numero'], $c['hora'], $c['distancia'], $c['nivel'],
        implode(', ', array_map(fn ($h) => sprintf('%s %s %.0f%%', $h['numero'], $h['nombre'], 100 * $h['prob']), array_slice($orden, 0, 4))));
}
