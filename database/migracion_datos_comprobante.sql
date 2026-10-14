-- Permite guardar solo los importes/cantidades que aparecen en el comprobante.
-- Los valores desconocidos quedan NULL; la aplicación no inventa ni calcula cifras.
-- Ejecutar una sola vez en biblioteca_proveedores para bases ya importadas.
USE biblioteca_proveedores;

ALTER TABLE documento_compra
  MODIFY subtotal DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY subtotal_exento DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY subtotal_exonerado DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY subtotal_gravado_15 DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY isv DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY descuento_total DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY costo_envio DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY retencion_isr DECIMAL(12,2) NULL DEFAULT NULL;

ALTER TABLE detalle_documento
  MODIFY cantidad DECIMAL(10,2) NULL DEFAULT NULL,
  MODIFY precio_unitario DECIMAL(12,2) NULL DEFAULT NULL,
  MODIFY descuento DECIMAL(12,2) NULL DEFAULT NULL;
