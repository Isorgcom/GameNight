#!/usr/bin/env bash
# commit.sh — stage all changes, commit, and push to main
# Usage: ./commit.sh "Your commit message"

set -e

if [ -z "$1" ]; then
    echo "Usage: $0 \"commit message\""
    exit 1
fi

git add -A
git commit -m "$1

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
git push

echo "Done."
