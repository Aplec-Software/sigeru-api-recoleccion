<?php

namespace App\Models;

use App\Core\Model;

class Cuadrilla extends Model
{
    protected static string $tabla = 'cuadrillas';
    protected static array $campos = ['nombre', 'zona_id', 'turno', 'activa'];
    protected static array $filtros = ['zona_id', 'turno'];
    protected static array $busqueda = ['t.nombre'];
    protected static string $orden = 't.nombre';
    protected static ?string $bajaLogica = 'activa';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, z.nombre AS zona,
                       (SELECT COUNT(*) FROM cuadrilla_integrante ci WHERE ci.cuadrilla_id = t.id) AS integrantes_cantidad
                FROM cuadrillas t JOIN zonas z ON z.id = t.zona_id';
    }

    public static function integrantes(int $id): array
    {
        return static::todos('SELECT u.id, u.nombre, u.apellido, u.email, ci.es_responsable
                              FROM cuadrilla_integrante ci JOIN usuarios u ON u.id = ci.usuario_id
                              WHERE ci.cuadrilla_id = ? ORDER BY ci.es_responsable DESC, u.apellido', [$id]);
    }

    public static function deUsuario(int $usuarioId): ?int
    {
        $id = static::valor('SELECT cuadrilla_id FROM cuadrilla_integrante WHERE usuario_id = ?', [$usuarioId]);
        return $id === false ? null : (int) $id;
    }

    /** Valida que el usuario exista, esté activo y tenga rol cuadrilla. */
    public static function esPersonalDeCuadrilla(int $usuarioId): bool
    {
        return static::valor("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
                              WHERE u.id = ? AND u.estado = 'activo' AND r.nombre = 'cuadrilla'", [$usuarioId]) > 0;
    }

    public static function otraCuadrillaDe(int $usuarioId, int $cuadrillaId): ?int
    {
        $id = static::valor('SELECT cuadrilla_id FROM cuadrilla_integrante WHERE usuario_id = ? AND cuadrilla_id <> ?', [$usuarioId, $cuadrillaId]);
        return $id === false ? null : (int) $id;
    }

    public static function guardarIntegrantes(int $id, array $integrantes): void
    {
        static::ejecutar('DELETE FROM cuadrilla_integrante WHERE cuadrilla_id = ?', [$id]);
        $st = static::db()->prepare('INSERT INTO cuadrilla_integrante (cuadrilla_id, usuario_id, es_responsable) VALUES (?, ?, ?)');
        foreach ($integrantes as $i) {
            $st->execute([$id, (int) $i['usuario_id'], empty($i['es_responsable']) ? 0 : 1]);
        }
    }
}
