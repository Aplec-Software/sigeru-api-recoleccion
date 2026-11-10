<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\CrudController;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Camion;

/**
 * Flota de camiones recolectores.
 */
class CamionController extends CrudController
{
    protected string $modelo = Camion::class;
    protected string $entidad = 'camion';
    protected array $reglas = [
        // matrícula uruguaya: tres letras y cuatro números (ej. SIM 3101)
        'matricula' => 'required|regex:/^[A-Z]{3} ?\d{4}$/',
        'marca' => 'required|string|min:2|max:40',
        'modelo' => 'required|string|min:1|max:40',
        'anio' => 'required|int|min:1990|max:2100',
        'tipo' => 'required|in:compactador,carga_lateral,caja_abierta,grua',
        'capacidad_ton' => 'required|numeric|min:0.5|max:40',
        'kilometraje' => 'nullable|int|min:0',
    ];

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['matricula'])) {
            $datos['matricula'] = strtoupper(preg_replace('/^([A-Z]{3}) ?(\d{4})$/i', '$1 $2', $datos['matricula']));
            if (Camion::existe('matricula', $datos['matricula'], $actual['id'] ?? null)) {
                throw $this->conflicto('recoleccion.matricula_repetida');
            }
        }
        if (isset($datos['anio']) && $datos['anio'] > (int) substr(Clock::hoy(), 0, 4) + 1) {
            throw $this->conflicto('recoleccion.anio_invalido');
        }
        // el formulario manda null si se deja el kilometraje vacío: en un alta se toma el valor por defecto (0)
        // y en una modificación se conserva el que tenía (defecto D-22)
        if (array_key_exists('kilometraje', $datos) && $datos['kilometraje'] === null) {
            unset($datos['kilometraje']);
        }
        // el kilometraje nunca disminuye
        if ($actual !== null && isset($datos['kilometraje']) && $datos['kilometraje'] < (int) $actual['kilometraje']) {
            throw $this->conflicto('recoleccion.kilometraje_menor');
        }
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
        if (Camion::asignacionesFuturas((int) $actual['id']) > 0) {
            throw $this->conflicto('recoleccion.camion_con_asignaciones');
        }
    }

    /** PUT /camiones/{id}/estado */
    public function estado(Request $req): Response
    {
        $id = $this->idParam($req);
        $camion = Camion::buscar($id) ?? throw $this->noEncontrado();
        $datos = $this->validar($req, ['estado' => 'required|in:disponible,mantenimiento,fuera_servicio']);
        if ($camion['estado'] === 'en_ruta') {
            throw $this->conflicto('recoleccion.camion_en_ruta');
        }
        Camion::cambiarEstado($id, $datos['estado']);
        Audit::registrar($req, 'estado_' . $datos['estado'], 'camion', $id);
        return Response::ok($this->vista(Camion::buscar($id)), 200, null, Lang::t('ok.modificado'));
    }
}
