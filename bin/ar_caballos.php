<?php
// Completa país, fecha de nacimiento, padres, abuelo materno y criador con la ficha de cada ejemplar de Argentina.
// Reanudable: solo pide las fichas que faltan. Uso: php bin/ar_caballos.php [--limite=100000] [--parte=0/4]
//   --parte=i/n  reparte los caballos entre n procesos en paralelo (este hace el i-ésimo)
require __DIR__ . '/ar_comun.php';

use GranChance\AR\{ParserResultados, Repositorio};

$opc = opciones($argv);
$cli = clienteAr($ar);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($ar['db']);
[$parte, $partes] = array_map('intval', explode('/', (string)($opc['parte'] ?? '0/1')) + [1 => 1]);
$n = 0;
foreach ($repo->caballosSinFicha((int)($opc['limite'] ?? 100000), $parte, $partes) as $c) {
    try {
        $repo->guardarFicha((int)$c['sb_id'], $parser->ficha($cli->ejemplar((int)$c['sb_id'], $c['slug'])));
        if (++$n % 200 === 0) {
            registro("$n fichas");
        }
    } catch (\Throwable $e) {
        registro("{$c['sb_id']}: ERROR: {$e->getMessage()}");
    }
}
registro("Fin: $n fichas");
