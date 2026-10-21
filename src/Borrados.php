<?php
declare(strict_types=1);

namespace GranChance;

use PDO;

/**
 * Caballos borrados de una reunión. La página hipica.maronas.com.uy/RacingInfo/ScratchedHorses los toma del servicio
 * XTurfRestService.svc/GetScratchedRaceParticipants (POST con JSON), que devuelve todos los hipódromos de esa fecha:
 * carrera, número de mandil, nombre y motivo ("Voluntario", "Obligatorio", "Veterinario"...). HRU los publica a las 11:00.
 */
final class Borrados
{
    public function __construct(private PDO $db, private string $url)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Baja los borrados de la fecha y los guarda (reemplaza los de esa fecha).
     * @return array<int, list<array>> hipódromo => borrados
     */
    public function actualizar(string $fecha): array
    {
        $lista = self::leer($this->pedir($fecha));
        if (!$lista) {
            return [];      // antes de las 11, o el servicio no devolvió nada: se conservan los ya leídos
        }
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM borrado WHERE fecha = ?')->execute([$fecha]);
        $st = $this->db->prepare('INSERT IGNORE INTO borrado (hipodromo_id, fecha, carrera, mandil, caballo, motivo, leido_en) VALUES (?,?,?,?,?,?,?)');
        $res = [];
        foreach ($lista as $b) {
            $st->execute([$b['hipodromo'], $fecha, $b['carrera'], $b['mandil'], $b['caballo'], $b['motivo'], date('Y-m-d H:i:s')]);
            $res[$b['hipodromo']][] = $b;
        }
        $this->db->commit();
        return $res;
    }

    /** Borrados guardados de una reunión: "carrera-mandil" => fila. */
    public function deReunion(int $hip, string $fecha): array
    {
        $st = $this->db->prepare('SELECT carrera, mandil, caballo, motivo FROM borrado WHERE hipodromo_id = ? AND fecha = ?');
        $st->execute([$hip, $fecha]);
        $res = [];
        foreach ($st as $b) {
            $res[$b['carrera'] . '-' . strtoupper($b['mandil'])] = $b;
        }
        return $res;
    }

    /** @return list<array{hipodromo:int, carrera:int, mandil:string, caballo:string, motivo:?string}> */
    public static function leer(string $json): array
    {
        $datos = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json), true);
        if (!is_array($datos)) {
            throw new \RuntimeException('Respuesta de borrados inesperada: ' . substr($json, 0, 200));
        }
        $res = [];
        foreach ($datos as $p) {
            $res[] = [
                'hipodromo' => (int)($p['Race']['RaceTrack']['Id'] ?? 0),
                'carrera' => (int)($p['Race']['Number'] ?? 0),
                'mandil' => trim((string)($p['ProgramNumber'] ?? '')),
                'caballo' => trim((string)($p['Horse']['ProgramName'] ?? $p['Horse']['Name'] ?? '')),
                'motivo' => isset($p['ScratchedCause']['Description']) ? trim($p['ScratchedCause']['Description']) : null,
            ];
        }
        return $res;
    }

    private function pedir(string $fecha): string
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['raceTrackId' => null, 'date' => $fecha . 'T00:00:00', 'ordinal' => null, 'sessionInfo' => null]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'GranChance/1.0 (estadisticas hipicas)',
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($cuerpo === false || $codigo !== 200) {
            throw new \RuntimeException('No se pudieron bajar los borrados: ' . ($cuerpo === false ? curl_error($ch) : "HTTP $codigo"));
        }
        return (string)$cuerpo;
    }
}
