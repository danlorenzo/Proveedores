-- Ajuste para instalaciones que ya ejecutaron la versión anterior de
-- migracion_ocr.sql, que añadía columnas de ISV 18% y 15% separadas.
-- Ejecutar una sola vez después de hacer una copia de seguridad.
USE biblioteca_proveedores;

-- Conserva como ISV total el importe que ya estaba repartido entre las tasas.
UPDATE documento_compra
   SET isv = COALESCE(isv_15, 0) + COALESCE(isv_18, 0)
 WHERE COALESCE(isv_15, 0) <> 0 OR COALESCE(isv_18, 0) <> 0;

ALTER TABLE documento_compra
  DROP COLUMN subtotal_gravado_18,
  DROP COLUMN isv_15,
  DROP COLUMN isv_18;
