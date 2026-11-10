<?php

namespace App\Services;

/**
 * Orden de visita de los contenedores de un recorrido: es el problema del viajante (TSP) con prioridades, que no
 * tiene una solución exacta rápida, así que se resuelve con dos heurísticas conocidas:
 *
 *  1. Vecino más cercano ponderado por urgencia: desde el punto actual se elige el contenedor que minimiza
 *     distancia / (1 + PESO_ELECCION × urgencia / 100); un contenedor muy urgente "parece" más cerca.
 *  2. Mejora 2-opt: se invierte un tramo del recorrido mientras eso baje el costo total, que es
 *     km recorridos (hasta el centro donde se descarga) + PESO_URGENCIA × Σ (urgencia_i / 100 × llegada_i),
 *     donde llegada_i es lo que se tarda en llegar a i medido en kilómetros: los km hasta i más el tiempo de las
 *     paradas anteriores convertido a km con la velocidad media. Así se acortan los kilómetros sin dejar para el
 *     final lo urgente: llegar un kilómetro más tarde a un contenedor con urgencia 100 "cuesta" medio kilómetro.
 *
 * La distancia es la del círculo máximo (fórmula del semiverseno) multiplicada por FACTOR_CALLES, que aproxima
 * el recorrido real por la trama de calles. No tiene en cuenta el tránsito ni los sentidos de circulación.
 * Es código sin base de datos: recibe los puntos y devuelve el orden.
 *
 * Para que sea rápido con circuitos reales (en Montevideo, unos 100 contenedores por circuito) las distancias se
 * calculan una sola vez en una matriz y cada inversión del 2-opt se evalúa con la diferencia de costo, sin recorrer
 * todo el orden (ver diferencia()).
 */
final class Optimizador
{
    public const FACTOR_CALLES = 1.3;
    public const PESO_ELECCION = 1.0;
    public const PESO_URGENCIA = 0.5;
    /** Supuestos de la estimación; la API los toma de los parámetros del sistema y estos son los valores por defecto. */
    public const SUPUESTOS = ['velocidad_kmh' => 20.0, 'minutos_por_contenedor' => 2.0, 'litros_100km' => 100.0];
    private const RADIO_TIERRA_KM = 6371.0;

    /** Distancia estimada por calle, en km, entre dos puntos con 'latitud' y 'longitud'. */
    public static function distancia(array $a, array $b): float
    {
        $la1 = deg2rad((float) $a['latitud']);
        $la2 = deg2rad((float) $b['latitud']);
        $dLa = $la2 - $la1;
        $dLo = deg2rad((float) $b['longitud'] - (float) $a['longitud']);
        $h = sin($dLa / 2) ** 2 + cos($la1) * cos($la2) * sin($dLo / 2) ** 2;
        return 2 * self::RADIO_TIERRA_KM * asin(min(1.0, sqrt($h))) * self::FACTOR_CALLES;
    }

    /**
     * Urgencia de 0 a 100 de un contenedor y los motivos que la explican:
     * reporte activo de desborde o falta de recolección (+50, y +5 por cada reporte sumado, hasta +15),
     * nivel de llenado (hasta +30) y días sin recolección (+4 por día, hasta +20).
     */
    public static function urgencia(array $c, string $hoy): array
    {
        $puntos = 0;
        $motivos = [];
        if ((int) ($c['reportes_urgentes'] ?? 0) > 0) {
            $puntos += 50 + min(15, 5 * max(0, (int) ($c['reportes_cantidad'] ?? 1) - 1));
            $motivos[] = 'reporte';
        }
        $nivel = max(0, min(100, (int) ($c['nivel_llenado'] ?? 0)));
        $puntos += (int) round($nivel * 0.3);
        if ($nivel >= 80) {
            $motivos[] = 'lleno';
        }
        $dias = empty($c['ultima_recoleccion']) ? 30
            : max(0, (int) floor((strtotime($hoy) - strtotime(substr($c['ultima_recoleccion'], 0, 10))) / 86400));
        $puntos += min(20, 4 * $dias);
        if ($dias >= 3) {
            $motivos[] = 'dias';
        }
        $urgencia = min(100, $puntos);
        $prioridad = $urgencia >= 70 ? 'alta' : ($urgencia >= 40 ? 'media' : 'baja');
        return ['urgencia' => $urgencia, 'prioridad' => $prioridad, 'motivos' => $motivos];
    }

    /**
     * Ordena los puntos (cada uno con 'id', 'latitud', 'longitud' y 'urgencia') saliendo de $inicio y terminando en
     * $fin (por defecto, el mismo punto de partida). Devuelve los puntos en el orden de visita.
     */
    public static function ordenar(array $puntos, array $inicio, ?array $fin = null, array $supuestos = []): array
    {
        $fin ??= $inicio;
        $puntos = array_values($puntos);
        if (count($puntos) <= 1) {
            return $puntos;
        }
        $m = self::matriz($puntos, $inicio, $fin, $supuestos);
        // se mejoran dos puntos de partida, el del vecino más cercano y el orden recibido (el actual de la ruta), y se
        // devuelve el mejor: así la propuesta nunca es peor que el orden que ya se usa
        $desdeVecino = self::dosOpt(self::vecinoMasCercano($m), $m);
        $desdeActual = self::dosOpt(range(0, count($puntos) - 1), $m);
        $mejor = self::costoIndices($desdeVecino, $m) <= self::costoIndices($desdeActual, $m) ? $desdeVecino : $desdeActual;
        return array_map(fn ($k) => $puntos[$k], $mejor);
    }

