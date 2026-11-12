# Instalación y migración 1.3.7

El soporte V4 de esta versión cubre la edición de texto en encabezados existentes.

1. Guardar copia recuperable de base de datos y plugin DEV. Esperar a que no haya operaciones running; no borrar bloqueos activos.
2. Rechazar o dejar caducar los planes antiguos. Los hashes/versiones de validación cambian: releer y preparar planes nuevos después de actualizar; no convertir estados en la BD.
3. En https://dev.fisioterapialaser.com, Plugins → Añadir plugin → Subir plugin: seleccionar `dist/dtr-mcp-bridge-1.3.7.zip`, reemplazar la versión instalada y confirmar activación. El ZIP contiene solo el directorio dtr-mcp-bridge, sus cinco PHP y el contrato JSON.
4. Comprobar versión 1.3.7 por capabilities. No cambiar roles/constantes para evitar un rechazo: investigar el permiso requerido. No se cambian automáticamente opciones de escritura, claves, roles ni tablas.
5. Crear en el editor Elementor un documento DEV desechable con contenedores, heading, texto, imagen, botón, icon-list, form y acordeón/pestañas. Registrar su ID; probar con aprobaciones reales según docs/rest-elementor.md.
6. HTML/CSS son pruebas separadas: requieren autorización explícita del administrador mediante constante DTR_MCP_ALLOW_CODE_PATCHES y unfiltered_html efectivo. Dejar deshabilitado por defecto. No habilitar ejecución JS/PHP ni privilegios generales para superar las pruebas.
7. Revisar frontend, editor y responsive además de las respuestas REST. Comprobar que Woo y WordPress mantienen sus herramientas y aprobaciones.

## Recuperación

Ante verification_failed/uncertain/running, inspeccionar el documento persistido y el registro del plan; no repetir la ejecución. Las revisiones de WordPress son auxiliares, no sustituyen una copia verificada de metadatos Elementor. Restaurar una copia DEV si fuese necesario; no asumir que revertir el plugin revierte los datos. La candidata no borra planes históricos ni migra contenido automáticamente.
