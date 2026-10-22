<?php
// Publica las reuniones de Japón en la web (la misma carpeta que bin/publicar.php): el programa no oficial de las
// próximas reuniones de la JRA (el programa sale el día anterior, cerca del mediodía de Japón) y, de las ya corridas con
// resultados cargados, la predicción vs resultado. Al final rehace indice.json, así la portada las muestra.
// Requiere haber corrido php bin/jp_modelo.php.
//
// Uso: php bin/jp_publicar.php [--dias=3] [--fecha=AAAA-MM-DD --hipodromo=05]
//
// Cron sugerido (ver README): cada hora, así toma retirados y cambios de jockey hasta la largada.
//   40 * * * *  php bin/jp_publicar.php
require __DIR__ . '/jp_comun.php';
ini_set('memory_limit', '-1');

use GranChance\JP\{Modelo, ParserResultados, ProgramaNoOficial, Publicador, Repositorio, Velocidades};
use GranChance\Publicador as PublicadorUy;

$opc = opciones($argv);
$db = new PDO($jp['db']['dsn'], $jp['db']['usuario'], $jp['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$carpeta = $config['carpeta_publica'] ?? __DIR__ . '/../public';
$cli = clienteJp($jp);
$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo de Japón: php bin/jp_modelo.php\n");
    exit(1);
}
$pub = new Publicador($db, new ProgramaNoOficial($db, $vel, $modelo), $cli, $carpeta, (string)($config['google_analytics'] ?? ''));

// Reuniones a publicar: las del calendario de la JRA desde hoy (hora de Japón) hasta --dias después.
$hoy = Publicador::hoy();
$repo = Repositorio::desdeConfig($jp['db']);
$hasta = (new DateTimeImmutable($hoy))->modify('+' . (int)($opc['dias'] ?? 3) . ' day');
cargarCalendario($cli, new ParserResultados(), $repo, new DateTimeImmutable($hoy), $hasta);
$reuniones = isset($opc['fecha'], $opc['hipodromo'])
    ? array_filter($repo->reuniones((string)$opc['fecha'], (string)$opc['fecha'], true), fn ($r) => (int)$r['hipodromo'] === (int)$opc['hipodromo'])
    : $repo->reuniones($hoy, $hasta->format('Y-m-d'), true);
foreach ($reuniones as $r) {
    $nombre = ParserResultados::HIPODROMOS[$r['hipodromo']] ?? $r['hipodromo'];
    try {
        $res = $pub->programa($r);
    } catch (\Throwable $e) {
        registro("{$r['fecha']} $nombre: ERROR al publicar el programa: {$e->getMessage()}");
        continue;
    }
    registro("{$r['fecha']} $nombre: " . ($res === null ? 'la JRA todavía no publicó el programa'
        : "programa publicado ({$res['carreras']} carreras" . ($res['congelada'] ? ', predicción ya congelada' : '') . ')'));
}

// Predicción vs resultado: las de la última semana (resultados corregidos) y las que falten.
foreach ($pub->reunionesComparables() as [$hip, $fecha]) {
    $html = sprintf('%s/resultados/%s_%d.html', $carpeta, $fecha, Publicador::WEB + $hip);
    if (is_file($html) && $fecha < date('Y-m-d', strtotime('-7 day'))) {
        continue;
    }
    if ($r = $pub->resultado($hip, $fecha)) {
        registro(sprintf('%s %s: resultado publicado, ganó el 1º del programa en %d de %d carreras', $fecha,
            ParserResultados::HIPODROMOS[sprintf('%02d', $hip)] ?? $hip, $r['ganador'], $r['carreras']));
    }
}
PublicadorUy::escribirIndice($carpeta, (string)($config['google_analytics'] ?? ''));
registro('Índice actualizado');
