<?php

namespace App\Core;

/**
 * Error controlado que se transforma en una respuesta HTTP con su código.
 * Ejemplo: throw new HttpException(404, Lang::t('error.no_encontrado'));
 */
class HttpException extends \RuntimeException
{
    public function __construct(public int $status, string $mensaje, public ?array $errores = null)
    {
        parent::__construct($mensaje, $status);
    }
}
