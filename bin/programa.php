<?php
// Genera el programa no oficial (HTML) de una reunión: baja el Programa del día y le agrega los antecedentes,
// las velocidades corregidas por pista y viento y los índices contra el par de cada categoría.
// Requiere haber corrido antes bin/velocidades.php.
// Uso: php bin/programa.php [--fecha=AAAA-MM-DD] [--hipodromo=1] [--salida=archivo.html] [--archivo=programa.xls]
//                           [--distancias=1:1400,3:2000] [--refrescar]
//   --distancias  distancia a mano por número de carrera, si no se pudo deducir del récord
//   --refrescar   vuelve a bajar el programa aunque ya esté en raw/programas (por retirados de último momento)
require __DIR__ . '/_inicio.php';

use GranChance\{CampaniaExterior, Cliente, Modelo, ParserPrograma, Prensa, ProgramaNoOficial, Velocidades};

$opc = opciones($argv);
$fecha = $opc['fecha'] ?? date('Y-m-d');
$hip = (int)($opc['hipodromo'] ?? 1);

$archivo = $opc['archivo'] ?? null;
if ($archivo === null) {
    $cli = new Cliente(
        str_replace('docType=ResultadoCarreraWeb', 'docType=Programa', $config['url_resultados']),
        $config['carpeta_crudos'] . '/programas',
        (float)$config['pausa_segundos'],
    );
    $d = $cli->bajarResultados($hip, $fecha, !isset($opc['refrescar']));
    if ($d['estado'] !== 'ok') {
        fwrite(STDERR, "No hay programa para el hipódromo $hip el $fecha ({$d['estado']} {$d['detalle']}).\n");
        exit(1);
    }
    $archivo = $d['archivo'];
}

$distancias = [];
foreach (array_filter(explode(',', (string)($opc['distancias'] ?? ''))) as $par) {
    [$n, $m] = array_map('intval', explode(':', $par) + [1 => 0]);
    $distancias[$n] = $m;
}

$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$vel = new Velocidades($db);
$vel->cargar();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    registro('Sin modelo entrenado: el programa sale sin probabilidades (correr php bin/modelo.php)');
    $modelo = null;
}
$gen = new ProgramaNoOficial($db, $vel, $modelo, new Prensa($db), CampaniaExterior::desdeConfig($config, 'uy'));
$p = $gen->armar((new ParserPrograma())->parsearArchivo($archivo), $hip, $fecha, $distancias);

foreach ($p['carreras'] as $c) {
    if (!$c['distancia']) {
        registro("Carrera {$c['numero']}: no se pudo deducir la distancia del récord ({$c['record']}); indicarla con --distancias={$c['numero']}:METROS");
    }
}
$salida = $opc['salida'] ?? sprintf('%s/programas/%s_%d.html', $config['carpeta_crudos'], $fecha, $hip);
if (!is_dir(dirname($salida))) {
    mkdir(dirname($salida), 0775, true);
}
// Los enlaces del calendario apuntan a los otros programas generados en la misma carpeta (AAAA-MM-DD_<hip>.html).
file_put_contents($salida, $gen->html($p, $gen->fechasConReunion($config['carpeta_crudos'] . '/programas')));
registro(sprintf('%d carreras, programa no oficial en %s', count($p['carreras']), $salida));
