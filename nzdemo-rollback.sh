#!/usr/bin/env bash
# 哪吒上线前 demo 数据收口入口。
# 默认只生成计划；任何执行都需要计划哈希。生产提交还需要显式环境闸。

set -euo pipefail

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODE="${1:-PLAN}"
EVIDENCE_DIR="${2:-${NZ_DEMO_EVIDENCE_DIR:-}}"
PLAN_HASH="${3:-}"
SCOPE_FILE="${NZ_DEMO_SCOPE_FILE:-}"
EXTRA=()
if [[ -n "$SCOPE_FILE" ]]; then
    EXTRA+=(--scope-file="$SCOPE_FILE")
fi

usage() {
    cat <<'EOF'
用法：
  bash nzdemo-rollback.sh PLAN <evidence-dir>
  bash nzdemo-rollback.sh REHEARSE <evidence-dir> <plan-sha256>
  NZ_DEMO_ALLOW_COMMIT=YES bash nzdemo-rollback.sh GO <evidence-dir> <plan-sha256>

PLAN      只读，输出精确目标、阻断和计划哈希。
REHEARSE 对一次性数据库执行完整删除/还原，然后事务回滚并核对原计划哈希。
GO        真提交；除计划哈希外还必须显式设置 NZ_DEMO_ALLOW_COMMIT=YES。

evidence-dir 必须包含 2026-06 demo 原始 manifest/备份；工具会校验固定 SHA-256，
不会信任临时改写的 manifest，也不会读取或调用旧的服务器本地 rollback 子脚本。
若 PLAN 发现关联订单或本地生活商家内容已漂移，须通过 NZ_DEMO_SCOPE_FILE 传入
精确 scope；自动导出的 scope 固定为 rehearsal-only，绝不能提交。
EOF
}

if [[ -z "$EVIDENCE_DIR" ]]; then
    usage >&2
    exit 2
fi

case "$MODE" in
    PLAN)
        exec php "$BASE/nzdemo-cleanup.php" --evidence-dir="$EVIDENCE_DIR" "${EXTRA[@]}"
        ;;
    REHEARSE)
        [[ -n "$PLAN_HASH" ]] || { echo "REHEARSE 缺 plan-sha256" >&2; exit 2; }
        exec php "$BASE/nzdemo-cleanup.php" --evidence-dir="$EVIDENCE_DIR" "${EXTRA[@]}" --apply --rollback --confirm="$PLAN_HASH"
        ;;
    GO)
        [[ -n "$PLAN_HASH" ]] || { echo "GO 缺 plan-sha256" >&2; exit 2; }
        [[ "${NZ_DEMO_ALLOW_COMMIT:-}" == "YES" ]] || {
            echo "GO 被拒绝：必须显式设置 NZ_DEMO_ALLOW_COMMIT=YES" >&2
            exit 3
        }
        exec php "$BASE/nzdemo-cleanup.php" --evidence-dir="$EVIDENCE_DIR" "${EXTRA[@]}" --apply --confirm="$PLAN_HASH"
        ;;
    -h|--help|HELP)
        usage
        ;;
    *)
        echo "未知模式：$MODE" >&2
        usage >&2
        exit 2
        ;;
esac
