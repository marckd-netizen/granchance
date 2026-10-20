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
| `php bin/studs.php` | Baja del Stud Book Uruguayo los studs registrados con sus colores (`stud_colores`); el programa los muestra junto al stud y dibuja la chaquetilla (`src/Chaquetilla.php`). |
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
- **Equivalencia entre hipódromos** (`equivalencia_hipodromo`): el par de cada hipódromo mide a sus propios caballos, y en
  Maroñas corren mejores. Con los caballos que pasan de un hipódromo a otro en 90 días o menos se mide cuánto cambia su
  índice: Las Piedras +0,52, Colonia +1,06, Florida +1,23, Paysandú +1,22, Melo +1,30, Rocha +1,49 respecto de Maroñas.
  Los índices de los antecedentes se pasan a la escala del hipódromo de hoy (subió el acierto del modelo de 28,2 % a 29,6 %).
- En el programa, cada antecedente muestra la velocidad, el ajuste, la velocidad corregida, el **índice de categoría**
  (contra el par de su categoría) y el **índice** (contra el par general de esa distancia: compara distancias, hipódromos y categorías).
  La **historia** promedia los índices de toda la campaña, pesando más lo reciente (cada carrera pesa la mitad que la de 5
  carreras después). La **proyección** pondera como el programa viejo: (3 × historia + último + 2 × historia en la distancia) / 6,
  y **vs par de hoy** la compara con el par de la categoría de la carrera.
- **Prob.** (`src/Modelo.php`, `php bin/modelo.php [--probar=2024-01-01]`): probabilidad de ganar de un logit condicional
  (softmax por carrera) con la proyección, el mejor índice del año, la campaña (% de placé, último puesto, días, kilos,
  gatera), cómo le fue contra los rivales de hoy cuando corrieron juntos, qué probabilidad le daban los dividendos en sus
  últimas carreras, cómo corren los hijos de su padre (en general y en esa superficie y tramo), de su madre y de su abuelo
  materno, cómo rinde esa gatera en ese hipódromo, distancia y superficie, y el % de ganadas y placé del jockey y del cuidador
  con cualquier caballo, en general y en ese hipódromo y tramo, y aparte en carreras de menos de 900 m y de más de 1600 m
  (especialistas en carreras muy rápidas y de fondo). Para los debutantes, además, cuánto ganan en el debut los caballos de
  ese jockey, ese cuidador, ese padre, esa madre y ese abuelo materno. Los puntajes se dividen por una escala calibrada con los
  últimos 2 años para que las probabilidades altas no salgan exageradas.
  Entrenado con 2019-2023 y medido en 2024-2026: el de más probabilidad gana el 30,7 % (la proyección sola, 27,7 %; el
  favorito de las apuestas, 35 %). Se probaron y no mejoran: los parciales (tiempos del puntero), la racha de 30 días de
  jockey y cuidador, el peso del caballo y el tratamiento, un modelo de árboles (LightGBM) en lugar del logit, bajarle el peso (o sumarle velocidad) a las carreras en que llegó 6º o peor a más de 10 cuerpos, y el peso del caballo estimado para hoy (el de su última carrera más lo que cambia según los días: con 10 días o menos baja 2,1 kg en promedio) comparado con el de su carrera más rápida, junto con "le faltaba la carrera" (segunda carrera después de un descanso de 60 días o más), y los kilos: corregir el índice de cada carrera anterior por los kilos que llevó (0,045 a 0,18 km/h por kilo) o sumar la diferencia de kilos con su última carrera. Correrlo después de
  `bin/velocidades.php`.
- Notas: debutante en el hipódromo o la superficie, primera vez con luz artificial, días sin correr, sube o baja de categoría
  (par de la categoría de su última carrera contra el de hoy, en el hipódromo y la distancia de hoy), vuelve con un jockey
  que ya lo montó (con cuántas ganó y fue placé), sube de peso.
- El Programa no trae la distancia: se deduce del récord que figura en cada carrera (`record_distancia`, o buscando la carrera
  del récord en la base). Si no se encuentra, `bin/programa.php` avisa y se indica con `--distancias=9:1300`.
- Un caballo del programa es el mismo de la base si coinciden nombre, país y año de nacimiento (edad del programa al 1° de julio).
