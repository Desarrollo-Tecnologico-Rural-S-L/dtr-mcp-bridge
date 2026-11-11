# Informe de pruebas de Elementor

La RC6 se validó en DEV para los casos que se detallan por versión. Los primeros resultados corresponden al 30 de octubre. La aceptación final sigue pendiente.

## Ejecutado

- PHP portátil 8.5.12 NTS x64; archivo oficial verificado por SHA256. Lint de los cinco archivos de producción, posteriormente ampliado a fixtures/tests.
- `php tests/lifecycle.php`: 72 assertions superadas (incluye 43 del motor). Estado de planes, caducidad, consumo único, alteración de payload, concurrencia anterior a guardar, bloqueo de editor/sitio, permisos revocados, fallo de persistencia, verificación fallida, excepción e imposibilidad de escribir auditoría.
- `php tests/elementor.php --code-enabled`: 45 assertions superadas. Texto, enlaces, estilos responsive, HTML/CSS, listas, formularios, imagen/botón/texto enriquecido/acordeón, operaciones estructurales y rechazos de entradas malformadas/código.
- Ambas suites también ejecutadas con KSES real de WordPress 6.8, obtenido de `WordPress/WordPress/6.8/wp-includes/kses.php`; SHA256 `b32aa80b0dc024589473d157f2b166d30740f3714b2093184cee735260fc0788`. Hooks, permisos, BD y Document API siguen siendo dobles; esto no convierte la suite en integración WordPress.
- Sin código específico de cliente en los PHP; no cambios en módulos Content/Woo. `git diff --check` sin errores.
- Conectividad real MCP fisiolaser-dev: status disponible, capabilities declara Bridge 1.3.7 y Elementor; consulta de borradores page responde con lista vacía. No acceso a credenciales ni a datos sensibles.
- Inspección del adaptador DEV n8n: necesita ampliar allowlist para nueva operación. No publicado ni modificado.

## Pendientes al 30 de octubre (véanse los resultados posteriores)

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

### Inserción estructural en DEV con rc.2

El adaptador DEV publicado admitió elementor_edit. WordPress registró aprobación y se ejecutó una vez el plan 9a0c94b6956727d403cf554d5555519d. El guardado devolvió persisted=true, verified=false y verification_failed. Una lectura independiente confirmó el contenedor vacío 3c325a06 en la raíz, posición 0, con isInner=false. El hash leído coincidió con el devuelto después del guardado. No se reintentó el plan.

La candidata rc.3 incluye el valor predeterminado isInner=false al construir un contenedor nuevo. Una prueba del ciclo completo reprodujo la discrepancia antes del cambio y pasó después. Se mantiene la comparación íntegra del árbol. Suites locales: 74 comprobaciones de ciclo y 45 con código habilitado. Queda pendiente validar la candidata instalada con una nueva operación aprobada.

### Inserción estructural verificada en DEV con rc.3

El 5 de noviembre de 2026, WordPress confirmó la aprobación del plan 2abfb05a67e04aa7fd475f7297611dbc sobre la página 60. Una sola llamada aplicó una inserción mediante la API Document. El resultado indicó persisted=true, verified=true y changes_applied=1. La consulta de estado posterior confirmó completed y OK.

La relectura del árbol devolvió el orden de raíz ea88521a, 3c325a06, 612486c. El nodo nuevo ea88521a era un contenedor vacío con isInner=false en posición 0. El hash de la relectura coincidió con el devuelto tras guardar (df8b36ed84648e939d6533b64313d731e97cb616818f9e3e54bb8ab91bad943f). Esto valida la corrección para esta inserción V3 en DEV.

### Duplicación, movimiento y reordenación en DEV con rc.3

El plan 92cd840a09b172df86eace99bf628647 se ejecutó una sola vez tras comprobar su aprobación en WordPress. Aplicó tres operaciones sobre la página 60 y terminó en completed con resultado OK, persisted=true y verified=true. Una lectura independiente confirmó el orden de raíz [612486c, 3c325a06]. El contenedor de prueba 3c325a06 tenía dos hijos vacíos en orden: la copia 581924e0 y el elemento movido ea88521a. Este último conservó su identificador. El hash leído coincidió con el del guardado (fd3224d800a5ec8a9afe50f3678c557253c805ad87c62bd7a076aa04a57bdb7c). La eliminación del subárbol de prueba queda pendiente de aprobación y verificación.

### Eliminación del subárbol de prueba en DEV con rc.3

En la página «Gracias» se ejecutó una sola vez el plan aprobado a268adbdd3d52b9a37f4a7777635ee06. WordPress registró una operación remove, persisted=true y verified=true. La consulta posterior del plan confirmó completed y OK.

