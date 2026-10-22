<?php
declare(strict_types=1);

namespace GranChance\JP;

use PDO;

/** Guarda en MySQL (base granchance_jp) lo que devuelve ParserResultados. Todo es UPSERT: se puede re-cargar una reunión sin duplicar. */
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

    /**
     * Anota las reuniones de un día (salida de ParserResultados::dia) que todavía no estaban, con su reunión, día y horas.
     * @return int cuántas son nuevas
     */
    public function registrarDia(array $reuniones): int
    {
        $st = $this->db->prepare(
            "INSERT INTO descarga (hipodromo_id, fecha, estado, kai, hi, carreras, horas) VALUES (?,?,'pendiente',?,?,?,?)
             ON DUPLICATE KEY UPDATE kai = VALUES(kai), hi = VALUES(hi), carreras = VALUES(carreras), horas = VALUES(horas)"
        );
        $nuevas = 0;
        foreach ($reuniones as $r) {
            $horas = implode(' ', array_map(fn($c) => $c['hora'] ?? '-', $r['carreras']));
            $st->execute([(int)$r['hipodromo'], $r['fecha'], (int)$r['kai'], (int)$r['hi'], count($r['carreras']), $horas]);
            $nuevas += $st->rowCount() === 1 ? 1 : 0;
        }
        return $nuevas;
    }

    /** Días del calendario ya anotados en descarga. @return array<string, true> */
    public function diasAnotados(string $desde, string $hasta): array
    {
        $st = $this->db->prepare('SELECT DISTINCT fecha FROM descarga WHERE fecha BETWEEN ? AND ?');
        $st->execute([$desde, $hasta]);
        return array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
    }

    /** @return list<array{hipodromo: string, fecha: string, kai: string, hi: string, carreras: int, horas: ?string}> */
    public function reuniones(string $desde, string $hasta, bool $todas): array
    {
        $st = $this->db->prepare(
            'SELECT h.codigo AS hipodromo, d.fecha, LPAD(d.kai, 2, "0") AS kai, LPAD(d.hi, 2, "0") AS hi, d.carreras, d.horas
             FROM descarga d JOIN hipodromo h ON h.id = d.hipodromo_id
             WHERE d.fecha BETWEEN ? AND ?' . ($todas ? '' : " AND d.estado IN ('pendiente','error')") . ' ORDER BY d.fecha, h.id'
        );
        $st->execute([$desde, $hasta]);
        return array_map(fn($r) => ['carreras' => (int)$r['carreras']] + $r, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function registrarDescarga(string $hip, string $fecha, array $d): void
    {
        $this->db->prepare(
            'INSERT INTO descarga (hipodromo_id, fecha, estado, bytes, detalle, consultado_en) VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), bytes = VALUES(bytes), detalle = VALUES(detalle), consultado_en = NOW()'
        )->execute([(int)$hip, $fecha, $d['estado'], $d['bytes'], $d['detalle'] !== null ? substr($d['detalle'], 0, 255) : null]);
    }

    /** @param list<array> $carreras salida de ParserResultados::carrera() (solo las oficiales) */
    public function guardarReunion(array $reunion, array $carreras, ?string $carpeta): int
    {
        $this->db->beginTransaction();
        try {
            $apostado = 0.0;
            foreach ($carreras as $c) {
                foreach ($c['apuestas'] as $ap) {
                    $apostado += (float)$ap['pozo'];
                }
            }
            $this->db->prepare(
                'INSERT INTO reunion (hipodromo_id, fecha, nro_reunion, dia_reunion, total_apostado, archivo_crudo, actualizado_en) VALUES (?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nro_reunion = VALUES(nro_reunion), dia_reunion = VALUES(dia_reunion),
                   total_apostado = VALUES(total_apostado), archivo_crudo = VALUES(archivo_crudo), actualizado_en = NOW()'
            )->execute([(int)$reunion['hipodromo'], $reunion['fecha'], (int)$reunion['kai'], (int)$reunion['hi'], $apostado ?: null, $carpeta]);
            $reunionId = (int)$this->db->lastInsertId();

            foreach ($carreras as $c) {
                $this->guardarCarrera($reunionId, $c);
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

    private function guardarCarrera(int $reunionId, array $c): void
    {
        $this->db->prepare(
            'INSERT INTO carrera (reunion_id, numero, premio, grado, distancia, hora, superficie, pista, clima, clase, edad, sexo, peso_tipo, marcas,
                condicion, bolsa, bolsa_detalle, premio_base, tiempo_ganador, tiempo_ganador_seg, parciales, retirados)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), premio = VALUES(premio), grado = VALUES(grado), distancia = VALUES(distancia),
                hora = VALUES(hora), superficie = VALUES(superficie), pista = VALUES(pista), clima = VALUES(clima), clase = VALUES(clase),
                edad = VALUES(edad), sexo = VALUES(sexo), peso_tipo = VALUES(peso_tipo), marcas = VALUES(marcas), condicion = VALUES(condicion),
                bolsa = VALUES(bolsa), bolsa_detalle = VALUES(bolsa_detalle), premio_base = VALUES(premio_base), tiempo_ganador = VALUES(tiempo_ganador),
                tiempo_ganador_seg = VALUES(tiempo_ganador_seg), parciales = VALUES(parciales), retirados = VALUES(retirados)'
        )->execute([
            $reunionId, $c['numero'], $c['premio'], $c['grado'], $c['distancia'], $c['hora'] ? "{$c['fecha']} {$c['hora']}:00" : null,
            $c['superficie'], $c['pista'], $c['clima'], $c['clase'], $c['edad'], $c['sexo'], $c['peso_tipo'], $c['marcas'],
            $c['condicion'] !== null ? mb_substr($c['condicion'], 0, 255) : null, $c['bolsa'], $c['bolsa_detalle'], $c['premio_base'],
            $c['tiempo_ganador'], $c['tiempo_ganador_seg'], $c['parciales'], $c['retirados'],
        ]);
        $carreraId = (int)$this->db->lastInsertId();

        // Una corrección oficial puede cambiar puestos o caballos: se reemplaza el detalle completo.
        foreach (['actuacion', 'carrera_parcial', 'apuesta'] as $tabla) {
            $this->db->prepare("DELETE FROM $tabla WHERE carrera_id = ?")->execute([$carreraId]);
        }

        $insTramo = $this->db->prepare('INSERT INTO carrera_parcial (carrera_id, tramo, tiempo_acum, tiempo) VALUES (?,?,?,?)');
        foreach ($c['parciales_tramos'] as $t) {
            $insTramo->execute([$carreraId, $t['tramo'], $t['tiempo_acum'], $t['tiempo']]);
        }

        $insAct = $this->db->prepare(
            'INSERT IGNORE INTO actuacion (carrera_id, caballo_id, puesto, puesto_texto, numero, gatera, cuadro, edad, sexo, jockey_id, cuidador_id,
                stud_id, peso_asignado, dist_anterior, posiciones, favorito, cuota, tiempo, tiempo_seg)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($c['actuaciones'] as $a) {
            if ($a['codigo'] === null || $a['codigo'] === '') {
                continue;   // fila que no se pudo leer; queda el crudo para revisarla
            }
            $insAct->execute([
                $carreraId, $this->caballo($a), $a['puesto'], $a['puesto_texto'], $a['numero'], $a['numero'], $a['cuadro'], $a['edad'], $a['sexo'],
                $this->persona('jockey', $a['jockey']), $this->persona('cuidador', $a['cuidador']), $this->persona('stud', $a['propietario']),
                $a['peso_asignado'], $a['margen'], $a['posiciones'], $a['favorito'], $a['cuota'], $a['tiempo'], $a['tiempo_seg'],
            ]);
        }

        $insAp = $this->db->prepare(
            'INSERT INTO apuesta (carrera_id, tipo, orden, monto_base, combinacion, dividendo, detalle, pozo) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE combinacion = VALUES(combinacion), dividendo = VALUES(dividendo), detalle = VALUES(detalle)'
        );
        foreach ($c['apuestas'] as $ap) {
            $insAp->execute([$carreraId, $ap['tipo'], $ap['orden'], $ap['monto_base'], $ap['combinacion'], $ap['dividendo'], $ap['detalle'], $ap['pozo']]);
        }
    }

    private function caballo(array $a): int
    {
        $anio = preg_match('/^(19|20)\d\d/', (string)$a['codigo'], $m) ? (int)$m[0] : null;
        $this->db->prepare(
            'INSERT INTO caballo (codigo_jra, nombre, anio_nac, pais, sexo, padre, madre, abuelo_materno, abuela_materna, criador_id) VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nombre = VALUES(nombre), anio_nac = COALESCE(VALUES(anio_nac), anio_nac),
                pais = COALESCE(VALUES(pais), pais), sexo = COALESCE(VALUES(sexo), sexo), padre = COALESCE(VALUES(padre), padre),
                madre = COALESCE(VALUES(madre), madre), abuelo_materno = COALESCE(VALUES(abuelo_materno), abuelo_materno),
                abuela_materna = COALESCE(VALUES(abuela_materna), abuela_materna), criador_id = COALESCE(VALUES(criador_id), criador_id)'
        )->execute([$a['codigo'], $a['nombre'], $anio, $a['pais'], $a['sexo'], $a['padre'], $a['madre'], $a['abuelo_materno'], $a['abuela_materna'],
            $this->persona('criador', $a['criador'])]);
        return (int)$this->db->lastInsertId();
    }

    private function persona(string $tipo, ?string $nombre): ?int
    {
        $nombre = trim((string)$nombre);
        if ($nombre === '') {
            return null;
        }
        $nombre = mb_substr($nombre, 0, 120);
        $clave = $tipo . '|' . mb_strtolower($nombre);
        if (!isset($this->personas[$clave])) {
            $this->db->prepare('INSERT INTO persona (tipo, nombre) VALUES (?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$tipo, $nombre]);
            $this->personas[$clave] = (int)$this->db->lastInsertId();
        }
        return $this->personas[$clave];
    }
}
