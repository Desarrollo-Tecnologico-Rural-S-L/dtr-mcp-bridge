# Elementor V3: alcance y pruebas

En DEV se verificaron la edición de texto, botones, imágenes, listas, formularios, acordeones y pestañas. También se probaron la inserción, duplicación, movimiento, reordenación y eliminación de contenedores. Cada cambio parte de una lectura con hash, requiere aprobación en WordPress y se comprueba con una lectura posterior.

La API Document guarda el árbol derivado de las operaciones puntuales. Los repeaters admiten cambios por ruta o sustitución validada y cada fila necesita un `_id` único. La RC7 añade validación de tipografía personalizada y tamaños por dispositivo. En V4, RC9 identifica los elementos atómicos en lectura y permite editar el texto plano del título de un `e-heading` existente.

Consultar rest-elementor.md para reglas de jerarquía, código y formularios; test-report.md para distinguir pruebas ejecutadas de pendientes.
