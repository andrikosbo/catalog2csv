#!/usr/bin/env sh
set -eu

root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
version=$(tr -d '\r\n' < "$root/VERSION")
output=${1:-"$root/dist"}
mkdir -p "$output"
output=$(CDPATH='' cd -- "$output" && pwd)

stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
trap 'exit 1' HUP INT TERM

for target in source deploy; do
    tree="$stage/$target/catalog2csv"
    mkdir -p "$tree"
    while read -r scope path; do
        if [ "$scope" = 'both' ] || [ "$scope" = "$target" ]; then
            mkdir -p "$(dirname -- "$tree/$path")"
            cp "$root/$path" "$tree/$path"
        fi
    done < "$root/tools/release-files.txt"

    if [ "$target" = 'deploy' ]; then
        (
            cd "$tree"
            composer install --no-dev --prefer-dist --classmap-authoritative \
                --no-interaction --no-progress --no-scripts --no-plugins
            composer check-platform-reqs --no-dev
        )
    fi

    filename="catalog2csv-$version-$target.zip"
    (
        cd "$stage/$target"
        zip -q -X -r "$stage/$filename" catalog2csv
    )
    mv -f "$stage/$filename" "$output/$filename"
done

(
    cd "$output"
    shasum -a 256 "catalog2csv-$version-source.zip" "catalog2csv-$version-deploy.zip" > SHA256SUMS
)
printf 'Built the source and deployment archives in %s\n' "$output"
