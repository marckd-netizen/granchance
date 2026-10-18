<?php
// Programa no oficial en el navegador: programa.php?fecha=AAAA-MM-DD&hipodromo=1
// Es lo mismo que bin/programa.php pero devuelve la página directamente (requiere haber corrido bin/velocidades.php).
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use GranChance\{Cliente, ParserPrograma, ProgramaNoOficial, Velocidades};

$config = require __DIR__ . '/../config.php';
$fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : date('Y-m-d');
$hip = (int)($_GET['hipodromo'] ?? 1);

$cli = new Cliente(
    str_replace('docType=ResultadoCarreraWeb', 'docType=Programa', $config['url_resultados']),
    $config['carpeta_crudos'] . '/programas',
    0.0,
);
$d = $cli->bajarResultados($hip, $fecha, !isset($_GET['refrescar']));
header('Content-Type: text/html; charset=utf-8');
if ($d['estado'] !== 'ok') {
    http_response_code(404);
    echo 'No hay programa para ese hipódromo en esa fecha.';
    exit;
}
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$vel = new Velocidades($db);
$vel->cargar();
$gen = new ProgramaNoOficial($db, $vel);
echo $gen->html($gen->armar((new ParserPrograma())->parsearArchivo($d['archivo']), $hip, $fecha));