Una relectura independiente encontró únicamente 612486c en la raíz. Al recorrer todos los descendientes se confirmó la ausencia del padre 3c325a06 y de sus hijos 581924e0 y ea88521a. El widget 7591ec1 conservó el texto aprobado «Te responderemos en breve.». El hash leído coincidió con el comunicado al guardar (067f20f14600471558f71daa9f476d0fa6e2205a2a0532c06ccc3c635bc6809f).

Con estas pruebas quedaron verificadas inserción, duplicación, movimiento, reordenación y eliminación de contenedores clásicos V3 en este documento DEV con rc.3.

### Botón responsive y activación de borradores

RC4, borrador 2991. El plan 11075a474c1f8c08e903052fbce56600 terminó en completed y la lectura confirmó rellenos de 16/24 px y 8/12 px (vertical/horizontal). La primera vista previa mostró el enlace de WordPress y la lectura devolvió builder nulo. Tras abrir la página en Elementor, las capturas del propietario mostraron el widget 4bd0d137. En escritorio se observaron los cuatro valores y en móvil emulado a 440 px se vieron 8 px abajo y 12 px en ambos lados en Computed.

RC5. En el borrador nuevo 2998 se activó Elementor con el plan `65708c6ac17888ddfcd699e44d8e6dd9` y se insertó el botón con `da7b742d04c7081178236a956ca2f792`. La primera vista previa, abierta antes que el editor, mostró el botón con relleno vertical/horizontal de 16/24 px en escritorio y 8/12 px en móvil.

### Widgets y controles protegidos en el borrador 2998

La vista previa autenticada mostró la imagen WEBP y los iconos y enlaces de la lista tras el plan `55cd2acd18873a876787c248e2527bf3`. Tras el plan `0850194f6c624a866e1a032f9f9842ab` se comprobó la interacción con el acordeón y las pestañas.

Se verificó el formulario del plan `a6218226ce6fbd21203ff6c7580b25d1` y, con RC6, el campo «Referencia de prueba» y el botón «Enviar prueba RC6» del plan `9e3a0201fecbb0c764e05b91eaced614`. La validación cubrió la presentación. Se omitió el envío. WordPress bloqueó por separado los intentos de cambiar el webhook, el destinatario y las acciones de envío.

Con la configuración de DEV, las solicitudes de edición de HTML y CSS, incluidas las pruebas con JavaScript, PHP y CSS externo, devolvieron `DTR_CODE_PATCHES_DISABLED` al preparar el plan. Las pruebas con la opción habilitada se realizaron en local.

### Conflicto con una edición nativa en RC6

Tras aprobar el plan `4034e15030fe8a52729478cf8e45127b`, el propietario guardó «Prueba de enlace - editor nativo» desde Elementor. El intento de ejecutar el plan devolvió `DTR_CONTENT_CHANGED`. Una relectura confirmó el texto del propietario y el hash `2f8f9fc6f6afc0ccd20267c762a251f9a526cf385b77a9f0d3649a278c5ee5eb`. Esta prueba cubre una edición guardada entre aprobación y ejecución. Las escrituras simultáneas requieren una prueba independiente.

### Encabezado H2 y bloqueo del editor en RC6

En el borrador DEV 2998, WordPress rechazó la ejecución del plan `5b2be2f8f8e293ede920fe323e5dae69` con `DTR_EDITOR_LOCKED`. El plan conservó la aprobación. Una vez liberado el bloqueo, el mismo plan terminó en `completed` con una operación aplicada y verificada. La lectura independiente confirmó el widget `24bfb247` en primera posición, con `header_size=h2`, y el hash coincidió con el del guardado. Al recargar la vista previa autenticada, el DOM mostró el elemento visible `<h2>` con el texto «Encabezado de prueba RC6». La página conservó el estado de borrador.

### Color del encabezado en RC6

El 10 de noviembre de 2026, el plan `d34a771252589b7eb09d711e246a484a` aplicó un cambio al widget `24bfb247` del borrador DEV 2998. Una lectura independiente confirmó `title_color` con valor `#245A73`. En la vista previa autenticada, el navegador identificó «Encabezado de prueba RC6» como H2 y devolvió `rgb(36, 90, 115)` como color calculado.

### Estilos del widget y tipografía

El plan `b1aecd8d6466c6400eafc72506eae486` aplicó seis ajustes al widget `24bfb247` del borrador 2998 tras liberarse el bloqueo del editor. La vista previa mostró en el contenedor del widget márgenes de 12 px arriba y 16 px abajo, borde superior sólido de 2 px de color `#245A73` y fondo `#F0F5F7`.

La planificación en RC6 rechazó el ajuste individual `typography_typography=custom` con `DTR_CONTROL_TYPE_MISMATCH`. En RC7 pasaron 90 comprobaciones de ciclo y 48 con edición de código habilitada en local. Queda validar la tipografía del H2 en escritorio y móvil tras instalar RC7. El borrador 3013 de DEV se creó para probar por separado elementos atómicos V4.

### Validación de tipografía responsive en DEV con RC7

