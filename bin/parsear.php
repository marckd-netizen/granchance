<?php
// Uso: php bin/parsear.php archivo.xls   -> muestra en JSON lo que se leyó (no toca la base)
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Uso: php bin/parsear.php archivo.xls\n");
    exit(1);
}
$r = (new GranChance\ParserResultados())->parsearArchivo($argv[1]);
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
