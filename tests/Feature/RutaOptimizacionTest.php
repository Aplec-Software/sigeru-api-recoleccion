<?php

namespace Tests\Feature;

use App\Core\Clock;
use App\Models\Ruta;
use App\Services\Optimizador;
use Tests\Support\TestCase;

/** Propuesta de orden de una ruta y recorrido del día ordenado por distancia y urgencia. */
class RutaOptimizacionTest extends TestCase
{
    public function testLaPropuestaIncluyeTodaLaRutaYNoEsPeorQueElOrdenActual(): void
    {
        $res = $this->pedir('GET', '/rutas/1/optimizacion', [], 1);
        $this->assertSame(200, $res->status);
        $d = $res->datos()['datos'];
        $enRuta = array_map('intval', $this->db->query('SELECT contenedor_id FROM ruta_contenedor WHERE ruta_id = 1')->fetchAll(\PDO::FETCH_COLUMN));
        $propuestos = array_column($d['contenedores'], 'id');
        sort($enRuta);
        sort($propuestos);
        $this->assertSame($enRuta, $propuestos);
        $this->assertLessThanOrEqual($d['actual']['costo'], $d['propuesta']['costo']);
        $this->assertNotNull($d['partida']['nombre']);
        $this->assertGreaterThan(0, $d['propuesta']['litros']);
    }

    public function testLaEstimacionUsaLosParametrosDelSistema(): void
    {
        $this->db->exec("UPDATE parametros SET valor = '50' WHERE clave = 'consumo_camion_litros_100km'");
        $d = $this->pedir('GET', '/rutas/1/optimizacion', [], 1)->datos()['datos'];
        $this->assertSame(['velocidad_kmh' => 20.0, 'minutos_por_contenedor' => 2.0, 'litros_100km' => 50.0], $d['supuestos']);
        // con 50 litros cada 100 km, el combustible es la mitad de los kilómetros
        $this->assertLessThan(0.1, abs($d['propuesta']['litros'] - $d['propuesta']['km'] / 2));
    }

    public function testSoloElAdministradorPideLaPropuesta(): void
    {
        $this->assertSame(403, $this->pedir('GET', '/rutas/1/optimizacion', [], 7)->status);    // cuadrilla
        $this->assertSame(403, $this->pedir('GET', '/rutas/1/optimizacion', [], 12)->status);   // vecino
        $this->assertSame(404, $this->pedir('GET', '/rutas/999/optimizacion', [], 1)->status);
    }

    public function testElRecorridoDelDiaMuestraLaUrgenciaYDejaArribaLoVaciado(): void
    {
        $asig = $this->db->query("SELECT * FROM asignaciones WHERE estado = 'en_curso' LIMIT 1")->fetch();
        $ruta = $this->db->query("SELECT contenedor_id FROM ruta_contenedor WHERE ruta_id = {$asig['ruta_id']} ORDER BY orden")->fetchAll(\PDO::FETCH_COLUMN);
        // un contenedor de la ruta recibe un reporte de desborde y otro ya se vació
        $urgente = (int) end($ruta);
        $this->db->exec("UPDATE contenedores SET nivel_llenado = 100, estado = 'desbordado' WHERE id = $urgente");
        $this->db->exec("INSERT INTO incidencias (contenedor_id, tipo, descripcion, estado, prioridad, origen, cantidad_reportes, creado_en)
                         VALUES ($urgente, 'desborde', NULL, 'abierta', 'media', 'qr', 2, '2026-10-30 09:00:00')");
        $vaciado = (int) $ruta[1];
        $this->assertSame(201, $this->pedir('POST', "/asignaciones/{$asig['id']}/recolecciones",
            ['contenedor_id' => $vaciado, 'nivel_previo' => 70, 'peso_kg' => 300], 7)->status);

        $d = $this->pedir('GET', "/asignaciones/{$asig['id']}", [], 7)->datos()['datos'];
        $this->assertTrue($d['recorrido_dinamico']);
        $this->assertSame($vaciado, $d['recorrido'][0]['id']);
        $this->assertTrue($d['recorrido'][0]['recolectado']);
        $porId = array_column($d['recorrido'], null, 'id');
        $this->assertSame('alta', $porId[$urgente]['prioridad']);
        $this->assertContains('reporte', $porId[$urgente]['motivos']);
        $this->assertSame(range(1, count($ruta)), array_column($d['recorrido'], 'orden'));
    }

    public function testAlEmpezarElRecorridoSeSaleDelCentroYNoDeLaUltimaPosicionDelCamion(): void
    {
        // recorrido de hoy sin contenedores vaciados; el camión quedó lejos el día anterior (defecto D-18)
        $asig = $this->db->query("SELECT a.* FROM asignaciones a WHERE a.fecha = '2026-10-30' AND a.estado = 'planificada'
                                  AND NOT EXISTS (SELECT 1 FROM recolecciones r WHERE r.asignacion_id = a.id) LIMIT 1")->fetch();
        $this->db->exec("UPDATE camiones SET ultima_latitud = -34.78, ultima_longitud = -56.30 WHERE id = {$asig['camion_id']}");
        $contenedores = Optimizador::conUrgencia(Ruta::paraRecorrido((int) $asig['ruta_id']), Clock::hoy());
        $centro = Optimizador::masCercano(Ruta::centrosDeDescarga(), Optimizador::centroide($contenedores));
        $partida = ['latitud' => (float) $centro['latitud'], 'longitud' => (float) $centro['longitud']];
        $esperado = array_column(Optimizador::ordenar($contenedores, $partida, $partida, Ruta::supuestos()), 'id');
        $d = $this->pedir('GET', "/asignaciones/{$asig['id']}", [], 1)->datos()['datos'];
        $this->assertSame($esperado, array_column($d['recorrido'], 'id'));
    }

    public function testSinRecorridoDinamicoSeRespetaElOrdenDeLaRuta(): void
    {
        $this->db->exec("UPDATE parametros SET valor = '0' WHERE clave = 'recorrido_dinamico'");
        $asig = $this->db->query("SELECT * FROM asignaciones WHERE estado = 'en_curso' LIMIT 1")->fetch();
        $d = $this->pedir('GET', "/asignaciones/{$asig['id']}", [], 7)->datos()['datos'];
        $this->assertFalse($d['recorrido_dinamico']);
        $this->assertSame(array_column($d['recorrido'], 'orden'), array_column($d['recorrido'], 'orden_ruta'));
    }
}
