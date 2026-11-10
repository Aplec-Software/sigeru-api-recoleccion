<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\CrudController;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Cuadrilla;
use App\Views\JsonView;

/**
 * Cuadrillas de recolección y sus integrantes.
 */
class CuadrillaController extends CrudController
{
    protected string $modelo = Cuadrilla::class;
    protected string $entidad = 'cuadrilla';
    protected array $reglas = [
        'nombre' => 'required|string|min:3|max:60',
        'zona_id' => 'required|int',
        'turno' => 'required|in:matutino,vespertino,nocturno',
    ];

    public function vista(array $fila): array
    {
        $datos = JsonView::render($fila);
        $datos['integrantes'] = array_map(fn ($i) => JsonView::render($i), Cuadrilla::integrantes((int) $fila['id']));
        return $datos;
    }

    protected function filtrosExtra(Request $req): array
    {
        return $req->usuario['rol'] === 'cuadrilla' ? ['t.id = ?' => Cuadrilla::deUsuario($req->usuario['id']) ?? 0] : [];
    }

    protected function autorizarLectura(Request $req, array $fila): void
    {
        if ($req->usuario['rol'] === 'cuadrilla' && Cuadrilla::deUsuario($req->usuario['id']) !== (int) $fila['id']) {
            throw $this->prohibido();
        }
    }

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['nombre']) && Cuadrilla::existe('nombre', $datos['nombre'], $actual['id'] ?? null)) {
            throw $this->conflicto('recoleccion.nombre_repetido');
        }
    }

    /** GET /cuadrillas/mia: la cuadrilla del usuario que inició sesión. */
    public function mia(Request $req): Response
    {
        $id = Cuadrilla::deUsuario($req->usuario['id']) ?? throw $this->noEncontrado();
        return Response::ok($this->vista(Cuadrilla::buscar($id)));
    }

    /**
     * PUT /cuadrillas/{id}/integrantes
     * { "integrantes": [ { "usuario_id": 3, "es_responsable": true }, ... ] }
     */
    public function integrantes(Request $req): Response
    {
        $id = $this->idParam($req);
        Cuadrilla::buscar($id) ?? throw $this->noEncontrado();
        $lista = $req->input('integrantes');
        if (!is_array($lista) || !$lista) {
            throw new HttpException(422, Lang::t('error.validacion'), ['integrantes' => Lang::t('recoleccion.integrantes_requeridos')]);
        }
        $responsables = 0;
        foreach ($lista as $i) {
            $usuario = (int) ($i['usuario_id'] ?? 0);
            if (!Cuadrilla::esPersonalDeCuadrilla($usuario)) {
                throw new HttpException(422, Lang::t('error.validacion'), ['integrantes' => Lang::t('recoleccion.integrante_invalido', ['id' => $usuario])]);
            }
            if (Cuadrilla::otraCuadrillaDe($usuario, $id) !== null) {
                throw $this->conflicto('recoleccion.integrante_en_otra', ['id' => $usuario]);
            }
            $responsables += empty($i['es_responsable']) ? 0 : 1;
        }
        // RNE: cada cuadrilla tiene un único responsable
        if ($responsables !== 1) {
            throw new HttpException(422, Lang::t('error.validacion'), ['integrantes' => Lang::t('recoleccion.un_responsable')]);
        }
        $db = Database::conexion();
        $db->beginTransaction();
        try {
            Cuadrilla::guardarIntegrantes($id, $lista);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::registrar($req, 'integrantes', 'cuadrilla', $id, json_encode(array_column($lista, 'usuario_id')));
        return Response::ok($this->vista(Cuadrilla::buscar($id)), 200, null, Lang::t('ok.modificado'));
    }
}
