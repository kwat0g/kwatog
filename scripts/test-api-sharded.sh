#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$script_dir/../api/phpunit.xml" ]]; then
    cd "$script_dir/../api"
elif [[ -f "$script_dir/../phpunit.xml" ]]; then
    cd "$script_dir/.."
else
    echo "Could not locate api/phpunit.xml" >&2
    exit 2
fi

phpunit=(vendor/bin/phpunit -c phpunit.xml --do-not-cache-result --colors=always)
"${phpunit[@]}" tests/Unit

shopt -s nullglob
feature_dirs=()
feature_tests=()
for path in tests/Feature/*; do
    if [[ -d "$path" ]]; then
        feature_dirs+=("$path")
    elif [[ -f "$path" && "$path" == *Test.php ]]; then
        feature_tests+=("$path")
    fi
done

if ((${#feature_tests[@]})); then
    "${phpunit[@]}" "${feature_tests[@]}"
fi

for path in "${feature_dirs[@]}"; do
    case "${path##*/}" in
        Accounting|B2B|Infrastructure|Inventory|Operations|Payroll|Purchasing|Quality|SupplyChain)
            while IFS= read -r test_file; do
                "${phpunit[@]}" "$test_file"
            done < <(find "$path" -type f -name '*Test.php' | sort)
            ;;
        *)
            "${phpunit[@]}" "$path"
            ;;
    esac
done
