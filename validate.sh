#!/usr/bin/env sh
set -eu
PHP_BIN="${PHP_BIN:-php}"
"$PHP_BIN" -l src/Models/Property.php
"$PHP_BIN" -l src/Routes/properties.php
echo "Validación PHP completada correctamente."
