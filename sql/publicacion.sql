-- Publicación en la web: predicciones guardadas antes de cada carrera, cambios detectados en los programas y borrados.
-- Las crea bin/publicar.php si no existen.
SET NAMES utf8mb4;

-- Lo que decía el programa no oficial de cada caballo antes de la carrera (se congela cuando pasa la fecha).
CREATE TABLE IF NOT EXISTS prediccion (
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  carrera       TINYINT UNSIGNED NOT NULL,      -- número de carrera en el programa
  nro_oficial   INT UNSIGNED NULL,
  mandil        VARCHAR(4) NOT NULL,
  caballo       VARCHAR(60) NOT NULL,
  orden         TINYINT UNSIGNED NOT NULL,      -- 1 = el de más probabilidad
  prob          DECIMAL(5,4) NULL,
  proyeccion    DECIMAL(6,3) NULL,
  vs_par_hoy    DECIMAL(6,3) NULL,
  origen        ENUM('previa','reconstruida') NOT NULL DEFAULT 'previa',  -- reconstruida: generada después de correrse
  generado_en   DATETIME NOT NULL,
  PRIMARY KEY (hipodromo_id, fecha, carrera, mandil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Última versión leída de cada programa (hora de cada carrera; monta, peso y si corre cada caballo), para comparar.
CREATE TABLE IF NOT EXISTS programa_estado (
  hipodromo_id   SMALLINT UNSIGNED NOT NULL,
  fecha          DATE NOT NULL,
  firma          MEDIUMTEXT NOT NULL,           -- JSON
  publicado_en   DATETIME NOT NULL,
  actualizado_en DATETIME NOT NULL,
  PRIMARY KEY (hipodromo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cambios encontrados entre una lectura del programa y la siguiente (monta, horario, peso, retiros).
CREATE TABLE IF NOT EXISTS programa_cambio (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  carrera       TINYINT UNSIGNED NOT NULL,
  mandil        VARCHAR(4) NULL,
  texto         VARCHAR(255) NOT NULL,
  detectado_en  DATETIME NOT NULL,
  KEY (hipodromo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Caballos borrados (hipica.maronas.com.uy/RacingInfo/ScratchedHorses), que se publican a las 11 del día de la reunión.
CREATE TABLE IF NOT EXISTS borrado (
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  carrera       TINYINT UNSIGNED NOT NULL,
  mandil        VARCHAR(4) NOT NULL,
  caballo       VARCHAR(60) NOT NULL,
  motivo        VARCHAR(60) NULL,
  leido_en      DATETIME NOT NULL,
  PRIMARY KEY (hipodromo_id, fecha, carrera, mandil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inscriptos de cada programa publicado, con el caballo, jockey y cuidador identificados en la base cuando se puede:
-- las fichas (public/ficha.php) muestran con esto las próximas carreras.
CREATE TABLE IF NOT EXISTS inscripcion (
  hipodromo_id   SMALLINT UNSIGNED NOT NULL,
  fecha          DATE NOT NULL,
  carrera        TINYINT UNSIGNED NOT NULL,
  mandil         VARCHAR(4) NOT NULL,
  caballo_id     INT UNSIGNED NULL,
  caballo        VARCHAR(60) NOT NULL,
  jockey_id      INT UNSIGNED NULL,
  jockey         VARCHAR(120) NULL,
  cuidador_id    INT UNSIGNED NULL,
  cuidador       VARCHAR(120) NULL,
  peso           DECIMAL(4,1) NULL,
  hora           VARCHAR(5) NULL,
  nombre_carrera VARCHAR(150) NULL,
  distancia      SMALLINT UNSIGNED NULL,
  superficie     VARCHAR(10) NULL,
  retirado       TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (hipodromo_id, fecha, carrera, mandil),
  KEY (caballo_id), KEY (jockey_id), KEY (cuidador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
