# Seguridad

- Nunca subir secretos, credenciales de WordPress o WooCommerce, ni `wp-config.php`.
- Validar permiso efectivo, rol y sitio antes de cada lectura o escritura.
- Los cambios públicos requieren aprobación del propietario.
- Aplicar control de concurrencia y protección contra reejecución.
- Aislar HTML, CSS y JavaScript bajo controles adicionales.
- Guardar auditoría; no registrar tokens.

## rc.2: controles y límites comprobables

Las mutaciones Elementor exigen plan de 15 minutos, hash previo, hash de payload y comparación del plan recalculado. Se revalidan permisos del operador y manage_options del aprobador. La reclamación SQL impide ejecutar dos veces un plan. Se conserva la aprobación manual POST con nonce; no se crea API de aprobación.

El motor valida controles instalados, tipos, tamaño/profundidad, unicidad de IDs, destinos y ciclos; revisa valores anidados y URLs completas. HTML y CSS requieren constante deshabilitada por defecto más unfiltered_html; JavaScript/PHP/shortcodes/atributos dinámicos quedan prohibidos. Configuración operativa de formularios no es editable como estilo. Los errores no publican excepciones ni credenciales.

Estados terminales verification_failed y uncertain impiden reintentos peligrosos. Un fallo de auditoría conserva running consumido. El cliente debe consultar el plan original si pierde la respuesta.

Riesgos abiertos antes de aceptación: el bloqueo de sitio solo coordina escritores que lo respetan; sigue existiendo una ventana entre comprobación y Document API frente a escritores externos. No se afirma aislamiento transaccional. Las opciones históricas de planes contienen snapshots y necesitan una política de retención/migración; el informe no se interpreta como certificación. Los tests de CSRF, autenticación real y guardado/renderizado deben completarse en DEV. Los controles/HTML de terceros pueden tener semánticas adicionales que requieren adaptadores específicos.