El plan `4ecdec7f041a621e145e9ab5b31e322a` terminó en `completed` con tres cambios aplicados al widget `24bfb247` del borrador 2998. Una lectura independiente confirmó tipografía personalizada, 36 px en escritorio y 24 px en móvil. La vista previa autenticada devolvió esos mismos tamaños calculados para el H2 al probar ventanas de 1440 y 390 px de ancho, respectivamente.

### Dos planes sobre la misma versión del borrador

El 11 de noviembre de 2026 se enviaron en paralelo desde MCP dos solicitudes de ejecución aprobadas para el título del widget `24bfb247` de la página DEV 2998. El plan A (`40c518d5689d965fe726ae90c49bf26f`) terminó en `completed` con un cambio verificado. El plan B (`18d81e802a3293cbb2f0424f8b3eecaf`) devolvió `DTR_CONTENT_CHANGED` y su registro seguía en `approved` al consultar el estado. Una lectura independiente confirmó «Prueba de concurrencia A», el estado de borrador y el mismo hash que devolvió A. La prueba verifica el rechazo por contenido cambiado entre dos solicitudes lanzadas en paralelo. El solapamiento de las escrituras dentro del servidor queda sin medir.

### Recuperación del H2 mediante revisión nativa

El 11 de noviembre de 2026, tras la restauración manual en WordPress DEV, la página 2998 seguía en borrador. Al comparar recursivamente los árboles de Elementor antes y después, la única diferencia fue el título del widget `24bfb247`, que pasó de «Prueba de concurrencia A» a «Encabezado de prueba RC6». Conservó los tamaños guardados de 36 px en escritorio y 24 px en móvil. La vista previa recargada confirmó el texto recuperado en un H2.

### Descubrimiento del esquema desde MCP en DEV

El 11 de noviembre de 2026, `wp_capabilities` de `fisiolaser-dev` devolvió RC7 y anunció `elementor_schema` y `elementor_edit`. En el catálogo disponible en esta sesión faltaba `wp_elementor_schema` y la descripción de `wp_editor_plan` omitía `elementor_edit`. El adaptador de lectura activo excluía `elementor_schema` de las operaciones admitidas por `extended_read`. En la ejecución de prueba n8n 4789, el validador real rechazó esa entrada con `INVALID_ARGUMENT` antes del nodo HTTP. Se simularon la entrada y el nodo HTTP para aislar el validador. La obtención del esquema desde el cliente sigue pendiente.
### Adaptador DEV: lectura de `elementor_schema` (11 de noviembre de 2026)

En la prueba 4790 el validador aceptó `elementor_schema` y devolvió `ok:true` con una respuesta HTTP simulada. Se publicó en el adaptador de lectura DEV la versión `5e81ace6-0471-4604-aeb6-8b26251cb2f1`; la única diferencia en los nodos y conexiones fue la nueva operación en la lista permitida. En la ejecución manual 4791, el control de acceso del router rechazó al flujo temporal de QA antes de consultar WordPress. El flujo temporal se archivó. Queda verificar los controles de `heading` mediante el catálogo MCP autorizado; no se modificó el control de acceso ni el catálogo compartido.


### Consulta por tipo de widget desde el catálogo MCP publicado

El 11 de noviembre de 2026, el cliente autenticado encontró `wp_elementor_schema` y consultó `fisiolaser-dev`. Para `heading` recibió la opción `h2` y controles de tamaño de escritorio y móvil. Para `button`, la respuesta incluyó únicamente el esquema de ese widget y confirmó los tipos de `text` (text), `link` (url), `button_type` (select) y `text_padding` (dimensions). Al solicitar `dtr_qa_widget_inexistente`, el adaptador devolvió `ok:false` y `UNKNOWN_WIDGET_TYPE`, sin datos. Las tres consultas fueron de lectura.

### Revisión de la candidata RC7 para GitHub

En DEV, el plan `a4ad2e2184bfaf58eae63a0cebbae663` terminó en completed. Una relectura independiente del borrador 2991 confirmó que solo cambió el texto del botón a «Ver página de gracias». El enlace siguió apuntando a /gracias/.

Se ejecutó lint sobre diez archivos PHP y pasaron las suites de ciclo (90 comprobaciones) y de código habilitado (48), tanto con el sanitizador de prueba como con KSES de WordPress 6.8. El empaquetador verificó las rutas y el contenido de los seis archivos del ZIP contra el código local. SHA256 de la RC7: e65b43d8324069d441897cb6fb6f001ebe49e048c251120a4a352b639ad1a3ce.

El inventario de DEV devolvió Bridge 1.3.7-rc.7, WordPress 7.1.4, Elementor 4.3.5 y Pro 4.3.0. WooCommerce no figuraba entre los 32 plugins devueltos. El borrador V4 3013 devolvió cero elementos. Quedan pendientes la escritura y validación V4, la regresión funcional con WooCommerce y la matriz GitHub Actions sobre la revisión final.
