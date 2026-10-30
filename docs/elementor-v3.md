# Elementor V3: alcance y pruebas

La versión 1.3.7 es candidata y necesita validar cambios de widgets de texto, HTML, lista de iconos, formularios, estilos, responsive y operaciones estructurales. Toda modificación debe preparar un plan revisable con hash esperado, aprobación WordPress, ejecución única y lectura posterior. No prometer inserción/movimiento/eliminación genérica hasta implementar y probar.

En rc.2 las operaciones V3 están implementadas y verificadas con contratos aislados; falta comprobarlas en Elementor real. La API Document guarda el documento completo derivado de operaciones puntuales, preservando nodos que no se cambian; no se acepta un árbol sustituto del cliente. Los repeaters se editan por rutas o sustitución validada, y cada fila necesita _id único. No se escriben props/styles atómicos V4; los elementos atómicos se identifican en lectura y se rechazan como destino de mutaciones.

Consultar rest-elementor.md para reglas de jerarquía, código y formularios; test-report.md para distinguir pruebas ejecutadas de pendientes.
