# DTR MCP Bridge

Complemento privado para integrar WordPress y WooCommerce con el MCP DTR gestionado desde n8n.

## Estado
- **main**: 1.3.6, versión de referencia estable.
- **develop**: 1.3.7-rc.1, candidata de ampliación Elementor V3; no certificada.
- **feature/elementor-v3**: 1.3.7-rc.7. La RC6 se probó en DEV con widgets V3, formularios y estilos. En RC7 se verificaron la tipografía responsive, el descubrimiento del esquema por widget y el ciclo completo de edición de un botón desde MCP. La escritura V4 sigue pendiente.

## Instalación
Instalar el paquete ZIP de la versión validada desde WordPress. No incluir claves de API ni credenciales en el repositorio.

## Seguridad
Las operaciones de escritura dependen de permisos WordPress, comprobación de hashes, planes de aprobación del propietario y controles por sitio. Los cambios HTML/código deben autorizarse expresamente.

## Desarrollo
Consultar `docs/elementor-v3.md` y `docs/security.md`. Validar con PHP lint y pruebas de integración en `dev.fisioterapialaser.com` antes de desplegar en producción.

API y ejemplos: `docs/rest-elementor.md`. Auditoría: `docs/audit-elementor.md`. Integración n8n: `docs/n8n-integration.md`. Instalación: `docs/install-migrate.md`. Evidencia y pendientes: `docs/test-report.md`.
