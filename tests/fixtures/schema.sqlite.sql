PRAGMA foreign_keys = ON;
CREATE TABLE roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre VARCHAR(30) NOT NULL,
  descripcion VARCHAR(150) NOT NULL,
  CONSTRAINT uq_roles_nombre UNIQUE (nombre)
);
CREATE TABLE zonas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre VARCHAR(60) NOT NULL,
  descripcion VARCHAR(200) NULL,
  latitud NUMERIC NULL,
  longitud NUMERIC NULL,
  activa INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT uq_zonas_nombre UNIQUE (nombre)
);
CREATE TABLE tipos_residuo (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre VARCHAR(50) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#6B7280',
  reciclable INTEGER NOT NULL DEFAULT 0,
  descripcion VARCHAR(200) NULL,
  activo INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT uq_tipos_residuo_nombre UNIQUE (nombre)
);
CREATE TABLE centros (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre VARCHAR(100) NOT NULL,
 tipo TEXT NOT NULL CHECK (tipo IN ('acopio','clasificacion','vertedero')),
  zona_id INTEGER NOT NULL,
  direccion VARCHAR(150) NOT NULL,
  latitud NUMERIC NOT NULL,
  longitud NUMERIC NOT NULL,
  capacidad_ton NUMERIC NOT NULL,
  ocupacion_ton NUMERIC NOT NULL DEFAULT 0,
  telefono VARCHAR(20) NULL,
  activo INTEGER NOT NULL DEFAULT 1,
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_centros_nombre UNIQUE (nombre),
  CONSTRAINT fk_centros_zona FOREIGN KEY (zona_id) REFERENCES zonas (id),
  CONSTRAINT ck_centros_capacidad CHECK (capacidad_ton > 0),
  CONSTRAINT ck_centros_ocupacion CHECK (ocupacion_ton >= 0 AND ocupacion_ton <= capacidad_ton)
);
CREATE TABLE usuarios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rol_id INTEGER NOT NULL,
  nombre VARCHAR(60) NOT NULL,
  apellido VARCHAR(60) NOT NULL,
  documento VARCHAR(8) NULL,
  email VARCHAR(120) NOT NULL,
  telefono VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
 estado TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','activo','inactivo','rechazado')),
  idioma CHAR(2) NOT NULL DEFAULT 'es',
  centro_id INTEGER NULL,
  intentos_fallidos INTEGER NOT NULL DEFAULT 0,
  bloqueado_hasta TEXT NULL,
  ultimo_acceso TEXT NULL,
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TEXT NULL,
  CONSTRAINT uq_usuarios_email UNIQUE (email),
  CONSTRAINT uq_usuarios_documento UNIQUE (documento),
  CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles (id) ON DELETE RESTRICT,
  CONSTRAINT fk_usuarios_centro FOREIGN KEY (centro_id) REFERENCES centros (id) ON DELETE SET NULL,
  CONSTRAINT ck_usuarios_idioma CHECK (idioma IN ('es','en'))
);
CREATE TABLE sesiones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NOT NULL,
  jti CHAR(32) NOT NULL,
  ip VARCHAR(45) NULL,
  agente VARCHAR(255) NULL,
  emitido_en TEXT NOT NULL,
  expira_en TEXT NOT NULL,
  revocado_en TEXT NULL,
  CONSTRAINT uq_sesiones_jti UNIQUE (jti),
  CONSTRAINT fk_sesiones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
);
CREATE TABLE notificaciones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NOT NULL,
  tipo VARCHAR(40) NOT NULL,
  titulo VARCHAR(120) NOT NULL,
  mensaje VARCHAR(255) NOT NULL,
  enlace VARCHAR(150) NULL,
  leida INTEGER NOT NULL DEFAULT 0,
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notificaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
);
CREATE TABLE parametros (
  clave VARCHAR(60) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL,
 tipo TEXT NOT NULL DEFAULT 'texto' CHECK (tipo IN ('entero','decimal','texto','booleano')),
  descripcion VARCHAR(200) NOT NULL,
  publico INTEGER NOT NULL DEFAULT 0,
  actualizado_en TEXT NULL
);
CREATE TABLE auditoria (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NULL,
  servicio VARCHAR(20) NOT NULL,
  accion VARCHAR(40) NOT NULL,
  entidad VARCHAR(40) NOT NULL,
  entidad_id VARCHAR(40) NULL,
  detalle VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
);
CREATE TABLE contenedores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  codigo VARCHAR(12) NOT NULL,
  tipo_residuo_id INTEGER NOT NULL,
  zona_id INTEGER NOT NULL,
  direccion VARCHAR(150) NOT NULL,
  latitud NUMERIC NOT NULL,
  longitud NUMERIC NOT NULL,
  capacidad_litros INTEGER NOT NULL DEFAULT 2400,
 estado TEXT NOT NULL DEFAULT 'funcional' CHECK (estado IN ('funcional','desbordado','roto','fuera_servicio')),
  nivel_llenado INTEGER NOT NULL DEFAULT 0,
  fecha_instalacion TEXT NULL,
  ultima_recoleccion TEXT NULL,
  activo INTEGER NOT NULL DEFAULT 1,
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TEXT NULL,
  CONSTRAINT uq_contenedores_codigo UNIQUE (codigo),
  CONSTRAINT fk_contenedores_tipo FOREIGN KEY (tipo_residuo_id) REFERENCES tipos_residuo (id),
  CONSTRAINT fk_contenedores_zona FOREIGN KEY (zona_id) REFERENCES zonas (id),
  CONSTRAINT ck_contenedores_nivel CHECK (nivel_llenado <= 100),
  CONSTRAINT ck_contenedores_coord CHECK (latitud BETWEEN -90 AND 90 AND longitud BETWEEN -180 AND 180)
);
CREATE TABLE centro_tipo_residuo (
  centro_id INTEGER NOT NULL,
  tipo_residuo_id INTEGER NOT NULL,
  PRIMARY KEY (centro_id, tipo_residuo_id),
  CONSTRAINT fk_ctr_centro FOREIGN KEY (centro_id) REFERENCES centros (id) ON DELETE CASCADE,
  CONSTRAINT fk_ctr_tipo FOREIGN KEY (tipo_residuo_id) REFERENCES tipos_residuo (id)
);
CREATE TABLE maquinaria (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  centro_id INTEGER NOT NULL,
  codigo VARCHAR(20) NOT NULL,
  descripcion VARCHAR(100) NOT NULL,
 tipo TEXT NOT NULL CHECK (tipo IN ('compactadora','prensa','balanza','cargadora','trituradora','cinta','otro')),
 estado TEXT NOT NULL DEFAULT 'operativa' CHECK (estado IN ('operativa','mantenimiento','fuera_servicio')),
  fecha_adquisicion TEXT NULL,
  ultimo_mantenimiento TEXT NULL,
  activo INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT uq_maquinaria_codigo UNIQUE (codigo),
  CONSTRAINT fk_maquinaria_centro FOREIGN KEY (centro_id) REFERENCES centros (id)
);
CREATE TABLE camiones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  matricula VARCHAR(10) NOT NULL,
  marca VARCHAR(40) NOT NULL,
  modelo VARCHAR(40) NOT NULL,
  anio INTEGER NOT NULL,
 tipo TEXT NOT NULL CHECK (tipo IN ('compactador','carga_lateral','caja_abierta','grua')),
  capacidad_ton NUMERIC NOT NULL,
 estado TEXT NOT NULL DEFAULT 'disponible' CHECK (estado IN ('disponible','en_ruta','mantenimiento','fuera_servicio')),
  kilometraje INTEGER NOT NULL DEFAULT 0,
  ultima_latitud NUMERIC NULL,
  ultima_longitud NUMERIC NULL,
  ultima_posicion_en TEXT NULL,
  activo INTEGER NOT NULL DEFAULT 1,
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_camiones_matricula UNIQUE (matricula),
  CONSTRAINT ck_camiones_anio CHECK (anio BETWEEN 1990 AND 2100),
  CONSTRAINT ck_camiones_capacidad CHECK (capacidad_ton > 0)
);
CREATE TABLE mantenimientos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  camion_id INTEGER NOT NULL,
 tipo TEXT NOT NULL CHECK (tipo IN ('preventivo','correctivo')),
  descripcion VARCHAR(200) NOT NULL,
  fecha_programada TEXT NOT NULL,
  fecha_realizada TEXT NULL,
  kilometraje INTEGER NULL,
  costo NUMERIC NULL,
 estado TEXT NOT NULL DEFAULT 'programado' CHECK (estado IN ('programado','realizado','cancelado')),
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mantenimientos_camion FOREIGN KEY (camion_id) REFERENCES camiones (id) ON DELETE CASCADE,
  CONSTRAINT ck_mantenimientos_costo CHECK (costo IS NULL OR costo >= 0)
);
CREATE TABLE cuadrillas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre VARCHAR(60) NOT NULL,
  zona_id INTEGER NOT NULL,
 turno TEXT NOT NULL CHECK (turno IN ('matutino','vespertino','nocturno')),
  activa INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT uq_cuadrillas_nombre UNIQUE (nombre),
  CONSTRAINT fk_cuadrillas_zona FOREIGN KEY (zona_id) REFERENCES zonas (id)
);
CREATE TABLE cuadrilla_integrante (
  cuadrilla_id INTEGER NOT NULL,
  usuario_id INTEGER NOT NULL,
  es_responsable INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (cuadrilla_id, usuario_id),
  CONSTRAINT uq_integrante_usuario UNIQUE (usuario_id),
  CONSTRAINT fk_integrante_cuadrilla FOREIGN KEY (cuadrilla_id) REFERENCES cuadrillas (id) ON DELETE CASCADE,
  CONSTRAINT fk_integrante_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
);
CREATE TABLE rutas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre VARCHAR(80) NOT NULL,
  zona_id INTEGER NOT NULL,
 frecuencia TEXT NOT NULL DEFAULT 'diaria' CHECK (frecuencia IN ('diaria','lun_mie_vie','mar_jue_sab','semanal')),
  descripcion VARCHAR(200) NULL,
  activa INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT uq_rutas_nombre UNIQUE (nombre),
  CONSTRAINT fk_rutas_zona FOREIGN KEY (zona_id) REFERENCES zonas (id)
);
CREATE TABLE ruta_contenedor (
  ruta_id INTEGER NOT NULL,
  contenedor_id INTEGER NOT NULL,
  orden INTEGER NOT NULL,
  PRIMARY KEY (ruta_id, contenedor_id),
  CONSTRAINT uq_ruta_orden UNIQUE (ruta_id, orden),
  CONSTRAINT fk_rc_ruta FOREIGN KEY (ruta_id) REFERENCES rutas (id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_contenedor FOREIGN KEY (contenedor_id) REFERENCES contenedores (id)
);
CREATE TABLE asignaciones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ruta_id INTEGER NOT NULL,
  cuadrilla_id INTEGER NOT NULL,
  camion_id INTEGER NOT NULL,
  fecha TEXT NOT NULL,
 turno TEXT NOT NULL CHECK (turno IN ('matutino','vespertino','nocturno')),
 estado TEXT NOT NULL DEFAULT 'planificada' CHECK (estado IN ('planificada','en_curso','completada','cancelada')),
  iniciada_en TEXT NULL,
  finalizada_en TEXT NULL,
  observaciones VARCHAR(300) NULL,
  creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_asig_camion_turno UNIQUE (camion_id, fecha, turno),
  CONSTRAINT uq_asig_cuadrilla_turno UNIQUE (cuadrilla_id, fecha, turno),
  CONSTRAINT fk_asig_ruta FOREIGN KEY (ruta_id) REFERENCES rutas (id),
  CONSTRAINT fk_asig_cuadrilla FOREIGN KEY (cuadrilla_id) REFERENCES cuadrillas (id),
  CONSTRAINT fk_asig_camion FOREIGN KEY (camion_id) REFERENCES camiones (id),
  CONSTRAINT ck_asig_fechas CHECK (finalizada_en IS NULL OR iniciada_en IS NULL OR finalizada_en >= iniciada_en)
);
CREATE TABLE recolecciones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  asignacion_id INTEGER NOT NULL,
  contenedor_id INTEGER NOT NULL,
  nivel_previo INTEGER NOT NULL,
  peso_kg NUMERIC NOT NULL,
  observacion VARCHAR(200) NULL,
  creado_en TEXT NOT NULL,
  CONSTRAINT uq_recoleccion_asig_cont UNIQUE (asignacion_id, contenedor_id),
  CONSTRAINT fk_recoleccion_asig FOREIGN KEY (asignacion_id) REFERENCES asignaciones (id) ON DELETE CASCADE,
  CONSTRAINT fk_recoleccion_cont FOREIGN KEY (contenedor_id) REFERENCES contenedores (id),
  CONSTRAINT ck_recoleccion_nivel CHECK (nivel_previo <= 100),
  CONSTRAINT ck_recoleccion_peso CHECK (peso_kg >= 0)
);
CREATE TABLE repuestos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
 categoria TEXT NOT NULL CHECK (categoria IN ('contenedor','pieza_contenedor','pieza_camion','herramienta','insumo')),
  unidad VARCHAR(15) NOT NULL DEFAULT 'unidad',
  stock INTEGER NOT NULL DEFAULT 0,
  stock_minimo INTEGER NOT NULL DEFAULT 0,
  activo INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT uq_repuestos_codigo UNIQUE (codigo)
);
CREATE TABLE movimientos_stock (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  repuesto_id INTEGER NOT NULL,
 tipo TEXT NOT NULL CHECK (tipo IN ('entrada','salida')),
  cantidad INTEGER NOT NULL,
  motivo VARCHAR(150) NOT NULL,
  usuario_id INTEGER NOT NULL,
  asignacion_id INTEGER NULL,
  creado_en TEXT NOT NULL,
  CONSTRAINT fk_mov_repuesto FOREIGN KEY (repuesto_id) REFERENCES repuestos (id),
  CONSTRAINT fk_mov_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_mov_asignacion FOREIGN KEY (asignacion_id) REFERENCES asignaciones (id) ON DELETE SET NULL,
  CONSTRAINT ck_mov_cantidad CHECK (cantidad > 0)
);
CREATE TABLE incidencias (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  contenedor_id INTEGER NOT NULL,
 tipo TEXT NOT NULL CHECK (tipo IN ('desborde','rotura','quema','falta_recoleccion','otro')),
  descripcion VARCHAR(500) NULL,
 estado TEXT NOT NULL DEFAULT 'abierta' CHECK (estado IN ('abierta','en_curso','resuelta','descartada')),
 prioridad TEXT NOT NULL DEFAULT 'media' CHECK (prioridad IN ('baja','media','alta')),
 origen TEXT NOT NULL CHECK (origen IN ('vecino','qr','cuadrilla','administrador')),
  reportado_por INTEGER NULL,
  cuadrilla_id INTEGER NULL,
  cantidad_reportes INTEGER NOT NULL DEFAULT 1,
  ip_hash CHAR(64) NULL,
  creado_en TEXT NOT NULL,
  asignado_en TEXT NULL,
  resuelto_en TEXT NULL,
  resolucion VARCHAR(300) NULL,
  CONSTRAINT fk_incidencias_contenedor FOREIGN KEY (contenedor_id) REFERENCES contenedores (id),
  CONSTRAINT fk_incidencias_usuario FOREIGN KEY (reportado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_incidencias_cuadrilla FOREIGN KEY (cuadrilla_id) REFERENCES cuadrillas (id) ON DELETE SET NULL,
  CONSTRAINT ck_incidencias_fechas CHECK (resuelto_en IS NULL OR resuelto_en >= creado_en)
);
CREATE TABLE incidencia_historial (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  incidencia_id INTEGER NOT NULL,
  estado_anterior VARCHAR(12) NULL,
  estado_nuevo VARCHAR(12) NOT NULL,
  usuario_id INTEGER NULL,
  comentario VARCHAR(300) NULL,
  creado_en TEXT NOT NULL,
  CONSTRAINT fk_historial_incidencia FOREIGN KEY (incidencia_id) REFERENCES incidencias (id) ON DELETE CASCADE,
  CONSTRAINT fk_historial_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
);
CREATE TABLE movimientos_centro (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  centro_id INTEGER NOT NULL,
 tipo_movimiento TEXT NOT NULL DEFAULT 'ingreso' CHECK (tipo_movimiento IN ('ingreso','egreso')),
  tipo_residuo_id INTEGER NOT NULL,
  camion_id INTEGER NULL,
  peso_ton NUMERIC NOT NULL,
  destino VARCHAR(100) NULL,
  usuario_id INTEGER NOT NULL,
  observacion VARCHAR(200) NULL,
  creado_en TEXT NOT NULL,
  CONSTRAINT fk_movcentro_centro FOREIGN KEY (centro_id) REFERENCES centros (id),
  CONSTRAINT fk_movcentro_tipo FOREIGN KEY (tipo_residuo_id) REFERENCES tipos_residuo (id),
  CONSTRAINT fk_movcentro_camion FOREIGN KEY (camion_id) REFERENCES camiones (id) ON DELETE SET NULL,
  CONSTRAINT fk_movcentro_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT ck_movcentro_peso CHECK (peso_ton > 0)
);
CREATE INDEX ix_usuarios_estado ON usuarios (estado);
CREATE INDEX ix_sesiones_usuario ON sesiones (usuario_id);
CREATE INDEX ix_notificaciones_usuario ON notificaciones (usuario_id, leida);
CREATE INDEX ix_auditoria_fecha ON auditoria (creado_en);
CREATE INDEX ix_contenedores_estado ON contenedores (estado);
CREATE INDEX ix_incidencias_estado ON incidencias (estado);
CREATE INDEX ix_incidencias_fecha ON incidencias (creado_en);
CREATE INDEX ix_incidencias_cont_tipo ON incidencias (contenedor_id, tipo, estado);
CREATE INDEX ix_asignaciones_fecha ON asignaciones (fecha);
CREATE INDEX ix_recolecciones_fecha ON recolecciones (creado_en);
CREATE INDEX ix_movcentro_fecha ON movimientos_centro (creado_en);
CREATE INDEX ix_mantenimientos_fecha ON mantenimientos (fecha_programada, estado);
CREATE VIEW v_incidencias_detalle AS
SELECT i.id, i.tipo, i.estado, i.prioridad, i.origen, i.cantidad_reportes,
       i.creado_en, i.asignado_en, i.resuelto_en, i.cuadrilla_id,
       c.id AS contenedor_id, c.codigo AS contenedor_codigo, c.direccion,
       c.latitud, c.longitud, z.id AS zona_id, z.nombre AS zona,
       t.nombre AS tipo_residuo
FROM incidencias i
JOIN contenedores c ON c.id = i.contenedor_id
JOIN zonas z ON z.id = c.zona_id
JOIN tipos_residuo t ON t.id = c.tipo_residuo_id;
CREATE VIEW v_ocupacion_centros AS
SELECT ce.id, ce.nombre, ce.tipo, ce.capacidad_ton, ce.ocupacion_ton,
       ROUND(ce.ocupacion_ton * 100 / ce.capacidad_ton, 1) AS porcentaje,
       z.nombre AS zona
FROM centros ce
JOIN zonas z ON z.id = ce.zona_id
WHERE ce.activo = 1;

