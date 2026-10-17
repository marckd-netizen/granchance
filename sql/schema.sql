-- Esquema MySQL 5.7+/MariaDB 10.3+ para los resultados de los hipódromos.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hipodromo (
  id      SMALLINT UNSIGNED PRIMARY KEY,          -- racetrackId del servicio oficial
  nombre  VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una fila por cada (hipódromo, fecha) consultada, haya o no carreras. Permite reanudar el histórico.
CREATE TABLE IF NOT EXISTS descarga (
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  estado        ENUM('ok','vacio','error') NOT NULL,
  bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  detalle       VARCHAR(255) NULL,
  consultado_en DATETIME NOT NULL,
  PRIMARY KEY (hipodromo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reunion (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hipodromo_id    SMALLINT UNSIGNED NOT NULL,
  fecha           DATE NOT NULL,
  nro_reunion     SMALLINT UNSIGNED NULL,
  total_apostado  DECIMAL(14,2) NULL,
  archivo_crudo   VARCHAR(255) NULL,
  actualizado_en  DATETIME NOT NULL,
  UNIQUE KEY (hipodromo_id, fecha),
  FOREIGN KEY (hipodromo_id) REFERENCES hipodromo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS carrera (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reunion_id      INT UNSIGNED NOT NULL,
  nro_oficial     INT UNSIGNED NULL,              -- "Nro." del Excel (ej. 32612); se repite entre hipódromos, no sirve de clave
  numero          TINYINT UNSIGNED NOT NULL,      -- 1ª, 2ª...
  premio          VARCHAR(150) NULL,
  distancia       SMALLINT UNSIGNED NULL,         -- metros
  hora            DATETIME NULL,
  pista           VARCHAR(40) NULL,               -- seca, pesada...
  viento          VARCHAR(40) NULL,
  condicion       TEXT NULL,
  bolsa           DECIMAL(12,2) NULL,
  bolsa_detalle   VARCHAR(255) NULL,
  tiempo_ganador  VARCHAR(12) NULL,               -- m'ss''cc tal cual
  tiempo_ganador_seg DECIMAL(7,2) NULL,           -- el mismo tiempo en segundos
  parciales       VARCHAR(150) NULL,
  retirados       VARCHAR(255) NULL,
  comentario      TEXT NULL,
  UNIQUE KEY (reunion_id, numero),
  FOREIGN KEY (reunion_id) REFERENCES reunion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El Excel no trae un id de caballo: es el mismo caballo si coinciden nombre, país y año de nacimiento.
CREATE TABLE IF NOT EXISTS caballo (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(60) NOT NULL,
  anio_nac        SMALLINT UNSIGNED NOT NULL,
  pais            VARCHAR(4) NOT NULL DEFAULT 'URU', -- ARG, BRZ...; URU cuando el Excel no indica país
  pelaje          VARCHAR(4) NULL,                -- z zaino, a alazán, t tordillo...
  sexo            CHAR(1) NULL,                   -- m macho, h hembra
  padre           VARCHAR(60) NULL,
  madre           VARCHAR(60) NULL,
  abuelo_materno  VARCHAR(60) NULL,
  criador_id      INT UNSIGNED NULL,
  UNIQUE KEY (nombre, pais, anio_nac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS persona (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo    ENUM('jockey','cuidador','stud','criador') NOT NULL,
  nombre  VARCHAR(120) NOT NULL,
  UNIQUE KEY (tipo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS actuacion (
  carrera_id      INT UNSIGNED NOT NULL,
  caballo_id      INT UNSIGNED NOT NULL,
  puesto          TINYINT UNSIGNED NULL,          -- NULL si no llegó / no tiene puesto numérico
  puesto_texto    VARCHAR(10) NULL,               -- tal cual figura ("1º", "Dist.", ...)
  gatera          TINYINT UNSIGNED NULL,
  peso_caballo    SMALLINT UNSIGNED NULL,         -- kg; el Excel trae 0 cuando no hay dato
  retiro_motivo   VARCHAR(120) NULL,              -- columna E: retirado y motivo
  tratamiento     VARCHAR(20) NULL,               -- columna F: tratamiento veterinario (FF, FB, FS...)
  marca           VARCHAR(10) NULL,               -- columna G: c, m, cm en reuniones viejas (sin confirmar qué es)
  jockey_id       INT UNSIGNED NULL,
  cuidador_id     INT UNSIGNED NULL,
  stud_id         INT UNSIGNED NULL,
  peso_asignado   DECIMAL(4,1) NULL,
  peso_real       DECIMAL(4,1) NULL,
  dist_anterior   VARCHAR(20) NULL,
  dist_acumulada  VARCHAR(20) NULL,
  sport           DECIMAL(8,2) NULL,              -- dividendo a ganador que figura en la fila
  div_segundo     DECIMAL(8,2) NULL,              -- de la grilla de dividendos (1º-3º)
  div_tercero     DECIMAL(8,2) NULL,
  tiempo          VARCHAR(12) NULL,               -- m'ss''cc tal cual
  tiempo_seg      DECIMAL(7,2) NULL,
  antidoping      TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (carrera_id, caballo_id),
  KEY (caballo_id), KEY (jockey_id), KEY (cuidador_id), KEY (stud_id),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE,
  FOREIGN KEY (caballo_id) REFERENCES caballo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Apuestas combinadas: Exacta, Trifecta, Doble, Pick 3, Pick 4...
CREATE TABLE IF NOT EXISTS apuesta (
  carrera_id   INT UNSIGNED NOT NULL,
  tipo         VARCHAR(30) NOT NULL,
  monto_base   DECIMAL(10,2) NULL,
  combinacion  VARCHAR(40) NULL,                  -- NULL si quedó sin acierto
  dividendo    DECIMAL(12,2) NULL,
  pozo         DECIMAL(12,2) NULL,                -- pozo acumulado cuando no hubo acierto
  PRIMARY KEY (carrera_id, tipo),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
