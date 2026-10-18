<?php
// Completa carrera.superficie (arena / césped) con el Programa del día, que la indica en la cabecera de cada carrera
// (el Excel de resultados solo la trae en 2018). Recorre las reuniones ya cargadas; los programas quedan en raw/programas/.
// Uso: php bin/superficie.php [--hipodromos=1] [--desde=AAAA-MM-DD] [--hasta=AAAA-MM-DD]
require __DIR__ . '/_inicio.php';

use GranChance\Cliente;
use PhpOffice\PhpSpreadsheet\IOFactory;

$opc = opciones($argv);
$hips = isset($opc['hipodromos']) ? array_map('intval', explode(',', (string)$opc['hipodromos'])) : [1];
$cli = new Cliente(
    str_replace('docType=ResultadoCarreraWeb', 'docType=Programa', $config['url_resultados']),
    $config['carpeta_crudos'] . '/programas',
    (float)$config['pausa_segundos'],
);
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st = $db->prepare(sprintf(
    'SELECT id, hipodromo_id, fecha FROM reunion WHERE hipodromo_id IN (%s) AND fecha BETWEEN ? AND ? ORDER BY fecha',
    implode(',', $hips)
));
$st->execute([$opc['desde'] ?? '2000-01-01', $opc['hasta'] ?? '2100-01-01']);
$upd = $db->prepare('UPDATE carrera SET superficie = ? WHERE reunion_id = ? AND nro_oficial = ?');
$total = 0;

foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $d = $cli->bajarResultados((int)$r['hipodromo_id'], $r['fecha']);
    if ($d['estado'] !== 'ok') {
        registro("{$r['fecha']} hip {$r['hipodromo_id']}: programa {$d['estado']} {$d['detalle']}");
        continue;
    }
    $n = 0;
    foreach (superficies($d['archivo']) as $nro => $sup) {
        $upd->execute([$sup, $r['id'], $nro]);
        $n += $upd->rowCount() > 0 ? 1 : 0;
    }
    $total += $n;
    registro("{$r['fecha']} hip {$r['hipodromo_id']}: $n carreras con superficie");
}
registro("Fin: $total carreras actualizadas");

/**
 * Cabecera de carrera del programa: "13:00 | PREMIO: MIKA (2013) | 32589 | CONDICIÓN: ... | Arena"
 * (los clásicos traen el nombre sin "PREMIO:"). Las columnas se corren según el año, así que se busca
 * la hora, el Nro. dos celdas a su derecha y "Arena"/"Césped" en el resto de la fila.
 * @return array<int, string> Nro. oficial => arena|césped
 */
function superficies(string $archivo): array
{
    $res = [];
    foreach (IOFactory::load($archivo)->getActiveSheet()->toArray(null, false, false, false) as $f) {
        $f = array_values(array_map(fn ($v) => trim((string)$v), $f));
        $i = array_search(true, array_map(fn ($v) => (bool)preg_match('/^\d{1,2}:\d{2}$/', $v), $f), true);
        if ($i === false || !is_numeric($f[$i + 2] ?? '')) {
            continue;
        }
        foreach (array_slice($f, $i + 3) as $v) {
            if (preg_match('/^(arena|c[ée]sped)$/iu', $v)) {
                $res[(int)$f[$i + 2]] = str_replace('cesped', 'césped', mb_strtolower($v));
            }
        }
    }
    return $res;
}
