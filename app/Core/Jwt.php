<?php

namespace App\Core;

/**
 * Tokens JWT firmados con RS256 (RSA + SHA-256).
 *
 * La API de Usuarios firma el token con la clave PRIVADA. Las demás APIs
 * solo tienen la clave PÚBLICA: pueden verificar que el token es auténtico
 * y que nadie lo modificó, pero no pueden fabricar tokens nuevos.
 */
class Jwt
{
    public static function crear(array $payload, string $clavePrivadaPem): string
    {
        $cabecera = ['alg' => 'RS256', 'typ' => 'JWT'];
        $partes = [self::b64(json_encode($cabecera)), self::b64(json_encode($payload))];
        $datos = implode('.', $partes);

        $clave = openssl_pkey_get_private($clavePrivadaPem);
        if ($clave === false || !openssl_sign($datos, $firma, $clave, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('No se pudo firmar el token');
        }
        return $datos . '.' . self::b64($firma);
    }

    /**
     * Verifica firma, algoritmo, emisor y vencimiento. Devuelve el payload.
     * @throws HttpException 401 si el token no es válido
     */
    public static function verificar(string $token, string $clavePublicaPem, string $emisor): array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            throw new HttpException(401, Lang::t('auth.token_invalido'));
        }
        [$cab64, $pay64, $firma64] = $partes;
        $cabecera = json_decode(self::b64d($cab64), true);
        $payload = json_decode(self::b64d($pay64), true);

        // Se exige RS256: así se evita el ataque de cambiar el algoritmo por "none" o HS256
        if (!is_array($cabecera) || ($cabecera['alg'] ?? '') !== 'RS256' || !is_array($payload)) {
            throw new HttpException(401, Lang::t('auth.token_invalido'));
        }
        $clave = openssl_pkey_get_public($clavePublicaPem);
        $ok = $clave !== false && openssl_verify("$cab64.$pay64", self::b64d($firma64), $clave, OPENSSL_ALGO_SHA256) === 1;
        if (!$ok) {
            throw new HttpException(401, Lang::t('auth.token_invalido'));
        }
        $ahora = time();
        if (($payload['iss'] ?? '') !== $emisor || ($payload['exp'] ?? 0) < $ahora || ($payload['nbf'] ?? 0) > $ahora + 30) {
            throw new HttpException(401, Lang::t('auth.token_vencido'));
        }
        return $payload;
    }

    private static function b64(string $datos): string
    {
        return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
    }

    private static function b64d(string $datos): string
    {
        return (string) base64_decode(strtr($datos, '-_', '+/'));
    }
}
