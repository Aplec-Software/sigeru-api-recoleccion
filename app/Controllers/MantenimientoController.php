<?php

namespace App\Controllers;

use App\Core\Clock;
use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Params;
use App\Core\Request;
use App\Core\Response;
use App\Models\Camion;
use App\Models\Mantenimiento;
use App\Views\JsonView;

/**
 * Mantenimiento preventivo y correctivo de la flota.
 */
class MantenimientoController extends CrudController
{
    protected string $modelo = Mantenimiento::class;
    protected string $entidad = 'mantenimiento';
    protected array $reglas = [
        'camion_id' => 'required|int',
        'tipo' => 'required|in:preventivo,correctivo',
        'descripcion' => 'required|string|min:5|max:200',
        'fecha_programada' => 'required|date',
        'fecha_realizada' => 'nullable|date',
        'kilometraje' => 'nullable|int|min:0',
        'costo' => 'nullable|numeric|min:0|max:10000000',
        'estado' => 'nullable|in:programado,realizado,cancelado',
    ];

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['camion_id']) && !Camion::buscar($datos['camion_id'])) {
            throw $this->conflicto('recoleccion.camion_inexistente');
        }
        if ($actual === null && $datos['fecha_programada'] < Clock::hoy() && ($datos['estado'] ?? 'programado') === 'programado') {
            throw new HttpException(422, Lang::t('error.validacion'), ['fecha_programada' => Lang::t('recoleccion.fecha_pasada')]);
        }
        $estado = $datos['estado'] ?? $actual['estado'] ?? 'programado';
        if ($estado === 'realizado') {
            $datos['fecha_realizada'] = $datos['fecha_realizada'] ?? Clock::hoy();
        }
    }

    protected function despuesDeGuardar(Request $req, int $id, array $datos, ?array $actual): void
    {
        // al terminar el mantenimiento el camión vuelve a estar disponible
        if (($datos['estado'] ?? null) === 'realizado' && ($actual['estado'] ?? null) !== 'realizado') {
            $m = Mantenimiento::buscar($id);
            $camion = Camion::buscar((int) $m['camion_id']);
            if ($camion['estado'] === 'mantenimiento') {
                Camion::cambiarEstado((int) $camion['id'], 'disponible');
            }
            if (!empty($m['kilometraje']) && $m['kilometraje'] > $camion['kilometraje']) {
                Camion::actualizar((int) $camion['id'], ['kilometraje' => (int) $m['kilometraje']]);
            }
        }
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
        if ($actual['estado'] !== 'programado') {
            throw $this->conflicto('recoleccion.mantenimiento_cerrado');
        }
    }

    /** GET /mantenimientos/proximos: los programados en los próximos días (alerta). */
    public function proximos(Request $req): Response
    {
        $dias = $req->queryInt('dias', Params::int('dias_aviso_mantenimiento', 3), 60);
        $filas = Mantenimiento::proximos(Clock::hoy(), substr(Clock::mas($dias * 24 * 60), 0, 10));
        return Response::ok(array_map(fn ($f) => JsonView::render($f), $filas));
    }
}
