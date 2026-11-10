<?php

namespace App\Core;

/**
 * Acceso a los parámetros del sistema (tabla parametros).
 * Se guardan en memoria durante la petición para no repetir consultas.
 */
class Params
{
    private static ?array $cache = null;

    public static function get(string $clave, string $porDefecto = ''): string
    {
        if (self::$cache === null) {
            try {
                $filas = Database::conexion()->query('SELECT clave, valor FROM parametros')->fetchAll();
                self::$cache = array_column($filas, 'valor', 'clave');
            } catch (\Throwable $e) {
                self::$cache = [];
            }
        }
        return self::$cache[$clave] ?? $porDefecto;
    }

    public static function int(string $clave, int $porDefecto): int
    {
        return (int) self::get($clave, (string) $porDefecto);
    }

    public static function limpiar(): void
    {
        self::$cache = null;
    }
}
