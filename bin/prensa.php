<?php
// Pronósticos del concurso de prensa (Maroñas y Las Piedras) y su combinación con el modelo (src/Prensa.php).
// Uso:
//   php bin/prensa.php --bajar [--desde=2024-01-01] [--hasta=AAAA-MM-DD] [--hipodromos=1,13] [--todo]
//        baja las reuniones del concurso entre esas fechas (por defecto, las que todavía no están en la base)
//   php bin/prensa.php --ajustar
//        entrena el modelo como bin/modelo.php y, con las carreras de prueba que no vio, ajusta cuánto pesa la prensa
//        en cada hipódromo; muestra el acierto con y sin prensa
require __DIR__ . '/_inicio.php';
ini_set('memory_limit', '-1');

use GranChance\{Modelo, Prensa, Velocidades};

$opc = opciones($argv);
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/../sql/velocidades.sql'));
$prensa = new Prensa($db);
$hips = isset($opc['hipodromos']) ? array_map('intval', explode(',', (string)$opc['hipodromos'])) : array_keys(Prensa::URLS);

if (isset($opc['bajar'])) {
    $desde = (string)($opc['desde'] ?? '2024-01-01');
    $hasta = (string)($opc['hasta'] ?? '9999-12-31');
    foreach ($hips as $hip) {
        $hay = array_flip($db->query("SELECT DISTINCT fecha FROM prensa_pronostico WHERE hipodromo_id = $hip")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($prensa->fechas($hip) as $fecha) {
            if ($fecha < $desde || $fecha > $hasta || (isset($hay[$fecha]) && !isset($opc['todo']))) {
                continue;
            }
            registro("$hip $fecha: " . $prensa->bajar($hip, $fecha) . ' pronósticos');
            sleep((int)$config['pausa_segundos']);
        }
    }
}

if (isset($opc['ajustar'])) {
    $vel = new Velocidades($db);
    $vel->cargar();
    $modelo = new Modelo($db, $vel);
    $r = $modelo->entrenar();
    registro("Modelo entrenado; carreras de prueba desde {$r['prueba']}");
    $carreras = $db->prepare('SELECT c.id, r.hipodromo_id, r.fecha, c.numero FROM carrera c JOIN reunion r ON r.id = c.reunion_id WHERE c.id = ?');
    $nombres = $db->prepare('SELECT a.caballo_id, ca.nombre, a.puesto FROM actuacion a JOIN caballo ca ON ca.id = a.caballo_id WHERE a.carrera_id = ?');
    $filas = [];
    foreach ($modelo->predicciones() as $cid => $prob) {
        $carreras->execute([$cid]);
        $c = $carreras->fetch(PDO::FETCH_ASSOC);
        if (!$c || !isset(Prensa::URLS[(int)$c['hipodromo_id']])) {
            continue;
        }
        $nombres->execute([$cid]);
        $cab = $nombres->fetchAll(PDO::FETCH_ASSOC);
        $votos = $prensa->votos((int)$c['hipodromo_id'], $c['fecha'], (int)$c['numero'], array_column($cab, 'nombre', 'caballo_id'));
        if ($votos === null) {
            continue;
        }
        $puesto = array_column($cab, 'puesto', 'caballo_id');
        foreach ($prob as $h => $p) {
            $filas[(int)$c['hipodromo_id']][] = ['carrera' => $cid, 'fecha' => $c['fecha'], 'prob' => $p, 'votos' => $votos[$h], 'gano' => (int)$puesto[$h] === 1,
                'puesto' => $puesto[$h] !== null ? (int)$puesto[$h] : 99, 'caballo' => $h];
        }
    }
    foreach ($filas as $hip => $fs) {
        // Se mide con la segunda mitad (ajustando con la primera) y después se ajusta con todo para guardar.
        $fechas = array_unique(array_column($fs, 'fecha'));
        sort($fechas);
        $corte = $fechas[intdiv(count($fechas), 2)];
        $coef = Prensa::ajustar(array_values(array_filter($fs, fn ($f) => $f['fecha'] < $corte)));
        $prueba = array_values(array_filter($fs, fn ($f) => $f['fecha'] >= $corte));
        $medir = function (callable $prob) use ($prueba): string {
            $porCarrera = [];
            foreach ($prueba as $f) {
                $porCarrera[$f['carrera']][] = $f;
            }
            $gana = $trif = 0;
            foreach ($porCarrera as $cs) {
                $p = $prob($cs);
                arsort($p);
                $tres = array_slice(array_keys($p), 0, 3);
                $gana += $cs[$tres[0]]['gano'] ? 1 : 0;
                $trif += count(array_filter($tres, fn ($i) => $cs[$i]['puesto'] <= 3)) === 3 ? 1 : 0;
            }
            $n = count($porCarrera);
            return sprintf('ganador %.1f %%, trifecta con sus 3 %.1f %% (%d carreras desde %s)', 100 * $gana / $n, 100 * $trif / $n, $n, min(array_column($prueba, 'fecha')));
        };
        registro("Hipódromo $hip, modelo solo: " . $medir(fn ($cs) => array_column($cs, 'prob')));
        $tmp = new Prensa($db);
        $tmp->guardar($hip, $coef);
        registro("Hipódromo $hip, modelo + prensa: " . $medir(fn ($cs) => $tmp->combinar($hip, array_column($cs, 'prob'), array_column($cs, 'votos'))));
        $coef = Prensa::ajustar($fs);
        $prensa->guardar($hip, $coef);
        registro("Hipódromo $hip: coeficientes guardados " . json_encode(array_map(fn ($b) => round($b, 3), $coef)));
    }
}
