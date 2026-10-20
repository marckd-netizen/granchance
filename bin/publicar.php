<?php
// Publica en la carpeta web (config 'carpeta_publica', por defecto public/): programas no oficiales de los próximos días,
// páginas de predicción vs resultado de las reuniones ya corridas, el acierto acumulado y el índice para index.php.
// Requiere haber corrido antes bin/velocidades.php y bin/modelo.php.
//
// Uso: php bin/publicar.php [--dias=15] [--desde=AAAA-MM-DD] [--hasta=AAAA-MM-DD] [--hipodromos=1,13] [--borrados]
//                           [--sin-programas] [--sin-resultados]
//   (sin opciones)   relee los programas de hoy a 15 días (anota cambios de monta, horario, peso y retiros)
//                    y arma las páginas de resultado que falten de reuniones con predicción ya cargadas.
//   --borrados       baja los borrados de hoy (los publica HRU a las 11:00) y vuelve a generar los programas de hoy.
//   --desde/--hasta  rango de fechas a (re)generar; sirve para publicar reuniones pasadas (predicción "reconstruida").
//                    Sin --hasta, llega hasta 15 días después de hoy.
//
// Cron sugerido (hora de Uruguay), ver README:
//   15 7 * * *  php bin/diario.php && php bin/velocidades.php && php bin/publicar.php
//   5 * * * *   php bin/publicar.php --borrados      (solo actúa de 11 a 20, hora de Uruguay)
require __DIR__ . '/_inicio.php';
date_default_timezone_set($config['zona_horaria'] ?? 'America/Montevideo');

use GranChance\{Borrados, Cliente, Modelo, ProgramaNoOficial, Publicador, Velocidades};

$opc = opciones($argv);
$db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/../sql/publicacion.sql'));

$vel = new Velocidades($db);
$vel->cargar();
$modelo = new Modelo($db, $vel);
if (!$modelo->cargar()) {
    registro('Sin modelo entrenado: los programas salen sin probabilidades (correr php bin/modelo.php)');
    $modelo = null;
}
$borrados = new Borrados($db, $config['url_borrados']
    ?? 'https://mobile-rest-services-v3.azurewebsites.net/XTurfRestService.svc/GetScratchedRaceParticipants');
$pub = new Publicador(
    $db,
    new ProgramaNoOficial($db, $vel, $modelo),
    new Cliente(str_replace('docType=ResultadoCarreraWeb', 'docType=Programa', $config['url_resultados']),
        $config['carpeta_crudos'] . '/programas', (float)$config['pausa_segundos']),
    $borrados,
    $config['carpeta_publica'] ?? __DIR__ . '/../public',
    (string)($config['google_analytics'] ?? ''),
);

$hoy = date('Y-m-d');
if (isset($opc['borrados'])) {
    // HRU publica los borrados a las 11:00. Fuera de 11 a 20 (hora de Uruguay) no hace nada, así el cron puede
    // correr cada hora aunque el servidor esté en otra zona horaria.
    if ((int)date('G') < 11 || (int)date('G') > 20) {
        exit(0);
    }
    $lista = $borrados->actualizar($hoy);
    registro(sprintf('Borrados de hoy: %d caballos', array_sum(array_map('count', $lista))));
    [$desde, $hasta] = [$hoy, $hoy];
} else {
    $desde = $opc['desde'] ?? $hoy;
    // Sin --hasta se llega siempre a 15 días después de hoy (también cuando --desde es una fecha pasada).
    $hasta = $opc['hasta'] ?? (new DateTimeImmutable(max($desde, $hoy)))->modify('+' . (int)($opc['dias'] ?? 15) . ' day')->format('Y-m-d');
}

if (!isset($opc['sin-programas'])) {
    for ($f = new DateTimeImmutable($desde); $f->format('Y-m-d') <= $hasta; $f = $f->modify('+1 day')) {
        foreach (hipodromos($opc, $config) as $hip) {
            $fecha = $f->format('Y-m-d');
            try {
                // Las reuniones pasadas no cambian: se usa el programa ya bajado si está.
                $r = $pub->programa($hip, $fecha, $fecha >= $hoy);
            } catch (\Throwable $e) {
                registro("$fecha hip $hip: ERROR al publicar el programa: {$e->getMessage()}");
                continue;
            }
            if ($r === null) {
                continue;
            }
            registro("$fecha hip $hip: programa publicado ({$r['carreras']} carreras)" . ($r['cambios'] ? ', cambios: ' . implode('; ', $r['cambios']) : ''));
        }
    }
}

if (!isset($opc['sin-resultados']) && !isset($opc['borrados'])) {
    $hechos = [];
    $carpeta = $config['carpeta_publica'] ?? __DIR__ . '/../public';
    foreach ($pub->reunionesComparables() as [$hip, $fecha]) {
        $html = sprintf('%s/resultados/%s_%d.html', $carpeta, $fecha, $hip);
        // Se rehacen las de la última semana (resultados corregidos) y las que falten.
        if (is_file($html) && $fecha < date('Y-m-d', strtotime('-7 day')) && !isset($opc['desde'])) {
            continue;
        }
        $r = $pub->resultado($hip, $fecha);
        if ($r) {
            registro(sprintf('%s hip %d: resultado publicado, ganó el 1º del programa en %d de %d carreras', $fecha, $hip, $r['ganador'], $r['carreras']));
            $hechos[] = [$hip, $fecha];
        }
    }
    // El programa publicado queda como estaba antes de la carrera; solo se le agrega el enlace al resultado.
    foreach ($hechos as [$hip, $fecha]) {
        $pub->enlazarResultado($hip, $fecha);
    }
}
$pub->indice();
// Si la web se publica en otra carpeta (public_html en cPanel), se le copia la portada. El .htaccess no se pisa
// (cPanel guarda ahí la versión de PHP): se le agregan nuestras líneas entre marcas, una sola vez.
$destino = realpath($config['carpeta_publica'] ?? __DIR__ . '/../public');
if ($destino !== realpath(__DIR__ . '/../public')) {
    if (!is_file("$destino/index.php") || md5_file(__DIR__ . '/../public/index.php') !== md5_file("$destino/index.php")) {
        copy(__DIR__ . '/../public/index.php', "$destino/index.php");
    }
    $actual = is_file("$destino/.htaccess") ? (string)file_get_contents("$destino/.htaccess") : '';
    $nuestro = "# BEGIN GranChance\n" . trim((string)file_get_contents(__DIR__ . '/../public/.htaccess')) . "\n# END GranChance\n";
    $nuevo = preg_match('/# BEGIN GranChance.*?# END GranChance\n/s', $actual)
        ? preg_replace('/# BEGIN GranChance.*?# END GranChance\n/s', $nuestro, $actual)
        : rtrim($actual) . ($actual !== '' ? "\n\n" : '') . $nuestro;
    if ($nuevo !== $actual) {
        file_put_contents("$destino/.htaccess", $nuevo);
    }
}
registro('Índice actualizado');
