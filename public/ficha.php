<?php
// Fichas de caballos, jockeys, cuidadores, studs, criadores, padrillos, madres y abuelos maternos (/caballo/123-nombre,
// /jockey/45-nombre, /padrillo/nombre...), el buscador (/buscar?q=...) y los rankings (/ranking/caballos...), ver .htaccess.
// A diferencia de index.php, lee la base: en cPanel, bin/publicar.php deja junto a esta página .granchance.php con la
// carpeta del proyecto (fuera de public_html) para encontrar la configuración.
declare(strict_types=1);
date_default_timezone_set('America/Montevideo');

$proyecto = is_file(__DIR__ . '/.granchance.php') ? require __DIR__ . '/.granchance.php' : dirname(__DIR__);
require $proyecto . '/vendor/autoload.php';
$config = require $proyecto . '/config.php';

$tipo = (string)($_GET['tipo'] ?? '');
// Fichas de otro país: /ar/caballo/123-nombre, /br/jockey/..., /hk/cuidador/..., /jp/stud/... (ver .htaccess).
$pais = isset(GranChance\Fichas::PAISES[$_GET['pais'] ?? '']) ? (string)$_GET['pais'] : null;
try {
    $db = new PDO($config['db']['dsn'], $config['db']['usuario'], $config['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
    // Los otros países leen su propia base (config 'hk', 'br', 'ar', 'jp'): los rankings (?pais=argentina...), el buscador y sus
    // fichas. Si no está configurada o no responde, ese país no aparece.
    $otra = function (string $p) use ($config, $tipo, $pais): ?PDO {
        if ((!in_array($tipo, ['ranking', 'buscar'], true) && $p !== $pais) || empty($config[$p]['db']['dsn'])) {
            return null;
        }
        try {
            $db = new PDO($config[$p]['db']['dsn'], $config[$p]['db']['usuario'], $config[$p]['db']['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']);
            return $db->query('SELECT 1 FROM actuacion LIMIT 1')->fetchColumn() ? $db : null;
        } catch (Throwable $e) {
            error_log("ficha.php: base de '$p': " . $e->getMessage());
            return null;
        }
    };
    $bases = array_filter(['ar' => $otra('ar'), 'br' => $otra('br'), 'hk' => $otra('hk'), 'jp' => $otra('jp')]);
    // La ficha del caballo suma lo que corrió en los otros países (abre esas bases solo si hace falta).
    $exterior = $tipo === 'caballo' ? GranChance\CampaniaExterior::desdeConfig($config, $pais ?? 'uy') : null;
    $fichas = new GranChance\Fichas($db, __DIR__, null, $exterior);
    $otrasFichas = function (array $bases): array {
        $f = [];
        foreach ($bases as $p => $b) {
            $f[$p] = new GranChance\Fichas($b, __DIR__, $p);
        }
        return $f;
    };
    // Los padrillos, madres y abuelos maternos van por el nombre (/padrillo/village-king), el resto por el id.
    $id = isset(GranChance\Fichas::LINEAS[$tipo]) ? (string)($_GET['nombre'] ?? '') : (int)($_GET['id'] ?? 0);
    $html = match (true) {
        $tipo === 'buscar' => $fichas->buscar((string)($_GET['q'] ?? ''), $otrasFichas($bases)),
        $tipo === 'ranking' && empty($_GET['de']) => (new GranChance\Rankings($db, __DIR__, $bases['hk'] ?? null, $bases['br'] ?? null, $bases['ar'] ?? null, $bases['jp'] ?? null))->indice(),
        $tipo === 'ranking' => (new GranChance\Rankings($db, __DIR__, $bases['hk'] ?? null, $bases['br'] ?? null, $bases['ar'] ?? null, $bases['jp'] ?? null))->pagina((string)$_GET['de'], $_GET),
        $pais !== null => isset($bases[$pais]) ? (new GranChance\Fichas($bases[$pais], __DIR__, $pais, $exterior))->pagina($tipo, $id, isset($_GET['todas']), isset($_GET['h']) ? (string)$_GET['h'] : null) : null,
        default => $fichas->pagina($tipo, $id, isset($_GET['todas']), isset($_GET['h']) ? (string)$_GET['h'] : null),
    };
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
