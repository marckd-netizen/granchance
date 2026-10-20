<?php
// Completa país, pelaje, sexo, padres, tipo de importación y propietario con la ficha de cada caballo de Hong Kong.
// Reanudable: solo pide las fichas que faltan. Uso: php bin/hk_caballos.php [--limite=100000]
require __DIR__ . '/hk_comun.php';

use GranChance\HK\{ParserResultados, Repositorio};

$opc = opciones($argv);
$cli = clienteHk($hk);
$parser = new ParserResultados();
$repo = Repositorio::desdeConfig($hk['db']);
$n = 0;
foreach ($repo->caballosSinFicha((int)($opc['limite'] ?? 100000)) as $codigo) {
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
