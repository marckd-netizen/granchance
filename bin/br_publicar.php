<?php
// Publica las reuniones de Brasil en la web (la misma carpeta que bin/publicar.php): el programa no oficial de las
// próximas reuniones del raceday del JCB (sale uno o dos días antes) y, de las ya corridas con resultados cargados
// (bin/br_diario.php), la predicción vs resultado. Al final rehace indice.json, así la portada las muestra.
// Requiere haber corrido php bin/br_modelo.php.
//
// Uso: php bin/br_publicar.php [--dias=3] [--fecha=AAAA-MM-DD --reunion=ID_JCB]
//
// Cron sugerido (ver README): cada hora, así toma forfaits y cambios de jóquei hasta la largada.
//   40 * * * *  php bin/br_publicar.php
require __DIR__ . '/br_comun.php';
ini_set('memory_limit', '-1');

use GranChance\BR\{Modelo, ParserResultados, ProgramaNoOficial, Publicador, Repositorio, Velocidades};
use GranChance\Publicador as PublicadorUy;

$opc = opciones($argv);
$db = new PDO($br['db']['dsn'], $br['db']['usuario'], $br['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$carpeta = $config['carpeta_publica'] ?? __DIR__ . '/../public';
$cli = clienteBr($br);
$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo de Brasil: php bin/br_modelo.php\n");
    exit(1);
}
$pub = new Publicador($db, new ProgramaNoOficial($db, $vel, $modelo), $cli, $carpeta, (string)($config['google_analytics'] ?? ''));
$repo = Repositorio::desdeConfig($br['db']);

// Reuniones a publicar: las brasileñas del raceday desde hoy (hora de Brasília) hasta --dias después.
$hoy = Publicador::hoy();
if (isset($opc['fecha'], $opc['reunion'])) {
    $reuniones = array_values(array_filter($repo->reuniones((string)$opc['fecha'], (string)$opc['fecha'], true), fn ($r) => $r['id'] === (int)$opc['reunion']));
    if (!$reuniones) {
        fwrite(STDERR, "La reunión {$opc['reunion']} del {$opc['fecha']} no está en descarga (php bin/br_diario.php o br_historico.php la anotan).\n");
        exit(1);
    }
} else {
    $hasta = (new DateTimeImmutable($hoy))->modify('+' . (int)($opc['dias'] ?? 3) . ' day')->format('Y-m-d');
    cargarDias($cli, new ParserResultados(), $repo, $br, $hoy, $hasta, false);
    $reuniones = $repo->reuniones($hoy, $hasta, true);
}
$hips = $db->query('SELECT nombre, id FROM hipodromo')->fetchAll(PDO::FETCH_KEY_PAIR);
$porDia = [];
foreach ($reuniones as $r) {
    $porDia["{$r['fecha']}|{$r['hipodromo']}"][] = $r['id'];
}
foreach ($porDia as $clave => $ids) {
    [$fecha, $hip] = explode('|', $clave);
    try {
        $res = $pub->programa((int)$hips[$hip], $fecha, $ids);
    } catch (\Throwable $e) {
        registro("$fecha $hip: ERROR al publicar el programa: {$e->getMessage()}");
        continue;
    }
    registro("$fecha $hip: " . ($res === null ? 'el JCB todavía no publicó el programa'
        : "programa publicado ({$res['carreras']} páreos" . ($res['congelada'] ? ', predicción ya congelada' : '') . ')'));
}

// Predicción vs resultado: las de la última semana (resultados corregidos) y las que falten.
foreach ($pub->reunionesComparables() as [$hip, $fecha]) {
    $html = sprintf('%s/resultados/%s_%d.html', $carpeta, $fecha, Publicador::WEB + $hip);
    if (is_file($html) && $fecha < date('Y-m-d', strtotime('-7 day'))) {
        continue;
    }
    if ($r = $pub->resultado($hip, $fecha)) {
        registro(sprintf('%s %s: resultado publicado, ganó el 1º del programa en %d de %d páreos', $fecha, ProgramaNoOficial::NOMBRES[$hip], $r['ganador'], $r['carreras']));
    }
}
PublicadorUy::escribirIndice($carpeta, (string)($config['google_analytics'] ?? ''));
registro('Índice actualizado');
