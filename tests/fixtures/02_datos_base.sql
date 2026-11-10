-- =====================================================================
-- SiGeRU - Datos base (catálogos y parámetros)
-- Se cargan siempre, tanto en producción como en el entorno de pruebas.
-- =====================================================================

-- Roles del sistema
INSERT INTO roles (id, nombre, descripcion) VALUES
  (1, 'administrador', 'Personal municipal que gestiona usuarios, recursos, incidencias e informes'),
  (2, 'cuadrilla', 'Integrante de una cuadrilla de recolección o mantenimiento'),
  (3, 'operario', 'Operario de un centro de acopio, planta de clasificación o vertedero'),
  (4, 'vecino', 'Vecino que consulta el mapa y reporta incidencias');

-- Parámetros configurables desde el backoffice
INSERT INTO parametros (clave, valor, tipo, descripcion, publico) VALUES
  ('nombre_organizacion', 'Municipio piloto - SiGeRU', 'texto', 'Nombre que se muestra en las interfaces', 1),
  ('idioma_por_defecto', 'es', 'texto', 'Idioma inicial de las interfaces (es / en)', 1),
  ('registro_vecino_autoaprobado', '1', 'booleano', 'Los vecinos quedan activos al registrarse (1) o esperan aprobación (0)', 0),
  ('jwt_duracion_minutos', '480', 'entero', 'Duración de la sesión (token) en minutos', 0),
  ('login_max_intentos', '5', 'entero', 'Intentos fallidos de inicio de sesión antes del bloqueo', 0),
  ('login_bloqueo_minutos', '15', 'entero', 'Minutos de bloqueo luego de superar los intentos', 0),
  ('password_largo_minimo', '10', 'entero', 'Largo mínimo de la contraseña', 1),
  ('umbral_ocupacion_centro', '85', 'entero', 'Porcentaje de ocupación de un centro que genera alerta', 0),
  ('umbral_llenado_contenedor', '80', 'entero', 'Nivel de llenado (%) a partir del cual un contenedor se considera crítico', 0),
  ('desbordes_contenedor_critico', '3', 'entero', 'Desbordes en 30 días para marcar un contenedor como crítico', 0),
  ('dias_aviso_mantenimiento', '3', 'entero', 'Días de anticipación para avisar un mantenimiento programado', 0),
  ('qr_reportes_por_hora', '5', 'entero', 'Reportes anónimos permitidos por hora desde una misma IP', 0),
  ('mapa_latitud', '-34.8960', 'decimal', 'Latitud inicial del mapa', 1),
  ('mapa_longitud', '-56.1500', 'decimal', 'Longitud inicial del mapa', 1),
  ('mapa_zoom', '13', 'entero', 'Zoom inicial del mapa', 1),
  ('recorrido_dinamico', '1', 'booleano', 'El recorrido del día de la cuadrilla se reordena por distancia y urgencia (1) o sigue el orden de la ruta (0)', 0),
  ('consumo_camion_litros_100km', '100', 'decimal', 'Consumo de referencia de un camión recolector (litros cada 100 km) para estimar el combustible de un recorrido', 0),
  ('velocidad_camion_kmh', '20', 'entero', 'Velocidad media de referencia del camión entre contenedores (km/h) para estimar la duración de un recorrido', 0),
  ('minutos_por_contenedor', '2', 'decimal', 'Minutos de referencia para vaciar un contenedor, usados en la estimación de la duración de un recorrido', 0);

-- Zonas (barrios del área piloto, con su centro aproximado para el mapa)
INSERT INTO zonas (id, nombre, descripcion, latitud, longitud) VALUES
  (1, 'Buceo', 'Barrio Buceo, Municipio E', -34.895500, -56.133000),
  (2, 'Pocitos', 'Barrio Pocitos, Municipio CH', -34.911000, -56.150500),
  (3, 'Malvín', 'Barrio Malvín, Municipio E', -34.889000, -56.108000),
  (4, 'Unión', 'Barrio Unión, Municipio E', -34.871000, -56.139000),
  (5, 'Parque Batlle', 'Barrio Parque Batlle, Municipio CH', -34.896000, -56.156000),
  (6, 'La Blanqueada', 'Barrio La Blanqueada, Municipio CH', -34.884000, -56.156000),
  (7, 'Cordón', 'Barrio Cordón, Municipio B', -34.902000, -56.178000),
  (8, 'Centro', 'Barrio Centro, Municipio B', -34.906000, -56.191500);

-- Tipos de residuo
INSERT INTO tipos_residuo (id, nombre, color, reciclable, descripcion) VALUES
  (1, 'Domiciliario', '#4B5563', 0, 'Residuos mezclados de los hogares'),
  (2, 'Reciclables secos', '#F59E0B', 1, 'Papel, cartón, plásticos y metales limpios'),
  (3, 'Vidrio', '#10B981', 1, 'Botellas y frascos de vidrio'),
  (4, 'Orgánico compostable', '#92400E', 1, 'Restos de frutas, verduras y yerba'),
  (5, 'Poda y voluminosos', '#7C3AED', 0, 'Restos de poda, muebles y objetos grandes'),
  (6, 'Pilas y electrónicos', '#DC2626', 1, 'Pilas, baterías y aparatos eléctricos y electrónicos');
