<?php
// Fichas de caballos, jockeys y cuidadores, y el buscador: /caballo/123-nombre, /jockey/45-nombre, /cuidador/67-nombre,
// /buscar?q=... (ver .htaccess). A diferencia de index.php, lee la base: en cPanel, bin/publicar.php deja junto a esta
// página .granchance.php con la carpeta del proyecto (fuera de public_html) para encontrar la configuración.
declare(strict_types=1);
date_default_timezone_set('America/Montevideo');

$proyecto = is_file(__DIR__ . '/.granchance.php') ? require __DIR__ . '/.granchance.php' : dirname(__DIR__);
require $proyecto . '/vendor/autoload.php';
$config = require $proyecto . '/config.php';

$tipo = (string)($_GET['tipo'] ?? '');
try {
    $db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
    $fichas = new GranChance\Fichas($db, __DIR__);
    $html = $tipo === 'buscar'
        ? $fichas->buscar((string)($_GET['q'] ?? ''))
        : $fichas->pagina($tipo, (int)($_GET['id'] ?? 0), isset($_GET['todas']), isset($_GET['h']) ? (string)$_GET['h'] : null);
} catch (Throwable $e) {
    error_log('ficha.php: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo armar la página. Probá de nuevo en un rato.');
}
if ($html === null) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><p>No existe esa ficha. <a href="/">Volver a GranChance</a></p>');
}
header('Content-Type: text/html; charset=utf-8');
echo $html;
