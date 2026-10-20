#!/usr/bin/env bash
# Heads CHANGELOG.md's "## Unreleased" section with the release version and
# writes that section to the notes file. Usage: release.sh <patch|minor|major|X.Y.Z> <notes-file>
# Prints the version tag (vX.Y.Z) on stdout.
set -euo pipefail

input="${1:?version or bump (patch, minor, major) required}"
notes="${2:?notes file required}"
changelog="CHANGELOG.md"

fail() {
    echo "::error::$1" >&2
    exit 1
}

latest="$(git tag --list 'v*' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -n 1 || true)"
latest="${latest:-v0.0.0}"
IFS=. read -r major minor patch <<< "${latest#v}"

case "$input" in
    patch) version="$major.$minor.$((patch + 1))" ;;
    minor) version="$major.$((minor + 1)).0" ;;
    major) version="$((major + 1)).0.0" ;;
    *)
        version="${input#v}"
        [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "\"$input\" is not patch, minor, major or a version like 0.19.0."
        ;;
esac
tag="v$version"

git rev-parse -q --verify "refs/tags/$tag" > /dev/null && fail "$tag already exists."
[[ "$(printf '%s\n%s\n' "$latest" "$tag" | sort -V | tail -n 1)" == "$tag" ]] || fail "$tag is lower than the latest release, $latest."

[[ "$(grep -c '^## Unreleased[[:space:]]*$' "$changelog")" == 1 ]] || fail "$changelog needs exactly one \"## Unreleased\" heading."

awk '
    /^## Unreleased[[:space:]]*$/ { inside = 1; next }
    inside && /^## / { exit }
    inside { print }
' "$changelog" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}' > "$notes"
grep -q '[^[:space:]]' "$notes" || fail "The \"## Unreleased\" section is empty."

# Before 1.0 a release with upgrade notes bumps the minor (see CHANGELOG.md).
if [[ "$major" == 0 ]] && grep -q '^### Upgrade notes' "$notes"; then
    [[ "${version%.*}" != "$major.$minor" ]] || fail "The release carries upgrade notes, so before 1.0 it must bump the minor, not cut $tag."
fi

sed -i "s/^## Unreleased[[:space:]]*$/## $tag/" "$changelog"

echo "$tag"
