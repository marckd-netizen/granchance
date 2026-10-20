<?php
// Completa país, pelaje, sexo, padres, tipo de importación y propietario con la ficha de cada caballo de Hong Kong.
// Reanudable: solo pide las fichas que faltan. Uso: php bin/hk_caballos.php [--limite=100000] [--parte=0/4]
//   --parte=i/n  reparte los caballos entre n procesos en paralelo (este hace el i-ésimo)
require __DIR__ . '/hk_comun.php';

use GranChance\HK\{ParserResultados, Repositorio};

$opc = opciones($argv);
$cli = clienteHk($hk);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($hk['db']);
[$parte, $partes] = array_map('intval', explode('/', (string)($opc['parte'] ?? '0/1')) + [1 => 1]);
$n = 0;
foreach ($repo->caballosSinFicha((int)($opc['limite'] ?? 100000), $parte, $partes) as $codigo) {
    try {
        $repo->guardarFicha($codigo, $parser->ficha($cli->caballo($codigo)));
        if (++$n % 200 === 0) {
            registro("$n fichas");
        }
    } catch (\Throwable $e) {
        registro("$codigo: ERROR: {$e->getMessage()}");
    }
}
registro("Fin: $n fichas");
