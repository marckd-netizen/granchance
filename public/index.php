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
// Una reunión pasa a las anteriores una hora después de la última carrera (con su hora real: Japón y Hong Kong corren
// de madrugada en Uruguay, la reunión del sábado allá termina el viernes de noche acá). Sin horario, al otro día.
$ahora = time();
$terminada = fn ($r) => !empty($r['ultima']) ? strtotime($r['ultima']) + 3600 <= $ahora : $r['fecha'] < $hoy;
$empieza = fn ($r) => !empty($r['inicio']) ? strtotime($r['inicio']) : strtotime($r['fecha'] . ' 12:00');
$proximas = array_values(array_filter($reuniones, fn ($r) => !$terminada($r)));
usort($proximas, fn ($a, $b) => [$empieza($a), $a['nombre']] <=> [$empieza($b), $b['nombre']]);
$pasadas = array_values(array_filter($reuniones, $terminada));

$variosPaises = count($paises) > 1 && $pais === null;
// Banderas en SVG (los emoji de bandera no se ven en Windows), de 3:2. Un país sin bandera queda con el nombre.
$sol = fn (string $c, float $x, float $y, float $r) => '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="' . $c . '"/>';
$banderas = [
    'Uruguay' => '<rect width="27" height="18" fill="#fff"/><path d="M10 2h17M10 6h17M0 10h27M0 14h27" stroke="#0038a8" stroke-width="2"/>'
        . $sol('#fcd116', 5, 5, 3),
    'Argentina' => '<rect width="27" height="18" fill="#74acdf"/><rect y="6" width="27" height="6" fill="#fff"/>' . $sol('#f6b40e', 13.5, 9, 1.8),
    'Brasil' => '<rect width="27" height="18" fill="#009c3b"/><path d="M13.5 2 25 9 13.5 16 2 9z" fill="#ffdf00"/>' . $sol('#002776', 13.5, 9, 3.6),
    'Hong Kong' => '<rect width="27" height="18" fill="#de2910"/><g fill="#fff">'
        . implode('', array_map(fn ($a) => '<ellipse cx="13.5" cy="6.3" rx="1.6" ry="2.9" transform="rotate(' . $a . ' 13.5 9)"/>', [0, 72, 144, 216, 288])) . '</g>',
    'Japón' => '<rect width="27" height="18" fill="#fff"/>' . $sol('#bc002d', 13.5, 9, 5.4),
];
$bandera = fn (string $p) => isset($banderas[$p])
    ? '<svg class="bandera" viewBox="0 0 27 18" role="img" aria-label="' . $e($p) . '"><title>' . $e($p) . '</title>' . $banderas[$p] . '</svg>'
    : '<small>' . $e($p) . '</small>';
