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

// Filtro por país (/pais-hong-kong, ?p=hong-kong) o por hipódromo (/hipodromo-rocha, ?h=rocha). Las direcciones viejas
// (?hipodromo=22) redirigen a la nueva. Los índices de antes de Hong Kong no traen el país: son de Uruguay.
$slug = fn (string $n) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n',
    'ã' => 'a', 'â' => 'a', 'à' => 'a', 'ê' => 'e', 'ô' => 'o', 'õ' => 'o', 'ç' => 'c', 'ü' => 'u']))), '-');
$hips = [];
$paises = [];       // país => [hipódromo => nombre], para el árbol
foreach ($reuniones as &$r) {
    $r['pais'] ??= 'Uruguay';
    $hips[$r['hipodromo']] = $r['nombre'];
    $paises[$r['pais']][$r['hipodromo']] = $r['nombre'];
}
unset($r);
asort($hips);
// Uruguay primero; dentro de cada país, los hipódromos por nombre.
uksort($paises, fn ($a, $b) => [$a !== 'Uruguay', $a] <=> [$b !== 'Uruguay', $b]);
foreach ($paises as &$hs) {
    asort($hs);
}
unset($hs);
$porPais = [];
foreach (array_keys($paises) as $p) {
    $porPais[$slug($p)] = $p;
}
$porSlug = [];
foreach ($hips as $id => $n) {
    $porSlug[$slug($n)] = $id;
}
if (isset($_GET['hipodromo'], $hips[(int)$_GET['hipodromo']])) {
    header('Location: hipodromo-' . $slug($hips[(int)$_GET['hipodromo']]), true, 301);
    exit;
}
$filtro = isset($_GET['h']) ? ($porSlug[(string)$_GET['h']] ?? null) : null;
$pais = isset($_GET['p']) ? ($porPais[(string)$_GET['p']] ?? null) : null;
if ((isset($_GET['h']) && $filtro === null) || (isset($_GET['p']) && $pais === null)) {
    http_response_code(404);
}
if ($filtro !== null) {
    $reuniones = array_values(array_filter($reuniones, fn ($r) => $r['hipodromo'] === $filtro));
    $pais = $reuniones[0]['pais'] ?? null;
} elseif ($pais !== null) {
    $reuniones = array_values(array_filter($reuniones, fn ($r) => $r['pais'] === $pais));
}
$titulo = $filtro !== null ? $hips[$filtro] : $pais;
$proximas = array_reverse(array_values(array_filter($reuniones, fn ($r) => $r['fecha'] >= $hoy)));
$pasadas = array_values(array_filter($reuniones, fn ($r) => $r['fecha'] < $hoy));
$porMes = [];
foreach ($pasadas as $r) {
    $porMes[substr($r['fecha'], 0, 7)][] = $r;
}

$variosPaises = count($paises) > 1 && $pais === null;
$fila = function (array $r) use ($e, $dias, $variosPaises): string {
    $t = strtotime($r['fecha']);
    $o = '<tr><td>' . $dias[(int)date('w', $t)] . ' ' . date('d/m', $t) . '</td><td>' . $e($r['nombre'])
        . ($variosPaises ? ' <small>' . $e($r['pais']) . '</small>' : '') . '</td><td>'
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
<title><?= $filtro !== null ? 'Reuniones del hipódromo de ' . $e($hips[$filtro]) . ' · GranChance' : ($pais !== null ? 'Reuniones de ' . $e($pais) . ' · GranChance' : 'GranChance · Programas no oficiales') ?></title>
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
.filtro { margin:10px 0; }
.filtro a { display:inline-block; border:1px solid var(--linea); border-radius:14px; padding:2px 10px; background:var(--cabecera); margin:2px 0; }
.filtro a.actual { background:var(--acento); color:var(--fondo); }
.filtro ul { list-style:none; margin:4px 0 0; padding:0; }
.filtro li { margin:4px 0; }
.filtro .pais { font-weight:600; }
.filtro .hips { display:flex; flex-wrap:wrap; gap:6px; margin:2px 0 0 18px; padding-left:10px; border-left:2px solid var(--linea); }
.proximas td { background:var(--cabecera); }
details summary { cursor:pointer; color:var(--acento); font-weight:600; margin:14px 0 4px; }
</style>
</head>
<body>
<h1>GranChance<?= $titulo !== null ? ' · ' . $e($titulo) : '' ?></h1>
<p>Programas no oficiales de los hipódromos de Uruguay y del mundo: antecedentes con velocidades corregidas por pista y viento, índices contra el par de cada categoría y probabilidad de ganar, generados objetivamente por modelos matemáticos.</p>
<p>Después de cada reunión, la predicción es comparada con el resultado, con total transparencia. <a href="resultados/estadisticas.html">Acierto acumulado</a> · <a href="ranking">Rankings</a>.</p>
<p><small>Carreras en tu calendario: cada carrera del programa tiene un 📅 para agregarla a Google Calendar, y arriba un enlace para agregar todas las de la reunión. Para tenerlas todas siempre al día, en Google Calendar elegí «Otros calendarios › Desde URL» y pegá <code>https://granchance.com/calendario.ics</code>.</small></p>
<form class="buscar" action="buscar"><input name="q" placeholder="Buscar caballo, jockey, cuidador, stud o criador"> <button>Buscar</button></form>
<?php if ($hips): ?>
<nav class="filtro"><a href="./"<?= $filtro === null && $pais === null ? ' class="actual"' : '' ?>>Todos</a>
<ul><?php foreach ($paises as $p => $hs): ?>
<li><a class="pais<?= $filtro === null && $pais === $p ? ' actual' : '' ?>" href="pais-<?= $slug($p) ?>"><?= $e($p) ?></a>
<div class="hips"><?php foreach ($hs as $id => $n): ?><a href="hipodromo-<?= $slug($n) ?>"<?= $filtro === $id ? ' class="actual"' : '' ?>><?= $e($n) ?></a><?php endforeach ?></div></li>
<?php endforeach ?></ul></nav>
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
