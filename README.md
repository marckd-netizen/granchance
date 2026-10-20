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
| `php bin/historico.php --desde=2015-01-01` | Baja todos los días hasta ayer. Si se corta, se vuelve a correr y sigue donde quedó. |
| `php bin/diario.php` | Vuelve a bajar ayer y anteayer (resultados tardíos o corregidos). Pensado para cron: `15 7 * * * php /ruta/bin/diario.php` |
| `php bin/reprocesar.php [--desde=AAAA-MM-DD] [--hasta=AAAA-MM-DD]` | Vuelve a leer los Excel de `raw/` y re-carga esas reuniones sin pedirlas de nuevo (después de corregir el lector). |
| `php bin/superficie.php [--hipodromos=1]` | Completa arena/césped de cada carrera con el Programa del día (el programa la trae desde 2018). |
| `php bin/parsear.php archivo.xls` | Muestra en JSON lo que se lee de un Excel, sin tocar la base. |
| `php bin/velocidades.php [--mostrar=1]` | Recalcula las velocidades normales (par) por categoría y los ajustes por pista y viento. Correrlo después de `diario.php`. |
| `php bin/records.php` | Arma el mapa récord → distancia con los programas de `raw/programas` (el Programa no trae la distancia). Una vez, y de vez en cuando. |
| `php bin/programa.php [--fecha=AAAA-MM-DD] [--hipodromo=1]` | Genera el programa no oficial en HTML (`raw/programas/AAAA-MM-DD_<hip>.html`). También `web/programa.php?fecha=...&hipodromo=1`. |

El servicio tiene datos desde el 3/1/2015. Cuando un hipódromo no corrió ese día, responde con una redirección
a `hipica.maronas.com.uy/RacingInfo/Errors?errorCode=ED0003`; se registra como "vacío" sin seguirla.
La carrera se identifica por (reunión, número): el "Nro." del Excel se repite entre hipódromos y queda en `carrera.nro_oficial`.
Un caballo es el mismo si coinciden nombre, país (URU cuando no figura) y año de nacimiento.

Cada Excel bajado queda en `raw/AAAA/MM/AAAA-MM-DD_<hipodromo>.xls`, así se puede volver a procesar sin pedirlo de nuevo.
La tabla `descarga` registra cada (hipódromo, fecha) consultado y si dio resultados, vacío o error.

## Tablas

`hipodromo`, `reunion`, `carrera`, `actuacion` (una fila por caballo en cada carrera), `caballo`, `persona`
(jockeys, cuidadores, studs y criadores) y `apuesta` (Exacta, Trifecta, Doble, Pick 3/4). Ver `sql/schema.sql`.

## Programa no oficial y velocidades por categoría

Antes de usarlo: `mysql granchance < sql/velocidades.sql` (o dejar que lo cree `bin/velocidades.php`), `php bin/records.php` y `php bin/velocidades.php`.

- **Categoría** de cada carrera (`src/Clasificador.php`): nivel sacado del premio y la condición (G1, G2, G3, Listado, Clásico,
  Handicap, Perdedores, Ganadores de 1, 2, 3 o 4+, Preferencial, Condicional, Cuarto de milla), edad del lote (2a, 3a, 3a+, 4a+)
  y si es solo de hembras.
- **Par** (`par_velocidad`): velocidad promedio en km/h de los 4 primeros en pista seca y sin viento, por hipódromo, distancia,
  superficie y categoría. Con menos de 8 carreras se usa el nivel más general (sin sexo, sin edad, todas las categorías).
- **Ajuste de pista y viento** (`ajuste_condicion`): cuánto más rápido o lento que el par corren los 4 primeros con cada estado
  de pista y viento, por hipódromo, superficie y tramo (corta hasta 1200 m, media hasta 1600 m, larga). Sale de los datos.
- En el programa, cada antecedente muestra la velocidad, el ajuste, la velocidad corregida, el **índice de categoría**
  (contra el par de su categoría) y el **índice** (contra el par general de esa distancia: compara distancias, hipódromos y categorías).
  La **proyección** pondera los índices como el programa viejo: (3 × promedio + último + 2 × promedio en la distancia) / 6, y
  **vs par de hoy** la compara con el par de la categoría de la carrera.
