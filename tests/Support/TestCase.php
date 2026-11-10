<?php

namespace Tests\Support;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Jwt;
use App\Core\Kernel;
use App\Core\Params;
use App\Core\Request;
use App\Core\Response;
use PDO;

/**
 * Clase base de las pruebas. Antes de cada prueba crea una base SQLite en
 * memoria con la misma estructura que MySQL y los datos de prueba, así cada
 * prueba empieza siempre desde el mismo estado.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected static ?string $clavePrivada = null;
    protected PDO $db;

    protected function setUp(): void
    {
        $dir = dirname(__DIR__) . '/fixtures';
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        foreach (['schema.sqlite.sql', '02_datos_base.sql', '03_datos_prueba.sql'] as $archivo) {
            $this->db->exec(file_get_contents("$dir/$archivo"));
        }
        Database::setConexion($this->db);
        Params::limpiar();
        Clock::fijar('2026-10-30 10:00:00');

        if (self::$clavePrivada === null) {
            $par = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($par, self::$clavePrivada);
            Auth::setClavePublica(openssl_pkey_get_details($par)['key']);
            putenv('JWT_CLAVE_PRIVADA_PEM=' . self::$clavePrivada);
        }
    }

    protected function tearDown(): void
    {
        Database::setConexion(null);
        Clock::fijar(null);
    }

    /** Genera un token válido (y su sesión) para el usuario indicado. */
    protected function tokenDe(int $usuarioId): string
    {
        $u = $this->db->query("SELECT u.id, u.nombre, r.nombre AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = $usuarioId")->fetch();
        $jti = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO sesiones (usuario_id, jti, emitido_en, expira_en) VALUES (?, ?, ?, ?)')
            ->execute([$usuarioId, $jti, Clock::ahora(), Clock::mas(60)]);
        return Jwt::crear([
            'iss' => Auth::EMISOR, 'sub' => $u['id'], 'rol' => $u['rol'], 'nombre' => $u['nombre'],
            'jti' => $jti, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 3600,
        ], self::$clavePrivada);
    }

    /** Ejecuta una petición contra la API completa (rutas, autenticación, controladores). */
    protected function pedir(string $metodo, string $ruta, array $body = [], ?int $usuarioId = null, array $headers = []): Response
    {
        $partes = parse_url($ruta);
        parse_str($partes['query'] ?? '', $query);
        if ($usuarioId !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->tokenDe($usuarioId);
        }
        $router = require dirname(__DIR__, 2) . '/app/rutas.php';
        $req = new Request($metodo, $partes['path'], $query, $body, $headers, [], '10.0.0.5');
        return (new Kernel($router))->atender($req);
    }

    protected function contar(string $tabla, string $where = '1 = 1'): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM $tabla WHERE $where")->fetchColumn();
    }
}
