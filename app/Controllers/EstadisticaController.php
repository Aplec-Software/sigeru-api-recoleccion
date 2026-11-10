<?php

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Recoleccion;
use App\Views\JsonView;

/**
 * Estadísticas de la operativa de recolección.
 */
class EstadisticaController extends Controller
{
    /** GET /estadisticas/volumen?desde=&hasta=&agrupar=dia|mes|zona|tipo (datos simulados de peso). */
    public function volumen(Request $req): Response
    {
        $hasta = $req->query['hasta'] ?? Clock::hoy();
        $desde = $req->query['desde'] ?? substr(Clock::mas(-30 * 24 * 60), 0, 10);
        $agrupar = in_array($req->query['agrupar'] ?? '', ['dia', 'mes', 'zona', 'tipo'], true) ? $req->query['agrupar'] : 'dia';
        $filas = array_map(fn ($f) => JsonView::render($f, ['kilos' => 'float', 'recolecciones' => 'int']), Recoleccion::volumen($desde, $hasta, $agrupar));
        return Response::ok($filas, 200, ['total_kilos' => round(array_sum(array_column($filas, 'kilos')), 1), 'desde' => $desde, 'hasta' => $hasta]);
    }

    public function flota(Request $req): Response
    {
        $db = Database::conexion();
        $estados = $db->query('SELECT estado, COUNT(*) AS n FROM camiones WHERE activo = 1 GROUP BY estado')->fetchAll();
        $hoy = Clock::hoy();
        $st = $db->prepare("SELECT COUNT(*) FROM mantenimientos WHERE estado = 'programado' AND fecha_programada >= ?");
        $st->execute([$hoy]);
        $st2 = $db->prepare("SELECT COUNT(*) FROM asignaciones WHERE fecha = ? AND estado <> 'cancelada'");
        $st2->execute([$hoy]);
        return Response::ok([
            'por_estado' => array_map('intval', array_column($estados, 'n', 'estado')),
            'mantenimientos_programados' => (int) $st->fetchColumn(),
            'asignaciones_hoy' => (int) $st2->fetchColumn(),
        ]);
    }

    public function cuadrillas(Request $req): Response
    {
        $desde = Clock::mas(-$req->queryInt('dias', 30, 365) * 24 * 60);
        return Response::ok(array_map(fn ($f) => JsonView::render($f, ['asignaciones' => 'int', 'completadas' => 'int', 'contenedores' => 'int']),
            Recoleccion::rendimientoCuadrillas($desde)));
    }

    /** Contenedores que no se vacían hace más de N días (frecuencia de recolección). */
    public function sinRecoleccion(Request $req): Response
    {
        return Response::ok(array_map(fn ($f) => JsonView::render($f), Recoleccion::sinRecoleccion($req->queryInt('dias', 3, 60))));
    }
}
