<?php

namespace App\Core;

use PDO;

/**
 * Modelo base. Cada modelo indica su tabla y qué columnas se pueden escribir
 * ($campos). Todas las consultas usan sentencias preparadas: los valores
 * nunca se concatenan en el SQL, lo que evita la inyección SQL.
 */
abstract class Model
{
    protected static string $tabla;
    protected static array $campos = [];          // columnas que se pueden insertar / modificar
    protected static array $filtros = [];         // columnas que se pueden filtrar por ?columna=valor
    protected static array $busqueda = [];        // columnas donde busca ?q=texto
    protected static string $orden = 'id DESC';
    protected static ?string $bajaLogica = null;  // columna "activo" si la baja es lógica

    public static function db(): PDO
    {
        return Database::conexion();
    }

    /** SELECT base del listado; los modelos lo redefinen cuando necesitan JOIN. */
    protected static function consultaBase(): string
    {
        return 'SELECT * FROM ' . static::$tabla . ' t';
    }

    public static function buscar(int $id): ?array
    {
        return static::uno(static::consultaBase() . ' WHERE t.id = ?', [$id]);
    }

    /**
     * Listado con filtros, búsqueda y paginación.
     * @return array{0: array, 1: int} [filas, total]
     */
    public static function listar(array $query, int $pagina = 1, int $porPagina = 20, array $extra = []): array
    {
        $donde = [];
        $valores = [];
        foreach (static::$filtros as $col) {
            if (isset($query[$col]) && $query[$col] !== '') {
                $donde[] = "t.$col = ?";
                $valores[] = $query[$col];
            }
        }
        if (!empty($query['q']) && static::$busqueda) {
            $partes = [];
            foreach (static::$busqueda as $col) {
                $partes[] = "$col LIKE ?";
                $valores[] = '%' . $query['q'] . '%';
            }
            $donde[] = '(' . implode(' OR ', $partes) . ')';
        }
        if (static::$bajaLogica && empty($query['incluir_bajas'])) {
            $donde[] = 't.' . static::$bajaLogica . ' = 1';
        }
        foreach ($extra as $condicion => $valor) {
            $donde[] = $condicion;
            if ($valor !== null) {
                array_push($valores, ...(array) $valor);
            }
        }
        $where = $donde ? ' WHERE ' . implode(' AND ', $donde) : '';

        $total = (int) static::valor('SELECT COUNT(*) FROM (' . static::consultaBase() . $where . ') x', $valores);
        $sql = static::consultaBase() . $where . ' ORDER BY ' . static::$orden . ' LIMIT ? OFFSET ?';
        $st = static::db()->prepare($sql);
        $i = 1;
        foreach ($valores as $v) {
            $st->bindValue($i++, $v);
        }
        $st->bindValue($i++, $porPagina, PDO::PARAM_INT);
        $st->bindValue($i, ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $st->execute();
        return [$st->fetchAll(), $total];
    }

    public static function crear(array $datos): int
    {
        $datos = array_intersect_key($datos, array_flip(static::$campos));
        $columnas = array_keys($datos);
        $sql = 'INSERT INTO ' . static::$tabla . ' (' . implode(', ', $columnas) . ') VALUES ('
            . implode(', ', array_fill(0, count($columnas), '?')) . ')';
        static::preparar($sql, array_values($datos));
        return (int) static::db()->lastInsertId();
    }

    public static function actualizar(int $id, array $datos): void
    {
        $datos = array_intersect_key($datos, array_flip(static::$campos));
        if (!$datos) {
            return;
        }
        $sets = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($datos)));
        $sql = 'UPDATE ' . static::$tabla . " SET $sets WHERE id = ?";
        static::preparar($sql, [...array_values($datos), $id]);
    }

    /** Elimina el registro (o lo marca como inactivo si el modelo usa baja lógica). */
    public static function eliminar(int $id): void
    {
        if (static::$bajaLogica) {
            static::ejecutar('UPDATE ' . static::$tabla . ' SET ' . static::$bajaLogica . ' = 0 WHERE id = ?', [$id]);
        } else {
            static::ejecutar('DELETE FROM ' . static::$tabla . ' WHERE id = ?', [$id]);
        }
    }

    public static function existe(string $columna, mixed $valor, ?int $excepto = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . static::$tabla . " WHERE $columna = ?";
        $params = [$valor];
        if ($excepto !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excepto;
        }
        return static::valor($sql, $params) > 0;
    }

    // ----------------------------------------------------- auxiliares SQL

    /** Prepara la sentencia y vincula cada valor con su tipo (entero, nulo o texto). */
    protected static function preparar(string $sql, array $params): \PDOStatement
    {
        $st = static::db()->prepare($sql);
        foreach (array_values($params) as $i => $valor) {
            $tipo = match (true) {
                is_int($valor) => PDO::PARAM_INT,
                is_bool($valor) => PDO::PARAM_BOOL,
                $valor === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($i + 1, $valor, $tipo);
        }
        $st->execute();
        return $st;
    }

    protected static function todos(string $sql, array $params = []): array
    {
        return static::preparar($sql, $params)->fetchAll();
    }

    protected static function uno(string $sql, array $params = []): ?array
    {
        $fila = static::preparar($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    protected static function valor(string $sql, array $params = []): mixed
    {
        return static::preparar($sql, $params)->fetchColumn();
    }

    protected static function ejecutar(string $sql, array $params = []): int
    {
        return static::preparar($sql, $params)->rowCount();
    }
}
