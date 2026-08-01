#!/usr/bin/env bash

set -Eeuo pipefail

NAMESPACE="${NAMESPACE:-reygom}"
DEPLOYMENT="${DEPLOYMENT:-reygom-api}"
CONTAINER="${CONTAINER:-api}"
IMAGE_NAME="${IMAGE_NAME:-reygom-api}"
VERSION="${VERSION:-v10}"
BUILD_ID="$(date +%Y%m%d%H%M%S)"
IMAGE="${IMAGE_NAME}:${VERSION}-${BUILD_ID}"

handle_error() {
    local exit_code=$?

    echo
    echo "❌ El despliegue ha fallado."
    echo "Código de salida: ${exit_code}"

    if [[ -n "${KUBECTL_COMMAND_DISPLAY:-}" ]]; then
        echo
        echo "Estado actual del Deployment:"
        "${KUBECTL[@]}" -n "$NAMESPACE" get deployment "$DEPLOYMENT" || true

        echo
        echo "Pods actuales:"
        "${KUBECTL[@]}" -n "$NAMESPACE" get pods \
            -l app="$DEPLOYMENT" \
            -o wide || true
    fi

    exit "$exit_code"
}

trap handle_error ERR

command -v docker >/dev/null 2>&1 || {
    echo "❌ Docker no está instalado o no está disponible."
    exit 1
}

if command -v microk8s >/dev/null 2>&1; then
    KUBECTL=(microk8s kubectl)
    KUBECTL_COMMAND_DISPLAY="microk8s kubectl"
    USE_MICROK8S=true
elif command -v kubectl >/dev/null 2>&1; then
    KUBECTL=(kubectl)
    KUBECTL_COMMAND_DISPLAY="kubectl"
    USE_MICROK8S=false
else
    echo "❌ No se encontró microk8s ni kubectl."
    exit 1
fi

echo "========================================="
echo " Despliegue de ReyGom API"
echo "========================================="
echo "Namespace:  $NAMESPACE"
echo "Deployment: $DEPLOYMENT"
echo "Contenedor: $CONTAINER"
echo "Imagen:     $IMAGE"
echo

echo "========================================="
echo " 1. Validando proyecto"
echo "========================================="

./validate.sh

echo
echo "========================================="
echo " 2. Construyendo imagen Docker"
echo "========================================="

docker build \
    --pull \
    --no-cache \
    -t "$IMAGE" \
    .

if [[ "$USE_MICROK8S" == true ]]; then
    echo
    echo "========================================="
    echo " 3. Importando imagen en MicroK8s"
    echo "========================================="

    docker save "$IMAGE" | microk8s ctr image import -
else
    echo
    echo "⚠️ Se está usando kubectl externo."
    echo "La imagen debe existir en un registro accesible por Kubernetes."
fi

echo
echo "========================================="
echo " 4. Actualizando Deployment"
echo "========================================="

"${KUBECTL[@]}" -n "$NAMESPACE" set image \
    "deployment/$DEPLOYMENT" \
    "$CONTAINER=$IMAGE"

if [[ "$USE_MICROK8S" == true ]]; then
    "${KUBECTL[@]}" -n "$NAMESPACE" patch deployment "$DEPLOYMENT" \
        --type='strategic' \
        -p="{
            \"spec\": {
                \"template\": {
                    \"spec\": {
                        \"containers\": [
                            {
                                \"name\": \"$CONTAINER\",
                                \"imagePullPolicy\": \"IfNotPresent\"
                            }
                        ]
                    }
                }
            }
        }"
fi

echo
echo "========================================="
echo " 5. Esperando el nuevo pod"
echo "========================================="

"${KUBECTL[@]}" -n "$NAMESPACE" rollout status \
    "deployment/$DEPLOYMENT" \
    --timeout=240s

echo
echo "========================================="
echo " 6. Verificando imagen desplegada"
echo "========================================="

DEPLOYED_IMAGE="$(
    "${KUBECTL[@]}" -n "$NAMESPACE" get deployment "$DEPLOYMENT" \
        -o jsonpath="{.spec.template.spec.containers[?(@.name=='$CONTAINER')].image}"
)"

if [[ "$DEPLOYED_IMAGE" != "$IMAGE" ]]; then
    echo "❌ La imagen desplegada no coincide."
    echo "Esperada:   $IMAGE"
    echo "Desplegada: $DEPLOYED_IMAGE"
    exit 1
fi

echo "✅ Imagen desplegada: $DEPLOYED_IMAGE"

echo
echo "========================================="
echo " 7. Verificando pod activo"
echo "========================================="

POD="$(
    "${KUBECTL[@]}" -n "$NAMESPACE" get pods \
        -l app="$DEPLOYMENT" \
        --field-selector=status.phase=Running \
        --sort-by=.metadata.creationTimestamp \
        -o jsonpath='{.items[-1:].metadata.name}'
)"

if [[ -z "$POD" ]]; then
    echo "❌ No se encontró ningún pod activo de $DEPLOYMENT."
    exit 1
fi

echo "Pod activo: $POD"

"${KUBECTL[@]}" -n "$NAMESPACE" get pod "$POD" -o wide

echo
echo "========================================="
echo " 8. Verificando código dentro del pod"
echo "========================================="

LOCAL_AUTH_HASH="$(sha256sum src/Routes/auth.php | awk '{print $1}')"

POD_AUTH_HASH="$(
    "${KUBECTL[@]}" -n "$NAMESPACE" exec "$POD" -- \
        sha256sum /var/www/html/src/Routes/auth.php |
        awk '{print $1}'
)"

echo "Hash local auth.php: $LOCAL_AUTH_HASH"
echo "Hash pod auth.php:   $POD_AUTH_HASH"

if [[ "$LOCAL_AUTH_HASH" != "$POD_AUTH_HASH" ]]; then
    echo "❌ El auth.php desplegado no coincide con el archivo local."
    exit 1
fi

echo "✅ auth.php coincide con el código local."

"${KUBECTL[@]}" -n "$NAMESPACE" exec "$POD" -- \
    grep -n -E "use App\\Models\\User|expires_in" \
    /var/www/html/src/Routes/auth.php

echo
echo "========================================="
echo " ✅ Despliegue completado correctamente"
echo "========================================="
echo "Imagen: $IMAGE"
echo "Pod:    $POD"
