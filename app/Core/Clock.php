<?php

namespace App\Core;

/**
 * Fecha y hora del sistema (zona horaria de Uruguay).
 * Centralizarlo permite fijar la hora en las pruebas.
 */
class Clock
{
    private static ?string $fija = null;

    public static function ahora(): string
    {
        if (self::$fija !== null) {
            return self::$fija;
        }
        $real = (new \DateTime('now', new \DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s');
        // Solo para demostraciones y pruebas: FECHA_SIMULADA=AAAA-MM-DD muestra el sistema como si fuera ese día
        // (con la hora real), por ejemplo para ver los recorridos planificados de los datos de prueba.
        $simulada = getenv('FECHA_SIMULADA');
        if (is_string($simulada) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $simulada)) {
            return $simulada . substr($real, 10);
        }
        return $real;
    }

    public static function hoy(): string
    {
        return substr(self::ahora(), 0, 10);
    }

    /** Suma (o resta) minutos a la fecha actual. */
    public static function mas(int $minutos): string
    {
        $fecha = new \DateTime(self::ahora(), new \DateTimeZone('America/Montevideo'));
        $fecha->modify(($minutos >= 0 ? '+' : '') . $minutos . ' minutes');
        return $fecha->format('Y-m-d H:i:s');
    }

    public static function fijar(?string $fecha): void
    {
        self::$fija = $fecha;
    }
}
