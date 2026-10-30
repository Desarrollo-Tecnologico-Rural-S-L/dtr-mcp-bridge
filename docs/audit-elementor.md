# Auditoría inicial — 2026-10-30

Base: `d94180b`, rama `feature/elementor-v3`. Inspeccionados los cuatro PHP completos antes de modificar código.

## Superficie REST

| Módulo | Rutas | Validación / autorización |
| --- | --- | --- |
| Bridge | GET status, diagnostics, updates, users, plans/{id}; POST plans, execute | HTTPS, Application Password, dtr_mcp_access; escrituras de mantenimiento con constante; usuarios con list_users; planes de 15 min |
| Content | GET content/items, items/{id}, taxonomies, terms, requests/{id}; POST drafts, drafts/update, plans, execute | permiso anterior más dtr_mcp_content, opción content_writes, capacidades por contenido; borradores idempotentes y publicaciones aprobadas |
| Extended | GET extended/read; POST extended/plan, extended/execute | permiso Content; operation/query_json o operation/arguments; estado anterior, hash de payload, aprobación local, bloqueo de sitio |
| Woo | wc/v3/dtr-mcp/product-plans, product-plans/{id}, product-execute, settings-plans, settings-execute | clave WC read_write vinculada al usuario, capacidades y aprobación de su propietario; bloqueo de escrituras WC directas |

Bridge mantiene operaciones plugin_state/update_plugin/update_theme/update_core/cache_purge. Extended mantiene lectura de tipos, registros, Elementor, búsqueda, plantillas, redirecciones, SEO, sitemap y auditoría; escritura trash/restore/delete, elementor_patch, elementor_insert_post_content, redirect_create/cache_purge. Las validaciones están en callbacks y no existe contrato JSON Schema completo descubrible.

## Hallazgos y decisiones

1. **Alta: validación incompleta de código.** `elementor_patch` identifica código por el nombre de la ruta, pero no valida HTML activo en texto ni URLs anidadas al reemplazar objetos. La comprobación de custom_css bloquea antes de llegar a la autorización reforzada. Separar política de valores, HTML y CSS; JavaScript denegado por defecto.
2. **Alta: formularios.** La lista de palabras prohibidas no protege `email_to`, `submit_actions`, campos ocultos o sustituciones completas de repeaters. Aplicar política específica al widget form; no confundir estilo con configuración operativa.
3. **Alta: concurrencia.** El bloqueo global serializa clientes MCP, no el editor nativo. La lectura/hash anterior al guardado deja una ventana TOCTOU. Respetar bloqueo de editor, revalidar inmediatamente y documentar la limitación frente a escritores externos.
4. **Alta: verificación.** Se reaplica el parche como comprobación, reutilizando la política de escritura. Todos los errores se registran como SAVE_FAILED aunque ya haya persistencia. Separar validación, comparación posterior y estados terminales inciertos; nunca reintentar automáticamente.
5. **Media: inserción.** Post Content permite sección como padre directo; restringe sin necesidad single-page y no valida todo el árbol. Mantener la operación genérica, usar reglas sección/columna/contenedor y el registro de widgets instalado.
6. **Media: esquema y límites.** Solo se cambian propiedades existentes; no hay inserción, duplicación, movimiento, reordenación ni eliminación genéricos. IDs restringidos a 7–8 hex; sin validación de duplicados/jerarquía/profundidad del árbol. Crear motor de transformaciones puro y acotado.
7. **Media: lectura.** No expone ajustes de página/kit/breakpoints, filtro estructural ni control por registro instalado; la redacción basada solo en claves puede filtrar enlaces autenticados o configuración de formularios.
8. **Media: aprobación.** Extended acepta cualquier approved_by no nulo; la interfaz permite a administradores del sitio revisar planes del operador. Mantener este modelo local explícito, verificar permisos del aprobador al ejecutar. La protección CSRF existente usa POST y nonce, excluye Application Password y rol de servicio.
9. **Media: Woo.** Compara hash antes de reclamar plan y no toma el bloqueo de sitio; guardar producto carece de relectura. Conservar operaciones y aprobación; no refactorizar incidentalmente, registrar limitación y añadir regresiones cuando se toque.
10. **Media: retención.** Options de planes contienen estado completo indefinidamente. No incluir secretos en respuestas/logs; minimizar contenido del nuevo flujo y documentar retención pendiente del legado.
11. **Compatibilidad:** V3 usa settings y árbol clásico; V4 usa props tipadas, styles, interactions y registros separados. No se puede certificar V4 por aceptar e-flexbox. Mantener lectura sin pérdida y bloquear mutaciones atómicas no soportadas hasta contar con adaptador validado.
12. **Deuda de cliente:** en esta base ya no existe elementor_account_finish ni código específico de cliente. Solo quedan menciones históricas en CHANGELOG. No se eliminan funciones Woo.

## Plan por fases

1. Auditoría, contratos y fixtures de regresión.
2. Motor de árbol V3, política de valores, consulta estructural y descubrimiento.
3. Integración con planes existentes, guardado por Document API, estados persistido/verificado e integridad del plan.
4. Suite PHP y matriz de integración DEV (aprobación humana obligatoria); V4 se prueba por separado, sin afirmar paridad.
5. Contratos MCP/n8n preparados sin cambiar producción, instalación, migración, informe de pruebas y PR hacia develop.

## Referencias de compatibilidad

- https://developers.elementor.com/docs/data-structure/general-structure.html
- https://developers.elementor.com/docs/data-structure/atomic-elements
- https://developers.elementor.com/docs/data-structure/atomic-prop-values
- https://developers.elementor.com/docs/data-structure/atomic-styles

Estado inicial: conector MCP responde status de fisiolaser-dev; no equivale a comprobar versión, guardado o renderizado. No hay PHP en PATH. No se han ejecutado pruebas de integración de escritura ni se ha aprobado ningún plan.
