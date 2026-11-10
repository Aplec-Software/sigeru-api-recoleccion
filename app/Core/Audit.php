<?php

namespace App\Core;

/**
 * Auditoría: guarda quién hizo qué cambio y cuándo (tabla auditoria).
 */
class Audit
{
    public static function registrar(Request $req, string $accion, string $entidad, int|string|null $id = null, ?string $detalle = null): void
    {
        $sql = 'INSERT INTO auditoria (usuario_id, servicio, accion, entidad, entidad_id, detalle, ip, creado_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        Database::conexion()->prepare($sql)->execute([
            $req->usuario['id'] ?? null,
            Config::get('APP_NOMBRE', 'api'),
            $accion,
            $entidad,
            $id === null ? null : (string) $id,
            $detalle === null ? null : mb_substr($detalle, 0, 500),
            $req->ip,
            Clock::ahora(),
        ]);
    }
}
