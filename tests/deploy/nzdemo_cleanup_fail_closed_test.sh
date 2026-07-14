#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WRAPPER="$ROOT/nzdemo-rollback.sh"
TOOL="$ROOT/nzdemo-cleanup.php"

php -l "$TOOL" >/dev/null
bash -n "$WRAPPER"

if grep -q '/www/wwwroot' "$WRAPPER" "$TOOL"; then
    echo "demo cleanup tool must not hard-code a production path" >&2
    exit 1
fi

grep -q 'NZ_DEMO_ALLOW_COMMIT' "$WRAPPER"
grep -q 'PLAN_SHA256' "$TOOL"
grep -q "hash_file('sha256'" "$TOOL"
grep -q 'beginTransaction' "$TOOL"
grep -q 'REHEARSAL_ROLLED_BACK' "$TOOL"
grep -q "str_starts_with(\$plan\['database'\], 'nezha_qa_')" "$TOOL"
grep -q 'manifest_outside_relation_counts' "$TOOL"
grep -q 'target_row_sha256' "$TOOL"
grep -q 'review_ids' "$TOOL"
grep -q 'add_on_ids' "$TOOL"
grep -q 'manifest 外关联仍存在' "$TOOL"
grep -q 'coupon_claims.coupon_id' "$TOOL"
grep -q 'offline_payments.order_id' "$TOOL"
grep -q 'food.category_id_outside_manifest' "$TOOL"
grep -q 'purpose=production-approved' "$TOOL"
grep -q 'scope.source_database 与实际数据库不一致' "$TOOL"
grep -q '拒绝覆盖既有证据' "$TOOL"

set +e
out="$(bash "$WRAPPER" GO /definitely/missing deadbeef 2>&1)"
rc=$?
set -e
if [[ $rc -eq 0 || "$out" != *"NZ_DEMO_ALLOW_COMMIT=YES"* ]]; then
    echo "GO must fail closed before application bootstrap" >&2
    exit 1
fi

echo "nzdemo cleanup fail-closed contract: PASS"
