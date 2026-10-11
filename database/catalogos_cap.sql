-- Catálogos de prueba para una base ya importada en XAMPP.
-- Los RTN son ficticios: reemplázalos por los RTN reales antes de producción.
-- Ejecuta usuario_demo.sql antes de este archivo para cargar las fichas y
-- calificaciones de muestra vinculadas al usuario de demostración.
USE biblioteca_proveedores;

-- Evita usar un número telefónico ya asociado a otra cuenta.
SET @phone_unique_exists = (
  SELECT COUNT(*)
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'usuario'
     AND INDEX_NAME = 'uq_usuario_celular'
);
SET @add_phone_unique = IF(
  @phone_unique_exists = 0,
  'ALTER TABLE usuario ADD UNIQUE INDEX uq_usuario_celular (celular)',
  'SELECT 1'
);
PREPARE add_phone_unique_stmt FROM @add_phone_unique;
EXECUTE add_phone_unique_stmt;
DEALLOCATE PREPARE add_phone_unique_stmt;

-- "Oficial de compras internacionales" supera los 30 caracteres originales.
ALTER TABLE cargo MODIFY nombre VARCHAR(50) NOT NULL;

-- En la base ya importada, sustituye la validación anterior de celular para
-- aceptar números fijos que comienzan con 2 además de móviles.
SET @phone_check_name = (
  SELECT tc.CONSTRAINT_NAME
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
   WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
     AND tc.TABLE_NAME = 'usuario'
     AND tc.CONSTRAINT_TYPE = 'CHECK'
     AND (
       tc.CONSTRAINT_NAME = 'chk_usuario_celular'
       OR cc.CHECK_CLAUSE LIKE '%3789%'
     )
   ORDER BY (tc.CONSTRAINT_NAME = 'chk_usuario_celular') DESC
   LIMIT 1
);
SET @drop_phone_check = IF(
  @phone_check_name IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE usuario DROP CONSTRAINT `', @phone_check_name, '`')
);
PREPARE drop_phone_check_stmt FROM @drop_phone_check;
EXECUTE drop_phone_check_stmt;
DEALLOCATE PREPARE drop_phone_check_stmt;
ALTER TABLE usuario ADD CONSTRAINT chk_usuario_celular
  CHECK (celular REGEXP '^[23789][0-9]{7}$');

INSERT INTO empresa_grupo (nombre, rtn) VALUES
  ('Mansiago',           '11111111111111'),
  ('Tecnicentro Didasa', '22222222222222'),
  ('Japan HN',           '33333333333333'),
  ('S&M',                '44444444444444'),
  ('Blessing',           '55555555555555')
ON DUPLICATE KEY UPDATE id_empresa_grupo = LAST_INSERT_ID(id_empresa_grupo);

INSERT INTO departamento (nombre) VALUES
  ('Compras internas'),
  ('Gerencia general'),
  ('Compras internacionales'),
  ('Ventas'),
  ('Pruebas y creatividad')
ON DUPLICATE KEY UPDATE id_departamento = LAST_INSERT_ID(id_departamento);

-- Mueve los cargos de prueba ya creados a su departamento correspondiente.
UPDATE cargo
INNER JOIN (
  SELECT 'Oficial de compras internas' AS nombre, 'Compras internas' AS departamento
  UNION ALL SELECT 'Gerente general', 'Gerencia general'
  UNION ALL SELECT 'Oficial de compras internacionales', 'Compras internacionales'
  UNION ALL SELECT 'Gerente en Ventas', 'Ventas'
  UNION ALL SELECT 'Pato de goma', 'Pruebas y creatividad'
) AS catalogo ON catalogo.nombre = cargo.nombre
INNER JOIN departamento ON departamento.nombre = catalogo.departamento
SET cargo.id_departamento = departamento.id_departamento;

INSERT INTO cargo (nombre, id_departamento)
SELECT catalogo.nombre, departamento.id_departamento
FROM (
  SELECT 'Oficial de compras internas' AS nombre, 'Compras internas' AS departamento
  UNION ALL SELECT 'Gerente general', 'Gerencia general'
  UNION ALL SELECT 'Oficial de compras internacionales', 'Compras internacionales'
  UNION ALL SELECT 'Gerente en Ventas', 'Ventas'
  UNION ALL SELECT 'Pato de goma', 'Pruebas y creatividad'
) AS catalogo
INNER JOIN departamento ON departamento.nombre = catalogo.departamento
ON DUPLICATE KEY UPDATE id_cargo = LAST_INSERT_ID(id_cargo);

-- Categorías y proveedores de demostración. Los RTN son ficticios y deben
-- sustituirse antes de usar esta base fuera del entorno de pruebas.
INSERT INTO categoria (nombre) VALUES
  ('Papelería'), ('Equipos'), ('Repuestos'), ('Redes'),
  ('Electrodomésticos'), ('Servicios'), ('Textil')
ON DUPLICATE KEY UPDATE id_categoria = LAST_INSERT_ID(id_categoria);

-- Requiere que usuario_demo.sql ya haya creado el usuario de prueba.
INSERT INTO proveedor (rtn, nombre_empresa, id_categoria, direccion, dni_usuario_registro)
SELECT muestras.rtn, muestras.nombre_empresa, categoria.id_categoria,
       muestras.direccion, usuario.dni
