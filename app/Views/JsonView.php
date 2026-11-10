<?php

namespace App\Views;

/**
 * Vista de la API: arma la representación JSON de un registro.
 * En una API REST la "vista" del MVC es el formato de la respuesta:
 * acá se convierten los tipos (la base devuelve los decimales como texto)
 * y se quitan los campos que nunca deben salir (por ejemplo, hashes).
 */
class JsonView
{
    public static function render(array $fila, array $tipos = [], array $ocultos = []): array
    {
        foreach ($ocultos as $campo) {
            unset($fila[$campo]);
        }
        foreach ($fila as $campo => $valor) {
            if ($valor === null) {
                continue;
            }
            $tipo = $tipos[$campo] ?? self::tipoPorNombre($campo);
            $fila[$campo] = match ($tipo) {
                'int' => (int) $valor,
                'float' => (float) $valor,
                'bool' => (bool) $valor,
                default => $valor,
            };
        }
        return $fila;
    }

    /** Convenciones de nombres para no repetir los tipos en cada recurso. */
    private static function tipoPorNombre(string $campo): ?string
    {
        if ($campo === 'id' || str_ends_with($campo, '_id') || in_array($campo, ['orden', 'anio', 'stock', 'stock_minimo', 'kilometraje', 'nivel_llenado', 'nivel_previo', 'capacidad_litros', 'cantidad', 'cantidad_reportes', 'total'], true)) {
            return 'int';
        }
        if (in_array($campo, ['latitud', 'longitud', 'ultima_latitud', 'ultima_longitud', 'porcentaje', 'costo', 'peso_kg', 'peso_ton', 'capacidad_ton', 'ocupacion_ton'], true)) {
            return 'float';
        }
        if (in_array($campo, ['activo', 'activa', 'leida', 'reciclable', 'publico', 'es_responsable'], true)) {
            return 'bool';
        }
        return null;
    }
}
