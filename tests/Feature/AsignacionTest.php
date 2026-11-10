<?php

namespace Tests\Feature;

use Tests\Support\TestCase;

class AsignacionTest extends TestCase
{
    private function asignacionEnCurso(): array
    {
        return $this->db->query("SELECT * FROM asignaciones WHERE estado = 'en_curso' LIMIT 1")->fetch();
    }

    public function testCrearAsignacionAvisaALaCuadrilla(): void
    {
        $res = $this->pedir('POST', '/asignaciones', ['ruta_id' => 4, 'cuadrilla_id' => 1, 'camion_id' => 6, 'fecha' => '2026-11-03', 'turno' => 'nocturno'], 1);
        $this->assertSame(201, $res->status);
        $this->assertSame('planificada', $res->datos()['datos']['estado']);
        $this->assertSame(1, $this->contar('notificaciones', "tipo = 'ruta_asignada' AND usuario_id = 3"));
    }

    public function testReglasDeNegocioDeLaAsignacion(): void
    {
        $base = ['ruta_id' => 4, 'cuadrilla_id' => 1, 'turno' => 'nocturno'];
        // fecha pasada
        $this->assertSame(422, $this->pedir('POST', '/asignaciones', $base + ['camion_id' => 6, 'fecha' => '2026-10-01'], 1)->status);
        // camión fuera de servicio
        $this->assertSame(409, $this->pedir('POST', '/asignaciones', $base + ['camion_id' => 8, 'fecha' => '2026-11-03'], 1)->status);
        // camión con mantenimiento programado ese día (camión 1, 1/11)
        $this->assertSame(409, $this->pedir('POST', '/asignaciones', $base + ['camion_id' => 1, 'fecha' => '2026-11-01'], 1)->status);
        // camión ya ocupado en ese turno
        $this->assertSame(409, $this->pedir('POST', '/asignaciones', ['ruta_id' => 4, 'cuadrilla_id' => 2, 'camion_id' => 1, 'fecha' => '2026-11-02', 'turno' => 'matutino'], 1)->status);
    }

    public function testLaCuadrillaSoloVeSusRecorridos(): void
    {
        $res = $this->pedir('GET', '/asignaciones?por_pagina=200', [], 7);   // cuadrilla 3
        foreach ($res->datos()['datos'] as $a) {
            $this->assertSame(3, $a['cuadrilla_id']);
        }
        $ajena = (int) $this->db->query('SELECT id FROM asignaciones WHERE cuadrilla_id = 1 LIMIT 1')->fetchColumn();
        $this->assertSame(404, $this->pedir('GET', "/asignaciones/$ajena", [], 7)->status);
    }

    public function testRegistrarRecoleccionVaciaElContenedorYMueveElCamion(): void
    {
        $asig = $this->asignacionEnCurso();
        $cont = (int) $this->db->query("SELECT contenedor_id FROM ruta_contenedor WHERE ruta_id = {$asig['ruta_id']} ORDER BY orden LIMIT 1")->fetchColumn();
        $res = $this->pedir('POST', "/asignaciones/{$asig['id']}/recolecciones", ['contenedor_id' => $cont, 'nivel_previo' => 85, 'peso_kg' => 360.5], 7);
        $this->assertSame(201, $res->status);
        $c = $this->db->query("SELECT nivel_llenado, latitud FROM contenedores WHERE id = $cont")->fetch();
        $this->assertSame(0, (int) $c['nivel_llenado']);
        $this->assertEquals($c['latitud'], $this->db->query("SELECT ultima_latitud FROM camiones WHERE id = {$asig['camion_id']}")->fetchColumn());
        // el mismo contenedor no se registra dos veces en el mismo recorrido
        $this->assertSame(409, $this->pedir('POST', "/asignaciones/{$asig['id']}/recolecciones", ['contenedor_id' => $cont, 'nivel_previo' => 10, 'peso_kg' => 5], 7)->status);
    }

    public function testContenedorFueraDeRutaDevuelve422(): void
    {
        $asig = $this->asignacionEnCurso();
        $fuera = (int) $this->db->query("SELECT id FROM contenedores WHERE id NOT IN (SELECT contenedor_id FROM ruta_contenedor WHERE ruta_id = {$asig['ruta_id']}) LIMIT 1")->fetchColumn();
        $this->assertSame(422, $this->pedir('POST', "/asignaciones/{$asig['id']}/recolecciones", ['contenedor_id' => $fuera, 'nivel_previo' => 50, 'peso_kg' => 100], 7)->status);
    }

    public function testRecursosUsadosDescuentanStock(): void
    {
        $asig = $this->asignacionEnCurso();
        $res = $this->pedir('POST', "/asignaciones/{$asig['id']}/recursos", ['repuesto_id' => 8, 'cantidad' => 2, 'motivo' => 'Guantes rotos'], 7);
        $this->assertSame(201, $res->status);
        $this->assertSame(36, $res->datos()['datos']['stock']);
        $this->assertSame(409, $this->pedir('POST', "/asignaciones/{$asig['id']}/recursos", ['repuesto_id' => 2, 'cantidad' => 99, 'motivo' => 'Prueba'], 7)->status);
    }

    public function testCicloDeEstadosDelRecorrido(): void
    {
        $asig = $this->asignacionEnCurso();
        $this->assertSame(403, $this->pedir('PUT', "/asignaciones/{$asig['id']}/estado", ['estado' => 'cancelada'], 7)->status);
        $res = $this->pedir('PUT', "/asignaciones/{$asig['id']}/estado", ['estado' => 'completada'], 7);
        $this->assertSame(200, $res->status);
        $this->assertSame('disponible', $this->db->query("SELECT estado FROM camiones WHERE id = {$asig['camion_id']}")->fetchColumn());
        $this->assertSame(409, $this->pedir('PUT', "/asignaciones/{$asig['id']}/estado", ['estado' => 'en_curso'], 7)->status);

        // un recorrido de otro día no se puede iniciar hoy
        $futuro = (int) $this->db->query("SELECT id FROM asignaciones WHERE estado = 'planificada' AND fecha > '2026-10-30' AND cuadrilla_id = 3 LIMIT 1")->fetchColumn();
        $this->assertSame(409, $this->pedir('PUT', "/asignaciones/$futuro/estado", ['estado' => 'en_curso'], 7)->status);
    }
}
