-- Esquema MySQL 5.7+/MariaDB 10.3+ para los resultados de Hong Kong (HKJC: Sha Tin y Happy Valley).
-- Base aparte (granchance_hk) con las mismas tablas que la de Uruguay, más lo que solo publica el HKJC:
-- tiempos y posiciones por tramo (carrera_parcial, actuacion_parcial), código de caballo, gatera, rating.
-- Los pesos se guardan en libras, como los publica el HKJC, y también en kg para comparar con Uruguay.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hipodromo (
  id      SMALLINT UNSIGNED PRIMARY KEY,
  codigo  CHAR(2) NOT NULL,                       -- ST, HV (como en la URL del HKJC)
  nombre  VARCHAR(80) NOT NULL,
  UNIQUE KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO hipodromo (id, codigo, nombre) VALUES (1, 'ST', 'Sha Tin'), (2, 'HV', 'Happy Valley');

-- Reuniones del calendario oficial (Fixtures) y si ya se bajaron. Permite reanudar el histórico.
CREATE TABLE IF NOT EXISTS descarga (
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  estado        ENUM('pendiente','ok','vacio','error') NOT NULL,
  turno         CHAR(1) NULL,                   -- D día, N noche (del calendario)
  pistas        VARCHAR(10) NULL,               -- turf, awt, mixed (del calendario)
  bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  detalle       VARCHAR(255) NULL,
  consultado_en DATETIME NULL,
  PRIMARY KEY (hipodromo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reunion (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hipodromo_id    SMALLINT UNSIGNED NOT NULL,
  fecha           DATE NOT NULL,
  nro_reunion     SMALLINT UNSIGNED NULL,         -- el HKJC no lo publica
  total_apostado  DECIMAL(14,2) NULL,             -- el HKJC no lo publica en resultados
  turno           CHAR(1) NULL,                   -- D día, N noche (del calendario)
  pistas          VARCHAR(10) NULL,               -- turf, awt, mixed (del calendario)
  archivo_crudo   VARCHAR(255) NULL,              -- carpeta de los HTML crudos
  actualizado_en  DATETIME NOT NULL,
  UNIQUE KEY (hipodromo_id, fecha),
  FOREIGN KEY (hipodromo_id) REFERENCES hipodromo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS carrera (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reunion_id      INT UNSIGNED NOT NULL,
  nro_oficial     INT UNSIGNED NULL,              -- número de carrera en la temporada: "RACE 1 (103)"
  numero          TINYINT UNSIGNED NOT NULL,      -- 1ª, 2ª...
  premio          VARCHAR(150) NULL,              -- HEUNG YIP HANDICAP
  distancia       SMALLINT UNSIGNED NULL,         -- metros
  hora            DATETIME NULL,                  -- el HKJC no la publica en resultados
  superficie      VARCHAR(10) NULL,               -- césped / sintética (all weather track)
  recorrido       VARCHAR(20) NULL,               -- riel: "A", "B", "C+3"... (solo césped)
  pista           VARCHAR(40) NULL,               -- going tal cual: GOOD, GOOD TO FIRM, WET SLOW...
  viento          VARCHAR(40) NULL,               -- el HKJC no lo publica
  clase           VARCHAR(40) NULL,               -- Class 4, Group One, Griffin Race...
  rating          VARCHAR(20) NULL,               -- banda de rating: 60-40
  condicion       TEXT NULL,                      -- la línea de condición completa
  bolsa           DECIMAL(12,2) NULL,             -- HK$
  bolsa_detalle   VARCHAR(255) NULL,
  tiempo_ganador  VARCHAR(12) NULL,               -- m:ss.cc tal cual
  tiempo_ganador_seg DECIMAL(7,2) NULL,
  parciales       VARCHAR(150) NULL,              -- tiempos por tramo del puntero, separados por espacio
  retirados       VARCHAR(255) NULL,              -- números de los retirados (WV, WX...)
  comentario      TEXT NULL,                      -- Racing Incident Report
  UNIQUE KEY (reunion_id, numero),
  FOREIGN KEY (reunion_id) REFERENCES reunion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tiempos del puntero por tramo (el primero suele ser corto, el resto de 400 m; en carreras nuevas, con parciales de 200 m).
CREATE TABLE IF NOT EXISTS carrera_parcial (
  carrera_id      INT UNSIGNED NOT NULL,
  tramo           TINYINT UNSIGNED NOT NULL,      -- 1, 2, 3...
  tiempo_acum     DECIMAL(7,2) NULL,              -- segundos desde la largada al final del tramo
  tiempo          DECIMAL(6,2) NULL,              -- segundos del tramo
  sub200          VARCHAR(30) NULL,               -- parciales de 200 m dentro del tramo ("11.67 11.73")
  PRIMARY KEY (carrera_id, tramo),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El HKJC identifica cada caballo con un código único (HK_2012_P420): ese es la clave.
CREATE TABLE IF NOT EXISTS caballo (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_hkjc     VARCHAR(20) NOT NULL,           -- HK_2012_P420 (2012 = temporada de importación)
  marca           VARCHAR(8) NULL,                -- P420, tal cual figura junto al nombre
  nombre          VARCHAR(60) NOT NULL,
  anio_nac        SMALLINT UNSIGNED NULL,         -- el HKJC no publica el año de nacimiento
  pais            VARCHAR(4) NULL,                -- país de origen (AUS, NZ, IRE...), de la ficha del caballo
  pelaje          VARCHAR(20) NULL,
  sexo            VARCHAR(10) NULL,               -- Gelding, Horse, Colt, Mare...
  padre           VARCHAR(60) NULL,
  madre           VARCHAR(60) NULL,
  abuelo_materno  VARCHAR(60) NULL,
  tipo_importacion VARCHAR(10) NULL,              -- PP, PPG, ISG...
  propietario     VARCHAR(255) NULL,
  ficha_en        DATETIME NULL,                  -- cuándo se leyó la ficha del caballo
  UNIQUE KEY (codigo_hkjc),
  KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS persona (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo    ENUM('jockey','cuidador','stud','criador') NOT NULL,   -- cuidador = trainer
  nombre  VARCHAR(120) NOT NULL,
  codigo  VARCHAR(10) NULL,                       -- jockeyid / trainerid del HKJC cuando figura
  UNIQUE KEY (tipo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS actuacion (
  carrera_id      INT UNSIGNED NOT NULL,
  caballo_id      INT UNSIGNED NOT NULL,
  puesto          TINYINT UNSIGNED NULL,          -- NULL si no tiene puesto numérico
  puesto_texto    VARCHAR(10) NULL,               -- tal cual figura ("1", "2 DH", "WV", "PU", "DISQ"...)
  numero          TINYINT UNSIGNED NULL,          -- número del caballo (Horse No.)
  gatera          TINYINT UNSIGNED NULL,          -- Draw
  peso_caballo_lb SMALLINT UNSIGNED NULL,         -- Declared Horse Weight, libras
  peso_caballo    SMALLINT UNSIGNED NULL,         -- el mismo en kg
  jockey_id       INT UNSIGNED NULL,
  cuidador_id     INT UNSIGNED NULL,
  peso_asignado_lb SMALLINT UNSIGNED NULL,        -- Actual Weight, libras
  peso_asignado   DECIMAL(4,1) NULL,              -- el mismo en kg
  dist_anterior   VARCHAR(20) NULL,               -- el HKJC publica solo la distancia al ganador
  dist_acumulada  VARCHAR(20) NULL,               -- LBW (Length Behind Winner): "---", "HD", "3-1/4"
  posiciones      VARCHAR(40) NULL,               -- Running Position: "3 4 1"
  cuota           DECIMAL(7,1) NULL,              -- Win Odds final (dividendo a ganador = cuota × 10 por HK$10)
  tiempo          VARCHAR(12) NULL,               -- m:ss.cc
  tiempo_seg      DECIMAL(7,2) NULL,
  incidente       TEXT NULL,                      -- Racing Incident Report por caballo (formato desde ~2024)
  PRIMARY KEY (carrera_id, caballo_id),
  KEY (caballo_id), KEY (jockey_id), KEY (cuidador_id),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE,
  FOREIGN KEY (caballo_id) REFERENCES caballo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sectional Time & Position: puesto, distancia al puntero y tiempo propio de cada caballo en cada tramo.
CREATE TABLE IF NOT EXISTS actuacion_parcial (
  carrera_id      INT UNSIGNED NOT NULL,
  caballo_id      INT UNSIGNED NOT NULL,
  tramo           TINYINT UNSIGNED NOT NULL,
  posicion        TINYINT UNSIGNED NULL,
  margen          VARCHAR(12) NULL,               -- cuerpos detrás del puntero: "1-1/4", "N", "HD"
  tiempo          DECIMAL(6,2) NULL,              -- segundos del tramo
  sub200          VARCHAR(30) NULL,               -- parciales de 200 m dentro del tramo
  PRIMARY KEY (carrera_id, caballo_id, tramo),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE,
  FOREIGN KEY (caballo_id) REFERENCES caballo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dividendos por HK$10: WIN, PLACE, QUINELLA, TIERCE, DOUBLE, TRIPLE TRIO... (PLACE y QUINELLA PLACE tienen varias filas).
CREATE TABLE IF NOT EXISTS apuesta (
  carrera_id   INT UNSIGNED NOT NULL,
  tipo         VARCHAR(30) NOT NULL,
  orden        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  monto_base   DECIMAL(10,2) NULL,
  combinacion  VARCHAR(60) NULL,
  dividendo    DECIMAL(12,2) NULL,                -- NULL si no es un número (REFUND, NOT WIN...)
  detalle      VARCHAR(60) NULL,                  -- el texto cuando el dividendo no es un número
  pozo         DECIMAL(12,2) NULL,
  PRIMARY KEY (carrera_id, tipo, orden),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
