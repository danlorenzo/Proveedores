-- Usuario ficticio para probar el login en XAMPP.
-- Ejecuta este archivo después de importar bd_proveedores.sql.
-- No uses estas credenciales en producción y elimina esta cuenta al terminar.

USE biblioteca_proveedores;
START TRANSACTION;

INSERT INTO empresa_grupo (nombre, rtn)
VALUES ('Empresa Demo CAP', '00000000000000')
ON DUPLICATE KEY UPDATE id_empresa_grupo = LAST_INSERT_ID(id_empresa_grupo);
SET @id_empresa_demo = LAST_INSERT_ID();

INSERT INTO departamento (nombre)
VALUES ('Sistemas Demo')
ON DUPLICATE KEY UPDATE id_departamento = LAST_INSERT_ID(id_departamento);
SET @id_departamento_demo = LAST_INSERT_ID();

INSERT INTO cargo (nombre, id_departamento)
VALUES ('Administrador Demo', @id_departamento_demo)
ON DUPLICATE KEY UPDATE id_cargo = LAST_INSERT_ID(id_cargo);
SET @id_cargo_demo = LAST_INSERT_ID();

INSERT INTO usuario (
    dni, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
    celular, correo, id_cargo, id_empresa_grupo, correo_verificado
)
VALUES (
    '0801199901234', 'Usuario', 'Demo', 'Prueba', NULL,
    '99991234', 'usuario.demo@example.com', @id_cargo_demo,
    @id_empresa_demo, 1
)
ON DUPLICATE KEY UPDATE correo_verificado = 1;

INSERT INTO credencial_usuario (dni_usuario, password_hash)
VALUES (
    '0801199901234',
    '$2y$10$awjqd2RZqcPpPovEv/R4TOWjEGZFndCVC9j1vgvawok1xswPcgnTK'
)
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);

COMMIT;
