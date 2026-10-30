# Arquitectura

`dtr-mcp-bridge.php` registra el plugin y rutas; `dtr-mcp-content.php` gestiona contenido WordPress; `dtr-mcp-extended.php` opera sobre Elementor, plantillas y herramientas ampliadas; `dtr-mcp-woo.php` expone operaciones WooCommerce con planes de aprobación.

n8n es el punto de acceso MCP multiinstancia. Cada sitio requiere adaptadores y credenciales propios; la selección de sitio se valida en servidor.
