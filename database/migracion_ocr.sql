-- Extiende el esquema actual para guardar facturas, recibos de honorarios y
-- comprobantes de plataformas internacionales, conservando los datos actuales.
-- Ejecutar una sola vez desde phpMyAdmin en biblioteca_proveedores.
USE biblioteca_proveedores;

-- Un vendedor internacional puede no tener RTN hondureño.
ALTER TABLE proveedor MODIFY rtn CHAR(14) NULL;
SET @old_provider_rtn_check = (
  SELECT tc.CONSTRAINT_NAME
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
   WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
     AND tc.TABLE_NAME = 'proveedor'
     AND tc.CONSTRAINT_TYPE = 'CHECK'
     AND cc.CHECK_CLAUSE LIKE '%rtn%'
   LIMIT 1
);
SET @drop_provider_rtn_check = IF(
  @old_provider_rtn_check IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE proveedor DROP CONSTRAINT `', @old_provider_rtn_check, '`')
);
PREPARE drop_provider_rtn_check_stmt FROM @drop_provider_rtn_check;
EXECUTE drop_provider_rtn_check_stmt;
DEALLOCATE PREPARE drop_provider_rtn_check_stmt;
ALTER TABLE proveedor ADD CONSTRAINT chk_proveedor_rtn
  CHECK (rtn IS NULL OR rtn REGEXP '^[0-9]{14}$');

-- Un documento puede no tener un proveedor local asociado (p. ej. un vendedor
-- de Amazon sin RTN hondureño). El nombre/RTN leído queda guardado en el documento.
ALTER TABLE documento_compra
  MODIFY id_proveedor INT UNSIGNED NULL,
  MODIFY tipo_documento ENUM('factura','recibo','recibo_honorarios','amazon','otro') NOT NULL,
  ADD COLUMN nombre_emisor VARCHAR(150) NULL AFTER id_proveedor,
  ADD COLUMN rtn_emisor CHAR(14) NULL AFTER nombre_emisor,
  ADD COLUMN rtn_cliente CHAR(14) NULL AFTER dni_usuario,
  ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT 'HNL' AFTER total,
  ADD COLUMN referencia_externa VARCHAR(80) NULL AFTER numero_documento,
  ADD COLUMN subtotal_exento DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN subtotal_exonerado DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal_exento,
  ADD COLUMN subtotal_gravado_15 DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal_exonerado,
  ADD COLUMN descuento_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER isv,
  ADD COLUMN costo_envio DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER descuento_total,
  ADD COLUMN retencion_isr DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER costo_envio,
  ADD COLUMN texto_ocr MEDIUMTEXT NULL AFTER imagen_url,
  ADD INDEX idx_documento_rtn_cliente (rtn_cliente),
  ADD INDEX idx_documento_rtn_emisor (rtn_emisor);

-- Permite que documentos tipo recibo, comprobante Amazon u otros no tengan
-- número fiscal; cuando sí existe, se conserva el índice anti-duplicados.
SET @old_number_check = (
  SELECT tc.CONSTRAINT_NAME
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
   WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
     AND tc.TABLE_NAME = 'documento_compra'
     AND tc.CONSTRAINT_TYPE = 'CHECK'
     AND cc.CHECK_CLAUSE LIKE '%numero_documento%'
   LIMIT 1
);
SET @drop_number_check = IF(
  @old_number_check IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE documento_compra DROP CONSTRAINT `', @old_number_check, '`')
);
PREPARE drop_number_check_stmt FROM @drop_number_check;
EXECUTE drop_number_check_stmt;
DEALLOCATE PREPARE drop_number_check_stmt;

ALTER TABLE documento_compra
  ADD CONSTRAINT chk_documento_numero
  CHECK (tipo_documento IN ('recibo','amazon','otro') OR numero_documento IS NOT NULL);

-- Trazabilidad de los teléfonos públicos de proveedores. Los teléfonos
-- obtenidos en internet deben guardar una fuente y fecha de consulta.
ALTER TABLE proveedor_telefono
  ADD COLUMN origen_dato ENUM('manual','documento','internet') NOT NULL DEFAULT 'manual',
  ADD COLUMN fuente_url VARCHAR(500) NULL,
  ADD COLUMN verificado_en DATE NULL;
