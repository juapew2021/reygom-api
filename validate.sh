#!/usr/bin/env bash
set -euo pipefail

NAMESPACE="${NAMESPACE:-reygom}"
APP_LABEL="${APP_LABEL:-reygom-api}"

if command -v php >/dev/null 2>&1; then
    echo "Validando con PHP local..."

    while IFS= read -r file; do
        php -l "$file"
    done < <(find src -type f -name '*.php' | sort)

    php -l index.php
else
    echo "PHP no está instalado localmente."
    echo "Validando con PHP dentro del pod de ReyGom..."

    POD="$(
        microk8s kubectl get pods \
            -n "$NAMESPACE" \
            -l "app=$APP_LABEL" \
            -o jsonpath='{.items[0].metadata.name}'
    )"

    if [ -z "$POD" ]; then
        echo "No se encontró ningún pod con la etiqueta app=$APP_LABEL"
        exit 1
    fi

    echo "Pod utilizado: $POD"

    while IFS= read -r file; do
        echo "Validando $file"

        cat "$file" | microk8s kubectl exec \
            -i \
            -n "$NAMESPACE" \
            "$POD" \
            -- php -l /dev/stdin
    done < <(find src -type f -name '*.php' | sort)

    echo "Validando index.php"

    cat index.php | microk8s kubectl exec \
        -i \
        -n "$NAMESPACE" \
        "$POD" \
        -- php -l /dev/stdin
fi

echo "Validación PHP completada correctamente."
