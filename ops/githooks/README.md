# ops/githooks —— git 墙的入库正本（2026-07-22 建）

`.git/hooks/` 不入库、不随 clone 走。这里放**正本**，`install.sh` 负责装到 `.git/hooks/`，
两边 md5 一致才算墙真的在。

## 用法

```bash
bash ops/githooks/install.sh          # 装 / 刷新（旧版自动备份成 <name>.bak.<时间戳>）
bash ops/githooks/install.sh --check  # 只对账，有漂移或缺失 → exit 1（可挂巡检）
```

装到 `git rev-parse --git-common-dir` 下的 `hooks/`：**主仓和它下面所有 worktree 共享同一份**，装一次全覆盖。

🔴 **什么时候必须重跑**：新 clone、`.git` 重建、有人手改过 `.git/hooks/` —— 否则墙静默消失，
提交侧一路绿灯，出事时才发现没人守门。

## 本仓正本清单

| 文件 | 事件 | 拦什么 | 应急绕过 |
|---|---|---|---|
| `commit-msg` | 每次 commit | 相对 `origin/main` **净删 > 15 行**的提交（疑似拿旧 `.bak`/旧快照盖回去，退掉别窗已 push 的代码） | 消息末尾加 `[force-revert]`；或 `git commit --no-verify` |
| `pre-commit` | 每次 commit | staged 的 `.php` 有语法错（查的是 index 内容，`nzcommit.sh` 私有 index 同样适用） | `git commit --no-verify` |
| `pre-push` | 每次 push | L1 红线 / IDOR 守卫 / 死指引墙等 5 个 phpunit 测试任一红 | `git push --no-verify`（自负 L1 风险） |

## 判据说明（commit-msg）

- 净删 = `git diff --cached --numstat origin/main -- <文件>` 的 `删 − 增`，**逐文件**判，任一文件超阈值即拦。
- 比的是**本地** `origin/main` ref，没 fetch 会偏旧 —— 定位是 best-effort 提交卫生，不是 review 替代品。
- 二进制文件 numstat 出 `-`，跳过不判。
- **2026-07-22 修**：老版本用 `grep -c '^-[^-]'` 数 diff 正文行，把"内容本身以 `-` / `+` 开头"的行
  （markdown 列表、YAML 列表）整片漏数 —— 实测 AGENTS.md 认领区删掉 124 行 `- [x]` 条目，
  墙数出来净删 = −1 直接放行。已改用 `--numstat` 由 git 给准确增删数。
