#!/usr/bin/env bash
# commit.sh — deploy changed files to server, then commit and push
# Usage: ./commit.sh "Your commit message"

set -e

SERVER="root@198.46.254.149"
REMOTE_WWW="/root/docker/GameNight/www"
LOCAL_WWW="$(dirname "$0")/www"

if [ -z "$1" ]; then
    echo "Usage: $0 \"commit message\""
    exit 1
fi

MSG="$1"

# Find changed/staged PHP files in www/ and scp them
CHANGED=$(git diff --name-only HEAD -- 'www/*.php'; git diff --cached --name-only HEAD -- 'www/*.php')
if [ -n "$CHANGED" ]; then
    echo "Deploying changed files to server..."
    while IFS= read -r file; do
        [ -z "$file" ] && continue
        filename=$(basename "$file")
        echo "  -> $filename"
        scp "$file" "$SERVER:$REMOTE_WWW/$filename"
    done <<< "$CHANGED"
else
    echo "No changed www/*.php files to deploy."
fi

# Also deploy CHANGELOG.md if changed
if git diff --name-only HEAD -- 'CHANGELOG.md' | grep -q CHANGELOG; then
    echo "  -> CHANGELOG.md (skipping — not in www/)"
fi

# Commit and push
git add -A
git commit -m "$MSG

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
git push

echo "Done."