- El Programa no trae la distancia: se deduce del récord que figura en cada carrera (`record_distancia`, o buscando la carrera
  del récord en la base). Si no se encuentra, `bin/programa.php` avisa y se indica con `--distancias=9:1300`.
- Un caballo del programa es el mismo de la base si coinciden nombre, país y año de nacimiento (edad del programa al 1° de julio).

## Hong Kong (HKJC: Sha Tin y Happy Valley)

Baja los resultados oficiales de [racing.hkjc.com](https://racing.hkjc.com/en-us/local/information/localresults) a una base aparte,
`granchance_hk`, con las mismas tablas que la de Uruguay y lo que solo publica el HKJC.

```sh
mysql -u root -p -e "CREATE DATABASE granchance_hk CHARACTER SET utf8mb4"
mysql -u root -p granchance_hk < sql/schema_hk.sql
# completar la sección 'hk' de config.php (ver config.example.php)
```

| Comando | Qué hace |
|---|---|
| `php bin/hk_historico.php --desde=2015-01-01 [--hasta=AAAA-MM-DD]` | Lee el calendario oficial (Fixtures) mes a mes y baja cada reunión. Reanudable. Se puede correr en paralelo por tramos de años. |
| `php bin/hk_diario.php [--dias=3]` | Vuelve a bajar las reuniones de los últimos días sin usar los crudos (correcciones, Incident Report definitivo). Para cron. |
| `php bin/hk_caballos.php` | Completa país, pelaje, sexo, padre, madre, abuelo materno, tipo de importación y propietario con la ficha de cada caballo. |
| `php bin/hk_parsear.php --fecha=2015-10-14 --hipodromo=HV [--carrera=1]` | Muestra en JSON lo que se lee de una carrera, sin tocar la base. |

- No hace falta probar fecha por fecha: el calendario (`fixture?calyear=AAAA&calmonth=MM`, el mes con dos dígitos) marca cada día de carreras
  con ST o HV, día o noche y césped, arena sintética o ambas. Una reunión anotada que no tiene resultados (suspendida por tifón) queda como "vacio".
- Por reunión se piden la página de resultados de cada carrera (`localresults?racedate=AAAA/MM/DD&RaceNo=N`) y una sola página de
  Sectional Time & Position con todas las carreras (`displaysectionaltime?racedate=DD/MM/AAAA&All=True`). Con 1 s entre pedidos, ~30 s por reunión.
- Los crudos quedan en `raw/hk/AAAA/MM/AAAA-MM-DD_<ST|HV>/carrera_NN.html.gz` y `parciales.html.gz`, sin menús ni scripts (~9 KB cada uno).

Diferencias con Uruguay:

- `caballo` se identifica por el código del HKJC (`codigo_hkjc`, ej. `HK_2012_P420`), no por nombre, país y año. El HKJC no publica el año de nacimiento.
- Pesos en libras como los publica el HKJC (`peso_asignado_lb`, `peso_caballo_lb`) y convertidos a kg en `peso_asignado` y `peso_caballo`.
- `actuacion.cuota` es la cuota final a ganador (Win Odds); los dividendos por HK$10 están en `apuesta` (WIN, PLACE, QUINELLA, TIERCE, TRIO,
  FIRST 4, QUARTET, DOUBLE, TREBLE, SIX UP...), con varias filas por tipo cuando corresponde (`orden`).
- `actuacion.posiciones` (Running Position) y `dist_acumulada` (LBW, cuerpos al ganador). `dist_anterior` queda vacío: el HKJC no la publica.
- `carrera.clase` (Class 1-5, Group One...), `rating` (banda, ej. 60-40), `recorrido` (riel "A", "C+3"...), `superficie` césped o sintética.
- Solo HK: `carrera_parcial` (tiempo de cada tramo del puntero y acumulado) y `actuacion_parcial` (puesto, cuerpos detrás del puntero y
  tiempo propio de cada caballo en cada tramo). En carreras recientes, también los parciales de 200 m dentro de los últimos tramos (`sub200`).
- `carrera.comentario` es el Racing Incident Report; desde que el HKJC lo publica por caballo, también está en `actuacion.incidente`.
- No publica en resultados: hora de la carrera, viento ni total apostado (quedan NULL).
