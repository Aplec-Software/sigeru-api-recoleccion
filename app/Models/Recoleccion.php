<?php

namespace App\Models;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Model;

class Recoleccion extends Model
{
    protected static string $tabla = 'recolecciones';
    protected static array $campos = ['asignacion_id', 'contenedor_id', 'nivel_previo', 'peso_kg', 'observacion', 'creado_en'];
    protected static array $filtros = ['asignacion_id', 'contenedor_id'];
    protected static string $orden = 't.creado_en DESC';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, c.codigo AS contenedor_codigo, c.direccion FROM recolecciones t JOIN contenedores c ON c.id = t.contenedor_id';
    }

    /** Al vaciar el contenedor queda en 0 % y, si estaba desbordado, vuelve a funcional. */
    public static function vaciarContenedor(int $contenedorId): void
    {
        static::ejecutar("UPDATE contenedores SET nivel_llenado = 0, ultima_recoleccion = ?,
                                 estado = CASE WHEN estado = 'desbordado' THEN 'funcional' ELSE estado END
                          WHERE id = ?", [Clock::ahora(), $contenedorId]);
    }

    public static function contenedor(int $id): ?array
    {
        return static::uno('SELECT id, codigo, latitud, longitud, activo FROM contenedores WHERE id = ?', [$id]);
    }

    /** Kilos recolectados agrupados por día, mes, zona o tipo de residuo. */
    public static function volumen(string $desde, string $hasta, string $agrupar): array
    {
        $grupo = match ($agrupar) {
            'mes' => Database::mes('t.creado_en'),
            'zona' => 'z.nombre',
            'tipo' => 'tr.nombre',
            default => 'DATE(t.creado_en)',
        };
        return static::todos("SELECT $grupo AS grupo, COUNT(*) AS recolecciones, ROUND(SUM(t.peso_kg), 1) AS kilos
                              FROM recolecciones t
                              JOIN contenedores c ON c.id = t.contenedor_id
                              JOIN zonas z ON z.id = c.zona_id
                              JOIN tipos_residuo tr ON tr.id = c.tipo_residuo_id
                              WHERE t.creado_en BETWEEN ? AND ?
                              GROUP BY $grupo ORDER BY grupo", [$desde, $hasta . ' 23:59:59']);
    }

    public static function rendimientoCuadrillas(string $desde): array
    {
        return static::todos("SELECT cu.id, cu.nombre, COUNT(DISTINCT a.id) AS asignaciones,
                                     SUM(CASE WHEN a.estado = 'completada' THEN 1 ELSE 0 END) AS completadas,
                                     (SELECT COUNT(*) FROM recolecciones r JOIN asignaciones a2 ON a2.id = r.asignacion_id
                                       WHERE a2.cuadrilla_id = cu.id AND a2.fecha >= ?) AS contenedores
                              FROM cuadrillas cu LEFT JOIN asignaciones a ON a.cuadrilla_id = cu.id AND a.fecha >= ? AND a.fecha <= ?
                              GROUP BY cu.id, cu.nombre ORDER BY cu.nombre", [substr($desde, 0, 10), substr($desde, 0, 10), Clock::hoy()]);
    }

    public static function sinRecoleccion(int $dias): array
    {
        return static::todos('SELECT c.id, c.codigo, c.direccion, c.ultima_recoleccion, c.nivel_llenado FROM contenedores c
                              WHERE c.activo = 1 AND (c.ultima_recoleccion IS NULL OR c.ultima_recoleccion < ?) ORDER BY c.ultima_recoleccion',
            [Clock::mas(-$dias * 24 * 60)]);
    }
}
