# DTR MCP Bridge

Plugin para conectar WordPress y WooCommerce a una aplicación de IA compatible con MCP mediante tu propia instancia de n8n. Permite ofrecer herramientas de consulta y edición, con permisos por operación y aprobación humana para los cambios que la requieren.

## Descargas de la versión 1.3.7

- [Kit completo de instalación](https://github.com/Desarrollo-Tecnologico-Rural-S-L/dtr-mcp-bridge/releases/download/v1.3.7/Kit-instalacion-independiente-DTR-MCP-Bridge.zip), con el plugin instalable, guía PDF y Word, flujo de lectura de ejemplo y configuración de servidor n8n con HTTPS.
- [Plugin instalable para WordPress](https://github.com/Desarrollo-Tecnologico-Rural-S-L/dtr-mcp-bridge/releases/download/v1.3.7/dtr-mcp-bridge-1.3.7.zip).
- [Publicación estable y archivos de descarga](https://github.com/Desarrollo-Tecnologico-Rural-S-L/dtr-mcp-bridge/releases/tag/v1.3.7).

## Primeros pasos

1. Descarga el kit y descomprímelo en tu ordenador.
2. Lee EMPIEZA-AQUI.txt y la [guía de instalación independiente](docs/Instalacion-independiente-DTR-MCP-Bridge.pdf).
3. En WordPress, abre Plugins → Añadir plugin → Subir plugin. Selecciona el ZIP interior dtr-mcp-bridge-1.3.7.zip y actívalo.
4. Crea el usuario con perfil DTR MCP operador y su contraseña de aplicación. Configura las credenciales en tu propio n8n siguiendo la guía.
5. Importa [estado-wordpress.json](integration/kit/estado-wordpress.json), sustituye el dominio de ejemplo y selecciona tu credencial.
6. Comprueba la lectura antes de habilitar las escrituras. La guía explica cómo construir las herramientas de borradores y de cambios aprobados en WooCommerce, y cómo conectarlas a un servidor MCP.

Si ya utilizas n8n puedes importar el ejemplo en esa instancia. La [carpeta servidor](integration/kit/servidor) incluye Docker Compose y Caddy para un servidor nuevo con HTTPS. Debes configurar tu dominio, una versión estable de n8n y una clave de cifrado propia.

## Requisitos y alcance

- WordPress individual 6.5 o posterior, PHP 8.1 o posterior y HTTPS. La versión 1.3.7 admite instalaciones individuales.
- Cliente de IA con soporte MCP remoto y autenticación compatible con el servidor configurado.
- WooCommerce activo para las herramientas de tienda. El módulo de integración está incluido en el plugin.
- El ejemplo importable realiza una consulta de estado. La guía desarrolla la construcción de herramientas adicionales.
- La versión 1.3.7 incorpora edición estructural de Elementor V3. En el editor atómico de V4 se verificó el cambio de texto de un encabezado existente. El detalle figura en el [informe de pruebas](docs/test-report.md).

## Permisos de escritura

La escritura de contenido se habilita desde Herramientas → DTR MCP. El mantenimiento y las operaciones WooCommerce requieren DTR_MCP_ALLOW_WRITES=true en wp-config.php. Las herramientas que publiques en n8n determinan qué operaciones puede solicitar el cliente.

Los planes de cambios públicos se revisan en WordPress. En WooCommerce aprueba el titular de la clave utilizada por la integración. Consulta la guía antes de habilitar escrituras, especialmente si tu tienda ya utiliza otras integraciones por API.

## Documentación

- [Guía independiente en PDF](docs/Instalacion-independiente-DTR-MCP-Bridge.pdf) y [versión editable](docs/Instalacion-independiente-DTR-MCP-Bridge.docx).
- [Archivos del ejemplo y servidor](integration/kit).
- [API de Elementor](docs/rest-elementor.md).
- [Seguridad](docs/security.md) y [arquitectura](docs/architecture.md).
- [Historial de cambios](CHANGELOG.md).

La configuración de servidor y el ejemplo incluidos en el kit se han revisado estáticamente. La instalación completa en una instancia n8n nueva está pendiente de evaluación independiente.

