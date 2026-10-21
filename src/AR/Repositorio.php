<?php
declare(strict_types=1);

namespace GranChance\AR;

use PDO;

/** Guarda en MySQL (base granchance_ar) lo que devuelve ParserResultados. Todo es UPSERT: se puede re-cargar una reunión sin duplicar. */
final class Repositorio
{
    /** @var array<string, int> caché tipo|codigo => id */
    private array $personas = [];
    /** @var array<string, int> caché codigo => id */
    private array $hipodromos = [];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function desdeConfig(array $cfg): self
    {
        return new self(new PDO($cfg['dsn'], $cfg['usuario'], $cfg['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']));
    }

    /** Anota las reuniones del calendario que todavía no estaban (y los hipódromos nuevos). */
    public function registrarCalendario(array $reuniones): int
    {
        $st = $this->db->prepare(
            "INSERT INTO descarga (sb_id, slug, hipodromo_id, fecha, estado, carreras) VALUES (?,?,?,?,'pendiente',?)
             ON DUPLICATE KEY UPDATE slug = VALUES(slug), hipodromo_id = VALUES(hipodromo_id), fecha = VALUES(fecha), carreras = VALUES(carreras)"
        );
        $nuevas = 0;
        foreach ($reuniones as $r) {
            $st->execute([$r['sb_id'], $r['slug'], $this->hipodromo($r['hipodromo'], $r['sigla'], $r['nombre']), $r['fecha'], $r['carreras']]);
            $nuevas += $st->rowCount() === 1 ? 1 : 0;
        }
        return $nuevas;
    }

    private function hipodromo(string $codigo, ?string $sigla, string $nombre): int
    {
        if (!isset($this->hipodromos[$codigo])) {
            $this->db->prepare(
                'INSERT INTO hipodromo (codigo, sigla, nombre) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), sigla = COALESCE(VALUES(sigla), sigla), nombre = VALUES(nombre)'
            )->execute([$codigo, $sigla, $nombre]);
            $this->hipodromos[$codigo] = (int)$this->db->lastInsertId();
        }
        return $this->hipodromos[$codigo];
    }

    /** @return list<array{sb_id: int, slug: string, hipodromo_id: int, fecha: string}> */
    public function reuniones(string $desde, string $hasta, bool $todas, int $parte = 0, int $partes = 1): array
    {
        $st = $this->db->prepare(
            'SELECT sb_id, slug, hipodromo_id, fecha FROM descarga WHERE fecha BETWEEN ? AND ?'
            . ($todas ? '' : " AND estado IN ('pendiente','error')")
            . sprintf(' AND sb_id %% %d = %d ORDER BY fecha, sb_id', max(1, $partes), $parte)
        );
        $st->execute([$desde, $hasta]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrarDescarga(int $sbId, array $d): void
    {
        $this->db->prepare('UPDATE descarga SET estado = ?, bytes = ?, detalle = ?, consultado_en = NOW() WHERE sb_id = ?')
            ->execute([$d['estado'], $d['bytes'], $d['detalle'] !== null ? mb_substr($d['detalle'], 0, 255) : null, $sbId]);
    }

    /** @param array $r salida de ParserResultados::reunion() */
    public function guardarReunion(array $descarga, array $r, ?string $archivo): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO reunion (sb_id, hipodromo_id, fecha, nro_reunion, archivo_crudo, actualizado_en) VALUES (?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), hipodromo_id = VALUES(hipodromo_id), fecha = VALUES(fecha),
                   nro_reunion = VALUES(nro_reunion), archivo_crudo = VALUES(archivo_crudo), actualizado_en = NOW()'
            )->execute([$descarga['sb_id'], $descarga['hipodromo_id'], $r['fecha'] ?? $descarga['fecha'], $r['nro_reunion'], $archivo]);
            $reunionId = (int)$this->db->lastInsertId();

            foreach ($r['carreras'] as $c) {
                $this->guardarCarrera($reunionId, $c);
            }
            // Carreras que ya no figuran (suspendidas): se borran con su detalle.
            $numeros = array_map(fn($c) => (int)$c['numero'], $r['carreras']) ?: [0];
            $this->db->prepare('DELETE FROM carrera WHERE reunion_id = ? AND numero NOT IN (' . implode(',', $numeros) . ')')->execute([$reunionId]);
            if ($r['carreras'] === []) {
                $this->db->prepare('DELETE FROM reunion WHERE id = ?')->execute([$reunionId]);
            }
            $this->db->commit();
            return count($r['carreras']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->personas = [];   // los ids dados de alta en la transacción deshecha ya no existen
            throw $e;
        }
    }

