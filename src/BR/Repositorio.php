<?php
declare(strict_types=1);

namespace GranChance\BR;

use PDO;

/**
 * Guarda en MySQL (base granchance_br) lo que devuelve ParserResultados: el programa de la reunión (todos los inscriptos)
 * completado con el resultado completo de cada páreo. Todo es UPSERT: se puede re-cargar una reunión sin duplicar.
 */
final class Repositorio
{
    /** @var array<string, int> caché tipo|nombre => id */
    private array $personas = [];
    /** @var array<string, int> */
    private array $hipodromos = [];

    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function desdeConfig(array $cfg): self
    {
        return new self(new PDO($cfg['dsn'], $cfg['usuario'], $cfg['clave'], [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']));
    }

    public function registrarDia(string $fecha, ?string $fechaReunion, array $reuniones): void
    {
        $texto = implode(';', array_map(fn($r) => "{$r['hipodromo']}|{$r['nro']}|" . ($r['id'] ?? ''), $reuniones));
        $this->db->prepare(
            'INSERT INTO dia (fecha, fecha_reunion, reuniones, consultado_en) VALUES (?,?,?,NOW())
             ON DUPLICATE KEY UPDATE fecha_reunion = VALUES(fecha_reunion), reuniones = VALUES(reuniones), consultado_en = NOW()'
        )->execute([$fecha, $fechaReunion, mb_substr($texto, 0, 1000)]);
    }

    /** Anota una reunión brasileña (si no estaba, como pendiente). Devuelve true si es nueva. */
    public function registrarReunion(int $id, string $hipodromo, string $fecha, ?int $nro): bool
    {
        $st = $this->db->prepare(
            "INSERT INTO descarga (id_jcb, hipodromo_id, fecha, nro_reunion, estado) VALUES (?,?,?,?,'pendiente')
             ON DUPLICATE KEY UPDATE hipodromo_id = VALUES(hipodromo_id), fecha = VALUES(fecha), nro_reunion = VALUES(nro_reunion)"
        );
        $st->execute([$id, $this->hipodromo($hipodromo), $fecha, $nro]);
        return $st->rowCount() === 1;
    }

    /** @return list<array{id: int, hipodromo: string, fecha: string, nro: ?int}> */
    public function reuniones(string $desde, string $hasta, bool $todas): array
    {
        $st = $this->db->prepare(
            'SELECT d.id_jcb AS id, h.nombre AS hipodromo, d.fecha, d.nro_reunion AS nro FROM descarga d JOIN hipodromo h ON h.id = d.hipodromo_id
             WHERE d.fecha BETWEEN ? AND ?' . ($todas ? '' : " AND d.estado IN ('pendiente','error')") . ' ORDER BY d.fecha, d.id_jcb'
        );
        $st->execute([$desde, $hasta]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'nro' => $r['nro'] === null ? null : (int)$r['nro']] + $r, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function registrarDescarga(int $id, array $d): void
    {
        $this->db->prepare('UPDATE descarga SET estado = ?, bytes = ?, detalle = ?, consultado_en = NOW() WHERE id_jcb = ?')
            ->execute([$d['estado'], $d['bytes'], $d['detalle'] !== null ? mb_substr($d['detalle'], 0, 255) : null, $id]);
    }

    /**
     * @param array $prog salida de ParserResultados::reunion()
     * @param array<int, array> $resultados salida de ParserResultados::resultado() por número de páreo
     * @return int carreras guardadas
     */
    public function guardarReunion(array $r, array $prog, array $resultados, ?string $carpeta): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO reunion (hipodromo_id, fecha, nro_reunion, id_jcb, total_apostado, penetrometro_vf, penetrometro_pr, archivo_crudo, actualizado_en)
                 VALUES (?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), hipodromo_id = VALUES(hipodromo_id), fecha = VALUES(fecha), nro_reunion = VALUES(nro_reunion),
                   total_apostado = VALUES(total_apostado), penetrometro_vf = VALUES(penetrometro_vf), penetrometro_pr = VALUES(penetrometro_pr),
                   archivo_crudo = VALUES(archivo_crudo), actualizado_en = NOW()'
            )->execute([
                $this->hipodromo($r['hipodromo']), $r['fecha'], $prog['nro_reunion'] ?? $r['nro'], $r['id'], $prog['total_apostado'],
                $prog['penetrometro_vf'], $prog['penetrometro_pr'], $carpeta,
            ]);
            $reunionId = (int)$this->db->lastInsertId();

            $numeros = [];
            foreach ($prog['carreras'] as $c) {
                if ($this->guardarCarrera($reunionId, $r['fecha'], $c, $resultados[$c['numero']] ?? null)) {
                    $numeros[] = (int)$c['numero'];
                }
            }
            // Carreras que ya no figuran o que no se corrieron: se borran con su detalle.
            $this->db->prepare('DELETE FROM carrera WHERE reunion_id = ? AND numero NOT IN (' . implode(',', $numeros ?: [0]) . ')')->execute([$reunionId]);
            if ($numeros === []) {
                $this->db->prepare('DELETE FROM reunion WHERE id = ?')->execute([$reunionId]);
            }
            $this->db->commit();
            return count($numeros);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Programa del raceday + resultado completo. Devuelve false (y no guarda) si el páreo no tiene ningún puesto: no se corrió. */
    private function guardarCarrera(int $reunionId, string $fecha, array $c, ?array $res): bool
    {
        $llegada = [];
        foreach ($res['llegada'] ?? [] as $l) {
            $llegada[self::clave($l['nombre'])] = $l;
        }
        $conPuesto = array_filter($c['actuaciones'], fn($a) => $a['puesto'] !== null);
        if ($llegada === [] && $conPuesto === []) {
            return false;
        }
        $ganador = null;
        foreach ($llegada as $l) {
            if ($l['puesto'] === 1) {
                $ganador ??= $l;
            }
        }
        $hora = $res['hora'] ?? $c['hora_programa'];
        $retirados = array_map(fn($a) => $a['numero'], array_filter($c['actuaciones'], fn($a) => $a['forfait']));

        $this->db->prepare(
            'INSERT INTO carrera (reunion_id, numero, premio, tipo_prova, distancia, hora, hora_programa, superficie, pista, condicion, recorde,
                total_apostado, tiempo_ganador, tiempo_ganador_seg, retirados, comentario)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), premio = VALUES(premio), tipo_prova = VALUES(tipo_prova), distancia = VALUES(distancia),
                hora = VALUES(hora), hora_programa = VALUES(hora_programa), superficie = VALUES(superficie), pista = VALUES(pista),
                condicion = VALUES(condicion), recorde = VALUES(recorde), total_apostado = VALUES(total_apostado),
                tiempo_ganador = VALUES(tiempo_ganador), tiempo_ganador_seg = VALUES(tiempo_ganador_seg), retirados = VALUES(retirados),
                comentario = VALUES(comentario)'
        )->execute([
            $reunionId, $c['numero'], self::corto($c['premio'] ?? $res['premio'] ?? null, 150), self::corto($c['tipo_prova'], 60), $c['distancia'] ?? $res['distancia'] ?? null,
            $hora === null ? null : "$fecha $hora", $c['hora_programa'], $c['superficie'] ?? $res['superficie'] ?? null, self::corto($c['pista'] ?? $res['pista'] ?? null, 40),
            $res['condicion'] ?? null, $res['recorde'] ?? null, $res['total_apostado'] ?? null,
            $ganador['posicion'] ?? null, ParserResultados::segundos($ganador['posicion'] ?? null),
            $retirados === [] ? null : implode(' ', $retirados), $res['comentario'] ?? null,
        ]);
        $carreraId = (int)$this->db->lastInsertId();

        // Una corrección oficial puede cambiar puestos o caballos: se reemplaza el detalle completo.
        foreach (['actuacion', 'apuesta'] as $tabla) {
            $this->db->prepare("DELETE FROM $tabla WHERE carrera_id = ?")->execute([$carreraId]);
        }

        $insAct = $this->db->prepare(
            'INSERT IGNORE INTO actuacion (carrera_id, caballo_id, puesto, puesto_texto, numero, peso_caballo, retiro_motivo, estreante, jockey_id, aprendiz,
                cuidador_id, stud_id, peso_asignado, peso_real, peso_texto, dist_acumulada, cuota, tiempo, tiempo_seg, favorito)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($c['actuaciones'] as $a) {
            $l = $llegada[self::clave($a['nombre'])] ?? null;
            // Puesto de la llegada; sin puesto o 28 (NCP, no completó el recorrido) queda lo del programa.
            $lp = $l !== null && $l['puesto'] !== null && $l['puesto'] < 25 ? $l['puesto'] : null;
            $criador = $l['criador'] ?? null;
            $insAct->execute([
                $carreraId, $this->caballo($fecha, $a, $criador === null ? null : $this->persona('criador', $criador)),
                $a['forfait'] ? null : ($lp ?? $a['puesto']), $a['forfait'] ? 'forfait' : ($lp !== null ? $lp . 'º' : $a['puesto_texto']),
                $a['numero'], $a['peso_caballo'], $a['forfait'] ? 'forfait' : null, $a['estreante'] ? 1 : 0,
                $this->persona('jockey', $a['jockey']), $a['aprendiz'], $this->persona('cuidador', $a['cuidador']), $this->persona('stud', $a['stud']),
                $a['peso_asignado'], $a['peso_real'], $a['peso_texto'],
                $lp !== null && $lp !== 1 ? $l['posicion'] : null,
                $a['cuota'] ?? $l['cuota'] ?? null,
                $lp === 1 ? $l['posicion'] : null,
                $lp === 1 ? ParserResultados::segundos($l['posicion']) : null,
                !empty($l['favorito']) ? 1 : 0,
            ]);
        }

        $insAp = $this->db->prepare(
            'INSERT INTO apuesta (carrera_id, tipo, orden, monto_base, combinacion, dividendo, detalle) VALUES (?,?,?,1,?,?,?)
             ON DUPLICATE KEY UPDATE combinacion = VALUES(combinacion), dividendo = VALUES(dividendo), detalle = VALUES(detalle)'
        );
        foreach ($res['apuestas'] ?? [] as $ap) {
            $insAp->execute([$carreraId, $ap['tipo'], $ap['orden'], $ap['combinacion'], $ap['dividendo'], $ap['detalle']]);
        }
        return true;
    }

    private function caballo(string $fecha, array $a, ?int $criadorId): int
    {
        $sexo = match (mb_strtolower((string)$a['sexo'])) {
            'macho' => 'm', 'fêmea', 'femea' => 'h', 'castrado' => 'c', default => null,
        };
        $this->db->prepare(
            'INSERT INTO caballo (nombre, anio_nac, pais, pelaje, sexo, padre, madre, abuelo_materno, criador_id) VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), pelaje = COALESCE(VALUES(pelaje), pelaje), sexo = COALESCE(VALUES(sexo), sexo),
                padre = COALESCE(VALUES(padre), padre), madre = COALESCE(VALUES(madre), madre),
                abuelo_materno = COALESCE(VALUES(abuelo_materno), abuelo_materno), criador_id = COALESCE(VALUES(criador_id), criador_id)'
        )->execute([
            mb_substr($a['nombre'], 0, 60), ParserResultados::anioNacimiento($fecha, $a['edad']), ParserResultados::pais($a['nombre']),
            $a['pelaje'], $sexo, $a['padre'], $a['madre'], $a['abuelo_materno'], $criadorId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function hipodromo(string $nombre): int
    {
        if (!isset($this->hipodromos[$nombre])) {
            $this->db->prepare('INSERT INTO hipodromo (nombre) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$nombre]);
            $this->hipodromos[$nombre] = (int)$this->db->lastInsertId();
        }
        return $this->hipodromos[$nombre];
    }

    private function persona(string $tipo, ?string $nombre): ?int
    {
        if ($nombre === null || trim($nombre) === '') {
            return null;
        }
        $nombre = mb_substr(trim($nombre), 0, 120);
        $clave = $tipo . '|' . mb_strtoupper($nombre);
        if (!isset($this->personas[$clave])) {
            $this->db->prepare('INSERT INTO persona (tipo, nombre) VALUES (?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$tipo, $nombre]);
            $this->personas[$clave] = (int)$this->db->lastInsertId();
        }
        return $this->personas[$clave];
    }

    private static function corto(?string $t, int $largo): ?string
    {
        return $t === null ? null : mb_substr($t, 0, $largo);
    }

    private static function clave(string $nombre): string
    {
        return mb_strtoupper(trim((string)preg_replace('/\s+/u', ' ', $nombre)));
    }
}
