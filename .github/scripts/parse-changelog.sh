#!/bin/bash
set -e

# parse-changelog.sh
# Parses and updates CHANGELOG.md following Keep a Changelog format.
#
# Usage:
#   ./parse-changelog.sh validate              - Check changelog has unreleased content
#   ./parse-changelog.sh release <version>     - Move unreleased to version section

CHANGELOG_FILE="${CHANGELOG_FILE:-CHANGELOG.md}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

error() {
    echo -e "${RED}Error: $1${NC}" >&2
    exit 1
}

success() {
    echo -e "${GREEN}$1${NC}"
}

warn() {
    echo -e "${YELLOW}$1${NC}"
}

check_changelog_exists() {
    if [[ ! -f "$CHANGELOG_FILE" ]]; then
        error "CHANGELOG.md not found"
    fi
}

# Validate that [Unreleased] section exists and has content.
validate() {
    check_changelog_exists

    if ! grep -q "## \[Unreleased\]" "$CHANGELOG_FILE"; then
        error "CHANGELOG.md does not contain an [Unreleased] section"
    fi

    # Extract content between [Unreleased] and the next version section.
    local unreleased_content
    unreleased_content=$(awk '
        /^## \[Unreleased\]/ { capture = 1; next }
        /^## \[/ && capture { exit }
        capture { print }
    ' "$CHANGELOG_FILE")

    # Check if there's any actual content (not just whitespace/headings).
    local trimmed
    trimmed=$(echo "$unreleased_content" | grep -v '^[[:space:]]*$' | grep -v '^###' || true)

    if [[ -z "$trimmed" ]]; then
        error "No changes found in [Unreleased] section. Add changelog entries before releasing."
    fi

    success "Changelog validation passed. Found unreleased changes."
}

# Move unreleased content to a new version section.
release() {
    local version="$1"

    if [[ -z "$version" ]]; then
        error "Version argument required. Usage: $0 release <version>"
    fi

    # Validate version format (X.Y.Z).
    if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        error "Invalid version format '$version'. Expected X.Y.Z (e.g., 1.2.0)"
    fi

    check_changelog_exists

    if grep -F -q "## [$version]" "$CHANGELOG_FILE"; then
        error "CHANGELOG.md already contains a section for version $version"
    fi

    local date
    date=$(date +%Y-%m-%d)

    # Get repository info for links.
    local repo_url
    repo_url=$(git config --get remote.origin.url 2>/dev/null | sed 's/\.git$//' | sed 's/git@github.com:/https:\/\/github.com\//')

    if [[ -z "$repo_url" ]]; then
        warn "Could not detect repository URL. Links may need manual update."
        repo_url="https://github.com/OWNER/REPO"
    fi

    # Move content from [Unreleased] to the new version section.
    awk -v version="$version" -v date="$date" '
        BEGIN { in_unreleased = 0; content = "" }
        /^## \[Unreleased\]/ {
            print $0
            print ""
            in_unreleased = 1
            next
        }
        /^## \[/ && in_unreleased {
            print "## [" version "] - " date
            print content
            in_unreleased = 0
        }
        # Stop capturing at link references section (lines starting with [).
        /^\[/ && in_unreleased {
            print "## [" version "] - " date
            print content
            in_unreleased = 0
        }
        in_unreleased {
            content = content $0 "\n"
            next
        }
        { print }
        END {
            # Handle case where unreleased section goes to end of file.
            if (in_unreleased && content != "") {
                print "## [" version "] - " date
                print content
            }
        }
    ' "$CHANGELOG_FILE" > "$CHANGELOG_FILE.tmp" && mv "$CHANGELOG_FILE.tmp" "$CHANGELOG_FILE"

    # Update the [Unreleased] comparison link if present.
    if grep -q "\[Unreleased\]:.*compare" "$CHANGELOG_FILE"; then
        sed -i.bak "s|\[Unreleased\]: \(.*\)/compare/v[0-9.]*\.\.\.HEAD|[Unreleased]: \1/compare/v$version...HEAD|" "$CHANGELOG_FILE"
        rm -f "$CHANGELOG_FILE.bak"
    fi

    # Find the previous version heading after the newly inserted release section.
    local prev_version
    prev_version=$(awk -v version="$version" '
        $0 ~ "^## \\[" version "\\]" { found = 1; next }
        found && /^## \[/ {
            line = $0
            sub(/^## \[/, "", line)
            sub(/\].*$/, "", line)
            print line
            exit
        }
    ' "$CHANGELOG_FILE")

    # Add new version link.
    if grep -q "^\[$version\]:" "$CHANGELOG_FILE"; then
        :
    elif [[ -n "$prev_version" ]] && [[ "$prev_version" != "$version" ]]; then
        awk -v target="$prev_version" -v newline="[$version]: $repo_url/compare/v$prev_version...v$version" '
            $0 ~ "^\\[" target "\\]:" && !inserted {
                print newline
                inserted = 1
            }
            { print }
            END {
                if (!inserted) {
                    print newline
                }
            }
        ' "$CHANGELOG_FILE" > "$CHANGELOG_FILE.tmp" && mv "$CHANGELOG_FILE.tmp" "$CHANGELOG_FILE"
    elif ! grep -q "^\[$version\]:" "$CHANGELOG_FILE"; then
        # First release - add link at the end.
        echo "[$version]: $repo_url/releases/tag/v$version" >> "$CHANGELOG_FILE"
    fi

    success "Updated CHANGELOG.md for version $version"
}

usage() {
    echo "Usage: $0 <command> [args]"
    echo ""
    echo "Commands:"
    echo "  validate           Check changelog has unreleased content"
    echo "  release <version>  Move unreleased content to version section"
    echo ""
    echo "Environment variables:"
    echo "  CHANGELOG_FILE     Path to changelog (default: CHANGELOG.md)"
}

case "${1:-}" in
    validate)
        validate
        ;;
    release)
        release "$2"
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        usage
        exit 1
        ;;
esac
