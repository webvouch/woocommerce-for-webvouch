#!/usr/bin/env sh

set -eu

repo_dir=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
plugin_slug=webvouch-for-woocommerce
output_dir="$repo_dir/dist"
version=$(sed -n "s/^define( 'WEBVOUCH_WC_VERSION', '\([^']*\)' );$/\1/p" "$repo_dir/webvouch-for-woocommerce.php")
header_version=$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$repo_dir/webvouch-for-woocommerce.php")
stable_tag=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$repo_dir/readme.txt")

if [ -z "$version" ] || [ "$version" != "$header_version" ] || [ "$version" != "$stable_tag" ]; then
	echo "Version mismatch: constant=$version header=$header_version stable_tag=$stable_tag" >&2
	exit 1
fi

printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || {
	echo "Invalid release version: $version" >&2
	exit 1
}

command -v zip >/dev/null 2>&1 || {
	echo 'zip is required to build the plugin package.' >&2
	exit 1
}

stage_dir=$(mktemp -d "${TMPDIR:-/tmp}/webvouch-wc-package.XXXXXX")
cleanup() {
	find "$stage_dir" -type f -delete 2>/dev/null || true
	find "$stage_dir" -depth -type d -exec rmdir {} \; 2>/dev/null || true
}
trap cleanup EXIT INT TERM

package_dir="$stage_dir/$plugin_slug"
mkdir -p "$package_dir/includes" "$package_dir/assets/js" "$package_dir/blocks/widget" "$output_dir"
cp "$repo_dir/webvouch-for-woocommerce.php" "$repo_dir/uninstall.php" "$repo_dir/readme.txt" "$package_dir/"
cp "$repo_dir"/includes/*.php "$package_dir/includes/"
cp "$repo_dir"/assets/js/*.js "$package_dir/assets/js/"
cp "$repo_dir/blocks/widget/block.json" "$package_dir/blocks/widget/"

# Fixed timestamps plus sorted paths make repeated builds byte-identical.
find "$package_dir" -exec touch -t 200001010000 {} +

archive_tmp="$stage_dir/$plugin_slug-$version.zip"
(
	cd "$stage_dir"
	find "$plugin_slug" -type f -print | LC_ALL=C sort | zip -X -q "$archive_tmp" -@
)

versioned_archive="$output_dir/$plugin_slug-$version.zip"
stable_archive="$output_dir/$plugin_slug.zip"
cp "$archive_tmp" "$versioned_archive"
cp "$archive_tmp" "$stable_archive"

if command -v sha256sum >/dev/null 2>&1; then
	checksum=$(sha256sum "$versioned_archive" | awk '{print $1}')
else
	checksum=$(shasum -a 256 "$versioned_archive" | awk '{print $1}')
fi

cat > "$output_dir/latest.json" <<EOF
{
  "version": "$version",
  "downloadUrl": "https://webvouch.com/downloads/woocommerce/$plugin_slug-$version.zip",
  "sha256": "$checksum",
  "requires": "6.4",
  "tested": "7.0",
  "requiresPhp": "8.1"
}
EOF

{
	printf '%s  %s\n' "$checksum" "$plugin_slug-$version.zip"
	printf '%s  %s\n' "$checksum" "$plugin_slug.zip"
} > "$output_dir/SHA256SUMS"
printf 'PLUGIN_PACKAGE_READY %s\n' "$versioned_archive"
printf '%s  %s\n' "$checksum" "$versioned_archive"
