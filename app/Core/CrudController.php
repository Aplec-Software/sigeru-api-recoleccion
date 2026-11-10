<?php

namespace App\Core;

use App\Views\JsonView;

/**
 * Controlador genérico para los ABM (altas, bajas, modificaciones y consultas).
 * Cada recurso solo define su modelo, sus reglas de validación y, si hace
 * falta, las reglas de negocio en los métodos antesDeGuardar / antesDeEliminar.
 *
 *   GET    /recurso        index    200
 *   GET    /recurso/{id}   show     200 / 404
 *   POST   /recurso        store    201 / 422 / 409
 *   PUT    /recurso/{id}   update   200 / 404 / 422
 *   DELETE /recurso/{id}   destroy  200 / 404 / 409
 */
abstract class CrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $modelo;
    protected string $entidad;
    protected array $reglas = [];
    protected array $tipos = [];   // conversión de tipos para la salida JSON

    public function index(Request $req): Response
    {
        $porPagina = $req->queryInt('por_pagina', 20, 200);
        [$filas, $total] = $this->modelo::listar($req->query, $req->queryInt('pagina', 1), $porPagina, $this->filtrosExtra($req));
        return Response::ok(array_map([$this, 'vista'], $filas), 200, $this->meta($req, $total, $porPagina));
    }

    public function show(Request $req): Response
    {
        $fila = $this->modelo::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->autorizarLectura($req, $fila);
        return Response::ok($this->vista($fila));
    }

    public function store(Request $req): Response
    {
        $datos = $this->validar($req, $this->reglas);
        $this->antesDeGuardar($req, $datos, null);
        $id = $this->modelo::crear($datos);
        $this->despuesDeGuardar($req, $id, $datos, null);
        Audit::registrar($req, 'crear', $this->entidad, $id);
        return Response::ok($this->vista($this->modelo::buscar($id)), 201, null, Lang::t('ok.creado'))
            ->conHeader('Location', $req->ruta . '/' . $id);
    }

    public function update(Request $req): Response
    {
        $id = $this->idParam($req);
        $actual = $this->modelo::buscar($id) ?? throw $this->noEncontrado();
        $datos = $this->validar($req, $this->reglas, true);
        $this->antesDeGuardar($req, $datos, $actual);
        $this->modelo::actualizar($id, $datos);
        $this->despuesDeGuardar($req, $id, $datos, $actual);
        Audit::registrar($req, 'modificar', $this->entidad, $id, json_encode(array_keys($datos)));
        return Response::ok($this->vista($this->modelo::buscar($id)), 200, null, Lang::t('ok.modificado'));
    }

    public function destroy(Request $req): Response
    {
        $id = $this->idParam($req);
        $actual = $this->modelo::buscar($id) ?? throw $this->noEncontrado();
        $this->antesDeEliminar($req, $actual);
        $this->modelo::eliminar($id);
        Audit::registrar($req, 'eliminar', $this->entidad, $id);
        return Response::ok(null, 200, null, Lang::t('ok.eliminado'));
    }

    // ------------------------------------------- puntos de extensión (RNE)

    /** Reglas de negocio antes de insertar ($actual = null) o modificar. */
    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
    }

    protected function despuesDeGuardar(Request $req, int $id, array $datos, ?array $actual): void
    {
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
    }

    /** Permite restringir qué registros ve cada rol en el listado. */
    protected function filtrosExtra(Request $req): array
    {
        return [];
    }

    protected function autorizarLectura(Request $req, array $fila): void
    {
    }

    public function vista(array $fila): array
    {
        return JsonView::render($fila, $this->tipos);
    }
}
