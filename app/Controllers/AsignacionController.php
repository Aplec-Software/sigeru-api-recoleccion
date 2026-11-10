<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\CrudController;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Notifier;
use App\Core\Params;
use App\Core\Request;
use App\Core\Response;
use App\Models\Asignacion;
use App\Models\Camion;
use App\Models\Cuadrilla;
use App\Models\Mantenimiento;
use App\Models\Recoleccion;
use App\Models\Ruta;
use App\Services\Optimizador;
use App\Views\JsonView;

/**
 * Asignación de rutas a cuadrillas y camiones, y registro de lo que se hace
 * durante el recorrido (contenedores vaciados y recursos utilizados).
 */
class AsignacionController extends CrudController
{
    protected string $modelo = Asignacion::class;
    protected string $entidad = 'asignacion';
    protected array $reglas = [
        'ruta_id' => 'required|int',
        'cuadrilla_id' => 'required|int',
        'camion_id' => 'required|int',
        'fecha' => 'required|date',
        'turno' => 'required|in:matutino,vespertino,nocturno',
        'observaciones' => 'nullable|string|max:300',
    ];

    protected function filtrosExtra(Request $req): array
    {
        $extra = [];
        if (!empty($req->query['desde'])) {
            $extra['t.fecha >= ?'] = $req->query['desde'];
        }
        if (!empty($req->query['hasta'])) {
            $extra['t.fecha <= ?'] = $req->query['hasta'];
        }
        if ($req->usuario['rol'] === 'cuadrilla') {
            $extra['t.cuadrilla_id = ?'] = Cuadrilla::deUsuario($req->usuario['id']) ?? 0;
        }
        return $extra;
    }

    protected function autorizarLectura(Request $req, array $fila): void
    {
        if ($req->usuario['rol'] === 'cuadrilla' && Cuadrilla::deUsuario($req->usuario['id']) !== (int) $fila['cuadrilla_id']) {
            throw $this->noEncontrado();
        }
    }

    public function show(Request $req): Response
    {
        $asig = Asignacion::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->autorizarLectura($req, $asig);
        $hechos = array_column(Recoleccion::listar(['asignacion_id' => $asig['id']], 1, 500)[0], null, 'contenedor_id');
        $contenedores = Optimizador::conUrgencia(Ruta::paraRecorrido((int) $asig['ruta_id']), Clock::hoy());
        // lo ya vaciado va primero, en el orden en que se vació
        $vaciados = array_values(array_filter($contenedores, fn ($c) => isset($hechos[$c['id']])));
        usort($vaciados, fn ($a, $b) => strcmp($hechos[$a['id']]['creado_en'], $hechos[$b['id']]['creado_en']));
        $pendientes = array_values(array_filter($contenedores, fn ($c) => !isset($hechos[$c['id']])));
        // lo pendiente se ordena cada vez que se consulta, desde el último punto, por distancia y urgencia (si el
        // parámetro recorrido_dinamico está activo); así un reporte nuevo de desborde adelanta ese contenedor
        $dinamico = Params::get('recorrido_dinamico', '1') === '1' && in_array($asig['estado'], ['planificada', 'en_curso'], true);
        if ($dinamico && count($pendientes) > 1) {
            // se sale del último contenedor vaciado o, si todavía no se vació ninguno, del centro de descarga más
            // cercano a la ruta, que es también adonde se vuelve
            $centro = Optimizador::masCercano(Ruta::centrosDeDescarga(), Optimizador::centroide($contenedores));
            $fin = $centro ? ['latitud' => (float) $centro['latitud'], 'longitud' => (float) $centro['longitud']] : null;
            $desde = $vaciados ? end($vaciados) : ($fin ?? $pendientes[0]);
            $pendientes = Optimizador::ordenar($pendientes, $desde, $fin ?? $desde, Ruta::supuestos());
        }
        $recorrido = [];
        foreach (array_merge($vaciados, $pendientes) as $k => $c) {
            $item = JsonView::render(array_diff_key($c, array_flip(['reportes_urgentes', 'reportes_cantidad', 'motivos', 'urgencia', 'prioridad'])));
            $item['orden'] = $k + 1;
            $item['orden_ruta'] = (int) $c['orden'];
            $item['recolectado'] = isset($hechos[$c['id']]);
            $item['urgencia'] = $c['urgencia'];
            $item['prioridad'] = $c['prioridad'];
            $item['motivos'] = $c['motivos'];
            $recorrido[] = $item;
        }
        $datos = $this->vista($asig);
        $datos['recorrido'] = $recorrido;
        $datos['recorrido_dinamico'] = $dinamico;
        return Response::ok($datos);
    }

    /** GET /asignaciones/hoy: lo que tiene que hacer hoy la cuadrilla del usuario. */
    public function hoy(Request $req): Response
    {
        $filtros = ['fecha' => Clock::hoy()];
        $extra = $req->usuario['rol'] === 'cuadrilla' ? ['t.cuadrilla_id = ?' => Cuadrilla::deUsuario($req->usuario['id']) ?? 0] : [];
        [$filas] = Asignacion::listar($filtros, 1, 50, $extra);
        return Response::ok(array_map([$this, 'vista'], $filas));
    }

