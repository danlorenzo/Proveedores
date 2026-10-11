-- Carga repetible de información ficticia para revisar la interfaz.
-- Todo usuario, RTN, factura, teléfono y dirección añadido aquí es de DEMO.
-- Contraseña común de las cuentas DEMO: DemoCAP2026!
-- No ejecutar como datos reales ni publicar fuera del entorno local.
USE biblioteca_proveedores;
START TRANSACTION;

-- Cuentas nuevas. No modifica ni reemplaza las cuentas existentes.
INSERT INTO usuario (
  dni, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
  celular, correo, id_cargo, id_empresa_grupo, correo_verificado
)
SELECT demo.dni, demo.nombre, demo.segundo_nombre, demo.apellido, demo.segundo_apellido,
       demo.celular, demo.correo, cargo.id_cargo, empresa.id_empresa_grupo, 1
FROM (
  SELECT '0801199002001' dni, 'Carlos' nombre, 'Andrés' segundo_nombre, 'Mejía' apellido, 'Paz' segundo_apellido, '90001001' celular, 'carlos.mejia@example.com' correo, 'Oficial de compras internas' cargo, 'Mansiago' empresa
  UNION ALL SELECT '0801199002002', 'María', 'José', 'Castro', 'López', '90001002', 'maria.castro@example.com', 'Gerente general', 'Tecnicentro Didasa'
  UNION ALL SELECT '0801199002003', 'José', 'Manuel', 'Reyes', 'Cruz', '90001003', 'jose.reyes@example.com', 'Oficial de compras internacionales', 'Japan HN'
  UNION ALL SELECT '0801199002004', 'Ana', 'Lucía', 'Flores', NULL, '90001004', 'ana.flores@example.com', 'Gerente en Ventas', 'S&M'
  UNION ALL SELECT '0801199002005', 'Luis', 'Fernando', 'Pineda', 'Díaz', '90001005', 'luis.pineda@example.com', 'Oficial de compras internas', 'Blessing'
  UNION ALL SELECT '0801199002006', 'Sofía', 'Isabel', 'Vásquez', 'Mena', '90001006', 'sofia.vasquez@example.com', 'Gerente general', 'Mansiago'
  UNION ALL SELECT '0801199002007', 'Daniel', 'Alberto', 'Gómez', 'Rivas', '90001007', 'daniel.gomez@example.com', 'Oficial de compras internacionales', 'Tecnicentro Didasa'
  UNION ALL SELECT '0801199002008', 'Valeria', 'Elena', 'Molina', 'Paz', '90001008', 'valeria.molina@example.com', 'Gerente en Ventas', 'Japan HN'
  UNION ALL SELECT '0801199002009', 'Pedro', 'José', 'Santos', NULL, '90001009', 'pedro.santos@example.com', 'Pato de goma', 'S&M'
  UNION ALL SELECT '0801199002010', 'Gabriela', 'María', 'Ramos', 'Reyes', '90001010', 'gabriela.ramos@example.com', 'Oficial de compras internas', 'Blessing'
) demo
JOIN empresa_grupo empresa ON empresa.nombre = demo.empresa
JOIN cargo ON cargo.nombre = demo.cargo
WHERE NOT EXISTS (SELECT 1 FROM usuario u WHERE u.dni = demo.dni OR u.correo = demo.correo OR u.celular = demo.celular);

-- Hash generado con password_hash() de PHP para DemoCAP2026! (bcrypt).
INSERT INTO credencial_usuario (dni_usuario, password_hash)
SELECT demo.dni, '$2y$10$pg7.bmB49aQ4aY7vzYLUAu.G9q27Qv9rZJoL2slfjWtJuJ1oYPNrm'
FROM (
  SELECT '0801199002001' dni UNION ALL SELECT '0801199002002' UNION ALL SELECT '0801199002003'
  UNION ALL SELECT '0801199002004' UNION ALL SELECT '0801199002005' UNION ALL SELECT '0801199002006'
  UNION ALL SELECT '0801199002007' UNION ALL SELECT '0801199002008' UNION ALL SELECT '0801199002009'
  UNION ALL SELECT '0801199002010'
) demo JOIN usuario u ON u.dni = demo.dni
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);

