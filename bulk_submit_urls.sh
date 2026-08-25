#!/usr/bin/env bash
#
# bulk_submit_urls.sh - Bulk-submit URLs from a CSV file for Admin approval.
#
# Reads a CSV file of "url,category" rows (one per line) and POSTs each one
# to the running app's public submission endpoint (submit.php), the same
# endpoint the "Submit a URL" form uses. Submissions land in submitted_urls
# awaiting an admin's approve/reject in admin.php - this script does not
# bypass that review step.
#
# Runs from outside the container: it only needs the web port that
# docker-compose.yml publishes to the host (8081 http / 8443 https), not
# direct DB access.
#
# Category handling:
#   - A blank category, or one of "Unknown"/"Missing" (case-insensitive),
#     is routed to the "General" category.
#   - A category that doesn't match any existing category name is also
#     routed to "General", with a warning, so the import is never blocked
#     by a typo.
#
# Usage:
#   ./bulk_submit_urls.sh <csv_file> [base_url]
#
#   csv_file   Path to a CSV file with "url,category" rows. An optional
#              header row ("url,category") is detected and skipped.
#   base_url   App base URL (default: http://localhost:8081). Use an
#              https:// URL to hit the TLS port (8443); the container's
#              self-signed cert is accepted automatically in that case.
#
# Example CSV:
#   url,category
#   https://example.com/wiki,General
#   https://docs.example.com/new-guide,Documentation
#   https://tool.example.com/dash,Unknown

set -uo pipefail

usage() {
    echo "Usage: $0 <csv_file> [base_url]" >&2
    echo "  csv_file  Path to a CSV file with 'url,category' rows (one per line)" >&2
    echo "  base_url  App base URL (default: http://localhost:8081)" >&2
    exit 1
}

[ "$#" -ge 1 ] && [ "$#" -le 2 ] || usage
CSV_FILE="$1"
BASE_URL="${2:-http://localhost:8081}"
BASE_URL="${BASE_URL%/}"

[ -f "$CSV_FILE" ] || { echo "Error: CSV file not found: $CSV_FILE" >&2; exit 1; }

CURL_OPTS=(-s)
case "$BASE_URL" in
    https://*) CURL_OPTS+=(-k) ;;
esac

SUBMIT_URL="$BASE_URL/submit.php"

