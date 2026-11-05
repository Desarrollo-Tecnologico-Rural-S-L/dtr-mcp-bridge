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

## Integración DEV del 5 de noviembre de 2026

El conector autenticado del sitio `fisiolaser-dev` confirmó Bridge `1.3.7-rc.2`, WordPress `7.1.4` y PHP `8.5.12`, con escrituras de contenido habilitadas y aprobación obligatoria. El sitio anuncia las operaciones `elementor_schema` y `elementor_edit`. La publicación de sus contratos MCP sigue pendiente.

La búsqueda literal de Elementor identificó un widget de texto en la página «Gracias» de DEV y devolvió su ruta `editor`, el valor anterior y el hash del documento. `elementor_patch` preparó un cambio de una frase y devolvió una vista previa con el antes y el después. WordPress registró el plan en estado `pending` con caducidad. Una solicitud independiente con un hash deliberadamente incorrecto devolvió `DTR_CONTENT_CHANGED`.

En este punto se han probado lectura, preparación y rechazo de un plan obsoleto en el sitio real. La ejecución, la persistencia y la lectura posterior requieren la aprobación manual del plan válido.

El HTML público de la página respondió con HTTP 200. Contenía el ID del widget y la frase original y todavía no contenía la frase propuesta. Como control negativo adicional, WordPress rechazó una solicitud que asignaba un número al control `editor` con `DTR_CONTROL_TYPE_MISMATCH`.

### Resultado de la edición de texto aprobada en DEV

El primer plan caducó. Tras releer el estado del widget, se preparó el plan `5c064288651a5e8570487f0fdee09474`. WordPress confirmó `approved` para `elementor_patch` sobre la página 60. Una única llamada a `extended_execute` aplicó un cambio mediante la API Document de Elementor. El resultado indicó `persisted=true`, `verified=true` y `changes_applied=1`. La consulta posterior del registro devolvió `completed` y `OK`.

La relectura independiente encontró «Te responderemos en breve.» en el widget `7591ec1`, ruta `editor`, con el mismo hash SHA256 que devolvió el guardado (`d8eef94b841641e298fabe1ae9001511e10ef3db324bde920f5d4810d1c289ef`). Un GET público a `/gracias/` devolvió HTTP 200 y el párrafo nuevo completo. La frase anterior ya no aparecía en el HTML.

Esta prueba cubre el ciclo de lectura, aprobación humana, guardado de texto y publicación del resultado en el HTML de DEV mediante `elementor_patch`. La edición estructural mediante `elementor_edit`, otros controles y la escritura V4 siguen pendientes de sus propias verificaciones.