    private function guardarCarrera(int $reunionId, array $c): void
    {
        $this->db->prepare(
            'INSERT INTO carrera (reunion_id, nro_oficial, numero, premio, distancia, hora, superficie, recorrido, pista, categoria, grupo, condicion,
                bolsa, bolsa_dolares, bolsa_detalle, tiempo_ganador, tiempo_ganador_seg, retirados)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nro_oficial = VALUES(nro_oficial), premio = VALUES(premio), distancia = VALUES(distancia),
                hora = VALUES(hora), superficie = VALUES(superficie), recorrido = VALUES(recorrido), pista = VALUES(pista), categoria = VALUES(categoria),
                grupo = VALUES(grupo), condicion = VALUES(condicion), bolsa = VALUES(bolsa), bolsa_dolares = VALUES(bolsa_dolares),
                bolsa_detalle = VALUES(bolsa_detalle), tiempo_ganador = VALUES(tiempo_ganador), tiempo_ganador_seg = VALUES(tiempo_ganador_seg),
                retirados = VALUES(retirados)'
        )->execute([
            $reunionId, $c['nro_oficial'], $c['numero'], $c['premio'], $c['distancia'], $c['hora'], $c['superficie'], $c['recorrido'], $c['pista'],
            $c['categoria'], $c['grupo'], $c['condicion'], $c['bolsa'], $c['bolsa_dolares'], $c['bolsa_detalle'], $c['tiempo_ganador'],
            $c['tiempo_ganador_seg'], $c['retirados'],
        ]);
        $carreraId = (int)$this->db->lastInsertId();

        // Una corrección oficial puede cambiar puestos o caballos: se reemplaza el detalle completo.
        $this->db->prepare('DELETE FROM actuacion WHERE carrera_id = ?')->execute([$carreraId]);

