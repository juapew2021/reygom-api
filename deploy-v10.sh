#!/usr/bin/env bash
set -euo pipefail

NAMESPACE="${NAMESPACE:-reygom}"
DEPLOYMENT="${DEPLOYMENT:-reygom-api}"
IMAGE="${IMAGE:-reygom-api:v10}"
CONTAINER="${CONTAINER:-reygom-api}"

command -v docker >/dev/null || { echo "Falta docker"; exit 1; }
command -v kubectl >/dev/null || { echo "Falta kubectl"; exit 1; }

./validate.sh

docker build -t "$IMAGE" .
kubectl -n "$NAMESPACE" set image "deployment/$DEPLOYMENT" "$CONTAINER=$IMAGE"
kubectl -n "$NAMESPACE" rollout status "deployment/$DEPLOYMENT" --timeout=180s
kubectl -n "$NAMESPACE" get pods -l app="$DEPLOYMENT"

echo "Despliegue completado: $IMAGE"
