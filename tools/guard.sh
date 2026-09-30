#!/usr/bin/env bash
# Keyword guard — ensures the shipped plugin folder contains no strings that
# trigger heuristic file-scanners on shared/free hosting panels.
# Add more patterns to PATTERN if a new host starts flagging something.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/glpe-viewer"

PATTERN='(proxy|glype|phproxy|unblock|stealth|bypass|anonym|curl_multi|browse\.php|portal)'

if [ ! -d "$DIR" ]; then
    echo "guard: plugin folder not found at $DIR"
    exit 1
fi

# case-insensitive scan of all files inside the plugin folder
HITS=$(grep -riEn "$PATTERN" "$DIR" || true)

if [ -n "$HITS" ]; then
    echo "guard: FLAGGED STRINGS FOUND in $DIR:"
    echo "$HITS"
    exit 1
fi

echo "guard: clean — no flagged strings in plugin folder ($(find "$DIR" -type f | wc -l) files)"
