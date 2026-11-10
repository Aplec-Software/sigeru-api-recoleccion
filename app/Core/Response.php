<?php

namespace App\Core;

/**
 * Respuesta HTTP. Todas las respuestas de la API son JSON con el formato:
 *   { "status": "ok", "datos": ..., "meta": ... }
 *   { "status": "error", "mensaje": "...", "errores": {...} }
 */
class Response
{
    public function __construct(
        public int $status = 200,
        public string $cuerpo = '',
        public array $headers = []
    ) {
    }

    public static function json(array $datos, int $status = 200, array $headers = []): self
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return new self($status, $json, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public static function ok(mixed $datos = null, int $status = 200, ?array $meta = null, ?string $mensaje = null): self
    {
        $cuerpo = ['status' => 'ok'];
        if ($mensaje !== null) {
            $cuerpo['mensaje'] = $mensaje;
        }
        if ($datos !== null) {
            $cuerpo['datos'] = $datos;
        }
        if ($meta !== null) {
            $cuerpo['meta'] = $meta;
        }
        return self::json($cuerpo, $status);
    }

    public static function error(string $mensaje, int $status, ?array $errores = null): self
    {
        $cuerpo = ['status' => 'error', 'mensaje' => $mensaje];
        if ($errores) {
            $cuerpo['errores'] = $errores;
        }
        return self::json($cuerpo, $status);
    }

    public static function csv(string $nombreArchivo, array $columnas, array $filas): self
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF"); // BOM para que Excel reconozca UTF-8
        fputcsv($f, $columnas, ';');
        foreach ($filas as $fila) {
            fputcsv($f, array_values($fila), ';');
        }
        rewind($f);
        $contenido = stream_get_contents($f);
        fclose($f);
        return new self(200, $contenido, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $nombreArchivo . '"',
        ]);
    }

    public function conHeader(string $nombre, string $valor): self
    {
        $this->headers[$nombre] = $valor;
        return $this;
    }

    /** Devuelve el cuerpo decodificado (se usa en las pruebas). */
    public function datos(): array
    {
        return json_decode($this->cuerpo, true) ?? [];
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $nombre => $valor) {
            header("$nombre: $valor");
        }
        echo $this->cuerpo;
    }
}
