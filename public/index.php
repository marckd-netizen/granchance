<?php
// Portada de granchance.com: lista los programas no oficiales y las páginas de predicción vs resultado que publicó
// bin/publicar.php en esta carpeta. No usa la base: lee indice.json.
declare(strict_types=1);
date_default_timezone_set('America/Montevideo');

$indice = is_file(__DIR__ . '/indice.json') ? json_decode((string)file_get_contents(__DIR__ . '/indice.json'), true) : null;
$reuniones = $indice['reuniones'] ?? [];
$hoy = date('Y-m-d');
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// Filtro por hipódromo: /hipodromo-rocha (el .htaccess lo pasa como ?h=rocha). Las direcciones viejas
// (?hipodromo=22) redirigen a la nueva.
$slug = fn (string $n) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n']))), '-');
$hips = [];
foreach ($reuniones as $r) {
    $hips[$r['hipodromo']] = $r['nombre'];
}
asort($hips);
$porSlug = [];
foreach ($hips as $id => $n) {
    $porSlug[$slug($n)] = $id;
}
if (isset($_GET['hipodromo'], $hips[(int)$_GET['hipodromo']])) {
    header('Location: hipodromo-' . $slug($hips[(int)$_GET['hipodromo']]), true, 301);
    exit;
}
$filtro = isset($_GET['h']) ? ($porSlug[(string)$_GET['h']] ?? null) : null;
if (isset($_GET['h']) && $filtro === null) {
    http_response_code(404);
}
if ($filtro !== null) {
    $reuniones = array_values(array_filter($reuniones, fn ($r) => $r['hipodromo'] === $filtro));
}
$proximas = array_reverse(array_values(array_filter($reuniones, fn ($r) => $r['fecha'] >= $hoy)));
$pasadas = array_values(array_filter($reuniones, fn ($r) => $r['fecha'] < $hoy));
$porMes = [];
foreach ($pasadas as $r) {
    $porMes[substr($r['fecha'], 0, 7)][] = $r;
}

$fila = function (array $r) use ($e, $dias): string {
    $t = strtotime($r['fecha']);
    $o = '<tr><td>' . $dias[(int)date('w', $t)] . ' ' . date('d/m', $t) . '</td><td>' . $e($r['nombre']) . '</td><td>'
        . ($r['programa'] ? '<a href="' . $e($r['programa']) . '">Programa</a>' : '') . '</td><td>';
    if ($r['resultado']) {
        $o .= '<a href="' . $e($r['resultado']) . '">Predicción vs resultado</a>';
        if ($r['acierto'] && $r['carreras']) {
            $o .= ' <small>ganó el 1º en ' . $r['acierto']['ganador'] . ' de ' . $r['carreras'] . ($r['acierto']['origen'] === 'reconstruida' ? ' · reconstruida' : '') . '</small>';
        }
    }
    return $o . '</td></tr>';
};
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $filtro !== null ? 'Reuniones del hipódromo de ' . $e($hips[$filtro]) . ' · GranChance' : 'GranChance · Programas no oficiales' ?></title>
<?php $ga = (string)($indice['analytics'] ?? ''); if (preg_match('/^G-[A-Z0-9]+$/', $ga)): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= $ga ?>"></script>
<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', '<?= $ga ?>');</script>
<?php endif ?>
<style>
:root { --fondo:#fff; --texto:#1d1d1f; --suave:#6b6b70; --linea:#e3e3e6; --cabecera:#f2f2f4; --acento:#1f4e8c; }
@media (prefers-color-scheme: dark) { :root { --fondo:#161618; --texto:#ececef; --suave:#a0a0a8; --linea:#34343a; --cabecera:#232327; --acento:#8ab4f8; } }
* { box-sizing:border-box; }
body { margin:0 auto; max-width:900px; padding:16px; background:var(--fondo); color:var(--texto); font:14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
h1 { font-size:24px; margin:4px 0; } h2 { font-size:17px; margin:22px 0 6px; color:var(--acento); }
p, small { color:var(--suave); }
a { color:var(--acento); text-decoration:none; } a:hover { text-decoration:underline; }
table { border-collapse:collapse; width:100%; font-variant-numeric:tabular-nums; }
td { border-bottom:1px solid var(--linea); padding:5px 6px; vertical-align:baseline; }
td:first-child { white-space:nowrap; width:9em; } td:nth-child(2) { width:9em; }
.buscar input { font:inherit; padding:4px 8px; border:1px solid var(--linea); border-radius:6px; background:var(--fondo); color:var(--texto); min-width:240px; }
.buscar button { font:inherit; padding:4px 10px; border:1px solid var(--linea); border-radius:6px; background:var(--cabecera); color:var(--texto); }
.filtro { display:flex; flex-wrap:wrap; gap:6px; margin:10px 0; }
.filtro a { border:1px solid var(--linea); border-radius:14px; padding:2px 10px; background:var(--cabecera); }
.filtro a.actual { background:var(--acento); color:var(--fondo); }
.proximas td { background:var(--cabecera); }
details summary { cursor:pointer; color:var(--acento); font-weight:600; margin:14px 0 4px; }
</style>
</head>
<body>
<h1>GranChance<?= $filtro !== null ? ' · ' . $e($hips[$filtro]) : '' ?></h1>
<p>Programas no oficiales de los hipódromos de Uruguay: antecedentes con velocidades corregidas por pista y viento, índices contra el par de cada categoría y probabilidad de ganar. Después de cada reunión, la predicción comparada con el resultado. <a href="resultados/estadisticas.html">Acierto acumulado</a>.</p>
<p><small>Carreras en tu calendario: cada carrera del programa tiene un 📅 para agregarla a Google Calendar, y arriba un enlace para agregar todas las de la reunión. Para tenerlas todas siempre al día, en Google Calendar elegí «Otros calendarios › Desde URL» y pegá <code>https://granchance.com/calendario.ics</code>.</small></p>
<form class="buscar" action="buscar"><input name="q" placeholder="Buscar caballo, jockey o cuidador"> <button>Buscar</button></form>
<?php if ($hips): ?>
<div class="filtro"><a href="./"<?= $filtro === null ? ' class="actual"' : '' ?>>Todos</a><?php foreach ($hips as $id => $n): ?><a href="hipodromo-<?= $slug($n) ?>"<?= $filtro === $id ? ' class="actual"' : '' ?>><?= $e($n) ?></a><?php endforeach ?></div>
<?php endif ?>
<?php if (!$reuniones): ?>
<p>Todavía no hay programas publicados.</p>
<?php endif ?>
<?php if ($proximas): ?>
<h2>Próximas reuniones</h2>
<table class="proximas"><?php foreach ($proximas as $r) { echo $fila($r); } ?></table>
<?php endif ?>
<?php $i = 0; foreach ($porMes as $mes => $lista): [$a, $m] = explode('-', $mes); ?>
<?php if ($i++ < 2): ?>
<h2><?= $meses[(int)$m] . ' ' . $a ?></h2>
<table><?php foreach ($lista as $r) { echo $fila($r); } ?></table>
<?php else: ?>
<details><summary><?= $meses[(int)$m] . ' ' . $a ?> (<?= count($lista) ?>)</summary>
<table><?php foreach ($lista as $r) { echo $fila($r); } ?></table></details>
<?php endif ?>
<?php endforeach ?>
<?php if (!empty($indice['actualizado'])): ?>
<p><small>Actualizado el <?= date('d/m/Y H:i', strtotime($indice['actualizado'])) ?>.</small></p>
<?php endif ?>
</body>
</html>
