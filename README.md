# GranChance: resultados de los hipódromos de Uruguay

Descarga los resultados oficiales (el Excel "ResultadoCarreraWeb" que publica el servicio de HRU) y los guarda en MySQL.
Requiere PHP 8.1 o superior con las extensiones `pdo_mysql`, `curl`, `zip`, `xml` y `gd`, y MySQL 5.7+ o MariaDB 10.3+.

## Instalación

```sh
composer install
mysql -u root -p -e "CREATE DATABASE granchance CHARACTER SET utf8mb4"
mysql -u root -p granchance < sql/schema.sql
cp config.example.php config.php   # completar usuario y clave de la base
```

## Uso

| Comando | Qué hace |
|---|---|
| `php bin/descubrir.php` | Prueba `racetrackId` del 1 al 40 en los últimos 14 días y muestra qué hipódromo es cada uno. Con eso se completa `hipodromos` en `config.php`. |
| `php bin/historico.php --desde=2016-01-01` | Baja todos los días hasta ayer. Si se corta, se vuelve a correr y sigue donde quedó. |
| `php bin/diario.php` | Vuelve a bajar ayer y anteayer (resultados tardíos o corregidos). Pensado para cron: `15 7 * * * php /ruta/bin/diario.php` |
| `php bin/parsear.php archivo.xls` | Muestra en JSON lo que se lee de un Excel, sin tocar la base. |

Cada Excel bajado queda en `raw/AAAA/MM/AAAA-MM-DD_<hipodromo>.xls`, así se puede volver a procesar sin pedirlo de nuevo.
La tabla `descarga` registra cada (hipódromo, fecha) consultado y si dio resultados, vacío o error.

## Tablas

`hipodromo`, `reunion`, `carrera`, `actuacion` (una fila por caballo en cada carrera), `caballo`, `persona`
(jockeys, cuidadores, studs y criadores) y `apuesta` (Exacta, Trifecta, Doble, Pick 3/4). Ver `sql/schema.sql`.
