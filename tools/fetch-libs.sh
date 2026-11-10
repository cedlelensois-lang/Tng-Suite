#!/usr/bin/env bash
# Télécharge les bibliothèques JavaScript tierces (versions figées) dans le plugin, depuis le registre npm.
# À lancer une fois sur une machine avec accès Internet : ./tools/fetch-libs.sh
# Exécuté aussi automatiquement par l'action GitHub de publication : le zip distribué est alors autonome (aucun CDN).
set -euo pipefail
cd "$(dirname "$0")/.."
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
pack() { (cd "$tmp" && npm pack --silent "$1" >/dev/null && mkdir -p "$2" && tar xzf "$(ls -1 *.tgz | head -1)" -C "$2" && rm -f ./*.tgz); }

mkdir -p libs modules/heatmap/assets/vendor/images

pack chart.js@4.4.1 chartjs
cp "$tmp/chartjs/package/dist/chart.umd.js" libs/chart.umd.min.js
cp "$tmp/chartjs/package/LICENSE.md" libs/CHARTJS-LICENSE.md

pack vis-network@9.1.9 vis
cp "$tmp/vis/package/standalone/umd/vis-network.min.js" libs/vis-network.min.js
cp "$tmp/vis/package/LICENSE-MIT" libs/VIS-NETWORK-LICENSE-MIT 2>/dev/null || true

pack leaflet@1.9.4 leaflet
cp "$tmp/leaflet/package/dist/leaflet.js" modules/heatmap/assets/vendor/leaflet.js
cp "$tmp/leaflet/package/dist/leaflet.css" modules/heatmap/assets/vendor/leaflet.css
cp "$tmp/leaflet/package/dist/images/"* modules/heatmap/assets/vendor/images/

pack leaflet.heat@0.2.0 heat
cp "$tmp/heat/package/dist/leaflet-heat.js" modules/heatmap/assets/vendor/leaflet-heat.js

# Contrôle d'intégrité de Leaflet (même empreinte que celle utilisée par le plugin si elle passe par le CDN)
sum="$(openssl dgst -sha256 -binary modules/heatmap/assets/vendor/leaflet.js | openssl base64 -A)"
[ "sha256-$sum" = "sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" ] || { echo "Empreinte de leaflet.js inattendue : $sum" >&2; exit 1; }
echo "Bibliothèques installées."
