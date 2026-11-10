<?php

namespace App\Core;

/**
 * Valida y sanea los datos recibidos. Las reglas se escriben como texto:
 *   'email' => 'required|email|max:120'
 * Devuelve los datos limpios (con el tipo correcto) o lanza un 422 con los errores.
 */
class Validator
{
    /**
     * @param bool $parcial en las modificaciones (PUT) solo se validan los campos enviados
     */
    public static function validar(array $datos, array $reglas, bool $parcial = false): array
    {
        $limpios = [];
        $errores = [];

        foreach ($reglas as $campo => $textoReglas) {
            $lista = explode('|', $textoReglas);
            $presente = array_key_exists($campo, $datos);
            $valor = $presente ? $datos[$campo] : null;

            if (is_string($valor) && !in_array('password', $lista, true)) {
                // Saneamiento: se quitan espacios de los extremos y etiquetas HTML
                $valor = trim(strip_tags($valor));
            }
            $vacio = $valor === null || $valor === '' || $valor === [];

            if ($vacio) {
                if (in_array('required', $lista, true) && (!$parcial || $presente)) {
                    $errores[$campo] = Lang::t('validacion.required', ['campo' => $campo]);
                } elseif ($presente && in_array('nullable', $lista, true)) {
                    $limpios[$campo] = null;
                }
                continue;
            }

            foreach ($lista as $regla) {
                [$nombre, $arg] = array_pad(explode(':', $regla, 2), 2, null);
                $error = self::aplicar($nombre, $arg, $valor);
                if ($error !== null) {
                    $errores[$campo] = Lang::t("validacion.$nombre", ['campo' => $campo, 'arg' => $arg]);
                    break;
                }
            }
            if (!isset($errores[$campo])) {
                $limpios[$campo] = $valor;
            }
        }

        if ($errores) {
            throw new HttpException(422, Lang::t('error.validacion'), $errores);
        }
        return $limpios;
    }

    /**
     * Aplica una regla. Devuelve null si el valor es válido o un texto si no lo es.
     * Algunas reglas convierten el valor a su tipo (int, numeric, bool).
     */
    private static function aplicar(string $regla, ?string $arg, mixed &$valor): ?string
    {
        switch ($regla) {
            case 'required':
            case 'nullable':
                return null;
            case 'password':
                return self::claveSegura((string) $valor) ? null : 'x';
            case 'string':
                if (!is_scalar($valor)) {
                    return 'x';
                }
                $valor = (string) $valor;
                return null;
            case 'int':
                $v = filter_var($valor, FILTER_VALIDATE_INT);
                if ($v === false) {
                    return 'x';
                }
                $valor = $v;
                return null;
            case 'numeric':
                if (!is_numeric($valor)) {
                    return 'x';
                }
                $valor = (float) $valor;
                return null;
            case 'bool':
                $v = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v === null) {
                    return 'x';
                }
                $valor = $v ? 1 : 0;
                return null;
            case 'email':
                return filter_var($valor, FILTER_VALIDATE_EMAIL) ? null : 'x';
            case 'min':
            case 'max':
                // en números se compara el valor; en textos, la cantidad de caracteres
                $medida = (is_int($valor) || is_float($valor)) ? $valor : mb_strlen((string) $valor);
                $fuera = $regla === 'min' ? $medida < (float) $arg : $medida > (float) $arg;
                return $fuera ? 'x' : null;
            case 'in':
                return in_array((string) $valor, explode(',', (string) $arg), true) ? null : 'x';
            case 'date':
                $d = \DateTime::createFromFormat('Y-m-d', (string) $valor);
                return ($d && $d->format('Y-m-d') === $valor) ? null : 'x';
            case 'regex':
                return preg_match((string) $arg, (string) $valor) ? null : 'x';
            case 'lat':
            case 'lng':
                $limite = $regla === 'lat' ? 90 : 180;
                if (!is_numeric($valor) || abs((float) $valor) > $limite) {
                    return 'x';
                }
                $valor = (float) $valor;
                return null;
            case 'ci':
                $valor = preg_replace('/\D/', '', (string) $valor);
                return self::cedulaValida($valor) ? null : 'x';
            case 'telefono':
                return preg_match('/^[0-9 +()-]{8,20}$/', (string) $valor) ? null : 'x';
            case 'color':
                return preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $valor) ? null : 'x';
            case 'array':
                return is_array($valor) ? null : 'x';
        }
        throw new \LogicException("Regla de validación desconocida: $regla");
    }

    /** Política de contraseñas: largo mínimo, mayúscula, minúscula, número y símbolo. */
    public static function claveSegura(string $clave): bool
    {
        $minimo = (int) Params::get('password_largo_minimo', '10');
        return mb_strlen($clave) >= $minimo
            && mb_strlen($clave) <= 72              // límite de bcrypt
            && preg_match('/[A-Z]/', $clave)
            && preg_match('/[a-z]/', $clave)
            && preg_match('/\d/', $clave)
            && preg_match('/[^A-Za-z0-9]/', $clave);
    }

    /** Verifica el dígito de control de una cédula de identidad uruguaya. */
    public static function cedulaValida(string $ci): bool
    {
        if (!preg_match('/^\d{7,8}$/', $ci)) {
            return false;
        }
        $ci = str_pad($ci, 8, '0', STR_PAD_LEFT);
        $pesos = [2, 9, 8, 7, 6, 3, 4];
        $suma = 0;
        for ($i = 0; $i < 7; $i++) {
            $suma += (int) $ci[$i] * $pesos[$i];
        }
        return (10 - $suma % 10) % 10 === (int) $ci[7];
    }
}
