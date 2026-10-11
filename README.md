# Biblioteca de Proveedores CAP

Proyecto PHP para ejecutar localmente con XAMPP.

## Estructura

- `frontend/`: páginas que usa la persona usuaria y parciales de interfaz.
- `backend/controllers/`: registro, cierre de sesión, carga de fotos y búsquedas.
- `backend/config/`: conexión MySQL, JWT y encabezados de seguridad.
- `database/`: esquema y scripts SQL de datos de muestra.
- `assets/js/`: JavaScript del navegador.
- `uploads/`: fotos de perfil, proveedores y comprobantes guardados en el servidor.

## Ejecutar en XAMPP

1. Coloca la carpeta `Proveedores` dentro de `xampp/htdocs`.
2. Inicia Apache y MySQL desde el panel de XAMPP.
3. Abre `http://localhost/Proveedores/` o `http://localhost/Proveedores/login.php`.
4. Si necesitas importar la base, usa primero `database/bd_proveedores.sql`; luego puedes cargar los datos de prueba con los otros archivos SQL según sus comentarios.

Las direcciones antiguas `index.php`, `login.php`, `perfil.php` y `proveedor.php` en la raíz redirigen a las páginas correspondientes dentro de `frontend/`.

## Ingreso de facturas y recibos

Desde el index, usa el botón flotante para abrir **Ingresar compra**. En celular se puede tomar una foto con la cámara o elegir un archivo; en computadora se elige el archivo. JPG/JPEG, PNG, WEBP y PDF están admitidos. También existe el modo de captura manual.

El OCR usa Tesseract.js con el modelo de español en el navegador. En el primer uso requiere conexión para descargar el motor y el modelo desde jsDelivr. La imagen se analiza en el dispositivo; al guardar, el original se envía al XAMPP local. PDF.js convierte hasta cinco páginas para analizarlas. Los campos detectados son sugerencias: hay que revisarlos antes de guardar, en especial con recibos manuscritos.

Si la base ya estaba creada antes de agregar OCR, inicia MySQL en XAMPP e importa **una sola vez** `database/migracion_ocr.sql` desde phpMyAdmin, seleccionando `biblioteca_proveedores`. Si ya ejecutaste una versión anterior de esa migración que incluía columnas de ISV 18%, haz primero una copia de seguridad e importa también `database/migracion_isv_15_casillero.sql`. Para una base nueva, importa el esquema actualizado `database/bd_proveedores.sql` y no ejecutes también esas migraciones.

La aplicación registra ISV al 15%. El RTN del emisor y el del cliente se distinguen; si el comprobante no trae RTN del cliente, se puede dejar vacío y seleccionar la empresa CAP que realizó la compra. Esto permite registrar compras de Amazon por casillero sin inventar un RTN: se guarda el vendedor, la moneda y el número de pedido o referencia del casillero, y el historial se agrupa bajo “Amazon Marketplace”. El botón de búsqueda pública abre una búsqueda web para que la persona confirme el teléfono y registre su fuente; no se guardan resultados no verificados.
