<?php

namespace App\Core;

/**
 * Representa la petición HTTP que llega a la API.
 * Se puede construir desde las superglobales de PHP o a mano (en las pruebas).
 */
class Request
{
    public array $params = [];
    public ?array $usuario = null;

    public function __construct(
        public string $metodo,
        public string $ruta,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
        public array $cookies = [],
        public string $ip = '127.0.0.1'
    ) {
        $this->metodo = strtoupper($metodo);
        $this->ruta = self::normalizar($ruta);
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function desdeGlobales(): self
    {
        $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = Config::get('BASE_PATH', '');
        if ($base !== '' && str_starts_with($ruta, $base)) {
            $ruta = substr($ruta, strlen($base));
        }

        $headers = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($clave, 5)))] = $valor;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $body = [];
        $tipo = $headers['content-type'] ?? '';
        if (str_contains($tipo, 'application/json')) {
            $crudo = file_get_contents('php://input');
            if ($crudo !== '' && $crudo !== false) {
                $body = json_decode($crudo, true);
                if (!is_array($body)) {
                    throw new HttpException(400, Lang::t('error.json_invalido'));
                }
            }
        } else {
            $body = $_POST;
        }

        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $ruta, $_GET, $body, $headers, $_COOKIE, self::ipCliente());
    }

    private static function ipCliente(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Detrás del gateway Nginx la IP real llega en X-Forwarded-For
        if (Config::get('CONFIAR_PROXY', '0') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    private static function normalizar(string $ruta): string
    {
        $ruta = '/' . trim($ruta, '/');
        return $ruta === '' ? '/' : $ruta;
    }

    public function input(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->body[$clave] ?? $porDefecto;
    }

    public function header(string $nombre): ?string
    {
        return $this->headers[strtolower($nombre)] ?? null;
    }

    public function param(string $nombre): ?string
    {
        return $this->params[$nombre] ?? null;
    }

    /** Entero positivo tomado de la query (?pagina=2) con un valor por defecto. */
    public function queryInt(string $clave, int $porDefecto, int $max = PHP_INT_MAX): int
    {
        $valor = filter_var($this->query[$clave] ?? null, FILTER_VALIDATE_INT);
        if ($valor === false || $valor < 1) {
            return $porDefecto;
        }
        return min($valor, $max);
    }
}
