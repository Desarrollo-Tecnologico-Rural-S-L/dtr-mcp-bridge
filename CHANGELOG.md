# Changelog

## 1.3.7-rc.6
- El puente reconoce el control `form-fields-repeater` de Elementor Pro en `form.form_fields` y valida cada campo con el esquema registrado por el widget.

## 1.3.7-rc.5
- Activación explícita de Elementor para páginas en borrador vacías. La vista previa del plan incluye la activación y la ejecución verifica el árbol, el modo de edición y el estado de borrador.
- El hash de estado v3 incluye el modo de edición. Después de actualizar, obtener un hash nuevo y volver a preparar los planes pendientes sobre contenido.

## 1.3.7-rc.4
- Reconoce las variantes por dispositivo de controles de dimensiones que Elementor genera en el editor. Respeta los dispositivos activos y las restricciones del control.

## 1.3.7-rc.2 (desarrollo parcial, no estable)
- Motor genérico de cambios settings y operaciones estructurales V3, validado contra controles instalados.
- Consulta estructural, ajustes de página/kit, esquema descubrible y hashes canónicos.
- Protección de formularios, enlaces anidados y HTML/CSS; ejecución JS/PHP denegada.
- Diferencia persistencia/verificación, planes consumidos y resultado incierto; comprobación del aprobador y del payload recalculado.
- Suite PHP local y CI por versiones; contrato MCP/n8n preparado sin desplegar.
- Pendientes: validación DEV real, escritura atómica V4, publicación de herramientas MCP y concurrencia con escritores externos.

## 1.3.7-rc.1 (candidata)
- Ampliación experimental de edición Elementor V3.
- Eliminación de la operación de plantilla exclusiva de JHModelator.
- Pendiente completar operaciones estructurales genéricas y pruebas E2E.

## 1.3.6 (base estable)
- Soporte de edición estructural limitada de Elementor y APIs WooCommerce protegidas.
- Contenía lógica exclusiva de JHModelator, que no debe propagarse a futuras versiones generales.
