<?php

namespace App\Core;

/**
 * Autenticación y control de acceso por rol.
 *
 * El token puede llegar en la cookie HttpOnly "sigeru_token" (navegador) o en
 * el encabezado "Authorization: Bearer ..." (Postman, pruebas, otros sistemas).
 * Además de la firma se verifica en la tabla sesiones que no haya sido revocado
 * (cierre de sesión o usuario dado de baja).
 */
class Auth
{
    public const COOKIE = 'sigeru_token';
    public const EMISOR = 'sigeru-api-usuarios';

    private static ?string $clavePublica = null;

    public static function setClavePublica(?string $pem): void
    {
        self::$clavePublica = $pem;
    }

    private static function clavePublica(): string
    {
        if (self::$clavePublica === null) {
            $ruta = Config::get('JWT_CLAVE_PUBLICA', '/claves/jwt_publica.pem');
            if (!is_readable($ruta)) {
                throw new \RuntimeException('No se encuentra la clave pública del JWT');
            }
            self::$clavePublica = file_get_contents($ruta);
        }
        return self::$clavePublica;
    }

    /** Exige un usuario autenticado y, si se indican, uno de los roles permitidos. */
    public static function requerir(Request $req, array $roles): array
    {
        $usuario = self::opcional($req);
        if ($usuario === null) {
            throw new HttpException(401, Lang::t('auth.no_autenticado'));
        }
        if ($roles && !in_array($usuario['rol'], $roles, true)) {
            throw new HttpException(403, Lang::t('auth.sin_permiso'));
        }
        return $usuario;
    }

    /** Devuelve el usuario si viene un token válido, o null en rutas públicas. */
    public static function opcional(Request $req): ?array
    {
        [$token, $desdeCookie] = self::extraerToken($req);
        if ($token === null) {
            return null;
        }
        $payload = Jwt::verificar($token, self::clavePublica(), self::EMISOR);

        // Protección CSRF: si el token viene en una cookie, las operaciones que
        // modifican datos deben traer este encabezado (un formulario de otro
        // sitio no lo puede agregar).
        if ($desdeCookie && $req->metodo !== 'GET' && $req->header('X-Requested-With') !== 'SiGeRU') {
            throw new HttpException(403, Lang::t('auth.csrf'));
        }

        $sesion = Database::conexion()->prepare(
            'SELECT revocado_en, expira_en FROM sesiones WHERE jti = ? AND usuario_id = ?'
        );
        $sesion->execute([$payload['jti'] ?? '', $payload['sub'] ?? 0]);
        $fila = $sesion->fetch();
        if (!$fila || $fila['revocado_en'] !== null || $fila['expira_en'] < Clock::ahora()) {
            throw new HttpException(401, Lang::t('auth.token_vencido'));
        }

        return [
            'id' => (int) $payload['sub'],
            'rol' => $payload['rol'],
            'nombre' => $payload['nombre'] ?? '',
            'jti' => $payload['jti'],
            'idioma' => $payload['idioma'] ?? 'es',
        ];
    }

    private static function extraerToken(Request $req): array
    {
        $cabecera = $req->header('Authorization') ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $cabecera, $m)) {
            return [$m[1], false];
        }
        if (!empty($req->cookies[self::COOKIE])) {
            return [$req->cookies[self::COOKIE], true];
        }
        return [null, false];
    }
}
