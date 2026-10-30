# Informe de pruebas — 2026-10-30

Estado: desarrollo parcial, no certificación ni aceptación final. El usuario instalará el ZIP en DEV. Ningún cambio de esta candidata se ha ejecutado en un sitio real.

## Ejecutado

- PHP portátil 8.5.12 NTS x64; archivo oficial verificado por SHA256. Lint de los cinco archivos de producción, posteriormente ampliado a fixtures/tests.
- `php tests/lifecycle.php`: 72 assertions superadas (incluye 43 del motor). Estado de planes, caducidad, consumo único, alteración de payload, concurrencia anterior a guardar, bloqueo de editor/sitio, permisos revocados, fallo de persistencia, verificación fallida, excepción e imposibilidad de escribir auditoría.
- `php tests/elementor.php --code-enabled`: 45 assertions superadas. Texto, enlaces, estilos responsive, HTML/CSS, listas, formularios, imagen/botón/texto enriquecido/acordeón, operaciones estructurales y rechazos de entradas malformadas/código.
- Ambas suites también ejecutadas con KSES real de WordPress 6.8, obtenido de `WordPress/WordPress/6.8/wp-includes/kses.php`; SHA256 `b32aa80b0dc024589473d157f2b166d30740f3714b2093184cee735260fc0788`. Hooks, permisos, BD y Document API siguen siendo dobles; esto no convierte la suite en integración WordPress.
- Sin código específico de cliente en los PHP; no cambios en módulos Content/Woo. `git diff --check` sin errores.
- Conectividad real MCP fisiolaser-dev: status disponible, capabilities declara Bridge 1.3.7 y Elementor; consulta de borradores page responde con lista vacía. No acceso a credenciales ni a datos sensibles.
- Inspección del adaptador DEV n8n: necesita ampliar allowlist para nueva operación. No publicado ni modificado.

## No ejecutado / aceptación pendiente

- Instalación del ZIP en DEV; lectura del nuevo esquema/controles efectivos.
- Ejecución sobre WordPress/Elementor/Elementor Pro reales, aprobaciones humanas, nonce/CSRF y autenticación de Application Password de extremo a extremo.
- Persistencia real y renderizado de todos los widgets, responsive, regeneración CSS, revisiones y recuperación.
- Carreras simultáneas con editor nativo u otros plugins. El bloqueo MCP y comprobación inmediata de hash no garantizan atomicidad con escritores externos.
- V4: lectura estructural implementada; adaptador de escritura de props/styles/interactions **no implementado**. No se considera cumplida la paridad V3/V4.
- Descubrimiento y ejecución mediante herramientas MCP nuevas: contrato preparado, workflows no desplegados.
- CI remoto: véase actualización de evidencia debajo; no sustituye integración real.
- Regresión funcional en instalaciones reales de WordPress y WooCommerce.

No hay fallos pendientes en las suites locales ejecutadas. Las limitaciones anteriores impiden promover la candidata o declarar terminado el proyecto.

## Actualización: evidencia GitHub Actions

Sobre el commit `eb99351c7dc22748ef647bc016a69475271ba36a`, ejecución `41063944057`, se observaron los pasos PHP syntax e Isolated PHP contracts completados con success en PHP 8.1, 8.3 y 8.5. La consulta de jobs todavía indicaba in_progress aunque todos sus pasos estaban completados; no se confunde esa observación con una prueba real WordPress/Elementor.

Evidencia: https://github.com/Desarrollo-Tecnologico-Rural-S-L/dtr-mcp-bridge/actions/runs/41063944057

Consulta posterior: workflow completado con `conclusion: success`. Queda confirmada la matriz remota PHP 8.1/8.3/8.5 para ese commit.
