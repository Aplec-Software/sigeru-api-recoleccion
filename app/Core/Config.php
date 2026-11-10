<?php

namespace App\Core;

/**
 * Lee la configuración desde variables de entorno.
 * Si existe un archivo .env en la raíz de la aplicación, se carga primero
 * (las variables definidas por Docker tienen prioridad sobre el archivo).
 */
class Config
{
    private static bool $cargado = false;

    public static function cargarArchivo(string $ruta): void
    {
        if (self::$cargado || !is_file($ruta)) {
            self::$cargado = true;
            return;
        }
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            $valor = trim($valor, "\"'");
            if (getenv($clave) === false) {
                putenv("$clave=$valor");
                $_ENV[$clave] = $valor;
            }
        }
        self::$cargado = true;
    }

    public static function get(string $clave, ?string $porDefecto = null): ?string
    {
        $valor = getenv($clave);
        if ($valor === false) {
            $valor = $_ENV[$clave] ?? null;
        }
        return ($valor === null || $valor === '') ? $porDefecto : $valor;
    }

    public static function int(string $clave, int $porDefecto): int
    {
        return (int) self::get($clave, (string) $porDefecto);
    }
}
