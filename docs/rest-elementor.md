# REST Elementor — candidata rc.2

Base: `/wp-json/dtr-mcp/v1`. HTTPS y Application Password vinculada a usuario con `dtr_mcp_access`, `dtr_mcp_content` y capacidades del contenido. Escritura requiere la opción local `dtr_mcp_content_writes=yes`. No se activan opciones ni capacidades al actualizar. No hay endpoint de aprobación remota.

## Lectura y descubrimiento

`GET /extended/read?operation=elementor&query_json={"id":123}` devuelve árbol `elements`, índice `matches` (ID, padre, posición, tipo, formato), ajustes de página y kit accesible, condiciones, `tree_hash` y `expected_hash`. Los ajustes del kit incluyen los breakpoints configurados y valores globales almacenados; no equivalen a estilos CSS computados ni a resolver todas las clases V4.

Filtros opcionales en query_json: `element_id`, `type` (elType o widgetType), `property` (clave superior de settings) y `value` (igualdad exacta). `matches` se filtra; el árbol íntegro sigue disponible. Para búsqueda literal anidada usar `operation=elementor_search`, argumentos `{id,search}`.

`operation=elementor_schema`, `query_json={}` devuelve controles registrados, tipos, restricciones, acciones y JSON Schema de argumentos. No se deducen widgets instalados a partir de una lista fija. Controles personalizados sin adaptador de validación se rechazan. `operation=templates` mantiene `{id}` o `{url}` y devuelve condiciones almacenadas y observación del HTML; no evalúa condiciones personalizadas.

## Preparación y ejecución

`POST /extended/plan`:

```json
{"operation":"elementor_edit","arguments":{"id":123,"expected_hash":"HASH_DEVUELTO_POR_LECTURA","operations":[{"action":"set","element_id":"a123bc45","path":["link","url"],"before":"/anterior","after":"/nueva"}]}}
```

`expected_hash` debe ser el SHA256 de 64 caracteres obtenido al leer, nunca un hash inventado por el cliente. Todas las rutas son relativas a `settings`, no al documento. El esquema formal está en `dtr-mcp-bridge/elementor-contract.json`.

| Acción | Parámetros adicionales obligatorios | Resultado |
| --- | --- | --- |
| set | element_id, path, before, after | cambia valor existente; para clave ausente usar before_exists:false y before:null; sus padres deben existir |
| unset | element_id, path, before | quita propiedad; reindexa una lista si elimina un índice |
| insert | parent_id, position, element | inserta un elemento sin ID ni descendientes; ID determinista asignado por servidor |
| duplicate | element_id, parent_id, position | copia subárbol con IDs nuevos; se valida todo el contenido copiado |
| move | element_id, parent_id, position | conserva ID; posición se calcula después de extraer el elemento |
| reorder | parent_id, order | permutación exacta de IDs hijos, sin omisiones ni duplicados |
| remove | element_id | elimina elemento y descendientes |

`parent_id:null` significa raíz. Raíz admite sección/contenedor, sección admite columnas, columna admite widget/sección/contenedor, contenedor admite widget/contenedor. No se insertan hijos arbitrarios de widgets anidados: requieren un adaptador específico. Para construir jerarquías, insertar por planes sucesivos usando los IDs devueltos en preview. Las operaciones de un plan se aplican secuencialmente en memoria y se guardan una vez.

Ejemplos de operaciones (dentro del envoltorio anterior):

```json
{"action":"insert","parent_id":"a123bc45","position":0,"element":{"elType":"widget","widgetType":"heading","settings":{"title":"Nuevo título"}}}
```
```json
{"action":"set","element_id":"a123bc45","path":["padding_mobile"],"before_exists":false,"before":null,"after":{"top":"10","right":"10","bottom":"10","left":"10","unit":"px","isLinked":true}}
```

Plan devuelve `plan_id`, `preview`, `expires_at`, `approval_required:true`. El administrador propietario del sitio revisa el cambio en Herramientas → DTR MCP, con sesión y nonce. El operador MCP no aprueba. Después, `POST /extended/execute` acepta **solo** `{"plan_id":"32_HEX"}`. Consultar `/plans/{plan_id}` ante timeout; no repetir ni crear otro plan automáticamente.

## Compatibilidad y límites

- Se conserva `elementor_patch` con patches `{element_id,path,before,after}` y se añade `before_exists`. Inserción Post Content conserva su nombre y usa las reglas genéricas sobre plantillas Elementor, sin IDs de clientes.
- máximo 60 operaciones, 500 KB por plan, 2 MB por documento, 4000 elementos y profundidad 30; valores hasta 100 KB y 200 entradas por colección.
- Campos HTML y custom_css requieren `DTR_MCP_ALLOW_CODE_PATCHES === true` y `unfiltered_html` efectivo. Se aceptan HTML de presentación compatible con KSES y CSS local sin url/import/escapes. JavaScript, PHP, shortcodes, atributos personalizados y contenido dinámico quedan bloqueados. No se crea una constante que habilite JavaScript.
- Form Pro: campos visibles y presentación; bloqueados destinatarios, acciones, integraciones y cambios de campos ocultos/antispam. Algunos formularios con configuraciones complejas se rechazan de forma conservadora.
- Lectura V4 preserva campos; escritura atómica no implementada (`DTR_ATOMIC_WRITE_UNSUPPORTED`). No hay certificación V4. No se modifican globales ni condiciones mediante estilos.

## Estados y errores

`pending → approved → running → completed | failed | verification_failed | uncertain`. Rechazo local produce `rejected`; caducidad se deriva para pending/approved. Un plan reclamado nunca vuelve a approved.

Éxito verificado: `{plan_id,status:"completed",retry_safe:false,result:{id,persisted:true,verified:true,expected_hash,saved_via}}`.

Guardado comunicado por Elementor pero datos distintos: `status:"verification_failed"`, `persisted:true`, `verified:false`, `retry_safe:false`. Excepción durante guardado: `DTR_EXTENDED_EXECUTION_UNCERTAIN`, estado `uncertain`; no se expone mensaje de excepción. Error de auditoría: `DTR_AUDIT_WRITE_FAILED`, plan permanece consumido/running. El resultado siempre necesita inspección antes de restaurar o repetir.

Errores 400: DTR_INVALID_ARGUMENTS, DTR_INVALID_OPERATIONS, DTR_INVALID_PATCH, DTR_INVALID_PATH, DTR_INVALID_ELEMENT_ID, DTR_DUPLICATE_ELEMENT_ID, DTR_CONTROL_TYPE_MISMATCH, DTR_UNKNOWN_CONTROL, DTR_INVALID_ORDER, DTR_INCOMPATIBLE_PARENT, DTR_MOVE_CYCLE, DTR_INVALID_POSITION. Errores 403: permisos, DTR_PROTECTED_SETTING, DTR_FORM_SECURITY_PROTECTED, DTR_CODE_PATCHES_DISABLED, DTR_UNSAFE_HTML, DTR_UNSAFE_CSS, DTR_EXECUTABLE_CONTENT_DENIED. Conflictos 409: DTR_CONTENT_CHANGED, DTR_PATCH_CONFLICT, DTR_PLAN_CHANGED, DTR_NOT_APPROVED_OR_EXPIRED, DTR_ALREADY_CLAIMED, DTR_SITE_LOCKED, DTR_EDITOR_LOCKED, DTR_ATOMIC_WRITE_UNSUPPORTED. Error 500: persistencia, auditoría o ejecución incierta. Límites: pueden devolver 400 o 413 según la fase.
