<?php

namespace App\Core;

/**
 * Enrutador REST. Asocia un método HTTP y una ruta con la acción de un
 * controlador e indica qué roles pueden usarla:
 *   null          -> ruta pública
 *   []            -> cualquier usuario autenticado
 *   ['administrador', ...] -> solo esos roles
 */
class Router
{
    private array $rutas = [];

    public function get(string $patron, array $accion, ?array $roles = []): void
    {
        $this->agregar('GET', $patron, $accion, $roles);
    }

    public function post(string $patron, array $accion, ?array $roles = []): void
    {
        $this->agregar('POST', $patron, $accion, $roles);
    }

    public function put(string $patron, array $accion, ?array $roles = []): void
    {
        $this->agregar('PUT', $patron, $accion, $roles);
    }

    public function delete(string $patron, array $accion, ?array $roles = []): void
    {
        $this->agregar('DELETE', $patron, $accion, $roles);
    }

    /** Registra las cinco rutas de un CRUD estándar sobre un recurso. */
    public function recurso(string $base, string $controlador, ?array $rolesLectura, array $rolesEscritura): void
    {
        $this->get($base, [$controlador, 'index'], $rolesLectura);
        $this->get("$base/{id}", [$controlador, 'show'], $rolesLectura);
        $this->post($base, [$controlador, 'store'], $rolesEscritura);
        $this->put("$base/{id}", [$controlador, 'update'], $rolesEscritura);
        $this->delete("$base/{id}", [$controlador, 'destroy'], $rolesEscritura);
    }

    private function agregar(string $metodo, string $patron, array $accion, ?array $roles): void
    {
        // {id} acepta solo números; {nombre:regex} permite indicar la expresión
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function ($m) {
            $exp = $m[2] ?? ($m[1] === 'id' ? '\d+' : '[^/]+');
            return '(?P<' . $m[1] . '>' . $exp . ')';
        }, $patron);
        $this->rutas[] = [
            'metodo' => $metodo,
            'regex' => '#^' . $regex . '$#',
            'accion' => $accion,
            'roles' => $roles,
        ];
    }

    public function despachar(Request $req): Response
    {
        $metodosPermitidos = [];
        foreach ($this->rutas as $ruta) {
            if (!preg_match($ruta['regex'], $req->ruta, $coincidencias)) {
                continue;
            }
            if ($ruta['metodo'] !== $req->metodo) {
                $metodosPermitidos[] = $ruta['metodo'];
                continue;
            }
            foreach ($coincidencias as $clave => $valor) {
                if (is_string($clave)) {
                    $req->params[$clave] = $valor;
                }
            }
            if ($ruta['roles'] !== null) {
                $req->usuario = Auth::requerir($req, $ruta['roles']);
            } else {
                // en rutas públicas un token vencido no impide el acceso
                try {
                    $req->usuario = Auth::opcional($req);
                } catch (HttpException $e) {
                    $req->usuario = null;
                }
            }
            [$clase, $metodo] = $ruta['accion'];
            return (new $clase())->$metodo($req);
        }

        if ($metodosPermitidos) {
            return Response::error(Lang::t('error.metodo_no_permitido'), 405)
                ->conHeader('Allow', implode(', ', array_unique($metodosPermitidos)));
        }
        return Response::error(Lang::t('error.ruta_no_encontrada'), 404);
    }
}
