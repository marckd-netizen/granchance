<?php
// Baja del Stud Book Uruguayo los studs registrados con la descripción de sus colores y los guarda en stud_colores.
// El programa no oficial muestra los colores junto al stud de cada caballo.
// Uso: php bin/studs.php
require __DIR__ . '/_inicio.php';

use GranChance\Personas;

const URL_STUDS = 'https://backend.studbook.com.uy/api/v1/public/stud?p_current=1&p_pageSize=10000';

$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/../sql/velocidades.sql'));

$ch = curl_init(URL_STUDS);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$json = curl_exec($ch);
$codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$datos = $json !== false && $codigo === 200 ? json_decode($json, true) : null;
if (!isset($datos['rows'])) {
    fwrite(STDERR, "No se pudo bajar la lista de studs (HTTP $codigo).\n");
    exit(1);
}

$db->beginTransaction();
$db->exec('DELETE FROM stud_colores');
$ins = $db->prepare('INSERT INTO stud_colores (studbook_id, nombre, clave, colores) VALUES (?,?,?,?)');
foreach ($datos['rows'] as $s) {
    $colores = trim(preg_replace('/\s+/u', ' ', (string)$s['description']));
    $ins->execute([(int)$s['id'], trim($s['name']), Personas::clave($s['name']), $colores !== '' ? $colores : null]);
}
$db->commit();

$claves = array_flip($db->query('SELECT clave FROM stud_colores')->fetchAll(PDO::FETCH_COLUMN));
$activos = $db->query("SELECT DISTINCT p.nombre FROM persona p JOIN actuacion a ON a.stud_id = p.id JOIN carrera c ON c.id = a.carrera_id
    JOIN reunion r ON r.id = c.reunion_id WHERE p.tipo = 'stud' AND r.fecha >= CURDATE() - INTERVAL 1 YEAR")->fetchAll(PDO::FETCH_COLUMN);
$con = count(array_filter($activos, fn ($n) => isset($claves[Personas::clave($n)])));
registro(count($datos['rows']) . " studs del Stud Book guardados; de los " . count($activos) . " que corrieron en el último año, $con tienen colores");
