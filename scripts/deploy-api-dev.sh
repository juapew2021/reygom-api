#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
exec 9>/opt/reygom/deploy-api-dev.lock
flock -n 9 || { echo 'Another API DEV deployment is running'; exit 1; }
exec > >(tee -a /opt/reygom/logs/deploy-api-dev.log) 2>&1
REPO=/root/reygom-api
git -C "$REPO" fetch origin develop
SHA=$(git -C "$REPO" rev-parse origin/develop)
if [[ -n ${1:-} && $1 != "$SHA" ]]; then
  echo 'Requested commit is no longer develop HEAD'; exit 1
fi
IMAGE=reygom-api:dev-${SHA:0:7}
RUN=$(mktemp -d /opt/reygom/releases/api-dev-${SHA:0:7}-XXXXXX)
mkdir -p "$RUN/source" "$RUN/rollback"
git -C "$REPO" archive "$SHA" | tar -x -C "$RUN/source"
printf '%s\n' "$SHA" > "$RUN/commit.txt"
K=(microk8s kubectl -n reygom-dev)
OLD=$("${K[@]}" get deployment reygom-api -o jsonpath='{.spec.template.spec.containers[?(@.name=="api")].image}')
test -n "$OLD"
"${K[@]}" get deployment reygom-api -o yaml > "$RUN/rollback/deployment.yaml"
printf '%s\n' "$OLD" > "$RUN/rollback/image.txt"
case "$OLD" in docker.io/*) OLD_EXPORT="$OLD";; *) OLD_EXPORT="docker.io/library/$OLD";; esac
microk8s ctr images export "$RUN/rollback/image.tar" "$OLD_EXPORT"
docker build -t "$IMAGE" "$RUN/source"
docker run --rm --network none --entrypoint sh "$IMAGE" -ec 'find index.php src -type f -name "*.php" | while IFS= read -r file; do php -l "$file" || exit 1; done; if [ -f tests/PropertyImageEnvironmentTest.php ]; then php tests/PropertyImageEnvironmentTest.php; fi'
docker save "$IMAGE" -o "$RUN/image.tar"
microk8s ctr images import "$RUN/image.tar"
rollback() {
  status=$?
  trap - ERR
  "${K[@]}" set image deployment/reygom-api "api=$OLD"
  "${K[@]}" rollout status deployment/reygom-api --timeout=180s
  exit "$status"
}
trap rollback ERR
"${K[@]}" set image deployment/reygom-api "api=$IMAGE"
"${K[@]}" rollout status deployment/reygom-api --timeout=180s
curl --fail --silent --show-error --retry 3 https://api-dev.reygom.com/ > "$RUN/health.json"
trap - ERR
echo "API DEV deployed: $SHA. Rollback: $RUN/rollback"
