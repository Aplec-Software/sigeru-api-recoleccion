<?php

namespace Tests\Feature;

use Tests\Support\TestCase;

class CuadrillaRutaEstadisticaTest extends TestCase
{
    public function testIntegrantesConUnSoloResponsable(): void
    {
        $this->assertSame(422, $this->pedir('PUT', '/cuadrillas/1/integrantes', ['integrantes' => [
            ['usuario_id' => 3, 'es_responsable' => true], ['usuario_id' => 4, 'es_responsable' => true],
        ]], 1)->status);
        // un vecino no puede integrar una cuadrilla
        $this->assertSame(422, $this->pedir('PUT', '/cuadrillas/1/integrantes', ['integrantes' => [['usuario_id' => 12, 'es_responsable' => true]]], 1)->status);
        // el usuario 5 ya está en la cuadrilla 2
        $this->assertSame(409, $this->pedir('PUT', '/cuadrillas/1/integrantes', ['integrantes' => [['usuario_id' => 5, 'es_responsable' => true]]], 1)->status);
        $res = $this->pedir('PUT', '/cuadrillas/1/integrantes', ['integrantes' => [['usuario_id' => 4, 'es_responsable' => true], ['usuario_id' => 3]]], 1);
        $this->assertSame(200, $res->status);
        $this->assertCount(2, $res->datos()['datos']['integrantes']);
    }

    public function testCuadrillaPropia(): void
    {
        $res = $this->pedir('GET', '/cuadrillas/mia', [], 5);
        $this->assertSame(200, $res->status);
        $this->assertSame(2, $res->datos()['datos']['id']);
        $this->assertSame(403, $this->pedir('GET', '/cuadrillas/1', [], 5)->status);
    }

    public function testContenedoresDeUnaRuta(): void
    {
        $this->assertSame(422, $this->pedir('PUT', '/rutas/4/contenedores', ['contenedores' => [37, 37]], 1)->status);
        $this->assertSame(409, $this->pedir('PUT', '/rutas/4/contenedores', ['contenedores' => [37, 48]], 1)->status);   // 48 está dado de baja
        $res = $this->pedir('PUT', '/rutas/4/contenedores', ['contenedores' => [40, 38, 37]], 1);
        $this->assertSame(200, $res->status);
        $this->assertSame([40, 38, 37], array_column($res->datos()['datos']['contenedores'], 'id'));
    }

    public function testEstadisticasDeRecoleccion(): void
    {
        $res = $this->pedir('GET', '/estadisticas/volumen?desde=2026-10-01&hasta=2026-10-29&agrupar=zona', [], 1);
        $this->assertSame(200, $res->status);
        $this->assertGreaterThan(0, $res->datos()['meta']['total_kilos']);
        $this->assertSame(200, $this->pedir('GET', '/estadisticas/flota', [], 1)->status);
        $this->assertSame(200, $this->pedir('GET', '/estadisticas/cuadrillas', [], 1)->status);
        $this->assertSame(403, $this->pedir('GET', '/estadisticas/volumen', [], 3)->status);
    }

    public function testRutaInexistenteYMetodoNoPermitido(): void
    {
        $this->assertSame(404, $this->pedir('GET', '/rutas/999', [], 1)->status);
        $this->assertSame(405, $this->pedir('DELETE', '/asignaciones/hoy', [], 1)->status);
        $this->assertSame(404, $this->pedir('GET', '/no-existe', [], 1)->status);
    }
}
