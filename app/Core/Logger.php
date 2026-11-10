<?php

namespace App\Core;

/**
 * Registro de actividad (log) de la API.
 * Escribe en el archivo indicado en LOG_ARCHIVO (en Docker es una carpeta del
 * servidor montada como volumen, así el script del servidor puede revisarlo).
 * Si el nombre lleva {fecha}, se escribe un archivo por día y el script borra
 * los más viejos (no hace falta logrotate).
 */
class Logger
{
    public static function info(string $mensaje): void
    {
        self::escribir('INFO', $mensaje);
    }

    public static function error(string $mensaje): void
    {
        self::escribir('ERROR', $mensaje);
    }

    public static function acceso(Request $req, Response $res, float $inicio): void
    {
        $ms = (int) round((microtime(true) - $inicio) * 1000);
        $usuario = $req->usuario['id'] ?? '-';
        self::escribir('ACCESO', sprintf('%s %s %s %d %dms usuario=%s', $req->ip, $req->metodo, $req->ruta, $res->status, $ms, $usuario));
    }

    private static function escribir(string $nivel, string $mensaje): void
    {
        $archivo = Config::get('LOG_ARCHIVO');
        if ($archivo === null) {
            return;
        }
        $archivo = str_replace('{fecha}', substr(Clock::ahora(), 0, 10), $archivo);
        $linea = sprintf("[%s] %s %s: %s\n", Clock::ahora(), Config::get('APP_NOMBRE', 'api'), $nivel, $mensaje);
        @file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
    }
}
