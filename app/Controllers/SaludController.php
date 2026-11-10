<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Chequeo de salud: lo usan Docker (HEALTHCHECK) y el script del servidor
 * para saber si la API y su conexión a la base de datos funcionan.
 */
class SaludController extends Controller
{
    public function index(Request $req): Response
    {
        try {
            Database::conexion()->query('SELECT 1');
            $bd = 'ok';
        } catch (\Throwable $e) {
            $bd = 'sin conexión';
        }
        return Response::ok(['servicio' => Config::get('APP_NOMBRE', 'api'), 'bd' => $bd], $bd === 'ok' ? 200 : 503);
    }
}