    /** Reglas de negocio de una asignación (RNE). */
    protected function antesDeGuardar(Request $req, array &$datos, ?array $actual): void
    {
        if ($actual !== null && $actual['estado'] !== 'planificada') {
            throw $this->conflicto('recoleccion.asignacion_no_editable');
        }
        $d = $datos + ($actual ?? []);
        if ($d['fecha'] < Clock::hoy()) {
            throw new HttpException(422, Lang::t('error.validacion'), ['fecha' => Lang::t('recoleccion.fecha_pasada')]);
        }
        $ruta = Ruta::buscar((int) $d['ruta_id']);
        if (!$ruta || !$ruta['activa']) {
            throw $this->conflicto('recoleccion.ruta_inexistente');
        }
        $cuadrilla = Cuadrilla::buscar((int) $d['cuadrilla_id']);
        if (!$cuadrilla || !$cuadrilla['activa'] || (int) $cuadrilla['integrantes_cantidad'] === 0) {
            throw $this->conflicto('recoleccion.cuadrilla_sin_integrantes');
        }
        $camion = Camion::buscar((int) $d['camion_id']);
        if (!$camion || !$camion['activo'] || $camion['estado'] === 'fuera_servicio') {
            throw $this->conflicto('recoleccion.camion_no_disponible');
        }
        if (Mantenimiento::programadoEn((int) $camion['id'], $d['fecha']) || ($d['fecha'] === Clock::hoy() && $camion['estado'] === 'mantenimiento')) {
            throw $this->conflicto('recoleccion.camion_en_mantenimiento');
        }
        $excepto = $actual['id'] ?? null;
        if (Asignacion::choque('camion_id', (int) $d['camion_id'], $d['fecha'], $d['turno'], $excepto)) {
            throw $this->conflicto('recoleccion.camion_ocupado');
        }
        if (Asignacion::choque('cuadrilla_id', (int) $d['cuadrilla_id'], $d['fecha'], $d['turno'], $excepto)) {
            throw $this->conflicto('recoleccion.cuadrilla_ocupada');
        }
        if ($actual === null) {
            $datos['estado'] = 'planificada';
        }
    }

    protected function despuesDeGuardar(Request $req, int $id, array $datos, ?array $actual): void
    {
        if ($actual === null) {
            $a = Asignacion::buscar($id);
            Notifier::aCuadrilla((int) $a['cuadrilla_id'], 'ruta_asignada', Lang::t('recoleccion.ruta_asignada_titulo'),
                Lang::t('recoleccion.ruta_asignada', ['ruta' => $a['ruta'], 'fecha' => $a['fecha'], 'turno' => $a['turno'], 'matricula' => $a['matricula']]), '#/mi-ruta');
        }
    }

    protected function antesDeEliminar(Request $req, array $actual): void
    {
        if ($actual['estado'] !== 'planificada') {
            throw $this->conflicto('recoleccion.asignacion_no_editable');
        }
    }

    /** PUT /asignaciones/{id}/estado: iniciar, completar o cancelar el recorrido. */
    public function estado(Request $req): Response
    {
        $asig = Asignacion::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->autorizarLectura($req, $asig);
        $datos = $this->validar($req, [
            'estado' => 'required|in:en_curso,completada,cancelada',
            'observaciones' => 'nullable|string|max:300',
        ]);
        $a = $datos['estado'];
        if (!in_array($a, Asignacion::TRANSICIONES[$asig['estado']], true)) {
            throw $this->conflicto('recoleccion.transicion_invalida', ['de' => $asig['estado'], 'a' => $a]);
        }
        if ($a === 'cancelada' && !$this->esAdmin($req)) {
            throw $this->prohibido();
        }
        if ($a === 'en_curso' && $asig['fecha'] !== Clock::hoy()) {
            throw $this->conflicto('recoleccion.no_es_hoy');
        }

        $cambios = ['estado' => $a];
        if (!empty($datos['observaciones'])) {
            $cambios['observaciones'] = $datos['observaciones'];
        }
        if ($a === 'en_curso') {
            $cambios['iniciada_en'] = Clock::ahora();
            Camion::cambiarEstado((int) $asig['camion_id'], 'en_ruta');
        } else {
            $cambios['finalizada_en'] = Clock::ahora();
            if ($asig['estado'] === 'en_curso') {
                Camion::cambiarEstado((int) $asig['camion_id'], 'disponible');
            }
        }
        Asignacion::actualizar((int) $asig['id'], $cambios);
        Audit::registrar($req, 'estado_' . $a, 'asignacion', $asig['id']);
        return Response::ok($this->vista(Asignacion::buscar((int) $asig['id'])), 200, null, Lang::t('ok.modificado'));
    }

