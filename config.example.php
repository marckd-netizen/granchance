<?php
// Copiar a config.php y completar.
return [
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=granchance;charset=utf8mb4',
        'usuario'  => 'granchance',
        'clave'    => '',
    ],
    // Servicio que devuelve el Excel de resultados de un hipódromo en una fecha.
    'url_resultados' => 'https://mobile-rest-services-v3.azurewebsites.net//XTurfResourcesService.svc/GetRacingDocument'
        . '?docType=ResultadoCarreraWeb&racetrackId={hipodromo}&date={fecha}&periodId=0&raceNum=0'
        . '&language=es-UY&docOrd=0&exportFormat=ExcelRecord',
    // racetrackId que se recorren. 1 = el del ejemplo; el resto se confirma con bin/descubrir.php.
    'hipodromos' => [1],
    'pausa_segundos' => 2,          // entre pedidos, para no cargar el servidor
    'carpeta_crudos' => __DIR__ . '/raw',
];
