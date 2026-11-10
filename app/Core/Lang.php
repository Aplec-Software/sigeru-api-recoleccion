<?php

namespace App\Core;

/**
 * Textos de la API en varios idiomas (es / en).
 * El idioma se toma de ?lang=, del encabezado Accept-Language o del parámetro
 * del sistema. Los textos están en lang/es.php y lang/en.php.
 */
class Lang
{
    private static string $idioma = 'es';
    private static array $textos = [];
    private static string $carpeta = '';

    public static function iniciar(string $carpeta): void
    {
        self::$carpeta = rtrim($carpeta, '/');
        self::$textos = [];
    }

    public static function detectar(Request $req): void
    {
        $pedido = $req->query['lang'] ?? substr($req->header('Accept-Language') ?? '', 0, 2);
        self::$idioma = in_array($pedido, ['es', 'en'], true) ? $pedido : Config::get('APP_IDIOMA', 'es');
    }

    /** Fija el idioma directamente (lo usa el backoffice, que no recibe un Request). */
    public static function fijar(?string $idioma): void
    {
        self::$idioma = in_array($idioma, ['es', 'en'], true) ? $idioma : Config::get('APP_IDIOMA', 'es');
    }

    public static function idioma(): string
    {
        return self::$idioma;
    }

    public static function t(string $clave, array $reemplazos = []): string
    {
        if (!isset(self::$textos[self::$idioma])) {
            $archivo = self::$carpeta . '/' . self::$idioma . '.php';
            self::$textos[self::$idioma] = is_file($archivo) ? require $archivo : [];
        }
        $texto = self::$textos[self::$idioma][$clave] ?? $clave;
        foreach ($reemplazos as $nombre => $valor) {
            $texto = str_replace(':' . $nombre, (string) $valor, $texto);
        }
        return $texto;
    }
}
