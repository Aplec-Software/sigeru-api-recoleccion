<?php

/**
 * Evaluación del orden de los recorridos (sección 3.3 del documento): para cada ruta activa compara el orden
 * guardado, el orden que propone SiGeRU y el recorrido más corto que encuentra el optimizador sin tener en cuenta
 * la urgencia. Escribe un CSV en la salida estándar.
 *
 *   docker compose exec -T api-recoleccion php bin/evaluar_rutas.php > evaluacion_rutas.csv
 *
 * En el entorno de desarrollo (SQLite):
 *   DB_DRIVER=sqlite DB_RUTA=sigeru-infra/desarrollo/sigeru.sqlite FECHA_SIMULADA=2026-10-30 \
 *       php sigeru-api-recoleccion/bin/evaluar_rutas.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\RutaController;
use App\Core\Clock;
use App\Core\Database;
use App\Models\Ruta;
use App\Services\Optimizador;

/** Minutos promedio desde la salida hasta llegar a los contenedores de prioridad alta; vacío si no hay. */
function llegadaUrgentes(array $orden, array $partida, array $supuestos): string
{
    $minutos = Optimizador::llegadas($orden, $partida, $supuestos);
    $urgentes = [];
    foreach (array_values($orden) as $k => $c) {
        if ($c['prioridad'] === 'alta') {
            $urgentes[] = $minutos[$k];
        }
    }
    return $urgentes ? (string) (int) round(array_sum($urgentes) / count($urgentes)) : '';
}

$salida = fopen('php://output', 'w');
fputcsv($salida, ['ruta_id', 'ruta', 'contenedores', 'urgentes', 'km_actual', 'km_propuesta', 'km_solo_distancia',
                  'ahorro_km_porcentaje', 'minutos_actual', 'minutos_propuesta', 'litros_actual', 'litros_propuesta',
                  'llegada_urgentes_actual', 'llegada_urgentes_propuesta', 'fecha'], ',', '"', '');
$rutas = Database::conexion()->query('SELECT id, nombre FROM rutas WHERE activa = 1 ORDER BY id')->fetchAll();
foreach ($rutas as $ruta) {
    $p = RutaController::propuesta((int) $ruta['id']);
    if ($p === null) {
        continue;
    }
    $puntos = Optimizador::conUrgencia(Ruta::paraRecorrido((int) $ruta['id']), Clock::hoy());
    // el recorrido más corto que encuentra el optimizador si todos los contenedores tuvieran la misma urgencia
    $sinUrgencia = array_map(fn ($c) => ['urgencia' => 0] + $c, $puntos);
    $corto = Optimizador::ordenar($sinUrgencia, $p['partida'], null, $p['supuestos']);
    $kmCorto = Optimizador::estimacion($corto, $p['partida'], null, $p['supuestos'])['km'];
    $porId = array_column($puntos, null, 'id');
    $propuesto = array_map(fn ($c) => $porId[$c['id']], $p['contenedores']);
    fputcsv($salida, [$ruta['id'], $ruta['nombre'], count($puntos), count(array_filter($puntos, fn ($c) => $c['prioridad'] === 'alta')),
                      $p['actual']['km'], $p['propuesta']['km'], $kmCorto, $p['ahorro']['porcentaje_km'],
                      $p['actual']['minutos'], $p['propuesta']['minutos'], $p['actual']['litros'], $p['propuesta']['litros'],
                      llegadaUrgentes($puntos, $p['partida'], $p['supuestos']), llegadaUrgentes($propuesto, $p['partida'], $p['supuestos']),
                      Clock::hoy()], ',', '"', '');
}
