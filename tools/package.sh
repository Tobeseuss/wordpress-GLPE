#!/usr/bin/env bash
# Package the GLPE Viewer plugin into a WordPress-ready ZIP.
# Usage: bash tools/package.sh [version]   (version optional; default from main file)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT/glpe-viewer"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    VERSION=$(grep -oP "define\('GLPE_VERSION',\s*'\K[^']+" "$PLUGIN_DIR/glpe-viewer.php" || echo "dev")
fi

DIST_DIR="$ROOT/dist"
mkdir -p "$DIST_DIR"
ZIP_PATH="$DIST_DIR/glpe-viewer-$VERSION.zip"

if command -v zip >/dev/null 2>&1; then
    (cd "$ROOT" && zip -rq "$ZIP_PATH" glpe-viewer -x '*.DS_Store' -x '*__MACOSX*')
else
    python3 - "$ZIP_PATH" <<'PY'
import os, sys, zipfile
zip_path = sys.argv[1]
root = os.path.abspath(os.path.join(os.path.dirname(zip_path), ".."))
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
    for base, _dirs, files in os.walk(os.path.join(root, "glpe-viewer")):
        for f in files:
            if f == ".DS_Store":
                continue
            full = os.path.join(base, f)
            z.write(full, os.path.relpath(full, root))
print("created", zip_path)
PY
fi

echo "OK: $ZIP_PATH"
echo "Install via WordPress -> Plugins -> Add New -> Upload Plugin"
