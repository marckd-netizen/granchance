<?php
// Corrige en la base los tiempos imposibles que trae el Excel (ParserResultados::corregirTiempos), sin volver a bajar
// ni leer los Excel. Después correr bin/velocidades.php y bin/modelo.php.
// Uso: php bin/tiempos.php [--mostrar]
require __DIR__ . '/_inicio.php';

use GranChance\ParserResultados;

$opc = opciones($argv);
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$filas = $db->query('SELECT a.carrera_id, a.caballo_id, a.puesto, a.tiempo, a.tiempo_seg, a.dist_acumulada, c.tiempo_ganador_seg, c.distancia
    FROM actuacion a JOIN carrera c ON c.id = a.carrera_id ORDER BY a.carrera_id')->fetchAll(PDO::FETCH_ASSOC);
$porCarrera = [];
foreach ($filas as $f) {
    $f['puesto'] = $f['puesto'] !== null ? (int)$f['puesto'] : null;
    $f['tiempo_seg'] = $f['tiempo_seg'] !== null ? (float)$f['tiempo_seg'] : null;
    $porCarrera[$f['carrera_id']][] = $f;
}
$upd = $db->prepare('UPDATE actuacion SET tiempo = ?, tiempo_seg = ? WHERE carrera_id = ? AND caballo_id = ?');
$n = 0;
foreach ($porCarrera as $cid => $as) {
    $ganador = $as[0]['tiempo_ganador_seg'] !== null ? (float)$as[0]['tiempo_ganador_seg'] : null;
    foreach (ParserResultados::corregirTiempos($as, $ganador, $as[0]['distancia'] ? (int)$as[0]['distancia'] : null) as $a) {
        if (isset($a['tiempo_excel'])) {
            $upd->execute([$a['tiempo'], $a['tiempo_seg'], $cid, $a['caballo_id']]);
            $n++;
            if (isset($opc['mostrar'])) {
                registro("carrera $cid, puesto {$a['puesto']}: {$a['tiempo_excel']} -> " . ($a['tiempo'] ?? 'sin tiempo') . " ({$a['dist_acumulada']})");
            }
        }
    }
}
registro("$n tiempos corregidos");