    public function recolecciones(Request $req): Response
    {
        $asig = Asignacion::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->autorizarLectura($req, $asig);
        [$filas] = Recoleccion::listar(['asignacion_id' => $asig['id']], 1, 500);
        return Response::ok(array_map(fn ($f) => JsonView::render($f), $filas));
    }

    /** POST /asignaciones/{id}/recolecciones: la cuadrilla registra un contenedor vaciado. */
    public function registrarRecoleccion(Request $req): Response
    {
        $asig = Asignacion::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->autorizarLectura($req, $asig);
        $datos = $this->validar($req, [
            'contenedor_id' => 'required|int',
            'nivel_previo' => 'required|int|min:0|max:100',
            'peso_kg' => 'required|numeric|min:0|max:2000',
            'observacion' => 'nullable|string|max:200',
            'latitud' => 'nullable|lat',
            'longitud' => 'nullable|lng',
        ]);
        if ($asig['estado'] !== 'en_curso') {
            throw $this->conflicto('recoleccion.asignacion_no_iniciada');
        }
        if (!Ruta::incluyeContenedor((int) $asig['ruta_id'], $datos['contenedor_id'])) {
            throw new HttpException(422, Lang::t('error.validacion'), ['contenedor_id' => Lang::t('recoleccion.contenedor_fuera_de_ruta')]);
        }
        $contenedor = Recoleccion::contenedor($datos['contenedor_id']);

        $db = Database::conexion();
        $db->beginTransaction();
        try {
            $id = Recoleccion::crear([
                'asignacion_id' => $asig['id'],
                'contenedor_id' => $datos['contenedor_id'],
                'nivel_previo' => $datos['nivel_previo'],
                'peso_kg' => $datos['peso_kg'],
                'observacion' => $datos['observacion'] ?? null,
                'creado_en' => Clock::ahora(),
            ]);
            Recoleccion::vaciarContenedor($datos['contenedor_id']);
            // sin GPS: la última posición conocida del camión es la del último contenedor atendido
            Camion::moverA((int) $asig['camion_id'], (float) ($datos['latitud'] ?? $contenedor['latitud']), (float) ($datos['longitud'] ?? $contenedor['longitud']));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::registrar($req, 'recoleccion', 'contenedor', $datos['contenedor_id']);
        return Response::ok(JsonView::render(Recoleccion::buscar($id)), 201, null, Lang::t('ok.creado'));
    }

    /** POST /asignaciones/{id}/recursos: repuestos o insumos usados durante la ruta. */
    public function registrarRecurso(Request $req): Response
    {
        $asig = Asignacion::buscar($this->idParam($req)) ?? throw $this->noEncontrado();
        $this->autorizarLectura($req, $asig);
        $datos = $this->validar($req, [
            'repuesto_id' => 'required|int',
            'cantidad' => 'required|int|min:1|max:500',
            'motivo' => 'required|string|min:3|max:150',
        ]);
        if (!in_array($asig['estado'], ['en_curso', 'completada'], true)) {
            throw $this->conflicto('recoleccion.asignacion_no_iniciada');
        }
        $db = Database::conexion();
        $st = $db->prepare('SELECT id, nombre, stock, stock_minimo FROM repuestos WHERE id = ? AND activo = 1');
        $st->execute([$datos['repuesto_id']]);
        $repuesto = $st->fetch() ?: throw $this->noEncontrado();

        $db->beginTransaction();
        try {
            $baja = $db->prepare('UPDATE repuestos SET stock = stock - ? WHERE id = ? AND stock >= ?');
            $baja->execute([$datos['cantidad'], $repuesto['id'], $datos['cantidad']]);
            if ($baja->rowCount() === 0) {
                throw $this->conflicto('recoleccion.stock_insuficiente', ['stock' => $repuesto['stock']]);
            }
            $db->prepare('INSERT INTO movimientos_stock (repuesto_id, tipo, cantidad, motivo, usuario_id, asignacion_id, creado_en)
                          VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$repuesto['id'], 'salida', $datos['cantidad'], $datos['motivo'], $req->usuario['id'], $asig['id'], Clock::ahora()]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $quedan = (int) $repuesto['stock'] - $datos['cantidad'];
        if ($quedan < (int) $repuesto['stock_minimo'] && (int) $repuesto['stock'] >= (int) $repuesto['stock_minimo']) {
            Notifier::aRol('administrador', 'stock_bajo', Lang::t('recoleccion.alerta_stock_titulo'),
                Lang::t('recoleccion.alerta_stock', ['repuesto' => $repuesto['nombre'], 'stock' => $quedan]), '#/repuestos');
        }
        Audit::registrar($req, 'uso_recurso', 'repuesto', $repuesto['id'], (string) $datos['cantidad']);
        return Response::ok(['repuesto_id' => (int) $repuesto['id'], 'stock' => $quedan], 201, null, Lang::t('ok.creado'));
    }
}
