<?php

namespace App\Models;

use App\Core\Model;

class Asignacion extends Model
{
    protected static string $tabla = 'asignaciones';
    protected static array $campos = [
        'ruta_id', 'cuadrilla_id', 'camion_id', 'fecha', 'turno', 'estado', 'iniciada_en', 'finalizada_en', 'observaciones',
    ];
    protected static array $filtros = ['ruta_id', 'cuadrilla_id', 'camion_id', 'fecha', 'turno', 'estado'];
    protected static array $busqueda = ['r.nombre', 'c.matricula'];
    protected static string $orden = 't.fecha DESC, t.turno, t.id';

    public const TRANSICIONES = [
        'planificada' => ['en_curso', 'cancelada'],
        'en_curso' => ['completada', 'cancelada'],
        'completada' => [],
        'cancelada' => [],
    ];

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, r.nombre AS ruta, cu.nombre AS cuadrilla, c.matricula,
                       (SELECT COUNT(*) FROM ruta_contenedor rc WHERE rc.ruta_id = t.ruta_id) AS contenedores_ruta,
                       (SELECT COUNT(*) FROM recolecciones re WHERE re.asignacion_id = t.id) AS contenedores_recolectados
                FROM asignaciones t
                JOIN rutas r ON r.id = t.ruta_id
                JOIN cuadrillas cu ON cu.id = t.cuadrilla_id
                JOIN camiones c ON c.id = t.camion_id';
    }

    /** Busca si el camión o la cuadrilla ya tienen otra asignación en esa fecha y turno. */
    public static function choque(string $columna, int $valor, string $fecha, string $turno, ?int $excepto = null): bool
    {
        return static::valor("SELECT COUNT(*) FROM asignaciones WHERE $columna = ? AND fecha = ? AND turno = ? AND estado <> 'cancelada' AND id <> ?",
            [$valor, $fecha, $turno, $excepto ?? 0]) > 0;
    }
}
