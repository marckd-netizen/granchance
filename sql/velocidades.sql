-- Velocidades normales (par) por categoría y ajustes por estado de pista y viento.
-- Las llena bin/velocidades.php a partir de carrera/actuacion; se pueden borrar y recalcular cuando se quiera.
SET NAMES utf8mb4;

-- Categoría de cada carrera: nivel (de la condición), edad y sexo del lote.
CREATE TABLE IF NOT EXISTS carrera_categoria (
  carrera_id   INT UNSIGNED PRIMARY KEY,
  nivel        VARCHAR(20) NOT NULL,           -- G1, Listado, Clásico, Handicap, Perdedores, Ganadores de 1...
  edad         VARCHAR(4) NOT NULL,            -- 2a, 3a, 3a+ (3 y más), 4a+
  sexo         VARCHAR(8) NOT NULL,            -- hembras / mixto
  superficie   VARCHAR(10) NOT NULL,           -- arena / césped / ? (Maroñas sin dato)
  pista        VARCHAR(10) NOT NULL,           -- seca, húmeda, pesada, fangosa
  velocidad_top4 DECIMAL(6,3) NULL,            -- km/h promedio de los 4 primeros
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Par: velocidad promedio (km/h) de los 4 primeros en pista seca y sin viento.
-- '*' en nivel/edad/sexo es el promedio de todas las categorías de ese nivel (sirve de respaldo cuando hay pocas carreras).
CREATE TABLE IF NOT EXISTS par_velocidad (
  hipodromo_id SMALLINT UNSIGNED NOT NULL,
  distancia    SMALLINT UNSIGNED NOT NULL,
  superficie   VARCHAR(10) NOT NULL,
  nivel        VARCHAR(20) NOT NULL,
  edad         VARCHAR(4) NOT NULL,
  sexo         VARCHAR(8) NOT NULL,
  carreras     INT UNSIGNED NOT NULL,
  velocidad    DECIMAL(6,3) NOT NULL,          -- km/h
  desvio       DECIMAL(6,3) NULL,              -- desvío estándar de las velocidades de los 4 primeros
  PRIMARY KEY (hipodromo_id, distancia, superficie, nivel, edad, sexo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cuánto más rápido (+) o lento (-) que el par corren los 4 primeros con ese estado de pista y viento.
-- hipodromo_id 0 = todos los hipódromos; tramo '*' = todas las distancias.
CREATE TABLE IF NOT EXISTS ajuste_condicion (
  hipodromo_id SMALLINT UNSIGNED NOT NULL,
  superficie   VARCHAR(10) NOT NULL,
  tramo        VARCHAR(6) NOT NULL,            -- corta (hasta 1200), media (1300-1600), larga (1700+)
  pista        VARCHAR(10) NOT NULL,
  viento       VARCHAR(20) NOT NULL,
  carreras     INT UNSIGNED NOT NULL,
  ajuste       DECIMAL(6,3) NOT NULL,          -- km/h
  PRIMARY KEY (hipodromo_id, superficie, tramo, pista, viento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El Programa no trae la distancia, pero sí el récord de la distancia. Este mapa récord → distancia se arma con los
-- programas viejos de raw/programas cruzados con los resultados (bin/records.php); cubre los récords anteriores a 2015.
CREATE TABLE IF NOT EXISTS record_distancia (
  hipodromo_id SMALLINT UNSIGNED NOT NULL,
  superficie   VARCHAR(10) NOT NULL,
  record       VARCHAR(150) NOT NULL,
  distancia    SMALLINT UNSIGNED NOT NULL,
  carreras     INT UNSIGNED NOT NULL,          -- cuántas carreras confirman el dato
  PRIMARY KEY (hipodromo_id, superficie, record, distancia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coeficientes del modelo de probabilidad de ganar (src/Modelo.php); los recalcula bin/modelo.php.
CREATE TABLE IF NOT EXISTS modelo_coef (
  variable     VARCHAR(40) PRIMARY KEY,
  media        DOUBLE NOT NULL,                -- para estandarizar la variable
  desvio       DOUBLE NOT NULL,
  coeficiente  DOUBLE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
