-- Esquema MySQL 5.7+/MariaDB 10.3+ para los resultados de Argentina (Stud Book Argentino: Palermo, San Isidro, La Plata
-- y los hipódromos del interior). Base aparte (granchance_ar) con las mismas tablas y columnas que la de Uruguay, más
-- los identificadores del Stud Book (sb_id) de reuniones, ejemplares y profesionales, que permiten no depender de nombres.
SET NAMES utf8mb4;

-- Los hipódromos se agregan solos a medida que aparecen en el calendario.
CREATE TABLE IF NOT EXISTS hipodromo (
  id      SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo  VARCHAR(40) NOT NULL,                   -- como en el enlace del Stud Book: san-isidro, palermo, la-plata...
  sigla   VARCHAR(6) NULL,                        -- la del calendario: SIS, ARG (Palermo), LP...
  nombre  VARCHAR(80) NOT NULL,
  UNIQUE KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reuniones del calendario del Stud Book y si ya se bajaron. Permite reanudar el histórico.
CREATE TABLE IF NOT EXISTS descarga (
  sb_id         INT UNSIGNED PRIMARY KEY,         -- id de la reunión en el Stud Book (/reuniones/detalle/16141/...)
  slug          VARCHAR(80) NOT NULL,             -- 20150102-san-isidro-1 (sin él la página da 404)
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  estado        ENUM('pendiente','ok','vacio','error') NOT NULL,
  carreras      TINYINT UNSIGNED NULL,            -- según el calendario
  bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  detalle       VARCHAR(255) NULL,
  consultado_en DATETIME NULL,
  KEY (fecha), KEY (hipodromo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reunion (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sb_id           INT UNSIGNED NOT NULL,
  hipodromo_id    SMALLINT UNSIGNED NOT NULL,
  fecha           DATE NOT NULL,
  nro_reunion     SMALLINT UNSIGNED NULL,         -- "REUNION 1": se numeran por año en cada hipódromo
  total_apostado  DECIMAL(14,2) NULL,             -- el Stud Book no lo publica
  archivo_crudo   VARCHAR(255) NULL,
  actualizado_en  DATETIME NOT NULL,
  UNIQUE KEY (sb_id),
  KEY (hipodromo_id, fecha),
  FOREIGN KEY (hipodromo_id) REFERENCES hipodromo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS carrera (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reunion_id      INT UNSIGNED NOT NULL,
  nro_oficial     INT UNSIGNED NULL,              -- id de la carrera en el Stud Book (b-174290 en la página)
  numero          TINYINT UNSIGNED NOT NULL,      -- 1ª, 2ª...
  premio          VARCHAR(150) NULL,
  distancia       SMALLINT UNSIGNED NULL,         -- metros
  hora            DATETIME NULL,
  superficie      VARCHAR(10) NULL,               -- arena / césped
  recorrido       VARCHAR(40) NULL,               -- lo que va entre paréntesis después de la pista: Codo, Codo Nuevo, Recta...
  pista           VARCHAR(40) NULL,               -- estado: Normal, Pesada, Barrosa, Humeda, Fangosa...
  viento          VARCHAR(40) NULL,               -- el Stud Book no lo publica
  categoria       VARCHAR(60) NULL,               -- Condicional, Handicap, Clásico, Gran Premio, Especial...
  grupo           VARCHAR(10) NULL,               -- G1, G2, G3, L (del nombre del premio, cuando figura)
  condicion       TEXT NULL,
  bolsa           DECIMAL(14,2) NULL,             -- pesos
  bolsa_dolares   DECIMAL(12,2) NULL,             -- la misma bolsa en U$S, como la convierte el Stud Book
  bolsa_detalle   VARCHAR(255) NULL,              -- premio de cada puesto y bonus
  tiempo_ganador  VARCHAR(16) NULL,               -- 1'38"35/100 tal cual
  tiempo_ganador_seg DECIMAL(7,2) NULL,           -- el mismo tiempo en segundos
  parciales       VARCHAR(150) NULL,              -- el Stud Book no los publica
  retirados       VARCHAR(255) NULL,              -- números de los retirados
  comentario      TEXT NULL,
  UNIQUE KEY (reunion_id, numero),
  FOREIGN KEY (reunion_id) REFERENCES reunion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El Stud Book identifica cada ejemplar con un número (/ejemplares/perfil/306923/dona-tanga): ese es la clave.
CREATE TABLE IF NOT EXISTS caballo (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sb_id           INT UNSIGNED NOT NULL,
  slug            VARCHAR(80) NULL,               -- la parte final del enlace (hace falta para pedir la ficha)
  nombre          VARCHAR(60) NOT NULL,
  anio_nac        SMALLINT UNSIGNED NULL,         -- de la fecha de nacimiento o, si no, de la edad (cumplen el 1º de julio)
  fecha_nac       DATE NULL,
  pais            VARCHAR(4) NULL,                -- ARG, USA, BRZ...; de la ficha (los importados lo llevan en el nombre del padre)
  pelaje          VARCHAR(4) NULL,                -- Z zaino, A alazán, T tordillo, ZC zaino colorado, ZD zaino doradillo...
  sexo            CHAR(1) NULL,                   -- M macho, H hembra (C castrado si figura)
  padre           VARCHAR(60) NULL,
  madre           VARCHAR(60) NULL,
  abuelo_materno  VARCHAR(60) NULL,
  padre_sb_id     INT UNSIGNED NULL,
  madre_sb_id     INT UNSIGNED NULL,
  criador_id      INT UNSIGNED NULL,
  ficha_en        DATETIME NULL,                  -- cuándo se leyó la ficha del ejemplar
  UNIQUE KEY (sb_id),
  KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jockeys, entrenadores (cuidador, como en Uruguay), caballerizas (stud) y criadores, por su id en el Stud Book.
CREATE TABLE IF NOT EXISTS persona (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo    ENUM('jockey','cuidador','stud','criador') NOT NULL,
  nombre  VARCHAR(120) NOT NULL,                  -- como figura en los resultados ("Giannetti Adrian M.")
  codigo  VARCHAR(20) NOT NULL,                   -- id del Stud Book (los criadores tienen un código alfanumérico)
  slug    VARCHAR(120) NULL,                      -- nombre completo, del enlace: giannetti-adrian-maximiliano
  UNIQUE KEY (tipo, codigo),
  KEY (tipo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS actuacion (
  carrera_id      INT UNSIGNED NOT NULL,
  caballo_id      INT UNSIGNED NOT NULL,
  puesto          TINYINT UNSIGNED NULL,          -- NULL si no tiene puesto numérico
  puesto_texto    VARCHAR(10) NULL,               -- tal cual figura
  gatera          TINYINT UNSIGNED NULL,          -- columna O: número (en Argentina, el del partidor)
  edad            TINYINT UNSIGNED NULL,
  peso_caballo    SMALLINT UNSIGNED NULL,         -- kg
  retiro_motivo   VARCHAR(120) NULL,              -- 'retirado' para los de la tabla RETIRADOS
  tratamiento     VARCHAR(20) NULL,               -- columna T (FF, ...), desde que el Stud Book la publica
  marca           VARCHAR(10) NULL,               -- columna H: herraduras (AA, HA, HH...), desde que se publica
  jockey_id       INT UNSIGNED NULL,
  cuidador_id     INT UNSIGNED NULL,
  stud_id         INT UNSIGNED NULL,
  peso_asignado   DECIMAL(4,1) NULL,              -- kg del jockey
  peso_real       DECIMAL(4,1) NULL,
  dist_anterior   VARCHAR(20) NULL,               -- columna Cpos tal cual
  dist_acumulada  VARCHAR(20) NULL,               -- columna Acum. tal cual
  sport           DECIMAL(8,2) NULL,              -- columna Pago: dividendo a ganador por $1 (el de cada caballo si hubiera ganado)
  div_segundo     DECIMAL(8,2) NULL,              -- el Stud Book no publica sport a placé
  div_tercero     DECIMAL(8,2) NULL,
  tiempo          VARCHAR(16) NULL,               -- solo el del ganador
  tiempo_seg      DECIMAL(7,2) NULL,
  antidoping      TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (carrera_id, caballo_id),
  KEY (caballo_id), KEY (jockey_id), KEY (cuidador_id), KEY (stud_id),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE,
  FOREIGN KEY (caballo_id) REFERENCES caballo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El Stud Book no publica las apuestas combinadas; la tabla queda igual que en Uruguay por si aparece otra fuente.
CREATE TABLE IF NOT EXISTS apuesta (
  carrera_id   INT UNSIGNED NOT NULL,
  tipo         VARCHAR(30) NOT NULL,
  monto_base   DECIMAL(10,2) NULL,
  combinacion  VARCHAR(40) NULL,
  dividendo    DECIMAL(12,2) NULL,
  pozo         DECIMAL(12,2) NULL,
  PRIMARY KEY (carrera_id, tipo),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
