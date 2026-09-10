#!/bin/sh
# Builds tools/preview/co-notes-preview.html: index.html with the stylesheet, the
# mock API, and app.js inlined, so the frontend can be opened straight from disk
# with no PHP, MySQL, or OAuth.
#
#   sh tools/preview/build.sh
#
# Then open tools/preview/co-notes-preview.html in a browser.
# Windows equivalent: tools/preview/build.ps1
#
# The asset tags carry a `?v=` cache-busting version (see sw.js), so the match
# has to tolerate any version - and, more importantly, has to *fail* when it
# matches nothing. It used to substitute silently, so a changed tag produced a
# 12KB harness with no app in it that still opened and just sat there.

set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
out="$root/tools/preview/co-notes-preview.html"

awk -v css="$root/public_html/assets/app.css" \
    -v mock="$root/tools/preview/mock-api.js" \
    -v app="$root/public_html/assets/app.js" '
  function dump(f,   line) { while ((getline line < f) > 0) print line; close(f) }

  /<link rel="stylesheet" href="\/assets\/app\.css(\?v=[0-9]+)?">/ {
    print "<style>"; dump(css); print "</style>"; cssDone++; next
  }
  /<script src="\/assets\/app\.js(\?v=[0-9]+)?"><\/script>/ {
    print "<script>"; dump(mock); print "</script>"
    print "<script>"; dump(app);  print "</script>"; appDone++; next
  }
  { print }
  END {
    if (cssDone != 1 || appDone != 1) {
      printf("build.sh: matched %d stylesheet and %d script tag(s), expected 1 of each -\n" \
             "  the asset tags in index.html have changed shape; fix the patterns above.\n",
             cssDone, appDone) > "/dev/stderr"
      exit 1
    }
  }
' "$root/public_html/index.html" > "$out"

echo "built $out ($(wc -c < "$out" | tr -d ' ') bytes)"
