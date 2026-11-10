<?php

namespace Tests\Feature;

use Tests\Support\TestCase;

class CamionMantenimientoTest extends TestCase
{
    public function testAltaDeCamionNormalizaLaMatricula(): void
    {
        $res = $this->pedir('POST', '/camiones', [
            'matricula' => 'sim3201', 'marca' => 'Volvo', 'modelo' => 'VM 270', 'anio' => 2024, 'tipo' => 'compactador', 'capacidad_ton' => 11,
        ], 1);
        $this->assertSame(422, $res->status);   // minúsculas no cumplen el formato
        $res = $this->pedir('POST', '/camiones', [
            'matricula' => 'SIM3201', 'marca' => 'Volvo', 'modelo' => 'VM 270', 'anio' => 2024, 'tipo' => 'compactador', 'capacidad_ton' => 11,
        ], 1);
        $this->assertSame(201, $res->status);
        $this->assertSame('SIM 3201', $res->datos()['datos']['matricula']);
        $this->assertSame(409, $this->pedir('POST', '/camiones', [
            'matricula' => 'SIM 3201', 'marca' => 'Volvo', 'modelo' => 'VM 270', 'anio' => 2024, 'tipo' => 'compactador', 'capacidad_ton' => 11,
        ], 1)->status);
    }

    public function testAltaDeCamionConElKilometrajeVacio(): void
    {
        // el formulario del frontend manda kilometraje null cuando el campo queda vacío (D-22)
        $res = $this->pedir('POST', '/camiones', [
            'matricula' => 'SBA 4821', 'marca' => 'Mercedes-Benz', 'modelo' => 'Atego 1726', 'anio' => 2025, 'tipo' => 'compactador',
            'capacidad_ton' => 12, 'kilometraje' => null,
        ], 1);
        $this->assertSame(201, $res->status);
        $this->assertSame(0, (int) $res->datos()['datos']['kilometraje']);
        $id = $res->datos()['datos']['id'];
        $res = $this->pedir('PUT', "/camiones/$id", ['modelo' => 'Atego 1730', 'kilometraje' => null], 1);
        $this->assertSame(200, $res->status);
        $this->assertSame(0, (int) $res->datos()['datos']['kilometraje']);
    }

    public function testLaCuadrillaConsultaPeroNoModificaLaFlota(): void
    {
        $this->assertSame(200, $this->pedir('GET', '/camiones', [], 3)->status);
        $this->assertSame(403, $this->pedir('PUT', '/camiones/1', ['kilometraje' => 1], 3)->status);
        $this->assertSame(401, $this->pedir('GET', '/camiones')->status);
    }

    public function testElKilometrajeNoDisminuye(): void
    {
        $this->assertSame(409, $this->pedir('PUT', '/camiones/1', ['kilometraje' => 10], 1)->status);
        $this->assertSame(200, $this->pedir('PUT', '/camiones/1', ['kilometraje' => 130000], 1)->status);
    }

    public function testNoSeDaDeBajaUnCamionConRecorridosPendientes(): void
    {
        $this->assertSame(409, $this->pedir('DELETE', '/camiones/1', [], 1)->status);
        $this->assertSame(200, $this->pedir('DELETE', '/camiones/8', [], 1)->status);
    }

    public function testRealizarMantenimientoDevuelveElCamionADisponible(): void
    {
        $id = (int) $this->db->query("SELECT id FROM mantenimientos WHERE camion_id = 5 AND estado = 'programado'")->fetchColumn();
        $res = $this->pedir('PUT', "/mantenimientos/$id", ['estado' => 'realizado', 'costo' => 25400, 'kilometraje' => 163000], 1);
        $this->assertSame(200, $res->status);
        $this->assertSame('2026-10-30', $res->datos()['datos']['fecha_realizada']);
        $camion = $this->db->query('SELECT estado, kilometraje FROM camiones WHERE id = 5')->fetch();
        $this->assertSame('disponible', $camion['estado']);
        $this->assertSame(163000, (int) $camion['kilometraje']);
    }

    public function testMantenimientosProximos(): void
    {
        $res = $this->pedir('GET', '/mantenimientos/proximos', [], 1);
        $this->assertSame(200, $res->status);
        $this->assertCount(3, $res->datos()['datos']);   // hoy, mañana y pasado
    }
}
