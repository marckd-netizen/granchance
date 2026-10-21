-- Esquema MySQL 5.7+/MariaDB 10.3+ para los resultados de Brasil (apostas.jcb.com.br: Gávea, Cidade Jardim, Cristal,
-- Tarumã y los demás hipódromos brasileños que transmite el Jockey Club Brasileiro).
-- Base aparte (granchance_br) con las mismas tablas que la de Uruguay, más lo que publica el JCB: número de reunión del JCB,
-- penetrómetro, criador, peso del jockey real (descargo de aprendiz), estreantes y la hora real de largada.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hipodromo (
  id      SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre  VARCHAR(80) NOT NULL,                   -- tal cual la pestaña del raceday: Gávea, Cidade Jardim, Cristal...
  ciudad  VARCHAR(80) NULL,
  uf      CHAR(2) NULL,                           -- estado: RJ, SP, RS, PR...
  UNIQUE KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO hipodromo (id, nombre, ciudad, uf) VALUES
  (1, 'Gávea', 'Rio de Janeiro', 'RJ'),
  (2, 'Cidade Jardim', 'São Paulo', 'SP'),
  (3, 'Cristal', 'Porto Alegre', 'RS'),
  (4, 'Tarumã', 'Curitiba', 'PR'),
  (5, 'Madalena', 'Recife', 'PE');

-- Una fila por cada día consultado del raceday (la página muestra la próxima reunión si ese día no hay carreras).
CREATE TABLE IF NOT EXISTS dia (
  fecha         DATE PRIMARY KEY,
  fecha_reunion DATE NULL,                        -- fecha de la reunión que mostró la página
  reuniones     VARCHAR(1000) NULL,               -- las pestañas: "Gávea|196|activo;Cidade Jardim|197|42008197;Belmont|..."
  consultado_en DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reuniones brasileñas y si ya se bajaron. Permite reanudar el histórico.
CREATE TABLE IF NOT EXISTS descarga (
  id_jcb        INT UNSIGNED PRIMARY KEY,          -- idReuniao del raceday (42007195)
  hipodromo_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  nro_reunion   SMALLINT UNSIGNED NULL,
  estado        ENUM('pendiente','ok','vacio','error') NOT NULL,
  bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  detalle       VARCHAR(255) NULL,
  consultado_en DATETIME NULL,
  KEY (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reunion (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hipodromo_id    SMALLINT UNSIGNED NOT NULL,
  fecha           DATE NOT NULL,
  nro_reunion     SMALLINT UNSIGNED NULL,         -- "Reunião 195" (numeración del JCB, común a todos los hipódromos)
  id_jcb          INT UNSIGNED NOT NULL,          -- idReuniao
  total_apostado  DECIMAL(14,2) NULL,             -- "Já apostados na reunião"
  penetrometro_vf DECIMAL(4,1) NULL,              -- penetrómetro en la volta fechada, cm (N/D = NULL)
  penetrometro_pr DECIMAL(4,1) NULL,              -- en el prolongamento da reta
  archivo_crudo   VARCHAR(255) NULL,              -- carpeta de los HTML crudos
  actualizado_en  DATETIME NOT NULL,
  UNIQUE KEY (id_jcb),
  KEY (hipodromo_id, fecha),
  FOREIGN KEY (hipodromo_id) REFERENCES hipodromo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS carrera (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reunion_id      INT UNSIGNED NOT NULL,
  nro_oficial     INT UNSIGNED NULL,              -- el JCB no lo publica
  numero          TINYINT UNSIGNED NOT NULL,      -- 1º páreo, 2º...
  premio          VARCHAR(150) NULL,              -- PRÊMIO IJUBINHA - 2009, CLÁSSICO GHADEER (L.)
  tipo_prova      VARCHAR(60) NULL,               -- "Tipo de Prova" (Handicap, Grupo I...); NULL si dice "Não Disponível"
  distancia       SMALLINT UNSIGNED NULL,         -- metros
  hora            DATETIME NULL,                  -- hora real de largada (resultado completo); si no, la del programa
  hora_programa   TIME NULL,                      -- hora anunciada en el raceday
  superficie      VARCHAR(10) NULL,               -- césped (Grama) / arena (Areia)
  pista           VARCHAR(40) NULL,               -- estado tal cual: leve, macia, pesada, encharcada; con "variante" si cambió
  viento          VARCHAR(40) NULL,               -- el JCB no lo publica
  condicion       TEXT NULL,                      -- "Chamada": Potrancas de 3 anos. Pesos da Tabela...
  recorde         VARCHAR(120) NULL,              -- récord de la distancia: "1m57s84 - PAINT NAIF (12/10/2014)"
  bolsa           DECIMAL(12,2) NULL,             -- el JCB no la publica en resultados
  bolsa_detalle   VARCHAR(255) NULL,
  total_apostado  DECIMAL(14,2) NULL,             -- "R$ 61.435,80 apostados"
  tiempo_ganador  VARCHAR(16) NULL,               -- tal cual: "1m 48s 4/10c", "2m 08s 02c"
  tiempo_ganador_seg DECIMAL(7,2) NULL,
  parciales       VARCHAR(150) NULL,              -- el JCB no los publica
  retirados       VARCHAR(255) NULL,              -- números de los forfaits
  comentario      TEXT NULL,
  UNIQUE KEY (reunion_id, numero),
  FOREIGN KEY (reunion_id) REFERENCES reunion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El JCB no trae un id de caballo: es el mismo caballo si coinciden nombre y generación (año de nacimiento,
-- deducido de la edad: en Brasil los caballos cumplen años el 1º de julio).
CREATE TABLE IF NOT EXISTS caballo (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(60) NOT NULL,           -- tal cual, con el sufijo de país si lo tiene: "SNAP DECISION-ARG"
  anio_nac        SMALLINT UNSIGNED NOT NULL,
  pais            VARCHAR(4) NULL,                -- solo si el nombre lo indica (ARG, USA...); NULL = brasileño
  pelaje          VARCHAR(20) NULL,               -- Castanho, Alazão, Tordilho...
  sexo            CHAR(1) NULL,                   -- m macho, h hembra (Fêmea), c castrado
  padre           VARCHAR(60) NULL,
  madre           VARCHAR(60) NULL,
  abuelo_materno  VARCHAR(60) NULL,
  criador_id      INT UNSIGNED NULL,
  UNIQUE KEY (nombre, anio_nac),
  KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS persona (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo    ENUM('jockey','cuidador','stud','criador') NOT NULL,   -- cuidador = treinador, stud = proprietário
  nombre  VARCHAR(120) NOT NULL,
  UNIQUE KEY (tipo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS actuacion (
  carrera_id      INT UNSIGNED NOT NULL,
  caballo_id      INT UNSIGNED NOT NULL,
  puesto          TINYINT UNSIGNED NULL,          -- NULL si no tiene puesto (el JCB publicaba solo los 5 primeros hasta ~2017)
  puesto_texto    VARCHAR(10) NULL,               -- tal cual: "1º", "--", "forfait"
  numero          TINYINT UNSIGNED NULL,          -- número del caballo
  gatera          TINYINT UNSIGNED NULL,          -- el JCB no la publica aparte (el número suele ser la partida)
  peso_caballo    SMALLINT UNSIGNED NULL,         -- kg; NULL en estreantes ("EST kg")
  retiro_motivo   VARCHAR(120) NULL,              -- "forfait" si no corrió
  estreante       TINYINT(1) NOT NULL DEFAULT 0,
  jockey_id       INT UNSIGNED NULL,
  aprendiz        VARCHAR(6) NULL,                -- Ap1, Ap2... (categoría de aprendiz)
  cuidador_id     INT UNSIGNED NULL,
  stud_id         INT UNSIGNED NULL,
  peso_asignado   DECIMAL(4,1) NULL,              -- "57/6 kg": 57 asignado
  peso_real       DECIMAL(4,1) NULL,              -- y 56 lo que carga (descargo de aprendiz o de jockey mujer)
  peso_texto      VARCHAR(12) NULL,               -- tal cual
  dist_anterior   VARCHAR(20) NULL,               -- el JCB no la publica
  dist_acumulada  VARCHAR(20) NULL,               -- cuerpos al ganador, del resultado completo: "1/2", "3 3/4", "cab"
  cuota           DECIMAL(8,2) NULL,              -- "Rateio": dividendo a ganador por R$1 (cuota final)
  tiempo          VARCHAR(16) NULL,               -- solo el ganador
  tiempo_seg      DECIMAL(7,2) NULL,              -- el del ganador; los demás, estimado con los cuerpos (2,4 m por cuerpo)
  favorito        TINYINT(1) NOT NULL DEFAULT 0,  -- marcado "Favorito" en el resultado completo
  PRIMARY KEY (carrera_id, caballo_id),
  KEY (caballo_id), KEY (jockey_id), KEY (cuidador_id), KEY (stud_id),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE,
  FOREIGN KEY (caballo_id) REFERENCES caballo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dividendos por R$1 del "Resultado por modalidade": Vencedor, Placê (varias filas), Dupla, Exata, Trifeta, Quadrifeta...
CREATE TABLE IF NOT EXISTS apuesta (
  carrera_id   INT UNSIGNED NOT NULL,
  tipo         VARCHAR(30) NOT NULL,
  orden        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  monto_base   DECIMAL(10,2) NULL,
  combinacion  VARCHAR(60) NULL,
  dividendo    DECIMAL(12,2) NULL,                -- NULL si no es un número
  detalle      VARCHAR(60) NULL,                  -- el texto cuando el dividendo no es un número
  pozo         DECIMAL(12,2) NULL,
  PRIMARY KEY (carrera_id, tipo, orden),
  FOREIGN KEY (carrera_id) REFERENCES carrera(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
