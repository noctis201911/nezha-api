# S4 — SSH 密钥 / 服务器密码轮换 Runbook（🔴 需业主在场 · 勿单独执行）

> 安全路线图 S4（正本 fable-brief/HANDOFF_security_roadmap.md）。开发期 SSH key + root/admin 密码在多处一次性脚本流转过，QA_MASTER §三判定「视为已泄露」，至今未轮换。
> **本项自锁风险最高**：改错 = 把自己关在服务器外。故此处只留 runbook，**不由 AI 单独执行**——排到业主在场的窗口，逃生舱验好再动手。

## 何时做
- 上线（真实资金流动）前找一个业主在场、非高峰的 30–60 分钟窗口。
- 与 S2（aaPanel 29928 防火墙收窄，同样自锁风险）可安排在同一窗口顺序做（先 S4 后 S2，或反之，各自独立验证）。

## 前置逃生舱（每一步都不能少）
1. **云商控制台 VNC / Web Console 先登录验证可用**（最后的兜底；密钥/密码全废也能进）。截图存证。
2. **全程保持一个已连上的 SSH 会话别断**（旧钥这条命脉，换钥成功前绝不关）。
3. 备份 `~/.ssh/authorized_keys`：`cp ~/.ssh/authorized_keys ~/.ssh/authorized_keys.bak.$(date +%Y%m%d%H%M)`。
4. 记下当前 `sshd_config` 关键项（PasswordAuthentication / PermitRootLogin 现状：实测 PasswordAuthentication=yes 但 root=prohibit-password → 实际仅 key 可登；**本次不改这些**，只换钥）。

## 步骤 A：SSH 密钥轮换（重叠切换，不断线）
1. 本地（业主机器）生成新密钥对：`ssh-keygen -t ed25519 -C "nezha-2026-rotated" -f ~/.ssh/nezha_new`。
2. **追加**新公钥到服务器（不删旧）：把 `nezha_new.pub` 内容追加到 `~/.ssh/authorized_keys`。
3. **开一个新终端**用新私钥登录验证：`ssh -i ~/.ssh/nezha_new root@178.105.216.158`。能进 = 新钥可用。**旧会话保持不动。**
4. 确认新钥稳定后，从 `authorized_keys` **删掉旧公钥**（只留新的）。
5. 更新本机 `nz.js` 的私钥路径（`~/.ssh/id_rsa` → 新钥），跑 `node nz.js run "echo ok"` 验证部署通道仍通。
6. GitHub deploy key（`/root/.ssh/github_nezha`）**是独立的 repo 部署钥，与登录钥不同**，本项不强制换；若也要换，另在 GitHub repo Deploy keys 里替换公钥并同步私钥。

## 步骤 B：密码轮换
7. `passwd`（root 密码）→ 新强密码 → **立刻更新密码管理器**。
8. aaPanel 面板密码 + 后台超管（admin）密码（后台改密走登录页/设置）→ 新强密码 → 更新密码管理器。
9. 若 MySQL root 密码也在「视为已泄露」范围（业主待办清单里另有一条），单独走 MySQL 改密流程（会牵动 .env / api-deploy/shared/.env，风险独立，别和 SSH 换钥混在一步）。

## 验收（每条都要证据）
- ✅ 新钥登录成功（新终端实登）。
- ✅ 旧钥被拒：`ssh -i <旧钥> root@...` → Permission denied。
- ✅ `nz.js` / `nzdeploy-api.sh` 用新钥仍通（跑一次只读命令）。
- ✅ 密码管理器已更新 root / aaPanel / admin（3 条）。
- ✅ 云商 VNC 仍可作为兜底（复验一次）。

## 回滚
- 换钥中任何一步新钥登不上 → 旧钥会话还在，把 authorized_keys 恢复 `.bak`，退回原状。
- 全断 → 云商 VNC 进去恢复 `authorized_keys.bak` + 重置密码。
- **绝不**在验证新钥能登之前删旧钥 / 改 sshd_config / 重启 sshd。

## 🔴 别碰（本项范围外）
- `sshd_config` 的 PasswordAuthentication / PermitRootLogin（latent 现状，改了可能自锁；单独评估）。
- fail2ban、ufw 22 规则、VPN keys（xray 2083 / Hysteria2 8443）。
- 关联：QA_MASTER §三、HANDOFF_security_roadmap.md（S4）、备份钥匙位置见 memory [[nezha-backup-dr-rpo-architecture]]（桌面\重要\nezha-keys-20260617）。
