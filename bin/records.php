<?php
// Arma la tabla record_distancia: lee los programas guardados en raw/programas y, para cada carrera que ya tiene
// resultado en la base, anota "récord que figura en el programa → distancia que se corrió". bin/programa.php la usa
// para saber la distancia de las carreras cuyo récord no está en la base: los anteriores a 2015 y los de setiembre a
// diciembre de 2015, que el servicio no tiene (responde ED0003 para todos los hipódromos).
// Uso: php bin/records.php [--desde=AAAA-MM-DD] [--bajar[=AAAA-MM-DD]] [--hipodromos=1,13]
//   --bajar  antes, baja el Programa de cada reunión ya cargada desde esa fecha (por defecto, el último año) que no
//            esté en raw/programas. Los récords vigentes figuran en todos los programas, así que con un año alcanza.
require __DIR__ . '/_inicio.php';

use GranChance\{Clasificador, Cliente, ParserPrograma};

$opc = opciones($argv);
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/../sql/velocidades.sql'));
if (isset($opc['bajar'])) {
    $cli = new Cliente(
        str_replace('docType=ResultadoCarreraWeb', 'docType=Programa', $config['url_resultados']),
        $config['carpeta_crudos'] . '/programas',
        (float)$config['pausa_segundos'],
    );
    $hips = hipodromos($opc, $config);
    $st = $db->prepare(sprintf('SELECT hipodromo_id, fecha FROM reunion WHERE hipodromo_id IN (%s) AND fecha >= ? ORDER BY fecha',
        implode(',', array_fill(0, count($hips), '?'))));
    $st->execute([...$hips, $opc['bajar'] === true ? date('Y-m-d', strtotime('-1 year')) : $opc['bajar']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $cli->bajarResultados((int)$r['hipodromo_id'], $r['fecha']);
        if ($d['estado'] !== 'ok') {
            registro("{$r['fecha']} hip {$r['hipodromo_id']}: programa {$d['estado']} {$d['detalle']}");
        }
    }
}
$parser = new ParserPrograma();
$buscar = $db->prepare('SELECT c.distancia, c.superficie FROM carrera c JOIN reunion r ON r.id = c.reunion_id
                        WHERE r.hipodromo_id = ? AND r.fecha = ? AND c.nro_oficial = ?');
$guardar = $db->prepare('INSERT INTO record_distancia (hipodromo_id, superficie, record, distancia, carreras) VALUES (?,?,?,?,1)
                         ON DUPLICATE KEY UPDATE carreras = carreras + 1');
$desde = $opc['desde'] ?? '2000-01-01';
$db->exec('DELETE FROM record_distancia');
$n = 0;
$archivos = glob($config['carpeta_crudos'] . '/programas/*/*/*.xls') ?: [];
sort($archivos);
foreach ($archivos as $archivo) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})_(\d+)\.xls$/', $archivo, $m) || $m[1] < $desde) {
        continue;
    }
    [$fecha, $hip] = [$m[1], (int)$m[2]];
    try {
        $prog = $parser->parsearArchivo($archivo);
    } catch (\Throwable $e) {
        registro("$archivo: no se pudo leer ({$e->getMessage()})");
        continue;
    }
    foreach ($prog['carreras'] as $c) {
        if (!$c['record']) {
            continue;
        }
        $buscar->execute([$hip, $fecha, $c['nro_oficial']]);
        $r = $buscar->fetch(PDO::FETCH_ASSOC);
        if ($r && (int)$r['distancia'] > 0) {
            $sup = $c['superficie'] ?? Clasificador::superficie($r['superficie'], $hip);
            $guardar->execute([$hip, $sup, mb_substr($c['record'], 0, 150), (int)$r['distancia']]);
            $n++;
        }
    }
}
registro("$n carreras cruzadas con su récord, " . count($archivos) . ' programas leídos');
