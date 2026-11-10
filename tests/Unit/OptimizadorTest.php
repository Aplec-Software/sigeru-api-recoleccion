<?php

namespace Tests\Unit;

use App\Services\Optimizador;
use PHPUnit\Framework\TestCase;

/** Pruebas del orden de recorrido (sin base de datos). Los puntos se ubican en km alrededor de un origen en Montevideo. */
class OptimizadorTest extends TestCase
{
    private const LAT0 = -34.9;
    private const LNG0 = -56.15;

    /** Punto a $este km al este y $norte km al norte del origen. */
    private function punto(int $id, float $este, float $norte, int $urgencia = 0): array
    {
        return ['id' => $id, 'urgencia' => $urgencia, 'latitud' => self::LAT0 + $norte / 111.195,
                'longitud' => self::LNG0 + $este / (111.195 * cos(deg2rad(self::LAT0)))];
    }

    public function testLaDistanciaIncluyeElFactorDeCalles(): void
    {
        // 2 km en línea recta equivalen a 2,6 km por calle
        $km = Optimizador::distancia($this->punto(0, 0, 0), $this->punto(1, 0, 2));
        $this->assertLessThan(0.01, abs($km - 2 * Optimizador::FACTOR_CALLES));
    }

    public function testElOrdenVisitaCadaContenedorUnaSolaVez(): void
    {
        $puntos = [$this->punto(1, 3, 1), $this->punto(2, -1, 2), $this->punto(3, 2, -2, 80), $this->punto(4, 0, 4), $this->punto(5, 4, 3)];
        $orden = array_column(Optimizador::ordenar($puntos, $this->punto(0, 0, 0)), 'id');
        sort($orden);
        $this->assertSame([1, 2, 3, 4, 5], $orden);
    }

    public function testSinCrucesRecorreElCuadradoPorElBorde(): void
    {
        // desde una esquina, el mejor recorrido de un cuadrado de 1 km es su perímetro (4 km en línea recta)
        $inicio = $this->punto(0, 0, 0);
        $cruzado = [$this->punto(1, 1, 1), $this->punto(2, 0, 1), $this->punto(3, 1, 0)];
        $orden = Optimizador::ordenar($cruzado, $inicio);
        $km = Optimizador::estimacion($orden, $inicio)['km'];
        $this->assertLessThan(0.1, abs($km - 4 * Optimizador::FACTOR_CALLES));
    }

    public function testLaPropuestaNuncaEsPeorQueElOrdenActual(): void
    {
        mt_srand(7);
        $inicio = $this->punto(0, 0, 0);
        for ($prueba = 0; $prueba < 5; $prueba++) {
            $puntos = [];
            for ($i = 1; $i <= 12; $i++) {
                $puntos[] = $this->punto($i, mt_rand(-40, 40) / 10, mt_rand(-40, 40) / 10, mt_rand(0, 100));
            }
            $this->assertLessThanOrEqual(Optimizador::costo($puntos, $inicio) + 1e-9,
                Optimizador::costo(Optimizador::ordenar($puntos, $inicio), $inicio));
        }
    }

    public function testLaDiferenciaDeCostoCoincideConRecalcularloTodo(): void
    {
        // el 2-opt evalúa cada inversión con una fórmula (diferencia()); tiene que dar lo mismo que armar el orden
        // nuevo y recalcular el costo completo, con o sin centro de llegada distinto y con distinto tiempo de parada
        $clase = new \ReflectionClass(Optimizador::class);
        [$matriz, $acumulados, $diferencia] = [$clase->getMethod('matriz'), $clase->getMethod('acumulados'), $clase->getMethod('diferencia')];
        mt_srand(5);
        for ($prueba = 0; $prueba < 6; $prueba++) {
            $puntos = [];
            for ($i = 1; $i <= 10; $i++) {
                $puntos[] = $this->punto($i, mt_rand(-50, 50) / 10, mt_rand(-50, 50) / 10, mt_rand(0, 100));
            }
            $inicio = $this->punto(0, 0, 0);
            $fin = $prueba % 2 ? $this->punto(99, 3, -2) : $inicio;
            $supuestos = ['minutos_por_contenedor' => $prueba];
            $m = $matriz->invoke(null, $puntos, $inicio, $fin, $supuestos);
            $orden = range(0, 9);
            shuffle($orden);
            $base = Optimizador::costo(array_map(fn ($k) => $puntos[$k], $orden), $inicio, $fin, $supuestos);
            $acum = $acumulados->invoke(null, $orden, $m);
            for ($i = 0; $i < 9; $i++) {
                for ($j = $i + 1; $j < 10; $j++) {
                    $nuevo = $orden;
                    array_splice($nuevo, $i, $j - $i + 1, array_reverse(array_slice($orden, $i, $j - $i + 1)));
                    $real = Optimizador::costo(array_map(fn ($k) => $puntos[$k], $nuevo), $inicio, $fin, $supuestos) - $base;
                    $this->assertLessThan(1e-9, abs($real - $diferencia->invoke(null, $orden, $i, $j, $m, $acum)));
                }
            }
        }
    }

