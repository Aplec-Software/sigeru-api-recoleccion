<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Params;

class Ruta extends Model
{
    protected static string $tabla = 'rutas';
    protected static array $campos = ['nombre', 'zona_id', 'frecuencia', 'descripcion', 'activa'];
    protected static array $filtros = ['zona_id', 'frecuencia'];
    protected static array $busqueda = ['t.nombre'];
    protected static string $orden = 't.nombre';
    protected static ?string $bajaLogica = 'activa';

    protected static function consultaBase(): string
    {
        return 'SELECT t.*, z.nombre AS zona,
                       (SELECT COUNT(*) FROM ruta_contenedor rc WHERE rc.ruta_id = t.id) AS contenedores_cantidad
                FROM rutas t JOIN zonas z ON z.id = t.zona_id';
    }

    public static function contenedores(int $id): array
    {
        return static::todos('SELECT rc.orden, c.id, c.codigo, c.direccion, c.latitud, c.longitud, c.estado, c.nivel_llenado, c.activo
                              FROM ruta_contenedor rc JOIN contenedores c ON c.id = rc.contenedor_id
                              WHERE rc.ruta_id = ? ORDER BY rc.orden', [$id]);
    }

    /**
     * Contenedores de la ruta con los datos que usa el optimizador: nivel, última recolección y reportes activos que
     * piden vaciarlo (desborde o falta de recolección), con la cantidad de reportes sumados.
     */
    public static function paraRecorrido(int $id): array
    {
        return static::todos("SELECT rc.orden, c.id, c.codigo, c.direccion, c.latitud, c.longitud, c.estado, c.nivel_llenado,
                                     c.activo, c.ultima_recoleccion,
                                     (SELECT COUNT(i.id) FROM incidencias i WHERE i.contenedor_id = c.id
                                         AND i.estado IN ('abierta','en_curso') AND i.tipo IN ('desborde','falta_recoleccion')) AS reportes_urgentes,
                                     (SELECT COALESCE(SUM(i.cantidad_reportes), 0) FROM incidencias i WHERE i.contenedor_id = c.id
                                         AND i.estado IN ('abierta','en_curso') AND i.tipo IN ('desborde','falta_recoleccion')) AS reportes_cantidad
                              FROM ruta_contenedor rc JOIN contenedores c ON c.id = rc.contenedor_id
                              WHERE rc.ruta_id = ? ORDER BY rc.orden", [$id]);
    }

    /** Supuestos para ordenar y estimar un recorrido (parámetros del sistema que el municipio ajusta con su flota). */
    public static function supuestos(): array
    {
        return [
            'velocidad_kmh' => (float) Params::get('velocidad_camion_kmh', '20'),
            'minutos_por_contenedor' => (float) Params::get('minutos_por_contenedor', '2'),
            'litros_100km' => (float) Params::get('consumo_camion_litros_100km', '100'),
        ];
    }

    /** Centros donde un camión descarga lo recolectado (acopio y clasificación): de ahí sale y ahí vuelve. */
    public static function centrosDeDescarga(): array
    {
        return static::todos("SELECT id, nombre, latitud, longitud FROM centros
                              WHERE activo = 1 AND tipo IN ('acopio','clasificacion') ORDER BY id");
    }

    public static function incluyeContenedor(int $rutaId, int $contenedorId): bool
    {
        return static::valor('SELECT COUNT(*) FROM ruta_contenedor WHERE ruta_id = ? AND contenedor_id = ?', [$rutaId, $contenedorId]) > 0;
    }

    public static function contenedoresActivos(array $ids): int
    {
        if (!$ids) {
            return 0;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        return (int) static::valor("SELECT COUNT(*) FROM contenedores WHERE activo = 1 AND id IN ($marcas)", array_map('intval', $ids));
    }

    public static function guardarContenedores(int $id, array $ids): void
    {
        static::ejecutar('DELETE FROM ruta_contenedor WHERE ruta_id = ?', [$id]);
        $st = static::db()->prepare('INSERT INTO ruta_contenedor (ruta_id, contenedor_id, orden) VALUES (?, ?, ?)');
        foreach (array_values($ids) as $orden => $contenedor) {
            $st->execute([$id, (int) $contenedor, $orden + 1]);
        }
    }

    public static function asignacionesPendientes(int $id): int
    {
        return (int) static::valor("SELECT COUNT(*) FROM asignaciones WHERE ruta_id = ? AND estado IN ('planificada','en_curso')", [$id]);
    }
}
