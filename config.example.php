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
    // racetrackId que se recorren (confirmados con bin/descubrir.php en oct. 2026):
    // 1 Maroñas, 4 Colonia, 8 Flores, 9 Florida, 13 Las Piedras, 16 Melo, 21 Paysandú, 22 Rocha.
    'hipodromos' => [1, 4, 8, 9, 13, 16, 21, 22],
    'pausa_segundos' => 2,          // entre pedidos, para no cargar el servidor
    'carpeta_crudos' => __DIR__ . '/raw',
    // Carpeta que se publica en la web (bin/publicar.php). En cPanel: el public_html del dominio, por ejemplo
    // '/home/USUARIO/public_html', con este proyecto fuera de public_html.
    'carpeta_publica' => __DIR__ . '/public',
    // Servicio de caballos borrados (el que usa hipica.maronas.com.uy/RacingInfo/ScratchedHorses).
    'url_borrados' => 'https://mobile-rest-services-v3.azurewebsites.net/XTurfRestService.svc/GetScratchedRaceParticipants',
    'zona_horaria' => 'America/Montevideo',
    // ID de medición de Google Analytics 4 ("G-XXXXXXXXXX"); vacío para no usarlo.
    'google_analytics' => '',
    // Hong Kong (HKJC, Sha Tin y Happy Valley): base aparte con sql/schema_hk.sql. Ver bin/hk_historico.php.
    'hk' => [
        'db' => [
            'dsn'      => 'mysql:host=localhost;dbname=granchance_hk;charset=utf8mb4',
            'usuario'  => 'granchance',
            'clave'    => '',
        ],
        'pausa_segundos' => 1,
        'carpeta_crudos' => __DIR__ . '/raw/hk',
    ],
];