    /**
     * Datos que usan las heurísticas, calculados una sola vez: distancias entre contenedores ('d'), desde la salida
     * ('ini') y hasta el centro de llegada ('fin'), el peso de cada contenedor (urgencia / 100) y los km que equivalen
     * a una parada.
     */
    private static function matriz(array $puntos, array $inicio, array $fin, array $supuestos): array
    {
        $s = $supuestos + self::SUPUESTOS;
        $n = count($puntos);
        $d = array_fill(0, $n, array_fill(0, $n, 0.0));
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $d[$a][$b] = $d[$b][$a] = self::distancia($puntos[$a], $puntos[$b]);
            }
        }
        return [
            'n' => $n, 'd' => $d, 'puntos' => $puntos,
            'ini' => array_map(fn ($p) => self::distancia($inicio, $p), $puntos),
            'fin' => array_map(fn ($p) => self::distancia($p, $fin), $puntos),
            'w' => array_map(fn ($p) => (float) $p['urgencia'] / 100, $puntos),
            'parada' => (float) $s['minutos_por_contenedor'] / 60 * (float) $s['velocidad_kmh'],
        ];
    }

    /** 1) vecino más cercano ponderado por urgencia (a igualdad, el de menor id, para que el resultado sea estable) */
    private static function vecinoMasCercano(array $m): array
    {
        $pendientes = range(0, $m['n'] - 1);
        $orden = [];
        $actual = null;
        while ($pendientes) {
            $elegido = null;
            $mejor = INF;
            foreach ($pendientes as $k => $p) {
                $km = $actual === null ? $m['ini'][$p] : $m['d'][$actual][$p];
                $valor = $km / (1 + self::PESO_ELECCION * $m['w'][$p]);
                if ($valor < $mejor - 1e-9
                    || (abs($valor - $mejor) <= 1e-9 && $m['puntos'][$p]['id'] < $m['puntos'][$pendientes[$elegido]]['id'])) {
                    $mejor = $valor;
                    $elegido = $k;
                }
            }
            $actual = $pendientes[$elegido];
            $orden[] = $actual;
            unset($pendientes[$elegido]);
        }
        return $orden;
    }

    /**
     * 2) mejora 2-opt: se prueba invertir cada tramo [i, j] y se acepta si baja el costo, hasta que ninguno lo baja.
     * Después de cada inversión aceptada se recalculan las sumas acumuladas que usa diferencia().
     */
    private static function dosOpt(array $orden, array $m): array
    {
        $n = $m['n'];
        $acum = self::acumulados($orden, $m);
        do {
            $mejoro = false;
            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (self::diferencia($orden, $i, $j, $m, $acum) < -1e-9) {
                        array_splice($orden, $i, $j - $i + 1, array_reverse(array_slice($orden, $i, $j - $i + 1)));
                        $acum = self::acumulados($orden, $m);
                        $mejoro = true;
                    }
                }
            }
        } while ($mejoro);
        return $orden;
    }

    /**
     * Km acumulados hasta cada posición (D) y sumas acumuladas de w, w·D y w·posición (empiezan en 0, así la suma
     * entre las posiciones i y j es X[j + 1] − X[i]).
     */
    private static function acumulados(array $orden, array $m): array
    {
        $km = [];
        $w = $wd = $wk = [0.0];
        foreach ($orden as $k => $p) {
            $km[$k] = $k === 0 ? $m['ini'][$p] : $km[$k - 1] + $m['d'][$orden[$k - 1]][$p];
            $w[] = $w[$k] + $m['w'][$p];
            $wd[] = $wd[$k] + $m['w'][$p] * $km[$k];
            $wk[] = $wk[$k] + $m['w'][$p] * $k;
        }
        return ['km' => $km, 'w' => $w, 'wd' => $wd, 'wk' => $wk];
    }

    /**
     * Cuánto cambia el costo si se invierte el tramo entre las posiciones i y j, sin recorrer el orden:
     *  - kilómetros: cambian solo las dos uniones del tramo con el resto (las distancias son simétricas);
     *  - llegadas dentro del tramo: el contenedor que estaba en la posición p pasa a la i + j − p y llega a los
     *    km de antes del tramo + la nueva unión + (D[j] − D[p]);
     *  - llegadas después del tramo: se corren lo mismo que cambian los kilómetros.
     */
    private static function diferencia(array $orden, int $i, int $j, array $m, array $a): float
    {
        $n = $m['n'];
        $entraViejo = $i === 0 ? $m['ini'][$orden[$i]] : $m['d'][$orden[$i - 1]][$orden[$i]];
        $entraNuevo = $i === 0 ? $m['ini'][$orden[$j]] : $m['d'][$orden[$i - 1]][$orden[$j]];
        $saleViejo = $j === $n - 1 ? $m['fin'][$orden[$j]] : $m['d'][$orden[$j]][$orden[$j + 1]];
        $saleNuevo = $j === $n - 1 ? $m['fin'][$orden[$i]] : $m['d'][$orden[$i]][$orden[$j + 1]];
        $deltaKm = $entraNuevo + $saleNuevo - $entraViejo - $saleViejo;

        $antes = $i === 0 ? 0.0 : $a['km'][$i - 1];
        $pesoTramo = $a['w'][$j + 1] - $a['w'][$i];
        $pesoPorKm = $a['wd'][$j + 1] - $a['wd'][$i];
        $pesoPorPosicion = $a['wk'][$j + 1] - $a['wk'][$i];
        $pesoDespues = $a['w'][$n] - $a['w'][$j + 1];
        $deltaPenalizacion = ($antes + $entraNuevo + $a['km'][$j] + ($i + $j) * $m['parada']) * $pesoTramo
            - 2 * $pesoPorKm - 2 * $m['parada'] * $pesoPorPosicion + $deltaKm * $pesoDespues;
        return $deltaKm + self::PESO_URGENCIA * $deltaPenalizacion;
    }

    /** Costo de un orden de índices con la matriz (misma fórmula que costo()). */
    private static function costoIndices(array $orden, array $m): float
    {
        $a = self::acumulados($orden, $m);
        $km = end($a['km']) + $m['fin'][end($orden)];
        return $km + self::PESO_URGENCIA * (end($a['wd']) + $m['parada'] * end($a['wk']));
    }

    /** Agrega a cada contenedor su urgencia, prioridad y motivos, y las coordenadas como números. */
    public static function conUrgencia(array $contenedores, string $hoy): array
    {
        return array_map(fn ($c) => ['latitud' => (float) $c['latitud'], 'longitud' => (float) $c['longitud']]
            + self::urgencia($c, $hoy) + $c, array_values($contenedores));
    }

    /** Punto medio (promedio de coordenadas) de una lista de puntos. */
    public static function centroide(array $puntos): array
    {
        $n = max(1, count($puntos));
        return ['latitud' => array_sum(array_column($puntos, 'latitud')) / $n,
                'longitud' => array_sum(array_column($puntos, 'longitud')) / $n];
    }

    /** El lugar de la lista más cercano a un punto, o null si la lista está vacía. */
    public static function masCercano(array $lugares, array $punto): ?array
    {
        $mejor = null;
        foreach ($lugares as $l) {
            if ($mejor === null || self::distancia($punto, $l) < self::distancia($punto, $mejor)) {
                $mejor = $l;
            }
        }
        return $mejor;
    }

    /** Costo de un orden: km totales más la penalización por llegar tarde a lo urgente. */
    public static function costo(array $orden, array $inicio, ?array $fin = null, array $supuestos = []): float
    {
        $s = $supuestos + self::SUPUESTOS;
        // una parada equivale a los km que el camión haría en ese tiempo
        $kmPorParada = (float) $s['minutos_por_contenedor'] / 60 * (float) $s['velocidad_kmh'];
        $km = 0.0;
        $penalizacion = 0.0;
        $actual = $inicio;
        foreach (array_values($orden) as $k => $p) {
            $km += self::distancia($actual, $p);
            $penalizacion += (float) $p['urgencia'] / 100 * ($km + $k * $kmPorParada);
            $actual = $p;
        }
        $km += self::distancia($actual, $fin ?? $inicio);
        return $km + self::PESO_URGENCIA * $penalizacion;
    }

    /** Minutos desde la salida hasta llegar a cada punto del orden (viaje más las paradas anteriores). */
    public static function llegadas(array $orden, array $inicio, array $supuestos = []): array
    {
        $s = $supuestos + self::SUPUESTOS;
        $minutos = [];
        $km = 0.0;
        $actual = $inicio;
        foreach (array_values($orden) as $k => $p) {
            $km += self::distancia($actual, $p);
            $minutos[] = $km / max(1.0, (float) $s['velocidad_kmh']) * 60 + $k * (float) $s['minutos_por_contenedor'];
            $actual = $p;
        }
        return $minutos;
    }

    /**
     * Kilómetros, minutos y litros estimados de un orden (con la vuelta al centro). $supuestos puede cambiar la
     * velocidad entre contenedores (km/h), los minutos de vaciado de cada contenedor y el consumo (litros cada 100 km).
     */
    public static function estimacion(array $orden, array $inicio, ?array $fin = null, array $supuestos = []): array
    {
        $s = $supuestos + self::SUPUESTOS;
        $km = 0.0;
        $actual = $inicio;
        foreach ($orden as $p) {
            $km += self::distancia($actual, $p);
            $actual = $p;
        }
        $km += self::distancia($actual, $fin ?? $inicio);
        return [
            'km' => round($km, 1),
            'minutos' => (int) round($km / max(1.0, (float) $s['velocidad_kmh']) * 60 + count($orden) * (float) $s['minutos_por_contenedor']),
            'litros' => round($km * (float) $s['litros_100km'] / 100, 1),
        ];
    }
}
