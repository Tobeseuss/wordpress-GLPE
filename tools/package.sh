#!/usr/bin/env bash
# Package the cloud-portal plugin into a WordPress-ready ZIP.
# Usage: bash tools/package.sh [version]   (version is optional, default from main file)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT/cloud-portal"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    VERSION=$(grep -oP "define\('CLOUD_PORTAL_VERSION',\s*'\K[^']+" "$PLUGIN_DIR/cloud-portal.php" || echo "dev")
fi

DIST_DIR="$ROOT/dist"
mkdir -p "$DIST_DIR"
ZIP_PATH="$DIST_DIR/cloud-portal-wp-$VERSION.zip"

if command -v zip >/dev/null 2>&1; then
    (cd "$ROOT" && zip -r "$ZIP_PATH" cloud-portal -x '*.DS_Store' -x '*__MACOSX*')
else
    # Fallback: python zipfile (works without `zip` binary)
    python3 - "$ZIP_PATH" <<'PY'
import os, sys, zipfile
zip_path, plugin_dir, name = sys.argv[1], os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(sys.argv[0]))), "..", "cloud-portal"), "cloud-portal"
root = os.path.abspath(os.path.join(os.path.dirname(zip_path), ".."))
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
    for base, _dirs, files in os.walk(os.path.join(root, "cloud-portal")):
        for f in files:
            if f == ".DS_Store":
                continue
            full = os.path.join(base, f)
            z.write(full, os.path.relpath(full, root))
print("created", zip_path)
PY
fi

echo "✅ Packaged: $ZIP_PATH"
echo "   Install via WordPress → Plugins → Add New → Upload Plugin"
