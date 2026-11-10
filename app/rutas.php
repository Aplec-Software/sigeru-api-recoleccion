<?php

/**
 * Rutas de la API de Recolección: flota, mantenimiento, cuadrillas,
 * rutas, asignaciones y registro de la recolección.
 * null = pública, [] = cualquier usuario autenticado, [roles] = solo esos roles.
 */

use App\Controllers\AsignacionController;
use App\Controllers\CamionController;
use App\Controllers\CuadrillaController;
use App\Controllers\EstadisticaController;
use App\Controllers\MantenimientoController;
use App\Controllers\RutaController;
use App\Controllers\SaludController;
use App\Core\Router;

$r = new Router();
$admin = ['administrador'];
$campo = ['administrador', 'cuadrilla'];

$r->get('/salud', [SaludController::class, 'index'], null);

// Flota y mantenimiento
$r->put('/camiones/{id}/estado', [CamionController::class, 'estado'], $admin);
$r->recurso('/camiones', CamionController::class, $campo, $admin);
$r->get('/mantenimientos/proximos', [MantenimientoController::class, 'proximos'], $admin);
$r->recurso('/mantenimientos', MantenimientoController::class, $admin, $admin);

// Cuadrillas
$r->get('/cuadrillas/mia', [CuadrillaController::class, 'mia'], ['cuadrilla']);
$r->put('/cuadrillas/{id}/integrantes', [CuadrillaController::class, 'integrantes'], $admin);
$r->recurso('/cuadrillas', CuadrillaController::class, $campo, $admin);

// Rutas de recolección
$r->put('/rutas/{id}/contenedores', [RutaController::class, 'contenedores'], $admin);
$r->get('/rutas/{id}/optimizacion', [RutaController::class, 'optimizacion'], $admin);
$r->recurso('/rutas', RutaController::class, $campo, $admin);

// Asignaciones y registro del recorrido
$r->get('/asignaciones/hoy', [AsignacionController::class, 'hoy'], $campo);
$r->put('/asignaciones/{id}/estado', [AsignacionController::class, 'estado'], $campo);
$r->get('/asignaciones/{id}/recolecciones', [AsignacionController::class, 'recolecciones'], $campo);
$r->post('/asignaciones/{id}/recolecciones', [AsignacionController::class, 'registrarRecoleccion'], $campo);
$r->post('/asignaciones/{id}/recursos', [AsignacionController::class, 'registrarRecurso'], $campo);
$r->recurso('/asignaciones', AsignacionController::class, $campo, $admin);

// Estadísticas
$r->get('/estadisticas/volumen', [EstadisticaController::class, 'volumen'], $admin);
$r->get('/estadisticas/flota', [EstadisticaController::class, 'flota'], $admin);
$r->get('/estadisticas/cuadrillas', [EstadisticaController::class, 'cuadrillas'], $admin);
$r->get('/estadisticas/sin-recoleccion', [EstadisticaController::class, 'sinRecoleccion'], $admin);

return $r;
