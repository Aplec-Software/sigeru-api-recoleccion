<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Ruta;
use App\Services\Optimizador;
use App\Views\JsonView;

/**
 * Rutas de recolección: zona, frecuencia y contenedores en orden de recorrido.
 */
class RutaController extends CrudController
{
    protected string $modelo = Ruta::class;
    protected string $entidad = 'ruta';
    protected array $reglas = [
        'nombre' => 'required|string|min:3|max:80',
        'zona_id' => 'required|int',
        'frecuencia' => 'required|in:diaria,lun_mie_vie,mar_jue_sab,semanal',
        'descripcion' => 'nullable|string|max:200',
    ];

    public function show(Request $req): Response
    {
        $ruta = Ruta::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $datos = $this->vista($ruta);
        $datos['contenedores'] = array_map(fn ($c) => JsonView::render($c), Ruta::contenedores((int) $ruta['id']));
        return Response::ok($datos);
    }

    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if (isset($datos['nombre']) && Ruta::existe('nombre', $datos['nombre'], $actual['id'] ?? null)) {
            throw $this->conflicto('recoleccion.nombre_repetido');
        }
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
        if (Ruta::asignacionesPendientes((int) $actual['id']) > 0) {
            throw $this->conflicto('recoleccion.ruta_con_asignaciones');
        }
    }

    /**
     * GET /rutas/{id}/optimizacion: propone un orden de visita que acorta los kilómetros y atiende antes los
     * contenedores urgentes, y lo compara con el orden actual. No cambia nada: el administrador decide si lo aplica
     * (con PUT /rutas/{id}/contenedores).
     */
    public function optimizacion(Request $req): Response
    {
        $id = $this->idParam($req);
        $ruta = Ruta::buscar($id) ?? throw $this->noEncontrado();
        $propuesta = self::propuesta($id);
        if ($propuesta === null) {
            throw $this->conflicto('recoleccion.ruta_sin_contenedores');
        }
        return Response::ok(['ruta' => ['id' => $id, 'nombre' => $ruta['nombre']]] + $propuesta);
    }

    /**
     * Cálculo de la propuesta (lo usan el endpoint y el script bin/evaluar_rutas.php): orden actual y propuesto con
     * su estimación, el ahorro y la urgencia de cada contenedor. Devuelve null si la ruta no tiene contenedores.
     */
    public static function propuesta(int $id): ?array
    {
        $puntos = Optimizador::conUrgencia(Ruta::paraRecorrido($id), Clock::hoy());
        if (!$puntos) {
            return null;
        }
        // el camión sale del centro de descarga más cercano a la ruta y vuelve a él
        $centro = Optimizador::masCercano(Ruta::centrosDeDescarga(), Optimizador::centroide($puntos));
        $partida = $centro ? ['nombre' => $centro['nombre'], 'latitud' => (float) $centro['latitud'], 'longitud' => (float) $centro['longitud']]
            : ['nombre' => null, 'latitud' => $puntos[0]['latitud'], 'longitud' => $puntos[0]['longitud']];
        $supuestos = Ruta::supuestos();
        $propuesta = Optimizador::ordenar($puntos, $partida, null, $supuestos);
        $actual = Optimizador::estimacion($puntos, $partida, null, $supuestos)
            + ['costo' => round(Optimizador::costo($puntos, $partida, null, $supuestos), 2)];
        $nueva = Optimizador::estimacion($propuesta, $partida, null, $supuestos)
            + ['costo' => round(Optimizador::costo($propuesta, $partida, null, $supuestos), 2)];
        $posicion = array_flip(array_column($puntos, 'id'));
        $contenedores = [];
        foreach ($propuesta as $k => $c) {
            $contenedores[] = ['id' => (int) $c['id'], 'codigo' => $c['codigo'], 'direccion' => $c['direccion'],
                               'latitud' => $c['latitud'], 'longitud' => $c['longitud'], 'nivel_llenado' => (int) $c['nivel_llenado'],
                               'urgencia' => $c['urgencia'], 'prioridad' => $c['prioridad'], 'motivos' => $c['motivos'],
                               'orden_actual' => $posicion[$c['id']] + 1, 'orden_propuesto' => $k + 1];
        }
        return [
            'partida' => $partida,
            'actual' => $actual,
            'propuesta' => $nueva,
            'supuestos' => $supuestos,
            'ahorro' => ['km' => round($actual['km'] - $nueva['km'], 1), 'minutos' => $actual['minutos'] - $nueva['minutos'],
                         'litros' => round($actual['litros'] - $nueva['litros'], 1),
                         'porcentaje_km' => $actual['km'] > 0 ? round(($actual['km'] - $nueva['km']) / $actual['km'] * 100, 1) : 0.0],
            'contenedores' => $contenedores,
        ];
    }

    /** PUT /rutas/{id}/contenedores  { "contenedores": [12, 5, 8] }  (el orden de la lista es el del recorrido) */
    public function contenedores(Request $req): Response
    {
        $id = $this->idParam($req);
        Ruta::buscar($id) ?? throw $this->noEncontrado();
        $ids = $req->input('contenedores');
        if (!is_array($ids) || !$ids || array_filter($ids, fn ($x) => !is_numeric($x))) {
            throw new HttpException(422, Lang::t('error.validacion'), ['contenedores' => Lang::t('recoleccion.contenedores_requeridos')]);
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new HttpException(422, Lang::t('error.validacion'), ['contenedores' => Lang::t('recoleccion.contenedores_repetidos')]);
        }
        if (Ruta::contenedoresActivos($ids) !== count($ids)) {
            throw $this->conflicto('recoleccion.contenedor_inactivo');
        }
        Ruta::guardarContenedores($id, $ids);
        Audit::registrar($req, 'contenedores', 'ruta', $id, (string) count($ids));
        return $this->show($req);
    }
}
