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
| `php bin/tiempos.php [--mostrar]` | Corrige en la base los tiempos imposibles que trae el Excel (la diferencia con el ganador en lugar del tiempo, o un dígito mal): el tiempo pasa a ser el del ganador más 0,17 s por cuerpo, como lo calculan las planillas. Al leer un Excel nuevo se corrige solo. Después, `bin/velocidades.php` y `bin/modelo.php`. |
| `php bin/velocidades.php [--mostrar=1]` | Recalcula las velocidades normales (par) por categoría y los ajustes por pista y viento. Correrlo después de `diario.php`. |
| `php bin/records.php` | Arma el mapa récord → distancia con los programas de `raw/programas` (el Programa no trae la distancia). Una vez, y de vez en cuando. |
| `php bin/studs.php` | Baja del Stud Book Uruguayo los studs registrados con sus colores (`stud_colores`); el programa los muestra junto al stud y dibuja la chaquetilla (`src/Chaquetilla.php`). |
| `php bin/prensa.php --bajar [--desde=2024-01-01] [--hipodromos=1,13]` | Baja del concurso de prensa (servicios.maronas.com.uy) el favorito, enemigo y sorpresa de cada periodista en Maroñas y Las Piedras (`prensa_pronostico`). Sigue donde quedó. El programa baja solo la reunión del día si todavía no está. |
| `php bin/prensa.php --ajustar` | Entrena el modelo y ajusta, con las carreras que no vio, cuánto pesa la prensa en cada hipódromo (`prensa_coef`). Muestra el acierto con y sin prensa. Después correr `php bin/modelo.php`. |
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
  ese jockey, ese cuidador, ese padre, esa madre y ese abuelo materno, y si el caballo rinde mejor o peor que su promedio
  en el hipódromo y la superficie de hoy (sobre todo en el césped de Maroñas). Los puntajes se dividen por una escala calibrada con los
  últimos 2 años para que las probabilidades altas no salgan exageradas.
  Entrenado con 2019-2023 y medido en 2024-2026: el de más probabilidad gana el 30,7 % (la proyección sola, 27,7 %; el
  favorito de las apuestas, 35 %). Se probaron y no mejoran: los parciales (tiempos del puntero), la racha de 30 días de
  jockey y cuidador, el peso del caballo y el tratamiento, un modelo de árboles (LightGBM) en lugar del logit, bajarle el peso (o sumarle velocidad) a las carreras en que llegó 6º o peor a más de 10 cuerpos, y el peso del caballo estimado para hoy (el de su última carrera más lo que cambia según los días: con 10 días o menos baja 2,1 kg en promedio) comparado con el de su carrera más rápida, junto con "le faltaba la carrera" (segunda carrera después de un descanso de 60 días o más), y los kilos: corregir el índice de cada carrera anterior por los kilos que llevó (0,045 a 0,18 km/h por kilo) o sumar la diferencia de kilos con su última carrera, y cómo rinde cada caballo según los días de descanso (lo que un caballo rindió con descansos cortos o largos casi no se repite: correlación 0,06 entre dos mitades de su campaña), y si el caballo rinde mejor con calor (diciembre a febrero) o con frío (junio a agosto): la preferencia existe (correlación 0,28 entre temporadas pares e impares, contra 0,14 de otoño contra primavera), pero sumada al modelo deja el acierto igual (30,8 % del ganador, trifecta 11,3 % contra 11,2 %). Correrlo después de
  `bin/velocidades.php`.
- **Prensa** (`src/Prensa.php`): en Maroñas y Las Piedras, cuando el concurso de prensa ya publicó la reunión, la
  probabilidad se combina con los votos: `b_modelo · log(prob) + b_fav · F + b_ene · E + b_sor · S`, con F, E y S la parte
  de los periodistas que lo da de favorito, enemigo o sorpresa. El resumen muestra los votos y la probabilidad sin prensa.
  Medido de octubre de 2025 a octubre de 2026 con carreras que ni el modelo ni el ajuste vieron: en Maroñas el de más
  probabilidad gana el 30,4 % (27,4 % sin prensa) y la trifecta con sus 3 primeros sale el 13,0 % (9,9 %); en Las Piedras,
  34,0 % (30,6 %) y 13,6 % (10,6 %).