    public function testUnCircuitoDeTamanoRealSeOrdenaEnMenosDeUnSegundo(): void
    {
        // en Montevideo un circuito tiene unos 100 contenedores (el más grande, 187)
        mt_srand(3);
        $puntos = [];
        for ($i = 1; $i <= 190; $i++) {
            $puntos[] = $this->punto($i, mt_rand(-40, 40) / 10, mt_rand(-40, 40) / 10, mt_rand(0, 100));
        }
        $desde = microtime(true);
        $orden = Optimizador::ordenar($puntos, $this->punto(0, 0, 0));
        $this->assertCount(190, $orden);
        $this->assertLessThan(1.0, microtime(true) - $desde);
    }

    public function testElContenedorUrgenteSeVisitaPrimeroSiNoAgregaKilometros(): void
    {
        // contenedores al este a 1, 2, 3 y 4 km y uno al oeste a 0,5 km: ir primero al oeste o dejarlo para la vuelta
        // suma los mismos kilómetros; si ese contenedor es urgente, se visita primero (y después el más cercano)
        $inicio = $this->punto(0, 0, 0);
        $este = [$this->punto(1, 1, 0, 10), $this->punto(2, 2, 0), $this->punto(3, 3, 0), $this->punto(4, 4, 0)];
        $orden = Optimizador::ordenar(array_merge($este, [$this->punto(9, -0.5, 0, 100)]), $inicio);
        $this->assertSame(9, $orden[0]['id']);
        $this->assertSame([1, 2, 3, 4], array_column(array_slice($orden, 1), 'id'));
    }

    public function testLasParadasAnterioresCuentanEnLaLlegadaALoUrgente(): void
    {
        // cuatro contenedores en la misma esquina que uno urgente: los km son los mismos en cualquier orden, pero vaciar
        // antes los otros cuatro demora la llegada al urgente (defecto D-17)
        $inicio = $this->punto(0, 0, 0);
        $urgente = $this->punto(9, 0, 1, 100);
        $otros = [$this->punto(1, 0, 1), $this->punto(2, 0, 1), $this->punto(3, 0, 1), $this->punto(4, 0, 1)];
        $this->assertLessThan(Optimizador::costo(array_merge($otros, [$urgente]), $inicio),
            Optimizador::costo(array_merge([$urgente], $otros), $inicio));
    }

    public function testLaEstimacionUsaLosSupuestosRecibidos(): void
    {
        // 2 paradas a 5 km en línea recta: 6,5 km por calle de ida y otros 6,5 de vuelta
        $inicio = $this->punto(0, 0, 0);
        $orden = [$this->punto(1, 0, 5), $this->punto(2, 0, 5)];
        $e = Optimizador::estimacion($orden, $inicio, null, ['velocidad_kmh' => 26, 'minutos_por_contenedor' => 3, 'litros_100km' => 50]);
        $this->assertSame(13.0, $e['km']);
        $this->assertSame(36, $e['minutos']);      // 13 km a 26 km/h son 30 minutos, más 3 por cada contenedor
        $this->assertSame(6.5, $e['litros']);
    }

    public function testUrgenciaSegunReportesNivelYDias(): void
    {
        $alta = Optimizador::urgencia(['reportes_urgentes' => 1, 'reportes_cantidad' => 3, 'nivel_llenado' => 100,
                                       'ultima_recoleccion' => '2026-10-28 07:00:00'], '2026-10-30');
        // 50 por el reporte + 10 por dos reportes sumados + 30 por el nivel + 8 por dos días
        $this->assertSame(98, $alta['urgencia']);
        $this->assertSame('alta', $alta['prioridad']);
        $this->assertSame(['reporte', 'lleno'], $alta['motivos']);

        $baja = Optimizador::urgencia(['reportes_urgentes' => 0, 'nivel_llenado' => 20, 'ultima_recoleccion' => '2026-10-30 06:30:00'], '2026-10-30');
        $this->assertSame(6, $baja['urgencia']);
        $this->assertSame('baja', $baja['prioridad']);
        $this->assertSame([], $baja['motivos']);
    }
}
