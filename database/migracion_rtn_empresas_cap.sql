-- Sustituye los RTN de prueba por los RTN oficiales de Grupo CAP.
-- Ejecutar una sola vez en una base existente biblioteca_proveedores.
-- Actualiza las filas en el sitio para conservar las relaciones por ID.
USE biblioteca_proveedores;
START TRANSACTION;

UPDATE empresa_grupo
   SET nombre = 'DISTRIBUIDORA MANSIAGO', rtn = '08019024052985'
 WHERE rtn = '11111111111111';

UPDATE empresa_grupo
   SET nombre = 'TECNICENTRO DIDASA', rtn = '08019020219802'
 WHERE rtn = '22222222222222';

UPDATE empresa_grupo
   SET nombre = 'JAPAN HN S.A. DE C.V.', rtn = '08019026304811'
 WHERE rtn = '33333333333333';

UPDATE empresa_grupo
   SET nombre = 'INVERSIONES CASTRO', rtn = '08019019103201'
 WHERE rtn = '44444444444444';

UPDATE empresa_grupo
   SET nombre = 'AUTOREPUESTOS BLESSING', rtn = '08011988090350'
 WHERE rtn = '55555555555555';

UPDATE empresa_grupo
   SET nombre = 'CAP (SINDY CASTRO)', rtn = '07151992000339'
 WHERE rtn = '00000000000000';

-- Solo agrega la empresa que no tenía fila de prueba en el catálogo.
INSERT INTO empresa_grupo (nombre, rtn)
SELECT 'AUTOCARROCERIA BLESSING', '08019025219107'
 WHERE NOT EXISTS (
   SELECT 1 FROM empresa_grupo
    WHERE nombre = 'AUTOCARROCERIA BLESSING'
       OR rtn = '08019025219107'
 );

COMMIT;