trim() {
    local s="$1"
    s="${s#"${s%%[![:space:]]*}"}"
    s="${s%"${s##*[![:space:]]}"}"
    printf '%s' "$s"
}

strip_quotes() {
    local s="$1"
    if [[ "$s" == \"*\" && ${#s} -ge 2 ]]; then
        s="${s:1:-1}"
    fi
    printf '%s' "$s"
}

decode_entities() {
    local s="$1"
    s="${s//&amp;/&}"
    s="${s//&quot;/\"}"
    s="${s//&#039;/\'}"
    s="${s//&lt;/<}"
    s="${s//&gt;/>}"
    printf '%s' "$s"
}

# --- Fetch the current category list from the live submission form ---
echo "Fetching categories from $SUBMIT_URL ..."
if ! FORM_HTML="$(curl "${CURL_OPTS[@]}" "$SUBMIT_URL")"; then
    echo "Error: could not reach $SUBMIT_URL" >&2
    exit 1
fi

if [ -z "$FORM_HTML" ]; then
    echo "Error: empty response from $SUBMIT_URL - is the app running at $BASE_URL?" >&2
    exit 1
fi

CATEGORY_NAMES=()
CATEGORY_IDS=()
GENERAL_ID=""

while IFS= read -r opt_line; do
    id="$(printf '%s' "$opt_line" | sed -E 's/.*value="([0-9]+)".*/\1/')"
    name="$(printf '%s' "$opt_line" | sed -E 's/.*>([^<]*)<\/option>.*/\1/')"
    name="$(decode_entities "$name")"
    [ -n "$id" ] && [ -n "$name" ] || continue
    CATEGORY_IDS+=("$id")
    CATEGORY_NAMES+=("$name")
    if [ "$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')" = "general" ]; then
        GENERAL_ID="$id"
    fi
done < <(printf '%s\n' "$FORM_HTML" | grep -oE '<option value="[0-9]+">[^<]*</option>')

if [ "${#CATEGORY_IDS[@]}" -eq 0 ]; then
    echo "Error: no categories found on the submission form at $SUBMIT_URL." >&2
    exit 1
fi

if [ -z "$GENERAL_ID" ]; then
    echo "Error: no 'General' category exists on the server; cannot apply the Unknown/Missing fallback." >&2
    exit 1
fi

echo "Found ${#CATEGORY_IDS[@]} categories (General = id $GENERAL_ID)."
echo

lookup_category_id() {
    local want lower i
    want="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    for i in "${!CATEGORY_NAMES[@]}"; do
        lower="$(printf '%s' "${CATEGORY_NAMES[$i]}" | tr '[:upper:]' '[:lower:]')"
        if [ "$lower" = "$want" ]; then
            printf '%s' "${CATEGORY_IDS[$i]}"
            return 0
        fi
    done
    return 1
}

# --- Process the CSV ---
total=0
submitted=0
redirected_general=0
skipped=0
skipped_rows=()

line_num=0
while IFS= read -r raw_line || [ -n "$raw_line" ]; do
    line_num=$((line_num + 1))
    raw_line="${raw_line%$'\r'}"   # strip trailing CR (Windows line endings)

    [ -n "$(trim "$raw_line")" ] || continue   # skip blank lines

    url="$(strip_quotes "$(trim "${raw_line%%,*}")")"
    if [ "$raw_line" = "${raw_line#*,}" ]; then
        category=""   # no comma present -> no category field
    else
        category="$(strip_quotes "$(trim "${raw_line#*,}")")"
    fi

    # Skip an optional header row
    if [ "$line_num" -eq 1 ]; then
        low_url="$(printf '%s' "$url" | tr '[:upper:]' '[:lower:]')"
        if [ "$low_url" = "url" ]; then
            continue
        fi
    fi

    total=$((total + 1))

    case "$url" in
        http://*|https://*) ;;
        *)
            echo "  [SKIP] line $line_num: invalid URL '$url' (must start with http:// or https://)"
            skipped=$((skipped + 1))
            skipped_rows+=("$line_num: $raw_line")
            continue
            ;;
    esac

    low_category="$(printf '%s' "$category" | tr '[:upper:]' '[:lower:]')"
    used_fallback=0
    if [ -z "$category" ] || [ "$low_category" = "unknown" ] || [ "$low_category" = "missing" ]; then
        category_id="$GENERAL_ID"
        used_fallback=1
    elif category_id="$(lookup_category_id "$category")"; then
        :
    else
        echo "  [WARN] line $line_num: unrecognized category '$category' for $url -> falling back to General"
        category_id="$GENERAL_ID"
        used_fallback=1
    fi

    if ! response="$(curl "${CURL_OPTS[@]}" \
        --data-urlencode "submit_url=1" \
        --data-urlencode "url=$url" \
        --data-urlencode "description=" \
        --data-urlencode "category_id=$category_id" \
        "$SUBMIT_URL")"; then
        echo "  [FAIL] line $line_num: $url - could not reach server"
        skipped=$((skipped + 1))
        skipped_rows+=("$line_num: $raw_line")
        continue
    fi

    if printf '%s' "$response" | grep -q "awaiting review"; then
        submitted=$((submitted + 1))
        [ "$used_fallback" -eq 1 ] && redirected_general=$((redirected_general + 1))
        echo "  [OK]   line $line_num: $url -> category_id=$category_id"
    else
        server_error="$(printf '%s' "$response" | grep -oE '<p class="alert-inline"[^>]*>[^<]*' | sed -E 's/.*>//')"
        echo "  [FAIL] line $line_num: $url${server_error:+ - $server_error}"
        skipped=$((skipped + 1))
        skipped_rows+=("$line_num: $raw_line")
    fi
done < "$CSV_FILE"

echo
echo "Done. $submitted/$total submitted for review ($redirected_general routed to General), $skipped skipped."
if [ "${#skipped_rows[@]}" -gt 0 ]; then
    echo "Skipped rows:"
    printf '  %s\n' "${skipped_rows[@]}"
fi

[ "$skipped" -eq 0 ]
