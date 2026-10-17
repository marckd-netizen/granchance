<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/** Guarda en MySQL lo que devuelve ParserResultados. Todo es UPSERT: se puede re-cargar el mismo día sin duplicar. */
final class Repositorio
{
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

    public function yaConsultado(int $hipodromo, string $fecha): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM descarga WHERE hipodromo_id = ? AND fecha = ? AND estado IN ('ok','vacio')");
        $st->execute([$hipodromo, $fecha]);
        return (bool)$st->fetchColumn();
    }

    public function registrarDescarga(int $hipodromo, string $fecha, array $d): void
    {
        $this->asegurarHipodromo($hipodromo, null);
        $this->db->prepare(
            'INSERT INTO descarga (hipodromo_id, fecha, estado, bytes, detalle, consultado_en) VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), bytes = VALUES(bytes), detalle = VALUES(detalle), consultado_en = NOW()'
        )->execute([$hipodromo, $fecha, $d['estado'], $d['bytes'], $d['detalle'] !== null ? substr($d['detalle'], 0, 255) : null]);
    }

    /** @return int cantidad de carreras guardadas */
    public function guardarReunion(int $hipodromo, string $fecha, array $r, ?string $archivo): int
    {
        $this->db->beginTransaction();
        try {
            $this->asegurarHipodromo($hipodromo, $r['hipodromo']);
            $this->db->prepare(
                'INSERT INTO reunion (hipodromo_id, fecha, nro_reunion, total_apostado, archivo_crudo, actualizado_en) VALUES (?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nro_reunion = VALUES(nro_reunion),
                   total_apostado = VALUES(total_apostado), archivo_crudo = VALUES(archivo_crudo), actualizado_en = NOW()'
            )->execute([$hipodromo, $fecha, $r['nro_reunion'], $r['total_apostado'], $archivo]);
            $reunionId = (int)$this->db->lastInsertId();

            foreach ($r['carreras'] as $c) {
                $this->guardarCarrera($reunionId, $c);
            }
            $this->db->commit();
            return count($r['carreras']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function guardarCarrera(int $reunionId, array $c): void
    {
        $this->db->prepare(
            'INSERT INTO carrera (id, reunion_id, numero, premio, distancia, hora, pista, viento, condicion, bolsa, bolsa_detalle,
                tiempo_ganador, tiempo_ganador_seg, parciales, retirados, comentario)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE reunion_id = VALUES(reunion_id), numero = VALUES(numero), premio = VALUES(premio),
                distancia = VALUES(distancia), hora = VALUES(hora), pista = VALUES(pista), viento = VALUES(viento),
                condicion = VALUES(condicion), bolsa = VALUES(bolsa), bolsa_detalle = VALUES(bolsa_detalle),
                tiempo_ganador = VALUES(tiempo_ganador), tiempo_ganador_seg = VALUES(tiempo_ganador_seg), parciales = VALUES(parciales), retirados = VALUES(retirados),
                comentario = VALUES(comentario)'
        )->execute([
            $c['id'], $reunionId, $c['numero'], $c['premio'], $c['distancia'], $c['hora'], $c['pista'], $c['viento'],
            $c['condicion'], $c['bolsa'], $c['bolsa_detalle'], $c['tiempo_ganador'], $c['tiempo_ganador_seg'], $c['parciales'], $c['retirados'], $c['comentario'],
        ]);

        // Una corrección oficial puede cambiar puestos o caballos: se reemplaza el detalle completo.
        $this->db->prepare('DELETE FROM actuacion WHERE carrera_id = ?')->execute([$c['id']]);
        $this->db->prepare('DELETE FROM apuesta WHERE carrera_id = ?')->execute([$c['id']]);

        $insAct = $this->db->prepare(
            'INSERT INTO actuacion (carrera_id, caballo_id, puesto, puesto_texto, gatera, peso_caballo, retiro_motivo, tratamiento, jockey_id,
                cuidador_id, stud_id, peso_asignado, peso_real, dist_anterior, dist_acumulada, sport, div_segundo, div_tercero,
                tiempo, tiempo_seg, antidoping)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($c['actuaciones'] as $a) {
            if ($a['nombre'] === null || $a['anio_nac'] === null) {
                continue;   // fila que no se pudo leer; queda el crudo para revisarla
            }
            $insAct->execute([
                $c['id'], $this->caballo($a), $a['puesto'], $a['puesto_texto'], $a['gatera'], $a['peso_caballo'], $a['retiro_motivo'], $a['tratamiento'],
                $this->persona('jockey', $a['jockey']), $this->persona('cuidador', $a['cuidador']), $this->persona('stud', $a['stud']),
                $a['peso_asignado'], $a['peso_real'], $a['dist_anterior'], $a['dist_acumulada'], $a['sport'],
                $a['div_segundo'], $a['div_tercero'], $a['tiempo'], $a['tiempo_seg'], (int)$a['antidoping'],
            ]);
        }

        $insAp = $this->db->prepare(
            'INSERT INTO apuesta (carrera_id, tipo, monto_base, combinacion, dividendo, pozo) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE monto_base = VALUES(monto_base), combinacion = VALUES(combinacion),
                dividendo = VALUES(dividendo), pozo = VALUES(pozo)'
        );
        foreach ($c['apuestas'] as $ap) {
            $insAp->execute([$c['id'], $ap['tipo'], $ap['monto_base'], $ap['combinacion'], $ap['dividendo'], $ap['pozo']]);
        }
    }

    private function caballo(array $a): int
    {
        $this->db->prepare(
            'INSERT INTO caballo (nombre, anio_nac, pais, pelaje, sexo, padre, madre, abuelo_materno, criador_id) VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), pais = COALESCE(VALUES(pais), pais), pelaje = COALESCE(VALUES(pelaje), pelaje),
                sexo = COALESCE(VALUES(sexo), sexo), padre = COALESCE(VALUES(padre), padre), madre = COALESCE(VALUES(madre), madre),
                abuelo_materno = COALESCE(VALUES(abuelo_materno), abuelo_materno), criador_id = COALESCE(VALUES(criador_id), criador_id)'
        )->execute([
            $a['nombre'], $a['anio_nac'], $a['pais'], $a['pelaje'], $a['sexo'], $a['padre'], $a['madre'], $a['abuelo_materno'],
            $this->persona('criador', $a['criador']),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function persona(string $tipo, ?string $nombre): ?int
    {
        if ($nombre === null) {
            return null;
        }
        $clave = $tipo . '|' . $nombre;
        if (!isset($this->personas[$clave])) {
            $this->db->prepare('INSERT INTO persona (tipo, nombre) VALUES (?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
                ->execute([$tipo, $nombre]);
            $this->personas[$clave] = (int)$this->db->lastInsertId();
        }
        return $this->personas[$clave];
    }

    private function asegurarHipodromo(int $id, ?string $nombre): void
    {
        $this->db->prepare(
            'INSERT INTO hipodromo (id, nombre) VALUES (?, ?) ON DUPLICATE KEY UPDATE nombre = IF(VALUES(nombre) = \'\', nombre, VALUES(nombre))'
        )->execute([$id, $nombre ?? '']);
    }
}
