<?php
declare(strict_types=1);

namespace GranChance\HK;

use PDO;

/** Guarda en MySQL (base granchance_hk) lo que devuelve ParserResultados. Todo es UPSERT: se puede re-cargar una reunión sin duplicar. */
final class Repositorio
{
    public const HIPODROMOS = ['ST' => 1, 'HV' => 2];

    /** @var array<string, int> caché tipo|nombre => id */
    private array $personas = [];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function desdeConfig(array $cfg): self
    {
        return new self(new PDO($cfg['dsn'], $cfg['usuario'], $cfg['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']));
    }

    /** Anota las reuniones del calendario que todavía no estaban. */
    public function registrarCalendario(array $reuniones): int
    {
        $st = $this->db->prepare(
            "INSERT INTO descarga (hipodromo_id, fecha, estado, turno, pistas) VALUES (?,?,'pendiente',?,?)
             ON DUPLICATE KEY UPDATE turno = VALUES(turno), pistas = VALUES(pistas)"
        );
        $nuevas = 0;
        foreach ($reuniones as $r) {
            $st->execute([self::HIPODROMOS[$r['hipodromo']], $r['fecha'], $r['turno'], $r['pistas']]);
            $nuevas += $st->rowCount() === 1 ? 1 : 0;
        }
        return $nuevas;
    }

    /** @return list<array{hipodromo: string, fecha: string, turno: ?string, pistas: ?string}> */
    public function reuniones(string $desde, string $hasta, bool $todas): array
    {
        $st = $this->db->prepare(
            'SELECT h.codigo AS hipodromo, d.fecha, d.turno, d.pistas FROM descarga d JOIN hipodromo h ON h.id = d.hipodromo_id
             WHERE d.fecha BETWEEN ? AND ?' . ($todas ? '' : " AND d.estado IN ('pendiente','error')") . ' ORDER BY d.fecha, h.id'
        );
        $st->execute([$desde, $hasta]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrarDescarga(string $hip, string $fecha, array $d): void
    {
        $this->db->prepare(
            'INSERT INTO descarga (hipodromo_id, fecha, estado, bytes, detalle, consultado_en) VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), bytes = VALUES(bytes), detalle = VALUES(detalle), consultado_en = NOW()'
        )->execute([self::HIPODROMOS[$hip], $fecha, $d['estado'], $d['bytes'], $d['detalle'] !== null ? substr($d['detalle'], 0, 255) : null]);
    }

    /**
     * @param list<array> $carreras salida de ParserResultados::carrera()
     * @param array<int, list<array>> $parciales salida de ParserResultados::parciales()
     */
    public function guardarReunion(array $reunion, array $carreras, array $parciales, ?string $carpeta): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO reunion (hipodromo_id, fecha, turno, pistas, archivo_crudo, actualizado_en) VALUES (?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), turno = VALUES(turno), pistas = VALUES(pistas),
                   archivo_crudo = VALUES(archivo_crudo), actualizado_en = NOW()'
            )->execute([self::HIPODROMOS[$reunion['hipodromo']], $reunion['fecha'], $reunion['turno'], $reunion['pistas'], $carpeta]);
            $reunionId = (int)$this->db->lastInsertId();

            foreach ($carreras as $c) {
                $this->guardarCarrera($reunionId, $c, $parciales[$c['numero']] ?? []);
            }
            // Carreras que ya no figuran (suspendidas): se borran con su detalle.
            $numeros = array_map(fn($c) => (int)$c['numero'], $carreras) ?: [0];
            $this->db->prepare('DELETE FROM carrera WHERE reunion_id = ? AND numero NOT IN (' . implode(',', $numeros) . ')')->execute([$reunionId]);
            if ($carreras === []) {
                $this->db->prepare('DELETE FROM reunion WHERE id = ?')->execute([$reunionId]);
            }
            $this->db->commit();
            return count($carreras);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function guardarCarrera(int $reunionId, array $c, array $parciales): void
    {
        $this->db->prepare(
            'INSERT INTO carrera (reunion_id, nro_oficial, numero, premio, distancia, superficie, recorrido, pista, clase, rating, condicion,
                bolsa, bolsa_detalle, tiempo_ganador, tiempo_ganador_seg, parciales, retirados, comentario)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nro_oficial = VALUES(nro_oficial), premio = VALUES(premio),
                distancia = VALUES(distancia), superficie = VALUES(superficie), recorrido = VALUES(recorrido), pista = VALUES(pista),
                clase = VALUES(clase), rating = VALUES(rating), condicion = VALUES(condicion), bolsa = VALUES(bolsa), bolsa_detalle = VALUES(bolsa_detalle),
                tiempo_ganador = VALUES(tiempo_ganador), tiempo_ganador_seg = VALUES(tiempo_ganador_seg), parciales = VALUES(parciales),
                retirados = VALUES(retirados), comentario = VALUES(comentario)'
        )->execute([
            $reunionId, $c['nro_oficial'], $c['numero'], $c['premio'], $c['distancia'], $c['superficie'], $c['recorrido'], $c['pista'],
            $c['clase'], $c['rating'], $c['condicion'], $c['bolsa'], $c['bolsa_detalle'], $c['tiempo_ganador'], $c['tiempo_ganador_seg'],
            $c['parciales'], $c['retirados'], $c['comentario'],
        ]);
        $carreraId = (int)$this->db->lastInsertId();

        // Una corrección oficial puede cambiar puestos o caballos: se reemplaza el detalle completo.
        foreach (['actuacion_parcial', 'actuacion', 'carrera_parcial', 'apuesta'] as $tabla) {
            $this->db->prepare("DELETE FROM $tabla WHERE carrera_id = ?")->execute([$carreraId]);
        }

        $insTramo = $this->db->prepare('INSERT INTO carrera_parcial (carrera_id, tramo, tiempo_acum, tiempo, sub200) VALUES (?,?,?,?,?)');
        foreach ($c['parciales_tramos'] as $t) {
            $insTramo->execute([$carreraId, $t['tramo'], $t['tiempo_acum'], $t['tiempo'], $t['sub200']]);
        }

        $insAct = $this->db->prepare(
            'INSERT INTO actuacion (carrera_id, caballo_id, puesto, puesto_texto, numero, gatera, peso_caballo_lb, peso_caballo, jockey_id, cuidador_id,
                peso_asignado_lb, peso_asignado, dist_acumulada, posiciones, cuota, tiempo, tiempo_seg, incidente)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $caballos = [];
        foreach ($c['actuaciones'] as $a) {
            if ($a['codigo'] === null) {
                continue;   // fila que no se pudo leer; queda el crudo para revisarla
            }
            $ganador = $a['puesto'] === 1 && $c['padre_ganador'] !== null;
            $caballoId = $this->caballo($a, $ganador ? $c['padre_ganador'] : null, $ganador ? $c['madre_ganador'] : null);
            if (isset($caballos[$a['codigo']])) {
                continue;
            }
            $caballos[$a['codigo']] = $caballoId;
            $insAct->execute([
                $carreraId, $caballoId, $a['puesto'], $a['puesto_texto'], $a['numero'], $a['gatera'], $a['peso_caballo_lb'], $a['peso_caballo'],
                $this->persona('jockey', $a['jockey']), $this->persona('cuidador', $a['cuidador']),
                $a['peso_asignado_lb'], $a['peso_asignado'], $a['dist_acumulada'], $a['posiciones'], $a['cuota'], $a['tiempo'], $a['tiempo_seg'], $a['incidente'],
            ]);
        }

        $insPar = $this->db->prepare(
            'INSERT IGNORE INTO actuacion_parcial (carrera_id, caballo_id, tramo, posicion, margen, tiempo, sub200) VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($parciales as $p) {
            $caballoId = $caballos[$p['codigo']] ?? null;
            if ($caballoId === null) {
                continue;
            }
            foreach ($p['tramos'] as $t) {
                $insPar->execute([$carreraId, $caballoId, $t['tramo'], $t['posicion'], $t['margen'], $t['tiempo'], $t['sub200']]);
            }
        }

        $insAp = $this->db->prepare(
            'INSERT INTO apuesta (carrera_id, tipo, orden, monto_base, combinacion, dividendo, detalle, pozo) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE combinacion = VALUES(combinacion), dividendo = VALUES(dividendo), detalle = VALUES(detalle)'
        );
        foreach ($c['apuestas'] as $ap) {
            $insAp->execute([$carreraId, $ap['tipo'], $ap['orden'], $ap['monto_base'], $ap['combinacion'], $ap['dividendo'], $ap['detalle'], $ap['pozo']]);
        }
    }

