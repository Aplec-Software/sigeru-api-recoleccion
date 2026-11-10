<?php

namespace App\Models;

use App\Core\Model;

class Mantenimiento extends Model
{
    protected static string $tabla = 'mantenimientos';
    protected static array $campos = ['camion_id', 'tipo', 'descripcion', 'fecha_programada', 'fecha_realizada', 'kilometraje', 'costo', 'estado'];
    protected static array $filtros = ['camion_id', 'tipo', 'estado'];
    protected static array $busqueda = ['t.descripcion', 'c.matricula'];
    protected static string $orden = 't.fecha_programada DESC, t.id DESC';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, c.matricula FROM mantenimientos t JOIN camiones c ON c.id = t.camion_id';
    }

    /** ¿Tiene el camión un mantenimiento programado para ese día? (RNE de asignación) */
    public static function programadoEn(int $camionId, string $fecha): bool
    {
        return static::valor("SELECT COUNT(*) FROM mantenimientos WHERE camion_id = ? AND fecha_programada = ? AND estado = 'programado'",
            [$camionId, $fecha]) > 0;
    }

    public static function proximos(string $desde, string $hasta): array
    {
        return static::todos("SELECT t.*, c.matricula FROM mantenimientos t JOIN camiones c ON c.id = t.camion_id
                              WHERE t.estado = 'programado' AND t.fecha_programada BETWEEN ? AND ? ORDER BY t.fecha_programada", [$desde, $hasta]);
    }
}