-- 18 proveedores de muestra, con RTN y contactos inventados.
INSERT INTO proveedor (rtn, nombre_empresa, id_categoria, logo_url, direccion, correo, dni_usuario_registro)
SELECT muestra.rtn, muestra.nombre, categoria.id_categoria,
       CONCAT('uploads/proveedores/demo-', muestra.slug, '.svg'),
       muestra.direccion, CONCAT('contacto.', muestra.slug, '@example.com'), usuario.dni
FROM (
  SELECT '88888888888801' rtn, 'Diunsa' nombre, 'Electrodomésticos' categoria, 'diunsa' slug, 'Dirección de demostración, Tegucigalpa' direccion, '0801199002001' dni
  UNION ALL SELECT '88888888888802', 'Jetstereo', 'Electrodomésticos', 'jetstereo', 'Dirección de demostración, San Pedro Sula', '0801199002002'
  UNION ALL SELECT '88888888888803', 'Lady Lee', 'Electrodomésticos', 'lady-lee', 'Dirección de demostración, Tegucigalpa', '0801199002003'
  UNION ALL SELECT '88888888888804', 'Acosa', 'Papelería', 'acosa', 'Dirección de demostración, San Pedro Sula', '0801199002004'
  UNION ALL SELECT '88888888888805', 'Office Depot', 'Papelería', 'office-depot', 'Dirección de demostración, Tegucigalpa', '0801199002005'
  UNION ALL SELECT '88888888888806', 'Grupo Q', 'Repuestos', 'grupo-q', 'Dirección de demostración, Tegucigalpa', '0801199002006'
  UNION ALL SELECT '88888888888807', 'Excel Automotriz', 'Repuestos', 'excel', 'Dirección de demostración, San Pedro Sula', '0801199002007'
  UNION ALL SELECT '88888888888808', 'Larach y Cía. Demo', 'Equipos', 'larach-demo', 'Dirección de demostración, Tegucigalpa', '0801199002008'
  UNION ALL SELECT '88888888888809', 'Tecnocomp', 'Redes', 'tecnocomp', 'Dirección de demostración, Tegucigalpa', '0801199002009'
  UNION ALL SELECT '88888888888810', 'CompuMall', 'Redes', 'compumall', 'Dirección de demostración, San Pedro Sula', '0801199002010'
  UNION ALL SELECT '88888888888811', 'Hondutel Servicios', 'Servicios', 'hondutel', 'Dirección de demostración, Tegucigalpa', '0801199002001'
  UNION ALL SELECT '88888888888812', 'ServiRed Honduras', 'Servicios', 'servired', 'Dirección de demostración, La Ceiba', '0801199002002'
  UNION ALL SELECT '88888888888813', 'Textiles del Valle', 'Textil', 'textiles-valle', 'Dirección de demostración, Villanueva', '0801199002003'
  UNION ALL SELECT '88888888888814', 'Moda Centro', 'Textil', 'moda-centro', 'Dirección de demostración, Tegucigalpa', '0801199002004'
  UNION ALL SELECT '88888888888815', 'Hogar y Más', 'Electrodomésticos', 'hogar-mas', 'Dirección de demostración, Choloma', '0801199002005'
  UNION ALL SELECT '88888888888816', 'Suministros CAP Demo', 'Papelería', 'suministros-cap', 'Dirección de demostración, Tegucigalpa', '0801199002006'
  UNION ALL SELECT '88888888888817', 'Repuestos del Norte', 'Repuestos', 'repuestos-norte', 'Dirección de demostración, San Pedro Sula', '0801199002007'
  UNION ALL SELECT '88888888888818', 'Redes y Soluciones', 'Redes', 'redes-soluciones', 'Dirección de demostración, Tegucigalpa', '0801199002008'
) muestra
JOIN categoria ON categoria.nombre = muestra.categoria
JOIN usuario ON usuario.dni = muestra.dni
WHERE NOT EXISTS (SELECT 1 FROM proveedor existente WHERE existente.rtn = muestra.rtn);

