<?php

/**
 * Controlador frontal: todas las peticiones entran por este archivo
 * (Apache las redirige con el .htaccess).
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\HttpException;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;

$router = require __DIR__ . '/../app/rutas.php';

try {
    $peticion = Request::desdeGlobales();
} catch (HttpException $e) {
    Response::error($e->getMessage(), $e->status)->enviar();
    exit;
}

(new Kernel($router))->atender($peticion)->enviar();
