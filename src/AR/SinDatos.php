<?php
declare(strict_types=1);

namespace GranChance\AR;

/** La página no existe (404) o no trae lo que se esperaba: no se reintenta. */
final class SinDatos extends \RuntimeException
{
}
