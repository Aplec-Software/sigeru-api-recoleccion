<?php

namespace App\Core;

/**
 * Controlador base con utilidades comunes.
 */
abstract class Controller
{
    protected function validar(Request $req, array $reglas, bool $parcial = false): array
    {
        return Validator::validar($req->body, $reglas, $parcial);
    }

    protected function idParam(Request $req): int
    {
        return (int) $req->param('id');
    }

    protected function noEncontrado(): HttpException
    {
        return new HttpException(404, Lang::t('error.no_encontrado'));
    }

    protected function conflicto(string $claveMensaje, array $reemplazos = []): HttpException
    {
        return new HttpException(409, Lang::t($claveMensaje, $reemplazos));
    }

    protected function prohibido(): HttpException
    {
        return new HttpException(403, Lang::t('auth.sin_permiso'));
    }

    protected function esAdmin(Request $req): bool
    {
        return ($req->usuario['rol'] ?? '') === 'administrador';
    }

    protected function meta(Request $req, int $total, int $porPagina): array
    {
        return [
            'total' => $total,
            'pagina' => $req->queryInt('pagina', 1),
            'por_pagina' => $porPagina,
            'paginas' => (int) ceil($total / max(1, $porPagina)),
        ];
    }
}
