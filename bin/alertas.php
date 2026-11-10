<?php

/**
 * Alertas programadas (se ejecuta una vez por día con cron desde el servidor):
 *   docker compose exec -T api-recoleccion php bin/alertas.php
 *
 * Avisa a los administradores de los mantenimientos próximos y de los
 * contenedores que llevan varios días sin recolección.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Clock;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Notifier;
use App\Core\Params;
use App\Models\Mantenimiento;
use App\Models\Recoleccion;

$db = Database::conexion();
$hoy = Clock::hoy();
$avisos = 0;

// evita repetir el mismo aviso en el mismo día
$yaAvisado = function (string $tipo, string $mensaje) use ($db, $hoy): bool {
    $st = $db->prepare('SELECT COUNT(*) FROM notificaciones WHERE tipo = ? AND mensaje = ? AND creado_en >= ?');
    $st->execute([$tipo, $mensaje, "$hoy 00:00:00"]);
    return $st->fetchColumn() > 0;
};

$dias = Params::int('dias_aviso_mantenimiento', 3);
foreach (Mantenimiento::proximos($hoy, substr(Clock::mas($dias * 24 * 60), 0, 10)) as $m) {
    $mensaje = Lang::t('recoleccion.alerta_mantenimiento', ['matricula' => $m['matricula'], 'fecha' => $m['fecha_programada']]);
    if (!$yaAvisado('mantenimiento', $mensaje)) {
        $avisos += Notifier::aRol('administrador', 'mantenimiento', Lang::t('recoleccion.alerta_mantenimiento_titulo'), $mensaje, '#/camiones');
    }
}

$diasSin = 3;
$atrasados = Recoleccion::sinRecoleccion($diasSin);
if ($atrasados) {
    $mensaje = Lang::t('recoleccion.alerta_sin_recoleccion', ['cantidad' => count($atrasados), 'dias' => $diasSin]);
    if (!$yaAvisado('sin_recoleccion', $mensaje)) {
        $avisos += Notifier::aRol('administrador', 'sin_recoleccion', Lang::t('recoleccion.alerta_sin_recoleccion_titulo'), $mensaje, '#/informes');
    }
}

echo '[' . Clock::ahora() . "] alertas generadas: $avisos\n";
