#!/usr/bin/env sh

set -eu

repo_dir=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
platform=woocommerce
package_slug=webvouch-for-woocommerce
output_dir="$repo_dir/dist"

: "${S3_ENDPOINT:?S3_ENDPOINT is required}"
: "${S3_BUCKET:?S3_BUCKET is required}"
: "${S3_REGION:?S3_REGION is required}"
: "${S3_PUBLIC_BASE_URL:?S3_PUBLIC_BASE_URL is required}"
: "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID is required}"
: "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY is required}"
export AWS_PAGER=

version=$(sed -n "s/^define( 'WEBVOUCH_WC_VERSION', '\([^']*\)' );$/\1/p" "$repo_dir/webvouch-for-woocommerce.php")
printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || {
	echo "Invalid release version: $version" >&2
	exit 1
}

case "$S3_ENDPOINT" in
	https://*) ;;
	*) echo 'S3_ENDPOINT must use HTTPS.' >&2; exit 1 ;;
esac
case "$S3_PUBLIC_BASE_URL" in
	https://*) ;;
	*) echo 'S3_PUBLIC_BASE_URL must use HTTPS.' >&2; exit 1 ;;
esac

command -v aws >/dev/null 2>&1 || {
	echo 'AWS CLI is required to publish release files.' >&2
	exit 1
}
command -v curl >/dev/null 2>&1 || {
	echo 'curl is required to verify public release files.' >&2
	exit 1
}

versioned_name="$package_slug-$version.zip"
stable_name="$package_slug.zip"
versioned_file="$output_dir/$versioned_name"
stable_file="$output_dir/$stable_name"
manifest_file="$output_dir/latest.json"
checksums_file="$output_dir/SHA256SUMS"

for file in "$versioned_file" "$stable_file" "$manifest_file" "$checksums_file"; do
	test -s "$file" || {
		echo "Missing release file: $file" >&2
		exit 1
	}
done

if command -v sha256sum >/dev/null 2>&1; then
	sha256_file() { sha256sum "$1" | awk '{print $1}'; }
else
	sha256_file() { shasum -a 256 "$1" | awk '{print $1}'; }
fi

versioned_sha=$(sha256_file "$versioned_file")
test "$versioned_sha" = "$(sha256_file "$stable_file")" || {
	echo 'The versioned and stable release ZIPs differ.' >&2
	exit 1
}
grep -Fqx "$versioned_sha  $versioned_name" "$checksums_file" || {
	echo 'SHA256SUMS does not contain the versioned release ZIP.' >&2
	exit 1
}
grep -Fqx "$versioned_sha  $stable_name" "$checksums_file" || {
	echo 'SHA256SUMS does not contain the stable release ZIP.' >&2
	exit 1
}

prefix="downloads/$platform"
versioned_key="$prefix/$versioned_name"
stable_key="$prefix/$stable_name"
checksums_key="$prefix/SHA256SUMS"
manifest_key="$prefix/latest.json"
public_base=${S3_PUBLIC_BASE_URL%/}

stage_dir=$(mktemp -d "${TMPDIR:-/tmp}/webvouch-wc-publish.XXXXXX")
cleanup() {
	find "$stage_dir" -type f -delete 2>/dev/null || true
	find "$stage_dir" -depth -type d -exec rmdir {} \; 2>/dev/null || true
}
trap cleanup EXIT INT TERM

aws_s3api() {
	aws --endpoint-url "$S3_ENDPOINT" --region "$S3_REGION" s3api "$@"
}

put_object() (
	put_file=$1
	put_key=$2
	put_content_type=$3
	put_cache_control=$4
	aws_s3api put-object \
		--bucket "$S3_BUCKET" \
		--key "$put_key" \
		--body "$put_file" \
		--acl public-read \
		--content-type "$put_content_type" \
		--cache-control "$put_cache_control" >/dev/null
)

verify_public_file() (
	verify_file=$1
	verify_key=$2
	expected_sha=$(sha256_file "$verify_file")
	downloaded="$stage_dir/$(basename "$verify_key").downloaded"
	curl --fail --silent --show-error --max-redirs 0 "$public_base/$verify_key" --output "$downloaded"
	actual_sha=$(sha256_file "$downloaded")
	test "$actual_sha" = "$expected_sha" || {
		echo "Public object checksum mismatch for $verify_key" >&2
		exit 1
	}
)

verify_metadata() (
	metadata_key=$1
	expected_content_type=$2
	expected_cache_control=$3
	actual_content_type=$(aws_s3api head-object --bucket "$S3_BUCKET" --key "$metadata_key" --query ContentType --output text)
	actual_cache_control=$(aws_s3api head-object --bucket "$S3_BUCKET" --key "$metadata_key" --query CacheControl --output text)
	test "$actual_content_type" = "$expected_content_type" || {
		echo "Content-Type mismatch for $metadata_key" >&2
		exit 1
	}
	test "$actual_cache_control" = "$expected_cache_control" || {
		echo "Cache-Control mismatch for $metadata_key" >&2
		exit 1
	}
)

# Versioned release artifacts are immutable. A retry may reuse identical bytes,
# but a published version must never be replaced with different content.
head_error="$stage_dir/head-object.error"
if aws_s3api head-object --bucket "$S3_BUCKET" --key "$versioned_key" >/dev/null 2>"$head_error"; then
	existing="$stage_dir/existing.zip"
	aws --endpoint-url "$S3_ENDPOINT" --region "$S3_REGION" \
		s3 cp "s3://$S3_BUCKET/$versioned_key" "$existing" --only-show-errors
	existing_sha=$(sha256_file "$existing")
	test "$existing_sha" = "$versioned_sha" || {
		echo "Refusing to overwrite $versioned_key with different bytes." >&2
		exit 1
	}
	printf 'IMMUTABLE_OBJECT_REUSED %s\n' "$versioned_key"
elif grep -Eq '(404|Not Found|NoSuchKey)' "$head_error"; then
	put_object "$versioned_file" "$versioned_key" 'application/zip' 'public, max-age=31536000, immutable'
else
	cat "$head_error" >&2
	exit 1
fi
verify_public_file "$versioned_file" "$versioned_key"
verify_metadata "$versioned_key" 'application/zip' 'public, max-age=31536000, immutable'

# Mutable aliases are published only after the immutable artifact is available.
put_object "$stable_file" "$stable_key" 'application/zip' 'public, max-age=300, must-revalidate'
verify_public_file "$stable_file" "$stable_key"
verify_metadata "$stable_key" 'application/zip' 'public, max-age=300, must-revalidate'
put_object "$checksums_file" "$checksums_key" 'text/plain; charset=utf-8' 'public, max-age=300, must-revalidate'
verify_public_file "$checksums_file" "$checksums_key"
verify_metadata "$checksums_key" 'text/plain; charset=utf-8' 'public, max-age=300, must-revalidate'

# Publish the manifest last: clients can never observe a version whose ZIP is
# still missing or has not passed public checksum verification.
put_object "$manifest_file" "$manifest_key" 'application/json; charset=utf-8' 'public, max-age=300, must-revalidate'
verify_public_file "$manifest_file" "$manifest_key"
verify_metadata "$manifest_key" 'application/json; charset=utf-8' 'public, max-age=300, must-revalidate'

printf 'S3_RELEASE_PUBLISHED version=%s sha256=%s prefix=%s\n' "$version" "$versioned_sha" "$prefix"
