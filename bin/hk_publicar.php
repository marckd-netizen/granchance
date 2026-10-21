<?php
// Publica las reuniones de Hong Kong en la web (la misma carpeta que bin/publicar.php): el programa no oficial de las
// próximas reuniones del calendario del HKJC (el Race Card sale unos dos días antes) y, de las ya corridas con
// resultados cargados, la predicción vs resultado. Al final rehace indice.json, así la portada las muestra.
// Requiere haber corrido php bin/hk_modelo.php.
//
// Uso: php bin/hk_publicar.php [--dias=4] [--fecha=AAAA-MM-DD --hipodromo=HV|ST]
//
// Cron sugerido (ver README): cada hora, así toma retirados y cambios de jockey hasta la largada.
//   20 * * * *  php bin/hk_publicar.php
require __DIR__ . '/hk_comun.php';
ini_set('memory_limit', '-1');

use GranChance\HK\{Modelo, ParserResultados, ProgramaNoOficial, Publicador, Repositorio, Velocidades};
use GranChance\Publicador as PublicadorUy;

$opc = opciones($argv);
$db = new PDO($hk['db']['dsn'], $hk['db']['usuario'], $hk['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$carpeta = $config['carpeta_publica'] ?? __DIR__ . '/../public';
$cli = clienteHk($hk);
$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo de Hong Kong: php bin/hk_modelo.php\n");
    exit(1);
}
$pub = new Publicador($db, new ProgramaNoOficial($db, $vel, $modelo), $cli, $carpeta, (string)($config['google_analytics'] ?? ''));

// Reuniones a publicar: las del calendario del HKJC desde hoy (hora de Hong Kong) hasta --dias después.
$hoy = Publicador::hoy();
if (isset($opc['fecha'], $opc['hipodromo'])) {
    $reuniones = [['hipodromo' => strtoupper((string)$opc['hipodromo']), 'fecha' => (string)$opc['fecha']]];
} else {
    $repo = Repositorio::desdeConfig($hk['db']);
    $hasta = (new DateTimeImmutable($hoy))->modify('+' . (int)($opc['dias'] ?? 4) . ' day');
    cargarCalendario($cli, new ParserResultados(), $repo, new DateTimeImmutable($hoy), $hasta);
    $reuniones = $repo->reuniones($hoy, $hasta->format('Y-m-d'), true);
}
foreach ($reuniones as $r) {
    try {
        $res = $pub->programa($r['hipodromo'], $r['fecha']);
    } catch (\Throwable $e) {
        registro("{$r['fecha']} {$r['hipodromo']}: ERROR al publicar el programa: {$e->getMessage()}");
        continue;
    }
    registro("{$r['fecha']} {$r['hipodromo']}: " . ($res === null ? 'el HKJC todavía no publicó el programa'
        : "programa publicado ({$res['carreras']} carreras" . ($res['congelada'] ? ', predicción ya congelada' : '') . ')'));
}

// Predicción vs resultado: las de la última semana (resultados corregidos) y las que falten.
foreach ($pub->reunionesComparables() as [$hip, $fecha]) {
    $html = sprintf('%s/resultados/%s_%d.html', $carpeta, $fecha, Publicador::WEB[$hip]);
    if (is_file($html) && $fecha < date('Y-m-d', strtotime('-7 day'))) {
        continue;
    }
    if ($r = $pub->resultado($hip, $fecha)) {
        registro(sprintf('%s %s: resultado publicado, ganó el 1º del programa en %d de %d carreras', $fecha, $hip, $r['ganador'], $r['carreras']));
    }
}
PublicadorUy::escribirIndice($carpeta, (string)($config['google_analytics'] ?? ''));
registro('Índice actualizado');
