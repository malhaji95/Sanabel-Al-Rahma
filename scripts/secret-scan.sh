#!/usr/bin/env sh
#
# T-85 — CICD-02. A floor, not a scanner.
#
# It looks through every tracked file for the shapes a credential takes: a
# private key, a cloud key id, a provider token, a connection string carrying
# a password, an assignment whose value looks like a real secret rather than a
# placeholder. Small and dependency-free on purpose, so it runs on every push
# with nothing to install and without sending the repository anywhere.
#
# T-94 — and now over the whole history, not the checkout alone.
#
# A credential removed in a later commit is still a credential: it is in the
# clone, in every fork, and in the pull request's own diff. Scanning the
# working tree therefore answered the wrong question — «is it there now»
# rather than «was it ever pushed». The history pass walks every blob every
# commit ever carried, each one once, which is cheap because git stores one
# blob per distinct content however many commits hold it.
#
# What it is still not: an entropy test. It will not spot a key that reads
# like prose, and CICD-02's remaining half — static analysis — is named in the
# workflow rather than pretended closed here.
#
# Usage: scripts/secret-scan.sh [directory]
#        scripts/secret-scan.sh --history [directory]
#        The directory defaults to the project. It is an argument so the suite
#        can point the scan at a repository it has planted a key in, and prove
#        the scan bites rather than only that it passes.
#
#        --history needs the full history: a CI checkout is shallow by
#        default, and a shallow clone is told so rather than passing on what
#        it cannot see.
# Exit:  0 clean, 1 something found — printed as name: file:line:text
set -eu

HISTORY=no

if [ "${1:-}" = '--history' ]; then
    HISTORY=yes
    shift
fi

cd "${1:-$(dirname "$0")/..}"

# This file holds the patterns, so it would match itself; the workflow names
# it. .env is never tracked. .env.example is, and its values must be empty or
# a placeholder — which the value shape below tests rather than excuses.
EXCLUDE='^(scripts/secret-scan\.sh|\.github/workflows/checks\.yml)$'

# A value that is plainly a stand-in. Kept short and explicit: every entry here
# is a line someone has to read before adding, which is the point.
PLACEHOLDERS='=(null|NULL|none|false|true|\.\.\.|<|\$\{|\$\(|"|'"'"'|changeme|CHANGEME|your|YOUR|xxx|XXX|example|EXAMPLE|placeholder)'

# <name>|<extended regex>, one per line. Two columns, and the regex is the
# whole of the rest of the line: `read` with two variables keeps the `|` inside
# an alternation, where a third column would cut every pattern at its first one.
PATTERNS=$(cat <<'PAT'
private key|-----BEGIN [A-Z ]*PRIVATE KEY-----
application key|APP_KEY=base64:[A-Za-z0-9+/=]{10,}
aws key id|AKIA[0-9A-Z]{16}
github token|gh[pousr]_[A-Za-z0-9]{30,}
slack token|xox[abprs]-[A-Za-z0-9-]{10,}
google api key|AIza[0-9A-Za-z_-]{35}
stripe key|sk_(live|test)_[0-9a-zA-Z]{20,}
json web token|eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}
connection string with a password|[a-z0-9+]+://[A-Za-z0-9._%-]+:[^@/[:space:]"']{4,}@
filled credential assignment|(PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY)[A-Z0-9_]*=[A-Za-z0-9+/_=-]{6,}
PAT
)

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# `|| true`: grep exits 1 when it passes nothing through, and under `set -e`
# that ended the scan with a failure for a repository that simply has no
# scannable file in it. A repository with nothing to read is clean, not broken.
git ls-files \
    | grep -Ev "$EXCLUDE" \
    | grep -Ev '\.(png|jpg|jpeg|gif|svg|ico|pdf|woff|woff2|ttf|eot|zip|gz)$' \
    > "$work/files" || true

: > "$work/hits"

printf '%s\n' "$PATTERNS" | while IFS='|' read -r name pattern; do
    [ -n "${name:-}" ] || continue

    # -I skips binaries, -H keeps the filename when xargs passes a single file.
    xargs < "$work/files" grep -nEIH -- "$pattern" 2>/dev/null \
        | grep -Ev "$PLACEHOLDERS" \
        | sed "s|^|${name}: |" >> "$work/hits" || true
done

# One exception, and a narrow one: the assignment shape inside a document.
# Documentation is worked examples, and «DB_PASSWORD=sanabel» in the docker run
# line of docs/deployment-demo.md is one of them. Every other pattern still
# reads the documents, so a key or a token pasted into one is caught — it is
# only this shape that is too eager there.
grep -Ev '^filled credential assignment: [^:]*\.md:' "$work/hits" > "$work/kept" || true
mv "$work/kept" "$work/hits"

if [ -s "$work/hits" ]; then
    cat "$work/hits"
    echo '' >&2
    echo 'Something credential-shaped is committed. Remove it, rotate it, push again.' >&2
    exit 1
fi

echo 'secret-scan: no credential-shaped string in any tracked file.'

[ "$HISTORY" = 'yes' ] || exit 0

# ---- the history ------------------------------------------------------------
#
# Every blob any commit ever carried, once each. A shallow clone cannot answer
# this and says so: passing silently would be the same as not running.
if [ -f "$(git rev-parse --git-dir)/shallow" ]; then
    echo 'secret-scan: this is a shallow clone; fetch the full history to scan it.' >&2
    exit 1
fi

git rev-list --objects --all \
    | git cat-file --batch-check='%(objectname) %(objecttype) %(rest)' \
    | awk '$2 == "blob" && $3 != "" { print $1, $3 }' \
    | grep -Ev '\.(png|jpg|jpeg|gif|svg|ico|pdf|woff|woff2|ttf|eot|zip|gz)$' \
    | sort -u -k1,1 \
    > "$work/blobs" || true

: > "$work/past"

# Blobs read by a person and cleared, by content hash — see the file's own
# header for why a hash and not a path.
ALLOWED="$(dirname "$0")/secret-scan-allowed-blobs.txt"

while read -r blob path; do
    case "$path" in
        scripts/secret-scan.sh|.github/workflows/checks.yml) continue ;;
    esac

    if [ -f "$ALLOWED" ] && grep -q "^${blob}$" "$ALLOWED"; then
        continue
    fi

    printf '%s\n' "$PATTERNS" | while IFS='|' read -r name pattern; do
        [ -n "${name:-}" ] || continue

        git cat-file blob "$blob" 2>/dev/null \
            | grep -nEI -- "$pattern" 2>/dev/null \
            | grep -Ev "$PLACEHOLDERS" \
            | sed "s|^|${name}: ${path}:|" >> "$work/past" || true
    done
done < "$work/blobs"

grep -Ev '^filled credential assignment: [^:]*\.md:' "$work/past" > "$work/kept" || true
mv "$work/kept" "$work/past"

if [ -s "$work/past" ]; then
    sort -u "$work/past"
    echo '' >&2
    echo 'A credential is in the history. Removing the file is not enough: rotate the key.' >&2
    exit 1
fi

echo "secret-scan: and none in any of $(wc -l < "$work/blobs" | tr -d ' ') blobs of the history."