    private function caballo(array $a, ?string $padre, ?string $madre): int
    {
        $this->db->prepare(
            'INSERT INTO caballo (codigo_hkjc, marca, nombre, padre, madre) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), marca = COALESCE(VALUES(marca), marca), nombre = VALUES(nombre),
                padre = COALESCE(VALUES(padre), padre), madre = COALESCE(VALUES(madre), madre)'
        )->execute([$a['codigo'], $a['marca'], $a['nombre'], $padre, $madre]);
        return (int)$this->db->lastInsertId();
    }

    /** @return list<string> códigos de caballos sin ficha leída */
    public function caballosSinFicha(int $limite, int $parte = 0, int $partes = 1): array
    {
        $st = $this->db->query(sprintf(
            "SELECT codigo_hkjc FROM caballo WHERE ficha_en IS NULL AND codigo_hkjc LIKE 'HK\\_%%' AND id %% %d = %d ORDER BY id LIMIT %d",
            max(1, $partes), $parte, $limite
        ));
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public function guardarFicha(string $codigo, array $f): void
    {
        $this->db->prepare(
            'UPDATE caballo SET pais = ?, pelaje = ?, sexo = ?, padre = COALESCE(?, padre), madre = COALESCE(?, madre),
                abuelo_materno = ?, tipo_importacion = ?, propietario = ?, ficha_en = NOW() WHERE codigo_hkjc = ?'
        )->execute([$f['pais'], $f['pelaje'], $f['sexo'], $f['padre'], $f['madre'], $f['abuelo_materno'], $f['tipo_importacion'], $f['propietario'], $codigo]);
    }

    /** @param array{nombre: string, codigo: ?string}|null $p */
    private function persona(string $tipo, ?array $p): ?int
    {
        if ($p === null) {
            return null;
        }
        $clave = $tipo . '|' . $p['nombre'];
        if (!isset($this->personas[$clave])) {
            $this->db->prepare(
                'INSERT INTO persona (tipo, nombre, codigo) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), codigo = COALESCE(VALUES(codigo), codigo)'
            )->execute([$tipo, $p['nombre'], $p['codigo']]);
            $this->personas[$clave] = (int)$this->db->lastInsertId();
        }
        return $this->personas[$clave];
    }
}
