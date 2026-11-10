# SiGeRU - API de Recolección

Parte de **SiGeRU** (Sistema de Gestión de Residuos Urbanos), proyecto de egreso de Aplec Software (ESI, 2026).

Elementos móviles y la operación diaria: camiones y sus mantenimientos, cuadrillas, rutas, asignaciones (ruta + camión + cuadrilla + turno), registro de recolecciones y de recursos usados. Propone el orden de visita de cada ruta y ordena el recorrido del día por distancia y urgencia (`app/Services/Optimizador.php`). Incluye las alertas programadas (`bin/alertas.php`).

API REST en PHP 8 con patrón MVC, sin frameworks. Responde siempre en JSON.

## Estructura

```
app/
  Core/          núcleo: router, peticiones y respuestas, validación, base de datos, JWT, idioma, log
  Controllers/   un controlador por recurso
  Models/        acceso a datos (consultas preparadas con PDO)
  Services/      Optimizador: orden de los recorridos (sin acceso a la base)
  Views/         JsonView: arma la respuesta y oculta campos internos
  rutas.php      todas las rutas con los roles que pueden usarlas
lang/            mensajes en español e inglés
public/          index.php (punto de entrada) y .htaccess
bin/             scripts de consola
tests/           pruebas unitarias y de funcionalidad (PHPUnit)
```

## Formato de las respuestas

```json
{"status": "ok", "datos": [ ], "meta": {"total": 48, "pagina": 1, "por_pagina": 15, "paginas": 4}}
{"status": "error", "mensaje": "Hay datos inválidos en la solicitud.", "errores": {"email": "..."}}
```

Códigos usados: 200, 201, 401 (sin sesión), 403 (sin permiso), 404, 405, 409 (conflicto con una regla de negocio),
422 (datos inválidos), 429 (demasiados intentos) y 500.

## Autenticación

El token JWT (RS256) llega en la cookie HttpOnly `sigeru_token` (navegador) o en `Authorization: Bearer <token>`
(Postman, pruebas). Si viene en la cookie, las operaciones que modifican datos deben traer la cabecera
`X-Requested-With: SiGeRU` (protección CSRF). Además de la firma se controla que la sesión no haya sido revocada.

## Variables de entorno

| Variable | Para qué sirve | Ejemplo |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME` | Servidor MySQL | `192.168.56.20`, `3306`, `sigeru` |
| `DB_USER`, `DB_PASS` | Usuario de MySQL propio de este servicio (permisos mínimos) | `api_recoleccion` |
| `JWT_CLAVE_PUBLICA` | Clave pública para verificar los tokens | `/claves/jwt_publica.pem` |
| `APP_IDIOMA` | Idioma por defecto de los mensajes (`es` o `en`) | `es` |
| `CONFIAR_PROXY` | `1` si está detrás del gateway (toma la IP de `X-Forwarded-For`) | `1` |
| `LOG_ARCHIVO` | Archivo de log; `{fecha}` genera uno por día | `/var/log/sigeru/api-recoleccion-{fecha}.log` |
| `BASE_PATH` | Prefijo de la ruta si el gateway no lo quita (en Docker queda vacío) | |
| `DB_DRIVER=sqlite`, `DB_RUTA` | Solo desarrollo: usar un archivo SQLite en lugar de MySQL | |

## Pruebas

Las pruebas crean una base SQLite en memoria con la misma estructura y los datos de prueba, así que no hace falta MySQL:

```bash
composer install
composer test
```

## Alertas programadas

`bin/alertas.php` avisa a los administradores de los mantenimientos próximos y de los contenedores que llevan días sin recolección. En el servidor lo ejecuta cron una vez por día:

```bash
docker compose exec -T api-recoleccion php bin/alertas.php
```

## Orden de los recorridos

`GET /rutas/{id}/optimizacion` devuelve el orden actual y el propuesto de una ruta, con los kilómetros, minutos y litros
estimados y la urgencia de cada contenedor; `PUT /rutas/{id}/contenedores` lo aplica. El recorrido del día de la cuadrilla
(`GET /asignaciones/{id}`) se reordena en cada consulta salvo que el parámetro `recorrido_dinamico` valga 0. Los supuestos
de la estimación son parámetros del sistema: `velocidad_camion_kmh`, `minutos_por_contenedor` y
`consumo_camion_litros_100km`.

`bin/evaluar_rutas.php` compara, para cada ruta activa, el orden guardado, el propuesto y el más corto sin urgencia, y
escribe un CSV:

```bash
docker compose exec -T api-recoleccion php bin/evaluar_rutas.php > evaluacion_rutas.csv
```

## Rutas

Todas se acceden a través del gateway con el prefijo `/api/recoleccion` (por ejemplo `https://192.168.56.10/api/recoleccion/salud`).

