# DTR MCP Bridge

Complemento privado para integrar WordPress y WooCommerce con el MCP DTR gestionado desde n8n.

## Estado
- **main**: 1.3.6, versión de referencia estable.
- **develop**: 1.3.7-rc.1, candidata de ampliación Elementor V3; no certificada.

## Instalación
Instalar el paquete ZIP de la versión validada desde WordPress. No incluir claves de API ni credenciales en el repositorio.

## Seguridad
Las operaciones de escritura dependen de permisos WordPress, comprobación de hashes, planes de aprobación del propietario y controles por sitio. Los cambios HTML/código deben autorizarse expresamente.

## Desarrollo
Consultar `docs/elementor-v3.md` y `docs/security.md`. Validar con PHP lint y pruebas de integración en `dev.fisioterapialaser.com` antes de desplegar en producción.