FROM (
  SELECT '99999999999991' AS rtn, 'PACASA' AS nombre_empresa,
         'Papelería' AS categoria, 'Barrio El Benque, 4 Calle, 8 y 9 Avenida, San Pedro Sula' AS direccion
  UNION ALL SELECT '99999999999992', 'IPSA', 'Equipos', 'Blvd. del Este, 1 Calle, 12-14 Avenida S.E., San Pedro Sula'
  UNION ALL SELECT '99999999999993', 'La Mundial', 'Repuestos', 'Honduras; cobertura nacional'
  UNION ALL SELECT '99999999999994', 'Larach y Cía.', 'Equipos', 'Tegucigalpa y San Pedro Sula'
) AS muestras
INNER JOIN categoria ON categoria.nombre = muestras.categoria
INNER JOIN usuario ON usuario.dni = '0801199901234'
ON DUPLICATE KEY UPDATE
  nombre_empresa = VALUES(nombre_empresa),
  id_categoria = VALUES(id_categoria),
  direccion = VALUES(direccion);

-- Teléfonos y calificaciones de demostración; los valores de calificación
-- son inventados para que se puedan apreciar los filtros de ordenamiento.
INSERT INTO proveedor_telefono (id_proveedor, numero, tipo, es_whatsapp)
SELECT proveedor.id_proveedor, muestras.numero, muestras.tipo, muestras.whatsapp
FROM (
  SELECT '99999999999991' AS rtn, '31520089' AS numero, 'celular' AS tipo, 1 AS whatsapp
  UNION ALL SELECT '99999999999992', '25274772', 'telefono', 0
  UNION ALL SELECT '99999999999993', '31559145', 'celular', 1
  UNION ALL SELECT '99999999999994', '22901100', 'telefono', 0
) AS muestras
INNER JOIN proveedor ON proveedor.rtn = muestras.rtn
ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), es_whatsapp = VALUES(es_whatsapp);

INSERT INTO calificacion (id_proveedor, dni_usuario, estrellas, comentario)
SELECT proveedor.id_proveedor, usuario.dni, muestras.estrellas, 'Calificación ficticia de demostración'
FROM (
  SELECT '99999999999991' AS rtn, 4 AS estrellas
  UNION ALL SELECT '99999999999992', 5
  UNION ALL SELECT '99999999999993', 3
  UNION ALL SELECT '99999999999994', 2
) AS muestras
INNER JOIN proveedor ON proveedor.rtn = muestras.rtn
INNER JOIN usuario ON usuario.dni = '0801199901234'
ON DUPLICATE KEY UPDATE estrellas = VALUES(estrellas), comentario = VALUES(comentario);

-- Facturas y líneas de compra ficticias para que la ficha de cada proveedor
-- tenga contenido de muestra en el historial.
INSERT INTO documento_compra (
  id_proveedor, id_empresa_grupo, dni_usuario, tipo_documento,
  numero_documento, cai, fecha_documento, subtotal, isv, total, metodo_ingreso
)
SELECT proveedor.id_proveedor, empresa.id_empresa_grupo, usuario.dni, 'factura',
       muestras.numero_documento, muestras.cai, muestras.fecha_documento,
       muestras.subtotal, muestras.isv, muestras.total, 'manual'
FROM (
  SELECT '99999999999991' AS rtn, 'DEMO-2026-0001' AS numero_documento, 'CAI-DEMO-0001' AS cai,
         '2026-10-02' AS fecha_documento, 1200.00 AS subtotal, 180.00 AS isv, 1380.00 AS total
  UNION ALL SELECT '99999999999992', 'DEMO-2026-0002', 'CAI-DEMO-0002', '2026-10-04', 8500.00, 1275.00, 9775.00
  UNION ALL SELECT '99999999999993', 'DEMO-2026-0003', 'CAI-DEMO-0003', '2026-10-06', 3400.00, 510.00, 3910.00
  UNION ALL SELECT '99999999999994', 'DEMO-2026-0004', 'CAI-DEMO-0004', '2026-10-08', 6250.00, 937.50, 7187.50
) AS muestras
INNER JOIN proveedor ON proveedor.rtn = muestras.rtn
INNER JOIN empresa_grupo empresa ON empresa.nombre = 'Mansiago'
INNER JOIN usuario ON usuario.dni = '0801199901234'
ON DUPLICATE KEY UPDATE
  fecha_documento = VALUES(fecha_documento),
  subtotal = VALUES(subtotal), isv = VALUES(isv), total = VALUES(total);

INSERT INTO detalle_documento (id_documento, descripcion, cantidad, precio_unitario, descuento)
SELECT documento.id_documento, muestras.descripcion, muestras.cantidad,
       muestras.precio_unitario, 0
FROM (
  SELECT 'DEMO-2026-0001' AS numero_documento, 'Materiales de oficina' AS descripcion, 2 AS cantidad, 600.00 AS precio_unitario
  UNION ALL SELECT 'DEMO-2026-0002', 'Mobiliario de oficina', 5, 1700.00
  UNION ALL SELECT 'DEMO-2026-0003', 'Herramientas y repuestos', 4, 850.00
  UNION ALL SELECT 'DEMO-2026-0004', 'Equipo para mantenimiento', 5, 1250.00
) AS muestras
INNER JOIN documento_compra documento ON documento.numero_documento = muestras.numero_documento
INNER JOIN proveedor ON proveedor.id_proveedor = documento.id_proveedor
WHERE proveedor.rtn IN ('99999999999991', '99999999999992', '99999999999993', '99999999999994')
  AND NOT EXISTS (
    SELECT 1 FROM detalle_documento existente
     WHERE existente.id_documento = documento.id_documento
       AND existente.descripcion = muestras.descripcion
  );