| Método | Ruta | Controlador | Acceso |
|---|---|---|---|
| GET | `/salud` | Salud::index | pública |
| GET | `/camiones` | Camion::index | administrador, cuadrilla |
| POST | `/camiones` | Camion::store | administrador |
| GET | `/camiones/{id}` | Camion::show | administrador, cuadrilla |
| PUT | `/camiones/{id}` | Camion::update | administrador |
| DELETE | `/camiones/{id}` | Camion::destroy | administrador |
| PUT | `/camiones/{id}/estado` | Camion::estado | administrador |
| GET | `/mantenimientos` | Mantenimiento::index | administrador |
| POST | `/mantenimientos` | Mantenimiento::store | administrador |
| GET | `/mantenimientos/proximos` | Mantenimiento::proximos | administrador |
| GET | `/mantenimientos/{id}` | Mantenimiento::show | administrador |
| PUT | `/mantenimientos/{id}` | Mantenimiento::update | administrador |
| DELETE | `/mantenimientos/{id}` | Mantenimiento::destroy | administrador |
| GET | `/cuadrillas` | Cuadrilla::index | administrador, cuadrilla |
| POST | `/cuadrillas` | Cuadrilla::store | administrador |
| GET | `/cuadrillas/mia` | Cuadrilla::mia | cuadrilla |
| GET | `/cuadrillas/{id}` | Cuadrilla::show | administrador, cuadrilla |
| PUT | `/cuadrillas/{id}` | Cuadrilla::update | administrador |
| DELETE | `/cuadrillas/{id}` | Cuadrilla::destroy | administrador |
| PUT | `/cuadrillas/{id}/integrantes` | Cuadrilla::integrantes | administrador |
| GET | `/rutas` | Ruta::index | administrador, cuadrilla |
| POST | `/rutas` | Ruta::store | administrador |
| GET | `/rutas/{id}` | Ruta::show | administrador, cuadrilla |
| PUT | `/rutas/{id}` | Ruta::update | administrador |
| DELETE | `/rutas/{id}` | Ruta::destroy | administrador |
| PUT | `/rutas/{id}/contenedores` | Ruta::contenedores | administrador |
| GET | `/rutas/{id}/optimizacion` | Ruta::optimizacion | administrador |
| GET | `/asignaciones` | Asignacion::index | administrador, cuadrilla |
| POST | `/asignaciones` | Asignacion::store | administrador |
| GET | `/asignaciones/hoy` | Asignacion::hoy | administrador, cuadrilla |
| GET | `/asignaciones/{id}` | Asignacion::show | administrador, cuadrilla |
| PUT | `/asignaciones/{id}` | Asignacion::update | administrador |
| DELETE | `/asignaciones/{id}` | Asignacion::destroy | administrador |
| PUT | `/asignaciones/{id}/estado` | Asignacion::estado | administrador, cuadrilla |
| GET | `/asignaciones/{id}/recolecciones` | Asignacion::recolecciones | administrador, cuadrilla |
| POST | `/asignaciones/{id}/recolecciones` | Asignacion::registrarRecoleccion | administrador, cuadrilla |
| POST | `/asignaciones/{id}/recursos` | Asignacion::registrarRecurso | administrador, cuadrilla |
| GET | `/estadisticas/volumen` | Estadistica::volumen | administrador |
| GET | `/estadisticas/flota` | Estadistica::flota | administrador |
| GET | `/estadisticas/cuadrillas` | Estadistica::cuadrillas | administrador |
| GET | `/estadisticas/sin-recoleccion` | Estadistica::sinRecoleccion | administrador |

## Docker

La imagen fija la versión de PHP con `ARG PHP_VERSION=8.3.36` (`FROM php:${PHP_VERSION}-apache`), la primera que corrige
las vulnerabilidades «php2shell» (CVE-2026-109618 y CVE-2026-109619, octubre de 2026). El `php.ini` de producción incluye
`session.upload_progress.enabled = 0`, la mitigación que recomendó PHP para CVE-2026-115560 hasta su parche. Para
actualizar a una versión nueva de PHP: cambiar `PHP_VERSION`, reconstruir con `docker compose build --pull` y subir
`PHP_MINIMO` en el servidor (ver `sigeru-infra`).


Normalmente se levanta junto con el resto desde el repositorio `sigeru-infra` (`docker compose up -d --build`).
Para construir solo esta imagen: `docker build -t aplec/sigeru-api-recoleccion .`
