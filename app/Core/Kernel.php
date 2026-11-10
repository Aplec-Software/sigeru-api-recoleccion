<?php

namespace App\Core;

/**
 * Punto central de la API: recibe la petición, la pasa al enrutador y
 * transforma cualquier error en una respuesta JSON con el código adecuado.
 * Nunca se muestran detalles internos (SQL, rutas de archivos) al cliente.
 */
class Kernel
{
    public function __construct(private Router $router)
    {
    }

    public function atender(Request $req): Response
    {
        $inicio = microtime(true);
        Lang::detectar($req);
        Params::limpiar();

        if ($req->metodo === 'OPTIONS') {
            $res = new Response(204);
        } else {
            try {
                $res = $this->router->despachar($req);
            } catch (HttpException $e) {
                $res = Response::error($e->getMessage(), $e->status, $e->errores);
            } catch (\PDOException $e) {
                Logger::error($e->getMessage());
                // 23000 = violación de una restricción (clave única, clave foránea, CHECK)
                $res = $e->getCode() === '23000'
                    ? Response::error(Lang::t('error.conflicto'), 409)
                    : Response::error(Lang::t('error.interno'), 500);
            } catch (\Throwable $e) {
                Logger::error(get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
                $res = Response::error(Lang::t('error.interno'), 500);
            }
        }

        $this->agregarCabeceras($req, $res);
        Logger::acceso($req, $res, $inicio);
        return $res;
    }

    private function agregarCabeceras(Request $req, Response $res): void
    {
        $res->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
        ];
        // CORS solo para desarrollo local (en producción todo pasa por el mismo dominio)
        $origen = $req->header('Origin');
        $permitidos = array_filter(explode(',', Config::get('CORS_ORIGENES', '')));
        if ($origen && in_array($origen, $permitidos, true)) {
            $res->headers['Access-Control-Allow-Origin'] = $origen;
            $res->headers['Access-Control-Allow-Credentials'] = 'true';
            $res->headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization, X-Requested-With, Accept-Language';
            $res->headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, DELETE, OPTIONS';
            $res->headers['Vary'] = 'Origin';
        }
    }
}
