-- Esquema MySQL 5.7+/MariaDB 10.3+ para los resultados de Japón (JRA: los 10 hipódromos centrales, de Sapporo a Kokura).
-- Base aparte (granchance_jp) con las mismas tablas que la de Uruguay, más lo que publica la JRA en su sitio en inglés
-- (jra.jp/JRAEN): posiciones en los 4 codos, parciales del puntero cada 200 m, cuadro (bracket) y dividendos de todas las apuestas.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hipodromo (
  id      SMALLINT UNSIGNED PRIMARY KEY,         -- el código de la JRA (raceJoCd): 1 Sapporo ... 10 Kokura
  codigo  CHAR(2) NOT NULL,                       -- '01' ... '10', como en el sitio
  nombre  VARCHAR(80) NOT NULL,
  UNIQUE KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO hipodromo (id, codigo, nombre) VALUES
  (1, '01', 'Sapporo'), (2, '02', 'Hakodate'), (3, '03', 'Fukushima'), (4, '04', 'Niigata'), (5, '05', 'Tokyo'),
  (6, '06', 'Nakayama'), (7, '07', 'Chukyo'), (8, '08', 'Kyoto'), (9, '09', 'Hanshin'), (10, '10', 'Kokura');

-- Reuniones del calendario (un día en un hipódromo) y si ya se bajaron. Permite reanudar el histórico.
CREATE TABLE IF NOT EXISTS descarga (
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  estado        ENUM('pendiente','ok','vacio','error') NOT NULL,
  kai           TINYINT UNSIGNED NULL,          -- número de reunión del año en ese hipódromo (raceKai)
  hi            TINYINT UNSIGNED NULL,          -- día dentro de la reunión (raceHi)
  carreras      TINYINT UNSIGNED NULL,          -- cuántas carreras anuncia el día
  horas         VARCHAR(120) NULL,              -- hora de cada carrera (hora de Japón), separadas por espacio
  bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  detalle       VARCHAR(255) NULL,
  consultado_en DATETIME NULL,
  PRIMARY KEY (hipodromo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reunion (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hipodromo_id    SMALLINT UNSIGNED NOT NULL,
  fecha           DATE NOT NULL,
  nro_reunion     SMALLINT UNSIGNED NULL,         -- kai: 1ª, 2ª... reunión del año en ese hipódromo
  dia_reunion     TINYINT UNSIGNED NULL,          -- hi: 1º a 12º día de la reunión
  total_apostado  DECIMAL(16,2) NULL,             -- yenes, suma de lo apostado en todas las carreras
  archivo_crudo   VARCHAR(255) NULL,              -- carpeta de los HTML crudos
  actualizado_en  DATETIME NOT NULL,
  UNIQUE KEY (hipodromo_id, fecha),
  FOREIGN KEY (hipodromo_id) REFERENCES hipodromo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS carrera (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reunion_id      INT UNSIGNED NOT NULL,
  nro_oficial     INT UNSIGNED NULL,              -- la JRA no lo publica
  numero          TINYINT UNSIGNED NOT NULL,      -- 1R, 2R... 12R
  premio          VARCHAR(150) NULL,              -- nombre de la carrera (solo las especiales: THE ARIMA KINEN (THE GRAND PRIX)(G1))
  grado           VARCHAR(6) NULL,                -- G1, G2, G3, L, J.G1 (obstáculos)...
  distancia       SMALLINT UNSIGNED NULL,         -- metros
  hora            DATETIME NULL,                  -- Post time, hora de Japón
  superficie      VARCHAR(10) NULL,               -- Turf (césped), Dirt (arena), Jump (obstáculos), tal cual
  pista           VARCHAR(20) NULL,               -- Firm, Good, Yielding, Soft (césped); Standard, Good, Muddy, Sloppy (arena)
  clima           VARCHAR(20) NULL,               -- Fine, Cloudy, Rainy, Drizzle, Snow...
  viento          VARCHAR(40) NULL,               -- la JRA no lo publica
  clase           VARCHAR(40) NULL,               -- Newcomer, Maiden, Allowance, Open Class
  edad            VARCHAR(30) NULL,               -- 2-Year-Olds, 3-Year-Olds, 3-Year-Olds & Up, 4-Year-Olds & Up
  sexo            VARCHAR(20) NULL,               -- hembras (F&M) o NULL
  peso_tipo       VARCHAR(30) NULL,               -- Special Weight, Weight for Age, Handicap...
  marcas          VARCHAR(30) NULL,               -- INT, MIX, DES, SD, DSN, F&M (quién puede correr; ver la página de la JRA)
  condicion       VARCHAR(255) NULL,              -- la línea de condición completa
  bolsa           DECIMAL(14,2) NULL,             -- yenes (Value of race)
  bolsa_detalle   VARCHAR(255) NULL,              -- premio del 1º al 5º, yenes, separados por espacio
  premio_base     DECIMAL(12,2) NULL,             -- Added Money del 1º: marca la clase de las Allowance (1, 2 y 3 Win)
  tiempo_ganador  VARCHAR(12) NULL,               -- m:ss.d tal cual
  tiempo_ganador_seg DECIMAL(7,2) NULL,
  parciales       VARCHAR(255) NULL,              -- tiempos del puntero cada 200 m (el primero puede ser más corto), separados por espacio
  retirados       VARCHAR(255) NULL,              -- números de los que no largaron
  comentario      TEXT NULL,
  UNIQUE KEY (reunion_id, numero),
  FOREIGN KEY (reunion_id) REFERENCES reunion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tiempos del puntero cada 200 m (Lap tm) y acumulados (Pas.tm).
CREATE TABLE IF NOT EXISTS carrera_parcial (
  carrera_id      INT UNSIGNED NOT NULL,
  tramo           TINYINT UNSIGNED NOT NULL,
  tiempo_acum     DECIMAL(7,2) NULL,
  tiempo          DECIMAL(6,2) NULL,
  PRIMARY KEY (carrera_id, tramo),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- La JRA identifica cada caballo con su número (horseno, el de JBIS): los 4 primeros dígitos son el año de nacimiento.
CREATE TABLE IF NOT EXISTS caballo (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_jra      VARCHAR(12) NOT NULL,           -- 2011105266
  nombre          VARCHAR(60) NOT NULL,
  anio_nac        SMALLINT UNSIGNED NULL,         -- del número del caballo
  pais            VARCHAR(4) NULL,                -- JPN, USA, IRE... (va entre paréntesis junto al nombre)
  pelaje          VARCHAR(20) NULL,               -- la JRA no lo publica en inglés
  sexo            CHAR(1) NULL,                   -- C potro, F potranca, H padrillo, M yegua, G castrado (el último que figura)
  padre           VARCHAR(60) NULL,
  madre           VARCHAR(60) NULL,
  abuelo_materno  VARCHAR(60) NULL,
  abuela_materna  VARCHAR(60) NULL,
  criador_id      INT UNSIGNED NULL,
  UNIQUE KEY (codigo_jra),
  KEY (nombre),
  KEY (criador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jockeys, entrenadores (cuidador, como en Uruguay), propietarios (stud) y criadores, por el nombre tal cual la JRA.
CREATE TABLE IF NOT EXISTS persona (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo    ENUM('jockey','cuidador','stud','criador') NOT NULL,
  nombre  VARCHAR(120) NOT NULL,
  codigo  VARCHAR(10) NULL,                       -- la JRA no publica códigos en inglés
  UNIQUE KEY (tipo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS actuacion (
  carrera_id      INT UNSIGNED NOT NULL,
  caballo_id      INT UNSIGNED NOT NULL,
  puesto          TINYINT UNSIGNED NULL,          -- NULL si no tiene puesto numérico
  puesto_texto    VARCHAR(10) NULL,               -- "1", "2 DH", o FF (no terminó), S (no largó), ER (excluido)...
  numero          TINYINT UNSIGNED NULL,          -- número del caballo (Hs); en Japón es también la gatera
  gatera          TINYINT UNSIGNED NULL,          -- el mismo número: la JRA larga cada caballo en la gatera de su número
  cuadro          TINYINT UNSIGNED NULL,          -- Bk: cuadro (bracket) 1 a 8, el color del mandil
  edad            TINYINT UNSIGNED NULL,
  sexo            CHAR(1) NULL,                   -- C, F, H, M, G ese día
  peso_caballo    SMALLINT UNSIGNED NULL,         -- la JRA no lo publica en inglés
  jockey_id       INT UNSIGNED NULL,
  cuidador_id     INT UNSIGNED NULL,
  stud_id         INT UNSIGNED NULL,              -- propietario (Owner)
  peso_asignado   DECIMAL(4,1) NULL,              -- kg
  dist_anterior   VARCHAR(20) NULL,               -- Margin: cuerpos al que llegó adelante (NK, HD, 1 1/4...)
  dist_acumulada  VARCHAR(20) NULL,               -- la JRA no la publica
  posiciones      VARCHAR(20) NULL,               -- puesto en cada codo (1c a 4c): "3 3 5 3"
  favorito        TINYINT UNSIGNED NULL,          -- Win Fav: 1 = el favorito del público
  cuota           DECIMAL(7,1) NULL,              -- Win Odds final (dividendo a ganador = cuota × 100 por ¥100)
  tiempo          VARCHAR(12) NULL,               -- m:ss.d
  tiempo_seg      DECIMAL(7,2) NULL,
  PRIMARY KEY (carrera_id, caballo_id),
  KEY (caballo_id), KEY (jockey_id), KEY (cuidador_id), KEY (stud_id),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE,
  FOREIGN KEY (caballo_id) REFERENCES caballo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dividendos por ¥100: Win, Show, Bracket Q., Quinella, Q.Place, Exacta, Trio, Trifecta (Show y Q.Place tienen varias filas).
CREATE TABLE IF NOT EXISTS apuesta (
  carrera_id   INT UNSIGNED NOT NULL,
  tipo         VARCHAR(30) NOT NULL,
  orden        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  monto_base   DECIMAL(10,2) NULL,
  combinacion  VARCHAR(60) NULL,
  dividendo    DECIMAL(12,2) NULL,
  detalle      VARCHAR(60) NULL,
  pozo         DECIMAL(16,2) NULL,                -- lo apostado en esa apuesta (Sold), en la primera fila
  PRIMARY KEY (carrera_id, tipo, orden),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
