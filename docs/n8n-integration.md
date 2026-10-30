# Integración MCP/n8n — propuesta, no aplicada

Inspección de solo lectura el 2026-10-30: el adaptador DEV `DTR WordPress - FisioLaser DEV - Escritura` tiene el nodo `Validar operacion`, credencial separada y host fijo. Su allowlist de `extended_plan` admite elementor_patch e inserción Post Content, pero **rechaza elementor_edit**. El adaptador no es un canal para instalar ZIPs. No se ha actualizado ningún workflow activo.

## Cambios preparados

1. Herramientas MCP: publicar la nueva lectura `wp_elementor_schema`; ampliar descripción y enum de `wp_editor_plan` con elementor_edit. Contratos: `integration/mcp-elementor-tools.json`; argumentos de edición: `dtr-mcp-bridge/elementor-contract.json`.
2. Router: mantener la resolución por site_id, separar lectura/escritura y permitir la nueva lectura y operación únicamente para sitios cuya capability/version la anuncie. No aceptar URLs/credenciales/rutas de cliente.
3. Adaptador de lectura: añadir `elementor_schema` al enum/regex de `a.operation` dentro de `extended_read`. Conserva GET `/dtr-mcp/v1/extended/read`, TLS verificado y cero redirecciones.
4. Adaptador de escritura: el enum actual `trash|restore|delete|elementor_patch|elementor_insert_post_content|redirect_create|cache_purge` debe añadir únicamente `elementor_edit`. El envoltorio sigue siendo `extended_plan` con `{operation,arguments_json}`; se traduce a POST `{operation,arguments:JSON.parse(arguments_json)}`. No añadir aprobación ni subida de plugins.
5. Respuesta: conservar `status`, `persisted`, `verified`, `retry_safe` y `plan_id`. En errores HTTP, propagar estos campos seguros desde `body.data` cuando existan, además del código DTR. El adaptador inspeccionado actualmente solo conserva el código en errores; el cliente debe conservar siempre el plan_id enviado.
6. Desactivar reintentos de nodos de ejecución. Tras timeout consultar wp_change_status con el mismo plan. `running`, `uncertain` y `verification_failed` requieren inspección humana y no son señales para repetir.

La JSON Schema no crea una herramienta automáticamente. Se debe probar `tools/list` en cada cliente MCP después de publicar el workflow autorizado. No declarar disponible la edición estructural hasta completar esa comprobación.

## Secuencia DEV reproducible

- Instalar rc.2 manualmente, confirmar `wp_capabilities(site_id="fisiolaser-dev")` y versión. El host autorizado es exclusivamente https://dev.fisioterapialaser.com y la credencial independiente es `WP dev.fisioterapialaser.com`.
- Para pruebas iniciales de texto, `wp_elementor_get` y `wp_editor_plan(operation="elementor_patch")` utilizan herramientas ya existentes. Se requiere un documento Elementor de prueba, no un borrador WordPress sin datos de Elementor.
- Preparar y revisar el plan; el propietario lo aprueba personalmente en WordPress. Consultar estado y ejecutar una sola vez. Volver a leer documento y revisar editor/renderizado responsive.
- Después de autorizar y aplicar la actualización n8n, repetir para elementor_edit, incluyendo insertar/duplicar/mover/reordenar/eliminar; verificar tools/list y rechazo de sitios/operaciones extra.
- No utilizar pin data como prueba de autenticación, persistencia ni integración real.

Pendiente: publicar cambios n8n bajo autorización del propietario, instalar candidata, confirmar widgets/controles efectivos, aprobación humana y E2E. Las credenciales no se exportan, copian ni incluyen en los contratos.
