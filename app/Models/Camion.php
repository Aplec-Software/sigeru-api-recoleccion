<?php

namespace App\Models;

use App\Core\Clock;
use App\Core\Model;

class Camion extends Model
{
    protected static string $tabla = 'camiones';
    protected static array $campos = [
        'matricula', 'marca', 'modelo', 'anio', 'tipo', 'capacidad_ton', 'estado', 'kilometraje',
        'ultima_latitud', 'ultima_longitud', 'ultima_posicion_en', 'activo',
    ];
    protected static array $filtros = ['estado', 'tipo'];
    protected static array $busqueda = ['t.matricula', 't.marca', 't.modelo'];
    protected static string $orden = 't.matricula';
    protected static ?string $bajaLogica = 'activo';

    public static function asignacionesFuturas(int $id): int
    {
        return (int) static::valor("SELECT COUNT(*) FROM asignaciones WHERE camion_id = ? AND fecha >= ? AND estado IN ('planificada','en_curso')", [$id, Clock::hoy()]);
    }

    public static function cambiarEstado(int $id, string $estado): void
    {
        static::ejecutar('UPDATE camiones SET estado = ? WHERE id = ?', [$estado, $id]);
    }

    public static function moverA(int $id, float $lat, float $lng): void
    {
        static::ejecutar('UPDATE camiones SET ultima_latitud = ?, ultima_longitud = ?, ultima_posicion_en = ? WHERE id = ?',
            [$lat, $lng, Clock::ahora(), $id]);
    }
}
