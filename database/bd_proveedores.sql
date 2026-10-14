-- =====================================================================
-- Biblioteca de Proveedores - Grupo CAP   (versión 2)
-- MySQL 8 / MariaDB 10.2+ (XAMPP + phpMyAdmin), motor InnoDB (ACID)
-- Normalización 3FN. Los campos numéricos (DNI, RTN, celular) se guardan
-- SOLO con dígitos; los guiones los pone la aplicación al mostrarlos.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS biblioteca_proveedores
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE biblioteca_proveedores;

-- ---------------------------------------------------------------------
-- 1. EMPRESAS DEL GRUPO CAP (las que compran; cada usuario pertenece a una)
-- ---------------------------------------------------------------------
CREATE TABLE empresa_grupo (
  id_empresa_grupo TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(50) NOT NULL UNIQUE,
  rtn              CHAR(14) NOT NULL UNIQUE,   -- exactamente 14 dígitos
  CHECK (rtn REGEXP '^[0-9]{14}$')
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. USUARIOS
-- ---------------------------------------------------------------------
CREATE TABLE departamento (
  id_departamento TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,  -- hasta 255
  nombre          VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE cargo (
  id_cargo        TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,  -- hasta 255
  nombre          VARCHAR(50) NOT NULL,
  id_departamento TINYINT UNSIGNED NOT NULL,
  UNIQUE (nombre, id_departamento),
  FOREIGN KEY (id_departamento) REFERENCES departamento (id_departamento)
) ENGINE=InnoDB;

-- El DNI (13 dígitos, ej. 0801199912345) es la llave del usuario.
-- La aplicación lo muestra como 0801-1999-12345.
CREATE TABLE usuario (
  dni                CHAR(13) PRIMARY KEY,
  primer_nombre      VARCHAR(15) NOT NULL,
  segundo_nombre     VARCHAR(15) NULL,
  primer_apellido    VARCHAR(15) NOT NULL,
  segundo_apellido   VARCHAR(15) NULL,
  celular            CHAR(8) NOT NULL,         -- Honduras: 2 fijo; 3, 7, 8 o 9 móvil
  correo             VARCHAR(50) NOT NULL UNIQUE,
  id_cargo           TINYINT UNSIGNED NOT NULL,
  id_empresa_grupo   TINYINT UNSIGNED NOT NULL,  -- empresa del grupo CAP a la que pertenece
  foto_perfil_url    VARCHAR(100) NULL,        -- ruta del archivo, no la imagen
  correo_verificado  TINYINT(1) NOT NULL DEFAULT 0,
  token_verificacion CHAR(64) NULL UNIQUE,     -- hash del enlace enviado al correo
  token_expira       DATETIME NULL,            -- la app lo calcula: ahora + 24 h
  ultima_vista_notif DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- última vez que abrió la campana
  fecha_registro     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (celular),
  CHECK (dni REGEXP '^[0-9]{13}$'),
  CHECK (celular REGEXP '^[23789][0-9]{7}$'),
  CHECK (correo REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$'),
  FOREIGN KEY (id_cargo) REFERENCES cargo (id_cargo),
  FOREIGN KEY (id_empresa_grupo) REFERENCES empresa_grupo (id_empresa_grupo)
) ENGINE=InnoDB;

-- La contraseña va aparte (1 a 1). Se guarda SOLO el hash (PHP: password_hash).
CREATE TABLE credencial_usuario (
  dni_usuario   CHAR(13) PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL,
  fecha_cambio  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (dni_usuario) REFERENCES usuario (dni) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. PROVEEDORES
-- ---------------------------------------------------------------------
CREATE TABLE categoria (
  id_categoria TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE proveedor (
  id_proveedor         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rtn                  CHAR(14) NULL UNIQUE,            -- 14 dígitos si existe; algunos vendedores internacionales no tienen RTN hondureño
  nombre_empresa       VARCHAR(150) NOT NULL,
  id_categoria         TINYINT UNSIGNED NOT NULL,       -- una categoría por proveedor
  logo_url             VARCHAR(255) NULL,
  direccion            VARCHAR(255) NULL,
  correo               VARCHAR(120) NULL,
  dni_usuario_registro CHAR(13) NOT NULL,               -- quién lo ingresó
  fecha_registro       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_proveedor_nombre (nombre_empresa),
  CHECK (rtn IS NULL OR rtn REGEXP '^[0-9]{14}$'),
  FOREIGN KEY (id_categoria) REFERENCES categoria (id_categoria),
  FOREIGN KEY (dni_usuario_registro) REFERENCES usuario (dni) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Un proveedor puede tener varios teléfonos (1FN)
CREATE TABLE proveedor_telefono (
  id_telefono  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_proveedor INT UNSIGNED NOT NULL,
  numero       CHAR(8) NOT NULL,       -- fijo (2) o celular (3, 7, 8, 9)
  tipo         ENUM('telefono','celular') NOT NULL,
  es_whatsapp  TINYINT(1) NOT NULL DEFAULT 0,
  origen_dato  ENUM('manual','documento','internet') NOT NULL DEFAULT 'manual',
  fuente_url   VARCHAR(500) NULL,
  verificado_en DATE NULL,
  UNIQUE (id_proveedor, numero),
  CHECK (numero REGEXP '^[23789][0-9]{7}$'),
  FOREIGN KEY (id_proveedor) REFERENCES proveedor (id_proveedor)
) ENGINE=InnoDB;

CREATE TABLE proveedor_red_social (
  id_red       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_proveedor INT UNSIGNED NOT NULL,
  plataforma   VARCHAR(30) NOT NULL,       -- Facebook, Instagram, etc.
  url          VARCHAR(255) NOT NULL,
  UNIQUE (id_proveedor, plataforma),
  FOREIGN KEY (id_proveedor) REFERENCES proveedor (id_proveedor)
) ENGINE=InnoDB;

-- Estrellas (1 a 5). El promedio NO se guarda: se calcula (ver la vista).
CREATE TABLE calificacion (
  id_calificacion INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_proveedor    INT UNSIGNED NOT NULL,
  dni_usuario     CHAR(13) NOT NULL,
  estrellas       TINYINT UNSIGNED NOT NULL,
  comentario      VARCHAR(255) NULL,
  fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (id_proveedor, dni_usuario),     -- un usuario califica una vez
  CHECK (estrellas BETWEEN 1 AND 5),
  FOREIGN KEY (id_proveedor) REFERENCES proveedor (id_proveedor),
  FOREIGN KEY (dni_usuario)  REFERENCES usuario (dni) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Bitácora: como el RTN y el nombre se pueden corregir, queda el rastro
CREATE TABLE historial_edicion_proveedor (
  id_edicion     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_proveedor   INT UNSIGNED NOT NULL,
  dni_usuario    CHAR(13) NOT NULL,
  campo          VARCHAR(30) NOT NULL,
  valor_anterior VARCHAR(255) NULL,
  valor_nuevo    VARCHAR(255) NULL,
  fecha_edicion  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_proveedor) REFERENCES proveedor (id_proveedor),
  FOREIGN KEY (dni_usuario)  REFERENCES usuario (dni) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. COMPRAS (facturas y recibos) = historial del proveedor
-- ---------------------------------------------------------------------
CREATE TABLE documento_compra (
  id_documento     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_proveedor     INT UNSIGNED NULL,
  nombre_emisor    VARCHAR(150) NULL,
  rtn_emisor       CHAR(14) NULL,
  id_empresa_grupo TINYINT UNSIGNED NOT NULL,   -- cuál empresa del grupo compró
  dni_usuario      CHAR(13) NOT NULL,           -- quién registró el documento
  rtn_cliente      CHAR(14) NULL,
  tipo_documento   ENUM('factura','recibo','recibo_honorarios','amazon','otro') NOT NULL,
  numero_documento VARCHAR(25) NULL,            -- obligatorio en facturas
  referencia_externa VARCHAR(80) NULL,
  cai              VARCHAR(40) NULL,
  fecha_documento  DATE NOT NULL,
  atendido_por     VARCHAR(100) NULL,
  subtotal         DECIMAL(12,2) NULL DEFAULT NULL,
  subtotal_exento  DECIMAL(12,2) NULL DEFAULT NULL,
  subtotal_exonerado DECIMAL(12,2) NULL DEFAULT NULL,
  subtotal_gravado_15 DECIMAL(12,2) NULL DEFAULT NULL,
  isv              DECIMAL(12,2) NULL DEFAULT NULL,
  descuento_total  DECIMAL(12,2) NULL DEFAULT NULL,
  costo_envio      DECIMAL(12,2) NULL DEFAULT NULL,
  retencion_isr    DECIMAL(12,2) NULL DEFAULT NULL,
  total            DECIMAL(12,2) NOT NULL,
  moneda           CHAR(3) NOT NULL DEFAULT 'HNL',
  imagen_url       VARCHAR(255) NULL,           -- foto o PDF original
  texto_ocr        MEDIUMTEXT NULL,
  metodo_ingreso   ENUM('ocr','manual') NOT NULL,
  fecha_registro   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (id_proveedor, tipo_documento, numero_documento),  -- evita duplicados
  INDEX idx_doc_proveedor_fecha (id_proveedor, fecha_documento),
  INDEX idx_doc_empresa (id_empresa_grupo),
  INDEX idx_documento_rtn_cliente (rtn_cliente),
  INDEX idx_documento_rtn_emisor (rtn_emisor),
  CHECK (tipo_documento IN ('recibo','amazon','otro') OR numero_documento IS NOT NULL),
  CHECK (total >= 0),
  FOREIGN KEY (id_proveedor)     REFERENCES proveedor (id_proveedor),
  FOREIGN KEY (id_empresa_grupo) REFERENCES empresa_grupo (id_empresa_grupo),
  FOREIGN KEY (dni_usuario)      REFERENCES usuario (dni) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Productos o servicios comprados (la "matriz" del perfil del proveedor).
-- Se almacenan las cifras visibles en el comprobante; la aplicación no deriva importes.
CREATE TABLE detalle_documento (
  id_detalle      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_documento    INT UNSIGNED NOT NULL,
  descripcion     VARCHAR(255) NOT NULL,
  cantidad        DECIMAL(10,2) NULL DEFAULT NULL,
  precio_unitario DECIMAL(12,2) NULL DEFAULT NULL,
  descuento       DECIMAL(12,2) NULL DEFAULT NULL,
  imagen_url      VARCHAR(255) NULL,
  CHECK (cantidad IS NULL OR cantidad > 0),
  CHECK (precio_unitario IS NULL OR precio_unitario >= 0),
  FOREIGN KEY (id_documento) REFERENCES documento_compra (id_documento)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. REGLAS Y VISTAS
-- ---------------------------------------------------------------------
-- Los proveedores no se pueden eliminar
DELIMITER $$
CREATE TRIGGER trg_proveedor_no_eliminar
BEFORE DELETE ON proveedor FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Los proveedores no se pueden eliminar';
END$$
DELIMITER ;

-- Tarjeta del proveedor en la página de inicio (nombre, logo, estrellas)
CREATE VIEW vw_proveedor_resumen AS
SELECT p.id_proveedor, p.nombre_empresa, p.rtn, p.logo_url,
       ROUND(AVG(c.estrellas), 1) AS promedio_estrellas,
       COUNT(c.id_calificacion)   AS total_calificaciones
FROM proveedor p
LEFT JOIN calificacion c ON c.id_proveedor = p.id_proveedor
GROUP BY p.id_proveedor, p.nombre_empresa, p.rtn, p.logo_url;

-- Notificaciones (campana): se calculan con las fechas de registro, sin tabla.
-- La app muestra las que tengan fecha > usuario.ultima_vista_notif y, al abrir
-- la campana, actualiza esa columna con la fecha y hora actual.
CREATE VIEW vw_notificaciones AS
SELECT 'proveedor_nuevo' AS tipo,
       p.id_proveedor AS id_referencia,
       p.nombre_empresa AS detalle,
       p.dni_usuario_registro AS dni_usuario_origen,
       p.fecha_registro AS fecha
FROM proveedor p
UNION ALL
SELECT 'usuario_nuevo', NULL,
       CONCAT(u.primer_nombre, ' ', u.primer_apellido),
       u.dni, u.fecha_registro
FROM usuario u
WHERE u.correo_verificado = 1;

-- Ejemplo (campana del usuario '<DNI>'):
-- SELECT n.* FROM vw_notificaciones n
-- WHERE n.fecha > (SELECT ultima_vista_notif FROM usuario WHERE dni = '<DNI>')
--   AND n.dni_usuario_origen <> '<DNI>'
-- ORDER BY n.fecha DESC LIMIT 20;

-- ---------------------------------------------------------------------
-- 6. DATOS INICIALES (RTN reales, 14 dígitos sin guiones)
-- ---------------------------------------------------------------------
INSERT INTO empresa_grupo (nombre, rtn) VALUES
  ('AUTOREPUESTOS BLESSING',   '08011988090350'),
  ('INVERSIONES CASTRO',       '08019019103201'),
  ('TECNICENTRO DIDASA',       '08019020219802'),
  ('DISTRIBUIDORA MANSIAGO',   '08019024052985'),
  ('JAPAN HN S.A. DE C.V.',    '08019026304811'),
  ('AUTOCARROCERIA BLESSING',  '08019025219107'),
  ('CAP (SINDY CASTRO)',       '07151992000339');

INSERT INTO categoria (nombre) VALUES
  ('Papelería'), ('Equipos'), ('Repuestos'), ('Redes'),
  ('Electrodomésticos'), ('Servicios'), ('Textil'), ('Sin clasificar');

INSERT INTO departamento (nombre) VALUES
  ('Compras internas'),
  ('Gerencia general'),
  ('Compras internacionales'),
  ('Ventas'),
  ('Pruebas y creatividad')
ON DUPLICATE KEY UPDATE id_departamento = LAST_INSERT_ID(id_departamento);

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

-- ---------------------------------------------------------------------
-- 7. EJEMPLO DE TRANSACCIÓN ACID: registrar una factura leída por OCR
--    Todo se guarda junto o no se guarda nada.
-- ---------------------------------------------------------------------
-- START TRANSACTION;
--
--   -- Solo si el RTN no existe todavía (la app busca primero por RTN):
--   INSERT INTO proveedor (rtn, nombre_empresa, id_categoria, direccion, dni_usuario_registro)
--   VALUES ('<RTN>', '<Nombre>', <id_categoria>, '<Dirección>', '<DNI del usuario>');
--   SET @id_prov = LAST_INSERT_ID();
--   -- (si ya existía: SET @id_prov = <id del proveedor encontrado>;)
--
--   INSERT INTO documento_compra
--     (id_proveedor, id_empresa_grupo, dni_usuario, tipo_documento,
--      numero_documento, cai, fecha_documento, subtotal, isv, total,
--      imagen_url, metodo_ingreso)
--   VALUES (@id_prov, <id_empresa>, '<DNI del usuario>', 'factura',
--           '<000-001-01-00000001>', '<CAI>', '2026-10-10', 100.00, 15.00,
--           115.00, '<ruta>', 'ocr');
--   SET @id_doc = LAST_INSERT_ID();
--
--   INSERT INTO detalle_documento (id_documento, descripcion, cantidad, precio_unitario)
--   VALUES (@id_doc, '<Producto>', 1, 100.00);
--
-- COMMIT;   -- si algo falla: ROLLBACK;
