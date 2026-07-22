#!/bin/sh
# 哪吒[git 墙 · 入库正本安装器]  2026-07-22
# 用法:
#   bash ops/githooks/install.sh          装 / 刷新（与仓库正本不一致的先备份再覆盖）
#   bash ops/githooks/install.sh --check  只对账不写，任一漂移/缺失 → exit 1（可挂巡检）
#
# 装到 git common dir 的 hooks/：主仓与它下面所有 worktree 共享同一份，装一次全覆盖。
# 🔴 hook 不随 clone 走：新 clone、或 .git 重建之后，墙会静默消失，必须再跑一次本脚本。
set -u
SRC="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(git -C "$SRC" rev-parse --show-toplevel)" || { echo "[githooks] 不在 git 仓库里"; exit 1; }
COMMON="$(git -C "$ROOT" rev-parse --git-common-dir)" || exit 1
case "$COMMON" in /*) ;; *) COMMON="$ROOT/$COMMON" ;; esac
DEST="$COMMON/hooks"
MODE="${1:-install}"
TS="$(date +%Y%m%d%H%M)"
rc=0

[ "$MODE" = "--check" ] || mkdir -p "$DEST" || exit 1
echo "[githooks] 仓库正本: $SRC"
echo "[githooks] 安装目标: $DEST"

managed=''
for f in commit-msg pre-commit pre-push; do
  [ -f "$SRC/$f" ] || continue
  managed="$managed $f"
  s=$(md5sum "$SRC/$f" | cut -d' ' -f1)
  if [ -f "$DEST/$f" ]; then d=$(md5sum "$DEST/$f" | cut -d' ' -f1); else d='(未安装)'; fi
  if [ "$s" = "$d" ]; then
    printf '  [一致] %-11s %s\n' "$f" "$s"
    continue
  fi
  if [ "$MODE" = "--check" ]; then
    printf '  [漂移] %-11s 仓库正本=%s  已装=%s\n' "$f" "$s" "$d"
    rc=1
    continue
  fi
  if [ -f "$DEST/$f" ]; then
    cp "$DEST/$f" "$DEST/$f.bak.$TS" || exit 1
    printf '  [覆盖] %-11s %s  (旧版备份 %s.bak.%s)\n' "$f" "$s" "$f" "$TS"
  else
    printf '  [新装] %-11s %s\n' "$f" "$s"
  fi
  cp "$SRC/$f" "$DEST/$f" || exit 1
  chmod +x "$DEST/$f" || exit 1
done

# 目标目录里有、但仓库不管的 hook（只提示，不动它）
for p in "$DEST"/*; do
  [ -f "$p" ] || continue
  n=$(basename "$p")
  case "$n" in *.sample|*.bak.*) continue ;; esac
  case " $managed " in *" $n "*) continue ;; esac
  printf '  [未纳管] %s（本脚本不碰，要纳管就把它加进 %s）\n' "$n" "$SRC"
done

if [ "$MODE" = "--check" ] && [ "$rc" -ne 0 ]; then
  echo "[githooks] 有漂移：跑 bash ops/githooks/install.sh 重装"
fi
exit "$rc"
