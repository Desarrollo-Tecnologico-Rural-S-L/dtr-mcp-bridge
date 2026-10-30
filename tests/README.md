# Pruebas PHP y límites

Ejecutar con PHP >=8.1:

```sh
php tests/lifecycle.php
php tests/elementor.php --code-enabled
```

lifecycle incluye la suite básica. Usa dobles explícitos de permisos, DB y Document API; las aprobaciones de fixture no se trasladan al sitio real. bootstrap contiene un sanitizador mínimo de prueba. Para usar KSES oficial, definir DTR_WP_KSES_PATH con el archivo wp-includes/kses.php de WordPress 6.8 (hash en docs/test-report.md). No activar ni instalar tests dentro de WordPress.

CI configura PHP 8.1/8.3/8.5, lint y suites. Su configuración no es evidencia de ejecución. La matriz manual DEV y pruebas pendientes están en docs/test-report.md. Usar exclusivamente DEV, con aprobación manual del propietario.