-- Completa también con imágenes de muestra las cuatro fichas que ya existían.
UPDATE proveedor
SET logo_url = CASE rtn
  WHEN '99999999999991' THEN 'uploads/proveedores/demo-pacasa-original.svg'
  WHEN '99999999999992' THEN 'uploads/proveedores/demo-ipsa-original.svg'
  WHEN '99999999999993' THEN 'uploads/proveedores/demo-la-mundial-original.svg'
  WHEN '99999999999994' THEN 'uploads/proveedores/demo-larach-original.svg'
  ELSE logo_url
END
WHERE rtn IN ('99999999999991', '99999999999992', '99999999999993', '99999999999994')
  AND (logo_url IS NULL OR logo_url = '');

-- Teléfono ficticio único por proveedor.
INSERT INTO proveedor_telefono (id_proveedor, numero, tipo, es_whatsapp)
SELECT p.id_proveedor, m.numero, IF(LEFT(m.numero, 1) = '2', 'telefono', 'celular'), IF(LEFT(m.numero, 1) = '2', 0, 1)
FROM (
  SELECT '88888888888801' rtn, '90002001' numero UNION ALL SELECT '88888888888802', '90002002'
  UNION ALL SELECT '88888888888803', '90002003' UNION ALL SELECT '88888888888804', '90002004'
  UNION ALL SELECT '88888888888805', '90002005' UNION ALL SELECT '88888888888806', '90002006'
  UNION ALL SELECT '88888888888807', '90002007' UNION ALL SELECT '88888888888808', '90002008'
  UNION ALL SELECT '88888888888809', '90002009' UNION ALL SELECT '88888888888810', '90002010'
  UNION ALL SELECT '88888888888811', '90002011' UNION ALL SELECT '88888888888812', '90002012'
  UNION ALL SELECT '88888888888813', '90002013' UNION ALL SELECT '88888888888814', '90002014'
  UNION ALL SELECT '88888888888815', '90002015' UNION ALL SELECT '88888888888816', '90002016'
  UNION ALL SELECT '88888888888817', '90002017' UNION ALL SELECT '88888888888818', '90002018'
) m JOIN proveedor p ON p.rtn = m.rtn
ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), es_whatsapp = VALUES(es_whatsapp);

-- Calificaciones variadas de las cuentas DEMO para alimentar filtros y promedio.
INSERT INTO calificacion (id_proveedor, dni_usuario, estrellas, comentario, fecha)
SELECT p.id_proveedor, u.dni,
       1 + MOD(p.id_proveedor * 3 + CAST(RIGHT(u.dni, 2) AS UNSIGNED), 5),
       'Opinión ficticia de demostración', DATE_SUB('2026-10-10 12:00:00', INTERVAL MOD(p.id_proveedor + CAST(RIGHT(u.dni, 2) AS UNSIGNED), 90) DAY)
FROM proveedor p
CROSS JOIN usuario u
WHERE u.dni BETWEEN '0801199002001' AND '0801199002010'
  AND MOD(p.id_proveedor + CAST(RIGHT(u.dni, 1) AS UNSIGNED), 4) = 0
ON DUPLICATE KEY UPDATE estrellas = VALUES(estrellas), comentario = VALUES(comentario);

-- 12 documentos en cada uno de los 4 proveedores que ya estaban en la BD,
-- para comprobar la paginación de 10 elementos, y 2 documentos por proveedor nuevo.
INSERT INTO documento_compra (
  id_proveedor, id_empresa_grupo, dni_usuario, tipo_documento, numero_documento,
  cai, fecha_documento, atendido_por, subtotal, isv, total, imagen_url, metodo_ingreso
)
SELECT p.id_proveedor, empresa.id_empresa_grupo, usuario.dni, 'factura',
       CONCAT('DEMO-', RIGHT(p.rtn, 2), '-', LPAD(m.n, 4, '0')),
       CONCAT('CAI-DEMO-', RIGHT(p.rtn, 2), '-', LPAD(m.n, 4, '0')),
       DATE_SUB('2026-10-10', INTERVAL m.n DAY),
       CONCAT(usuario.primer_nombre, ' ', usuario.primer_apellido),
       m.subtotal, ROUND(m.subtotal * 0.15, 2), ROUND(m.subtotal * 1.15, 2),
       'uploads/proveedores/factura-demo.svg', 'manual'