// Hora de la 1ª carrera, en hora de Uruguay; el script de abajo la pasa a la zona horaria del que mira. Si ese día no
// es el de la reunión (Japón, visto desde acá), va también el día.
$primera = function (array $r) use ($e, $dias, $ahora): string {
    if (empty($r['inicio'])) {
        return '<td></td>';
    }
    $t = strtotime($r['inicio']);
    $dia = date('Y-m-d', $t) !== $r['fecha'] ? mb_strtolower(mb_substr($dias[(int)date('w', $t)], 0, 3)) . ' ' . date('d/m', $t) . ' ' : '';
    return '<td>1ª <time class="primera" datetime="' . $e($r['inicio']) . '" data-fecha="' . $e($r['fecha']) . '">' . $dia . date('H:i', $t) . '</time>'
        . ($t <= $ahora ? ' <small>· en curso</small>' : '') . '</td>';
};
$fila = function (array $r, bool $hora = false) use ($e, $dias, $variosPaises, $bandera, $primera): string {
    $t = strtotime($r['fecha']);
    $o = '<tr><td>' . $dias[(int)date('w', $t)] . ' ' . date('d/m', $t) . '</td><td>' . $e($r['nombre'])
        . ($variosPaises ? ' ' . $bandera($r['pais']) : '') . '</td>' . ($hora ? $primera($r) : '') . '<td>'
        . ($r['programa'] ? '<a href="' . $e($r['programa']) . '">Programa</a>' : '') . '</td><td>';
    if ($r['resultado']) {
        $o .= '<a href="' . $e($r['resultado']) . '">Predicción vs resultado</a>';
        if ($r['acierto'] && $r['carreras']) {
            $o .= ' <small>ganó el 1º en ' . $r['acierto']['ganador'] . ' de ' . $r['carreras'] . ($r['acierto']['origen'] === 'reconstruida' ? ' · reconstruida' : '') . '</small>';
        }
    }
    return $o . '</td></tr>';
};
// Las reuniones pasadas por día, ya armadas, para el calendario: el script muestra las del día que se elige.
$porDia = [];
foreach ($pasadas as $r) {
    $porDia[$r['fecha']] = ($porDia[$r['fecha']] ?? '') . $fila($r);
}
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
.proximas td:nth-child(3) { white-space:nowrap; width:10em; }
p.zona { margin:0 0 6px; }
.bandera { width:21px; height:14px; vertical-align:-2px; margin-left:2px; border-radius:2px; box-shadow:0 0 0 1px var(--linea); }
.historial { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start; }
.calendario { width:252px; flex:none; border:1px solid var(--linea); border-radius:8px; padding:8px; }
.calendario .mes { display:flex; justify-content:space-between; align-items:center; font-weight:600; margin-bottom:6px; }
.calendario button { font:inherit; color:var(--acento); background:none; border:0; padding:2px 8px; cursor:pointer; border-radius:6px; }
.calendario button:disabled { color:var(--linea); cursor:default; }
.calendario .dias { display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; }
.calendario .dias span { color:var(--suave); font-size:12px; padding:2px 0; }
.calendario .dias button { padding:5px 0; color:var(--suave); }
.calendario .dias button.hay { color:var(--acento); background:var(--cabecera); font-weight:600; }
.calendario .dias button.hay:hover { outline:1px solid var(--acento); }
.calendario .dias button.elegido { background:var(--acento); color:var(--fondo); }
.dia { flex:1 1 360px; min-width:0; }
.dia h3 { font-size:15px; margin:0 0 6px; }
.dia td:first-child { display:none; }
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
<p class="zona"><small>Hora de la primera carrera, en hora de Uruguay.</small></p>
<table class="proximas"><?php foreach ($proximas as $r) { echo $fila($r, true); } ?></table>
<script>
(() => {
    // La hora de la 1ª carrera en la zona horaria del que mira (con el día si no es el de la reunión).
    const hora = new Intl.DateTimeFormat('es', {hour: '2-digit', minute: '2-digit', hourCycle: 'h23'});
    const fecha = new Intl.DateTimeFormat('en-CA', {year: 'numeric', month: '2-digit', day: '2-digit'});
    const dia = new Intl.DateTimeFormat('es', {weekday: 'short'});
    document.querySelectorAll('time.primera').forEach((t) => {
        const d = new Date(t.getAttribute('datetime'));
        if (isNaN(d)) return;
        const f = fecha.format(d);
        t.title = 'Hora de Uruguay: ' + t.textContent;
        t.textContent = (f !== t.dataset.fecha ? dia.format(d).replace('.', '') + ' ' + f.slice(8, 10) + '/' + f.slice(5, 7) + ' ' : '') + hora.format(d);
    });
    const z = document.querySelector('p.zona small');
    if (z) z.textContent = 'Hora de la primera carrera en tu zona horaria (' + Intl.DateTimeFormat().resolvedOptions().timeZone + ').';
})();
</script>
<?php endif ?>
<?php if ($pasadas): ?>
<h2>Reuniones anteriores</h2>
<div class="historial" id="historial" hidden>
<div class="calendario"><div class="mes"><button type="button" data-ir="-1" aria-label="Mes anterior">‹</button><span></span><button type="button" data-ir="1" aria-label="Mes siguiente">›</button></div><div class="dias"></div></div>
<div class="dia"><h3></h3><table></table></div>
</div>
<h2>Últimas <?= min(20, count($pasadas)) ?> reuniones</h2>
<table><?php foreach (array_slice($pasadas, 0, 20) as $r) { echo $fila($r); } ?></table>
<script>
(() => {
    const porDia = <?= json_encode($porDia, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const meses = <?= json_encode(array_values($meses), JSON_UNESCAPED_UNICODE) ?>;
    const dias = <?= json_encode($dias, JSON_UNESCAPED_UNICODE) ?>;
    const fechas = Object.keys(porDia).sort();
    const caja = document.getElementById('historial'), titulo = caja.querySelector('.mes span'), grilla = caja.querySelector('.dias');
    const primero = fechas[0].slice(0, 7), ultimo = fechas[fechas.length - 1].slice(0, 7);
    let elegido = fechas[fechas.length - 1], mes = ultimo;
    const elegir = (f) => {
        elegido = f;
        const [a, m, d] = f.split('-').map(Number);
        caja.querySelector('.dia h3').textContent = dias[new Date(a, m - 1, d).getDay()] + ' ' + d + ' de ' + meses[m - 1].toLowerCase() + ' de ' + a;
        caja.querySelector('.dia table').innerHTML = porDia[f];
        dibujar();
    };
    const dibujar = () => {
        const [a, m] = mes.split('-').map(Number);
        titulo.textContent = meses[m - 1] + ' ' + a;
        caja.querySelector('[data-ir="-1"]').disabled = mes <= primero;
        caja.querySelector('[data-ir="1"]').disabled = mes >= ultimo;
        let html = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'].map((n) => '<span>' + n + '</span>').join('');
        html += '<span></span>'.repeat((new Date(a, m - 1, 1).getDay() + 6) % 7);
        for (let d = 1, n = new Date(a, m, 0).getDate(); d <= n; d++) {
            const f = mes + '-' + String(d).padStart(2, '0');
            html += porDia[f]
                ? '<button type="button" class="hay' + (f === elegido ? ' elegido' : '') + '" data-fecha="' + f + '">' + d + '</button>'
                : '<button type="button" disabled>' + d + '</button>';
        }
        grilla.innerHTML = html;
    };
    caja.addEventListener('click', (ev) => {
        const b = ev.target.closest('button');
        if (!b || b.disabled) return;
        if (b.dataset.fecha) {
            elegir(b.dataset.fecha);
        } else {
            const [a, m] = mes.split('-').map(Number), x = new Date(a, m - 1 + Number(b.dataset.ir), 1);
            mes = x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0');
            dibujar();
        }
    });
    caja.hidden = false;
    elegir(elegido);
})();
</script>
<?php endif ?>
<?php if (!empty($indice['actualizado'])): ?>
<p><small>Actualizado el <?= date('d/m/Y H:i', strtotime($indice['actualizado'])) ?>.</small></p>
<?php endif ?>
</body>
</html>