- Notas: debutante en el hipódromo o la superficie, primera vez con luz artificial, días sin correr, sube o baja de categoría
  (par de la categoría de su última carrera contra el de hoy, en el hipódromo y la distancia de hoy), vuelve con un jockey
  que ya lo montó (con cuántas ganó y fue placé), sube de peso.
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

### Programa no oficial de Hong Kong

El mismo programa y el mismo modelo que en Uruguay, con los datos del HKJC (`src/HK/Velocidades.php`, `src/HK/Modelo.php`,
`src/HK/ProgramaNoOficial.php`). El logit (ajuste, calibración y medición) es común a los dos: `src/LogitCondicional.php`.

| Comando | Qué hace |
|---|---|
| `php bin/hk_modelo.php [--desde=2017-01-01] [--probar=AAAA-MM-DD]` | Calcula las velocidades y entrena el modelo con las carreras de HK; guarda los coeficientes en `modelo_coef` de `granchance_hk`. Tarda menos de un minuto. |
| `php bin/hk_programa.php --fecha=AAAA-MM-DD --hipodromo=HV\|ST [--salida=archivo.html]` | Baja el Race Card de cada carrera (`racecard?racedate=...&Racecourse=HV&RaceNo=N`, queda en `raw/hk/.../programa_NN.html.gz`) y arma el programa no oficial en `raw/hk/programas/AAAA-MM-DD_<HV\|ST>.html`. El HKJC publica el programa dos días antes; conviene volver a correrlo el día de la reunión (retirados, cambios de jockey). |

Qué cambia respecto de Uruguay:

- **Par** por hipódromo, distancia, superficie (césped o arena sintética) y **clase** (Class 1 a 5, G1/G2/G3, Griffin), con los 4
  primeros en pista GOOD. No se separa por edad ni sexo: casi todos son castrados de 3 a 9 años. Cada caballo tiene su propio tiempo.
- **Ajustes**: el HKJC no publica el viento. Se mide el going (GOOD TO FIRM, YIELDING, WET SLOW...) y, sobre eso, el **riel**
  (A, B, C+3...), que mueve los tiempos casi lo mismo. Se probó además una variante por reunión (lo que todavía se apartan del par
  todas las carreras del día) y no mejora el acierto (24,7 % contra 24,5 %).
- **Equivalencia**: Sha Tin = 0; Happy Valley da +0,21 km/h con los mismos caballos.
- **Modelo**: las mismas 40 variables. El mercado sale de la cuota final a ganador; los kilos, del peso llevado; jockeys y
  cuidadores por nombre completo (hay muchos Leung, Chan y Wong con la misma inicial); "sprint" es 1000 m y "fondo" 1800 m o más;
  corrió = tiene cuota final (WV/WX no corrieron; PU, UR, FE y DNF cuentan como perdedores).
- El HKJC no guarda el rating de cada caballo en cada carrera (solo el de hoy, en el programa): se muestra pero no entra al modelo.
  En los handicaps el peso ya lo refleja (más rating, más peso).
- Entrenado con 2017 a octubre de 2024 y medido con las 1.717 carreras de octubre de 2024 a octubre de 2026: el de más
  probabilidad gana el **24,5 %** (la proyección sola, 20,1 %; el favorito de las cuotas, 30,1 %). Trifecta sin orden con sus 3
  primeros, 4,4 %; exacta sin orden, 20,2 %. Bien calibrado: a los que les da 24 % ganan 22 %; a los de 44 %, 43 %.
  El mercado de Hong Kong es mucho más eficiente que el de Uruguay (allá el favorito gana 35 % y el modelo 30,7 %).
- El programa muestra además rating y su cambio, banda de rating de la carrera, riel, equipo (avisa la primera vez con
  anteojeras, capucha...), peso del caballo, posiciones en cada tramo, la cuota final de cada antecedente y la chaquetilla oficial.
- Debutantes: sin carreras en HK. Los PPG llegan con campaña en el exterior que la base no tiene.
