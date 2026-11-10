<?php

namespace App\Core;

/**
 * Sistema de notificaciones y alertas.
 * Crea una notificación por cada destinatario; el frontend las consulta
 * periódicamente a la API de Usuarios y muestra el aviso.
 */
class Notifier
{
    public static function aRol(string $rol, string $tipo, string $titulo, string $mensaje, ?string $enlace = null): int
    {
        $st = Database::conexion()->prepare(
            "SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.nombre = ? AND u.estado = 'activo'"
        );
        $st->execute([$rol]);
        return self::aUsuarios(array_column($st->fetchAll(), 'id'), $tipo, $titulo, $mensaje, $enlace);
    }

    public static function aCuadrilla(int $cuadrillaId, string $tipo, string $titulo, string $mensaje, ?string $enlace = null): int
    {
        $st = Database::conexion()->prepare('SELECT usuario_id FROM cuadrilla_integrante WHERE cuadrilla_id = ?');
        $st->execute([$cuadrillaId]);
        return self::aUsuarios(array_column($st->fetchAll(), 'usuario_id'), $tipo, $titulo, $mensaje, $enlace);
    }

    public static function aUsuarios(array $ids, string $tipo, string $titulo, string $mensaje, ?string $enlace = null): int
    {
        $st = Database::conexion()->prepare(
            'INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, enlace, leida, creado_en) VALUES (?, ?, ?, ?, ?, 0, ?)'
        );
        $ahora = Clock::ahora();
        foreach (array_unique($ids) as $id) {
            $st->execute([(int) $id, $tipo, mb_substr($titulo, 0, 120), mb_substr($mensaje, 0, 255), $enlace, $ahora]);
        }
        return count(array_unique($ids));
    }
}
