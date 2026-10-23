<?php
// Publica las reuniones de Argentina en la web (la misma carpeta que bin/publicar.php): el programa no oficial de las
// próximas reuniones del calendario del Stud Book y, de las ya corridas con resultados cargados (bin/ar_diario.php),
// la predicción vs resultado. Al final rehace indice.json, así la portada las muestra.
// Requiere haber corrido php bin/ar_modelo.php.
//
// Uso: php bin/ar_publicar.php [--dias=3] [--id=21449]
//   --id  publica solo esa reunión (id del Stud Book, ver la tabla descarga)
//
// Cron sugerido (ver README): cada hora, así toma retirados y cambios de jockey hasta la largada.
//   50 * * * *  php bin/ar_publicar.php
require __DIR__ . '/ar_comun.php';
ini_set('memory_limit', '-1');

use GranChance\CampaniaExterior;
use GranChance\AR\{Modelo, ParserResultados, ProgramaNoOficial, Publicador, Repositorio, Velocidades};
use GranChance\Publicador as PublicadorUy;

$opc = opciones($argv);
$db = new PDO($ar['db']['dsn'], $ar['db']['usuario'], $ar['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$carpeta = $config['carpeta_publica'] ?? __DIR__ . '/../public';
$cli = clienteAr($ar);
$vel = new Velocidades($db);
$vel->recalcular();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    fwrite(STDERR, "Falta entrenar el modelo de Argentina: php bin/ar_modelo.php\n");
    exit(1);
}
$pub = new Publicador($db, new ProgramaNoOficial($db, $vel, $modelo, CampaniaExterior::desdeConfig($config, 'ar')), $cli, $carpeta, (string)($config['google_analytics'] ?? ''));
$repo = Repositorio::desdeConfig($ar['db']);

// Reuniones a publicar: las del calendario desde hoy (hora de Argentina) hasta --dias después.
$hoy = Publicador::hoy();
$hasta = (new DateTimeImmutable($hoy))->modify('+' . (int)($opc['dias'] ?? 3) . ' day')->format('Y-m-d');
cargarCalendarioAr($cli, new ParserResultados(), $repo, new DateTimeImmutable($hoy), new DateTimeImmutable($hasta));
$reuniones = isset($opc['id'])
    ? $db->query('SELECT sb_id, slug, hipodromo_id, fecha FROM descarga WHERE sb_id = ' . (int)$opc['id'])->fetchAll(PDO::FETCH_ASSOC)
    : $repo->reuniones($hoy, $hasta, true);
foreach ($reuniones as $r) {
    $hip = (int)$r['hipodromo_id'];
    $nombre = ProgramaNoOficial::NOMBRES[$hip] ?? $r['slug'];
    try {
        $res = $pub->programa($hip, $r['fecha'], $r);
    } catch (\Throwable $e) {
        registro("{$r['fecha']} $nombre: ERROR al publicar el programa: {$e->getMessage()}");
        continue;
    }
    registro("{$r['fecha']} $nombre: " . ($res === null ? 'el Stud Book todavía no publicó el programa'
        : "programa publicado ({$res['carreras']} carreras" . ($res['congelada'] ? ', predicción ya congelada' : '') . ')'));
}

// Predicción vs resultado: las de la última semana (resultados corregidos) y las que falten.
foreach ($pub->reunionesComparables() as [$hip, $fecha]) {
    $html = sprintf('%s/resultados/%s_%d.html', $carpeta, $fecha, Publicador::WEB + $hip);
    if (is_file($html) && $fecha < date('Y-m-d', strtotime('-7 day'))) {
        continue;
    }
    if ($r = $pub->resultado($hip, $fecha)) {
        registro(sprintf('%s %s: resultado publicado, ganó el 1º del programa en %d de %d carreras', $fecha, ProgramaNoOficial::NOMBRES[$hip] ?? $hip, $r['ganador'], $r['carreras']));
    }
}
PublicadorUy::escribirIndice($carpeta, (string)($config['google_analytics'] ?? ''));
registro('Índice actualizado');
