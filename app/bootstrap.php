<?php

/**
 * Arranque de la aplicación: carga automática de clases y configuración.
 * Las clases del espacio de nombres App\ se buscan en la carpeta app/
 * (por ejemplo App\Models\Contenedor -> app/Models/Contenedor.php).
 */
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        $archivo = __DIR__ . '/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
        if (is_file($archivo)) {
            require $archivo;
        }
    }
});

date_default_timezone_set('America/Montevideo');
App\Core\Config::cargarArchivo(dirname(__DIR__) . '/.env');
App\Core\Lang::iniciar(dirname(__DIR__) . '/lang');