FROM (
  SELECT 1 n, 850.00 subtotal UNION ALL SELECT 2, 1250.00 UNION ALL SELECT 3, 2100.00
  UNION ALL SELECT 4, 760.00 UNION ALL SELECT 5, 3400.00 UNION ALL SELECT 6, 980.00
  UNION ALL SELECT 7, 1750.00 UNION ALL SELECT 8, 520.00 UNION ALL SELECT 9, 2890.00
  UNION ALL SELECT 10, 1120.00 UNION ALL SELECT 11, 640.00 UNION ALL SELECT 12, 1980.00
) m
JOIN proveedor p ON p.rtn IN ('99999999999991', '99999999999992', '99999999999993', '99999999999994')
JOIN empresa_grupo empresa ON empresa.nombre = ELT(1 + MOD(m.n + p.id_proveedor, 5), 'Mansiago', 'Tecnicentro Didasa', 'Japan HN', 'S&M', 'Blessing')
JOIN usuario ON usuario.dni = CONCAT('0801199002', LPAD(1 + MOD(m.n + p.id_proveedor, 10), 3, '0'))
ON DUPLICATE KEY UPDATE
  fecha_documento = VALUES(fecha_documento), subtotal = VALUES(subtotal), isv = VALUES(isv), total = VALUES(total),
  imagen_url = VALUES(imagen_url), dni_usuario = VALUES(dni_usuario), id_empresa_grupo = VALUES(id_empresa_grupo);

INSERT INTO documento_compra (
  id_proveedor, id_empresa_grupo, dni_usuario, tipo_documento, numero_documento,
  cai, fecha_documento, atendido_por, subtotal, isv, total, imagen_url, metodo_ingreso
)
SELECT p.id_proveedor, empresa.id_empresa_grupo, usuario.dni, 'factura',
       CONCAT('DEMO-', RIGHT(p.rtn, 2), '-', LPAD(m.n, 4, '0')),
       CONCAT('CAI-DEMO-', RIGHT(p.rtn, 2), '-', LPAD(m.n, 4, '0')),
       DATE_SUB('2026-10-10', INTERVAL m.n DAY),
       CONCAT(usuario.primer_nombre, ' ', usuario.primer_apellido),
       500.00 + (m.n * 125), ROUND((500.00 + (m.n * 125)) * 0.15, 2),
       ROUND((500.00 + (m.n * 125)) * 1.15, 2),
       'uploads/proveedores/factura-demo.svg', 'manual'
FROM (
  SELECT 1 n UNION ALL SELECT 2
) m
JOIN proveedor p ON p.rtn BETWEEN '88888888888801' AND '88888888888818'
JOIN empresa_grupo empresa ON empresa.nombre = ELT(1 + MOD(m.n + p.id_proveedor, 5), 'Mansiago', 'Tecnicentro Didasa', 'Japan HN', 'S&M', 'Blessing')
JOIN usuario ON usuario.dni = CONCAT('0801199002', LPAD(1 + MOD(m.n + p.id_proveedor, 10), 3, '0'))
ON DUPLICATE KEY UPDATE
  fecha_documento = VALUES(fecha_documento), subtotal = VALUES(subtotal), isv = VALUES(isv), total = VALUES(total),
  imagen_url = VALUES(imagen_url), dni_usuario = VALUES(dni_usuario), id_empresa_grupo = VALUES(id_empresa_grupo);

-- Detalle demostrativo por documento. El NOT EXISTS permite reejecutar la carga.
INSERT INTO detalle_documento (id_documento, descripcion, cantidad, precio_unitario, descuento, imagen_url)
SELECT dc.id_documento, CONCAT('Artículo de muestra ', RIGHT(dc.numero_documento, 4)), 1, dc.subtotal, 0,
       'uploads/proveedores/articulo-demo.svg'
FROM documento_compra dc
WHERE dc.numero_documento LIKE 'DEMO-%'
  AND NOT EXISTS (
    SELECT 1 FROM detalle_documento dd
     WHERE dd.id_documento = dc.id_documento
       AND dd.descripcion = CONCAT('Artículo de muestra ', RIGHT(dc.numero_documento, 4))
  );

COMMIT;
