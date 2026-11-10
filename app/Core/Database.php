<?php

namespace App\Core;

use PDO;

/**
 * Conexión única a la base de datos mediante PDO.
 * En producción se usa MySQL 8; en las pruebas automáticas se inyecta una
 * conexión SQLite en memoria con setConexion().
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function conexion(): PDO
    {
        if (self::$pdo === null && Config::get('DB_DRIVER') === 'sqlite') {
            // solo para desarrollo y demostraciones locales sin servidor MySQL
            self::$pdo = new PDO('sqlite:' . Config::get('DB_RUTA', 'sigeru.sqlite'), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }
        if (self::$pdo === null) {
            $host = Config::get('DB_HOST', '127.0.0.1');
            $puerto = Config::get('DB_PORT', '3306');
            $base = Config::get('DB_NAME', 'sigeru');
            $dsn = "mysql:host=$host;port=$puerto;dbname=$base;charset=utf8mb4";
            self::$pdo = new PDO($dsn, Config::get('DB_USER', ''), Config::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '-03:00'");
        }
        return self::$pdo;
    }

    public static function setConexion(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function driver(): string
    {
        return self::conexion()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Expresión SQL que devuelve las horas entre dos columnas de fecha (MySQL o SQLite). */
    public static function horasEntre(string $desde, string $hasta): string
    {
        return self::driver() === 'sqlite'
            ? "((julianday($hasta) - julianday($desde)) * 24)"
            : "(TIMESTAMPDIFF(MINUTE, $desde, $hasta) / 60)";
    }

    /** Expresión SQL que devuelve el mes (AAAA-MM) de una columna de fecha. */
    public static function mes(string $columna): string
    {
        return self::driver() === 'sqlite'
            ? "strftime('%Y-%m', $columna)"
            : "DATE_FORMAT($columna, '%Y-%m')";
    }
}
