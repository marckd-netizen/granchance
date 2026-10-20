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
| `php bin/publicar.php [--borrados] [--desde=AAAA-MM-DD --hasta=AAAA-MM-DD]` | Publica la web en `public/` (o `carpeta_publica`): programas de hoy a 15 días con los cambios detectados, borrados tachados, predicción vs resultado y acierto acumulado. Ver "Publicación en la web". |

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
  jockey y cuidador, el peso del caballo y el tratamiento, un modelo de árboles (LightGBM) en lugar del logit, bajarle el peso (o sumarle velocidad) a las carreras en que llegó 6º o peor a más de 10 cuerpos, y el peso del caballo estimado para hoy (el de su última carrera más lo que cambia según los días: con 10 días o menos baja 2,1 kg en promedio) comparado con el de su carrera más rápida, junto con "le faltaba la carrera" (segunda carrera después de un descanso de 60 días o más), y los kilos: corregir el índice de cada carrera anterior por los kilos que llevó (0,045 a 0,18 km/h por kilo) o sumar la diferencia de kilos con su última carrera, y cómo rinde cada caballo según los días de descanso (lo que un caballo rindió con descansos cortos o largos casi no se repite: correlación 0,06 entre dos mitades de su campaña). Correrlo después de
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

## Publicación en la web (granchance.com)

`bin/publicar.php` arma todo lo que se sirve en la web, como archivos estáticos más una portada en PHP que no usa la base:

| Archivo | Qué es |
|---|---|
| `index.php` | Portada: próximas reuniones y reuniones pasadas por mes, con filtro por hipódromo. Lee `indice.json`. |
| `programas/AAAA-MM-DD_<hip>.html` | Programa no oficial. Arriba, los borrados y los cambios detectados desde que se publicó. El calendario salta entre los programas publicados. |
| `resultados/AAAA-MM-DD_<hip>.html` | Lo que decía el programa (orden por probabilidad) contra la llegada, carrera por carrera, con el acierto de la reunión. |
| `ficha.php` | Fichas de caballos, jockeys, cuidadores, studs y criadores (`/caballo/123-nombre`, `/jockey/…`, `/cuidador/…`, `/stud/…`, `/criador/…`) y el buscador (`/buscar?q=`): todas las carreras corridas y las próximas (tabla `inscripcion`), premios cobrados, puestos por año y por distancia, con filtro por hipódromo. A diferencia de la portada, lee la base; en cPanel encuentra el proyecto con el `.granchance.php` que deja `publicar.php`. Los programas y resultados enlazan cada caballo, jockey, cuidador y stud con su ficha, y el programa suma el criador de cada caballo (sale de la base). |
| `/ranking/…` (`ficha.php`, `src/Rankings.php`) | Rankings de caballos, potrillos, jockeys, cuidadores, studs, criadores, padrillos, madres y abuelos maternos: los 100 mejores (o todos), ordenables por cualquier columna (premios, ganadas, % de ganadas, % entre los 3 primeros, velocidad promedio...) y con filtros por año y mes, hipódromos, distancias, superficie, estado de la pista, mínimo de carreras, y sexo y pelaje para caballos y potrillos. |
| `resultados/estadisticas.html` | Acierto acumulado: total, por hipódromo y por mes, comparado con el favorito del público. |
| `calendario/AAAA-MM-DD_<hip>.ics`, `calendario.ics` | Las carreras como eventos de 5 minutos (los programas preliminares, con horas de relleno 01:00, 02:00..., quedan afuera hasta que salga el definitivo): de una reunión, y de todas desde hace una semana (para suscribirse en Google Calendar con «Desde URL»). En el programa, cada carrera tiene además un 📅 que la agrega sola a Google Calendar. |

Para medir las visitas con Google Analytics 4, poner el ID de medición en `config.php` (`$c['google_analytics'] = 'G-XXXXXXXXXX';`): la etiqueta va en la portada y en todas las páginas generadas.

Cómo trabaja:

- **Programas a 15 días.** Cada corrida vuelve a bajar el Programa de hoy a 15 días (el servicio lo publica unos días antes) y lo
  compara con la lectura anterior (`programa_estado`). Los cambios de monta, horario, peso, caballos agregados o que no correrán
  quedan en `programa_cambio` y se muestran en el programa con la fecha en que se detectaron.
- **Predicciones congeladas.** Cada vez que se genera un programa antes de la reunión se guarda el orden y la probabilidad de cada
  caballo (`prediccion`). Cuando la reunión ya tiene resultados, no se tocan más: la comparación es con lo publicado. Si se
  publica una reunión ya corrida (`--desde`), la predicción se marca como *reconstruida* y se cuenta aparte.
- **Borrados.** `--borrados` lee el servicio que usa hipica.maronas.com.uy/RacingInfo/ScratchedHorses
  (`GetScratchedRaceParticipants`, todos los hipódromos de la fecha), los guarda en `borrado` y regenera los programas de hoy con
  esos caballos tachados (sin probabilidad: el modelo reparte entre los que corren). HRU los publica a las 11:00; fuera de
  11 a 20 h (Uruguay) el comando no hace nada.

Tablas nuevas en `sql/publicacion.sql` (las crea `bin/publicar.php`).

### Instalación en cPanel

1. Subir el proyecto **fuera** de `public_html` (por ejemplo `/home/USUARIO/granchance`), con *Git Version Control* de cPanel
   o por FTP, y correr `composer install` (Terminal de cPanel) o subir también la carpeta `vendor/`.
2. Crear la base y el usuario en *MySQL Databases*, cargar `sql/schema.sql` y el respaldo con *phpMyAdmin* (o `mysql` desde la
   Terminal), y completar `config.php`, con `'carpeta_publica' => '/home/USUARIO/public_html'`. `bin/publicar.php` copia ahí
   `index.php` y `.htaccess`.
3. Elegir PHP 8.1 o más en *MultiPHP Manager* / *Select PHP Version*, con `pdo_mysql`, `curl`, `zip`, `xml`, `gd` y `mbstring`.
4. Primera vez: `php bin/records.php`, `php bin/velocidades.php`, `php bin/modelo.php` y `php bin/publicar.php`.
5. En *Cron Jobs* (la hora es la del servidor; si no está en hora de Uruguay, correr las horas):

```
15 7 * * *  cd /home/USUARIO/granchance && php bin/diario.php && php bin/velocidades.php && php bin/publicar.php >> raw/publicar.log 2>&1
5 * * * *   cd /home/USUARIO/granchance && php bin/publicar.php --borrados >> raw/publicar.log 2>&1
30 4 * * 1  cd /home/USUARIO/granchance && php bin/modelo.php >> raw/modelo.log 2>&1
```

El primero carga los resultados de ayer, publica su predicción vs resultado y relee los programas a 15 días; el segundo toma los
borrados del día (solo actúa de 11 a 20 h, hora de Uruguay); el tercero reentrena el modelo los lunes.