        $insAct = $this->db->prepare(
            'INSERT IGNORE INTO actuacion (carrera_id, caballo_id, puesto, puesto_texto, gatera, edad, peso_caballo, retiro_motivo, tratamiento, marca,
                jockey_id, cuidador_id, stud_id, peso_asignado, dist_anterior, dist_acumulada, sport, tiempo, tiempo_seg)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($c['actuaciones'] as $a) {
            if ($a['sb_id'] === null) {
                continue;   // fila que no se pudo leer; queda el crudo para revisarla
            }
            $ganador = $a['ganador'] ? $c['ganador'] : null;
            $insAct->execute([
                $carreraId, $this->caballo($a, $ganador), $a['puesto'], $a['puesto_texto'], $a['gatera'], $a['edad'], $a['peso_caballo'],
                $a['retiro_motivo'], $a['tratamiento'], $a['marca'],
                $this->persona('jockey', $a['jockey']), $this->persona('cuidador', $a['cuidador']), $this->persona('stud', $a['stud']),
                $a['peso_asignado'], $a['dist_anterior'], $a['dist_acumulada'], $a['sport'],
                $a['puesto'] === 1 ? $c['tiempo_ganador'] : null, $a['puesto'] === 1 ? $c['tiempo_ganador_seg'] : null,
            ]);
        }
    }

    /** Alta o actualización del ejemplar. Lo que se sabe de una fuente mejor (ficha, recuadro del ganador) no se pisa con NULL. */
    private function caballo(array $a, ?array $g): int
    {
        $anio = $g !== null && $g['fecha_nac'] !== null ? (int)substr($g['fecha_nac'], 0, 4) : $a['anio_nac'];
        $this->db->prepare(
            'INSERT INTO caballo (sb_id, slug, nombre, anio_nac, fecha_nac, pelaje, sexo, padre, madre, abuelo_materno, padre_sb_id, madre_sb_id, criador_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), slug = COALESCE(VALUES(slug), slug), nombre = VALUES(nombre),
                anio_nac = COALESCE(IF(fecha_nac IS NULL, VALUES(anio_nac), NULL), anio_nac), fecha_nac = COALESCE(fecha_nac, VALUES(fecha_nac)),
                pelaje = COALESCE(VALUES(pelaje), pelaje), sexo = COALESCE(VALUES(sexo), sexo),
                padre = COALESCE(padre, VALUES(padre)), madre = COALESCE(madre, VALUES(madre)), abuelo_materno = COALESCE(abuelo_materno, VALUES(abuelo_materno)),
                padre_sb_id = COALESCE(padre_sb_id, VALUES(padre_sb_id)), madre_sb_id = COALESCE(madre_sb_id, VALUES(madre_sb_id)),
                criador_id = COALESCE(criador_id, VALUES(criador_id))'
        )->execute([
            (int)$a['sb_id'], $a['slug'], mb_substr((string)$a['nombre'], 0, 60), $anio, $g['fecha_nac'] ?? null, $a['pelaje'], $a['sexo'],
            self::nombre($g['padre'] ?? null) ?? $a['padre_nombre'], self::nombre($g['madre'] ?? null) ?? $a['madre_nombre'],
            self::nombre($g['abuelo_materno'] ?? null), self::codigo($g['padre'] ?? null), self::codigo($g['madre'] ?? null),
            $this->persona('criador', $g['criador'] ?? null),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** @return list<array{sb_id: int, slug: string}> ejemplares sin ficha leída */
    public function caballosSinFicha(int $limite, int $parte = 0, int $partes = 1): array
    {
        return $this->db->query(sprintf(
            'SELECT sb_id, slug FROM caballo WHERE ficha_en IS NULL AND slug IS NOT NULL AND sb_id %% %d = %d ORDER BY id LIMIT %d',
            max(1, $partes), $parte, $limite
        ))->fetchAll(PDO::FETCH_ASSOC);
    }

    public function guardarFicha(int $sbId, array $f): void
    {
        $this->db->prepare(
            'UPDATE caballo SET pais = COALESCE(?, pais), sexo = COALESCE(?, sexo), fecha_nac = COALESCE(?, fecha_nac),
                anio_nac = COALESCE(YEAR(?), anio_nac), padre = COALESCE(?, padre), madre = COALESCE(?, madre),
                abuelo_materno = COALESCE(?, abuelo_materno), padre_sb_id = COALESCE(?, padre_sb_id), madre_sb_id = COALESCE(?, madre_sb_id),
                criador_id = COALESCE(?, criador_id), ficha_en = NOW() WHERE sb_id = ?'
        )->execute([
            $f['pais'], $f['sexo'], $f['fecha_nac'], $f['fecha_nac'], self::nombre($f['padre']), self::nombre($f['madre']),
            self::nombre($f['abuelo_materno']), self::codigo($f['padre']), self::codigo($f['madre']),
            $this->persona('criador', $f['criador']), $sbId,
        ]);
    }

    private static function nombre(?array $l): ?string
    {
        return $l === null ? null : mb_substr($l['nombre'], 0, 60);
    }

    private static function codigo(?array $l): ?int
    {
        return $l !== null && ctype_digit((string)$l['codigo']) ? (int)$l['codigo'] : null;
    }

    /** @param array{codigo: ?string, slug: ?string, nombre: string}|null $p */
    private function persona(string $tipo, ?array $p): ?int
    {
        if ($p === null || $p['nombre'] === '') {
            return null;
        }
        // Sin enlace (pasa con algunos aprendices o caballerizas viejas) se usa el nombre como código.
        $codigo = $p['codigo'] ?? ('n:' . mb_substr($p['nombre'], 0, 18));
        $clave = $tipo . '|' . $codigo;
        if (!isset($this->personas[$clave])) {
            $this->db->prepare(
                'INSERT INTO persona (tipo, nombre, codigo, slug) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nombre = VALUES(nombre), slug = COALESCE(VALUES(slug), slug)'
            )->execute([$tipo, mb_substr($p['nombre'], 0, 120), $codigo, $p['slug'] !== null ? mb_substr($p['slug'], 0, 120) : null]);
            $this->personas[$clave] = (int)$this->db->lastInsertId();
        }
        return $this->personas[$clave];
    }
}
