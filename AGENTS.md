# AGENTS.md — 哪吒多窗口并发协调约定（所有 AI 窗口必读）

> ⚠️ 本服务器是**单一共享工作目录**，可能同时有多个 AI 窗口（Claude Code / Codex / …）在改同一批文件。
> 没有这份约定，两个窗口会互相覆盖未提交改动。〔🔴2026-07-22 更正：原文的「构建时把对方半成品一起推上线 → 全站 500」是 **2026-06-22 切 release 部署之前**的旧事，现已不可能，理由见 §0 末两条。〕
> **每个窗口开工前先读本文件，并遵守下面四条。**

## 0. 构建方式：排队脚本自行构建（不依赖人盯）〔2026-06-16 定〕
- 构建上线**一律走固定 SHA 排队脚本**：`node nz.js run "bash /www/wwwroot/nezha.am/nzbuild.sh <40位完整commit SHA>"`，无参/短 SHA 会被拒绝，**绝不裸跑 `npm run build`**。
- 脚本用 **flock 串行化**，并核对排队前后的 current、fetch 后的 `origin/main` 与完整目标 SHA；等待期间 current 改变或构建期间 ref 漂移会拒绝切换。失败不切换、健康门自动回滚。
- **任何窗口都可自行构建，不需要等谁手动把关**——用户不一定在电脑前。
- 🔴**2026-07-22 实读更正（本节原「残余风险」条已作废）**：`nzdeploy-web.sh:128` 与 `nzdeploy-api.sh:165` 都是 `git archive <目标SHA> | tar -x`——release 从 **git 对象库**按精确 SHA 出货，**别窗未提交的 WIP 物理上进不了 release**。「构建带别人半成品上线」这个风险自 2026-06-22 切 release 起已归零，**不必为它等人、不必避让**。
- 🔴**唯一残留的共享树耦合是依赖目录，且有明确触发条件**：部署器优先从**上一个 release** 复制 `node_modules`（`nzdeploy-web.sh:135`），**只有当本次部署的 `package-lock.json` 与线上不一致时**，才回退到**共享工作树**取（`:138`）。所以依赖没变的常规部署根本不碰共享树；**只有依赖变更的部署**才需确认共享树 `node_modules` 干净（Claude 窗口另有本机 `[DEP-OK]` 墙拦服务器加改依赖；**Codex 等其它窗口无此墙**，靠本条自觉）。后端更干净：`nzdeploy-api.sh:181` 的 vendor 从上一个 release 硬链，任何情况都不碰共享树。

## 1. 一窗一提交（不留 WIP）
每改完一处、**Playwright 验证通过后立刻 commit**：精确 `git add <自己的文件>`（**严禁 `-a` / `-am`**，会打包别人的改动），写清描述后 commit + push。
**绝不让自己的半成品在工作树里过夜**——🔴 理由 2026-07-22 已更正：不是「会被构建推上线」（`git archive` 按 SHA 出货，已不可能），而是**别窗会卷走你的改动**（整文件 get/put 覆盖、共享 `.git/index` 串味、别窗 plain commit 回退）。丢的是**你的活**，不是线上。

> 🛡️ **推荐用 `/www/wwwroot/nzcommit.sh`（私有 index 提交，架构债 Step3-B）**：`node nz.js run "bash /www/wwwroot/nzcommit.sh <repo> -b <base64中文消息> <文件...>"`（英文 msg 可 -m）。它把暂存放进**每进程私有 index**（从 HEAD 起、只 add 你列的文件），**别窗的 `git commit` 卷不走你 add 的文件**（共享 .git/index 串味，2026-06-21 真出过事）；提交后自动 `reset HEAD -- <文件>` 把共享 index 拉平、防别窗 plain commit 回退你的文件。不 push，提交后照常 `git -C <repo> push`。opt-in，没用此脚本的窗口=现状无回归。

## 1.1 🔴 main 必须时刻可部署〔2026-07-22 定·同日判例〕
进 `origin/main` 的每一个提交，必须处于「任何窗口、任何时刻拿去部署都安全」的状态：
- 功能/视觉改动 → 要么总闸默认关（dormant，翻闸另走验收），要么已过对应验收（前端=业主截图点头；高风险=GATE）。
- 达不到 → 留在自己分支（`nz/*`/`codex/*`）；要新坐标就 rebase origin/main，**别拿合 main 当占位**。
- 判例：2026-07-22 动效批7 波A `3868c0e` 未过 GATE 却占住 origin/main HEAD 与公共 staging，被迫全局 revert（`3eeb047`）+ 拆门收场。
- 本条同时化解「谁部署谁背书别人 N 个提交」的顾虑：release 出的是整棵 main（`git archive <SHA>`），main 上没有不可部署的东西，捎带别人的提交才是安全的；§0.5 的自动升产授权也隐含依赖本条。

## 1.5 🔴 共享工作树的定位：只读坐标系，不是编辑台〔2026-07-22 定〕
`/www/wwwroot/nezha.am` 与 `/www/wwwroot/api.nezha.am` **不只是「大家的编辑台」**——它们同时是 release 部署读取的 `.git` 对象源、`storage` 等软链的挂载点、若干常驻进程的运行目录、以及部分 cron 脚本的所在地。在上面直接改源码，代价**不是**「上线事故」（`git archive` 已挡住），而是**互相卷走改动**与**坐标系失真**。

- **动手前从最新 `origin/main` 建隔离 worktree**：
  `git -C <repo> worktree add /root/nzwt/<任务名> -b nz/<任务名> origin/main`
  在里面改、测、commit，再 `git push origin HEAD:main`（或先推自己分支）。
- **共享树只用来**：读 git 历史与对象、跑部署器、看认领区。
- 🔴**别拿共享树当「线上现在什么样」的坐标系**：2026-07-22 实测它落后 `origin/main`——后端 5 个提交、前端 13 个提交（今日已追平，但它会再漂）。要判断线上，读 `*-deploy/current/.nz-deploy-sha`。

### 🔴 建了 worktree 就登记，干完就回收
建 worktree 时在末尾「认领区」那一行写清**路径**。不登记的代价不是撞车，是**静默丢活**：

> 2026-07-22 盘点：全服 **31 棵 worktree**，其中 **4 棵里的 24 个提交 + 17 个未提交改动没有任何远端副本**，最久的放置 9 天无人认领。已全部抢救成远端 `rescue/*` 分支，但下一次不一定有人来查。

**回收前下面两条必须都空**，否则不许 `git worktree remove`：
1. `git -C <worktree> status --short` —— 有没有未提交的活
2. `git -C <worktree> branch -r --contains HEAD` —— **空 = 全服只此一份，删了就永久没了**（比 `origin/main..HEAD` 更准：它查的是所有远端 ref，不只 main）

⚠️**别为了腾磁盘删 worktree**：31 棵合计 14.3G，但严格判据下只有 1 棵可安全回收（139M）。真正的大头是 `web-deploy/releases`(6.6G) 与已无 nginx 引用的 `web-staging-deploy`(5.5G)。

## 2. 构建前扫一眼（🔴2026-07-22 重写：该扫的东西整个变了）
- 🔴**别窗未提交的代码改动一律不用管**——`git archive` 按 SHA 出货，进不了 release。看到别窗 WIP **不必等、不必避让**。（这是本节旧版最主要的错误指引，曾让不相干的并行任务互相排队，现作废。）
- 🔴**要扫的只有依赖目录，且只在依赖变更时**：只有本次部署的 `package-lock.json` 与线上不一致时，部署器才从共享工作树取 `node_modules`（`nzdeploy-web.sh:138`）。依赖没变的常规部署不碰共享树，无需扫。
- ⚠️**`[drift]` 段已不存在**：旧 `nzbuild.sh` 打印的那个「工作树 vs HEAD」对比段随 2026-06-22 切 release 一起废了（`nzbuild.sh` 现在只是个转发壳）。新部署器日志里出现的 `drift` 是**另一回事**——current / origin-main 在部署期间变动的并发防护，FATAL 级自动拒绝，不需要人看。
- ⚠️`nzdriftcheck.sh` 同期作废：它的文件头前提「构建是从工作树出货、不是从 HEAD」自 2026-06-22 起不再成立，输出会误导，别再拿它当构建前置。
- ✅ 仍然要做的：**记准你要发布的完整 40 位 SHA，并确认它已 push 且可从 `origin/main` 达到**。部署器会自己 fetch 复核；共享工作树 HEAD 是什么**与出货内容无关**，别拿它当坐标系（它经常落后 origin/main）。

## 3. 先认领再动手〔0723 拆分：认领/发现一律写仓库根 `CLAIMS.md`，不再写本文件〕
- **在哪登记**：仓库根 **`CLAIMS.md`**（活跃账本：`[ ]` 认领 / `⚙️发现` / `🔔` 窗间知会）；完成的 `[x]` 满 14 天由 `archive` 归档进 `AGENTS_ARCHIVE.md`。本文件只放规则——往这里追加认领行＝写错地方（0722 判例：认领区被 EOF 追加劈成三块，扫的人只看到其中一段）。
- **登记用工具（一条命令，成本≈0，不碰共享树）**：
  - 登记：`bash /www/wwwroot/nzclaim.sh <web|api|both> add -b <base64一句话>`（认领/发现/知会同一入口；纯英文可不用 -b 直接跟文字）
  - 完成：`bash /www/wwwroot/nzclaim.sh <web|api|both> done -b <base64关键词>`（唯一匹配的 `[ ]` 行翻 `[x]` 并盖完成日期）
  - 战情板：`bash /www/wwwroot/nzclaim.sh both list`（两仓活跃行 + 24h 提交 + worktree 清单，开工先看它）
  - 归档：`bash /www/wwwroot/nzclaim.sh <web|api> archive [天数]`（>14 天 `[x]` 搬入 AGENTS_ARCHIVE.md；工具 commit 自带 `[force-revert]`，净删属预期）
  工具对 `origin/main` 做 plumbing 提交并直接 push（不依赖、不扰动共享树与别窗 WIP，推拒自动换新基底重试）。手工编辑 CLAIMS.md 也可以（登记前先 `git pull`）。
- **小修豁免〔0723 定〕**：同时满足 ①单任务 ≤2 个 commit 且合计 ≤3 个文件 ②纯 L3（不碰资金/L1/开关/迁移/依赖）③不占公共运行态 → 可免登记，但**每个 commit message 末尾带 `[小修]`**（把「有意豁免」与「忘了登记」区分开、事后可审计）。**同一条链滚到第 3 个 commit＝已不是小修，先补登记再继续**（判例 0723：规格修复链 5 个 commit 跨两仓零登记，事后归属只能靠推断）。
- **占公共运行态也要认领〔0722〕**：翻全局开关 / `cache:clear` / 重启 fpm / 占公共 staging（3002）之前先登记一行（干什么＋预计多久），完事恢复原值并 `done`——别窗的验证可能正跑在你要动的运行态上，你一动，它的绿灯就成了假绿。
- 开工先 `list` 扫活跃行；撞面先避让，或在 CLAIMS.md 用 `🔔` 行打招呼协调。
## 4. 跨全站改动要打招呼
改 theme / navbar / `_app` / 全局样式 / `nzbuild.sh` / `next.config.js` 这类**牵一发动全身**的东西，先在认领区显著标注——它几乎和所有页面冲突，别人没法躲。

## 出事了
- 不确定工作树里别人的改动能不能动：**停，问人**，别赌。
- 构建挂了 / 全站 500：先 `cat /tmp/nezha_build_last.log`，多半是带了半成品。

---
## 🔴🔴 后端部署契约已变更(2026-06-22 部署边界改造窗口) — 所有后端窗口必读
**后端「存盘即上线」已废除。** production 现从 `/www/wwwroot/api-deploy/current`(→ releases/ 下不可变快照)跑,**只认 commit+push 到 origin/main 的代码**。
- 改后端 = 工作树 `/www/wwwroot/api.nezha.am` 改 → 精确 `git add`+commit+**push** → 记录欲发布的完整 40 位 SHA → 跑 `node nz.js run "bash /www/wwwroot/api-deploy/nzdeploy-api.sh <40位完整commit SHA>"` 上线（目标必须可从 fetch 后的 `origin/main` 到达；干净ref+vendor硬链+排队/current/ref 防漂移+原子切current+健康门+自动回滚）。部署器拒绝无参、短 SHA 与隐式 latest main。
- **发布范围护栏（2026-07-15）**：运行部署器前必须先把 `current` release 名中的提交解析为生产基线，逐项核对 `git log --oneline <current-sha>..<target-sha>`、`git diff --name-status <current-sha>..<target-sha>` 与 `git diff --name-only <current-sha>..<target-sha> -- database/migrations`。只要目标夹带本次未授权文件、功能或 migration，就必须停止，不能因目标是 `origin/main` 头或健康门会过而继续；需要单项热修时，从当前 production 提交建最小 hotfix，再以不改变 main 树的 merge 让该 hotfix 成为 `origin/main` 可达提交，部署 hotfix SHA 而不是 main 头。此条是人工护栏，不是部署器能机械判断授权范围的墙。
- 2026-07-14 实测 `/www/wwwroot/api-deploy/nzdeploy-api.sh` 是独立普通文件，不是指向仓库正本的软链；部署器改动必须从已 push 的精确提交提取同一 blob，同时备份并安装到仓库 `deploy/nzdeploy-api.sh` 和该实际运行入口，再核对两者哈希一致。禁止假定软链后只更新其中一份。
- **未提交/未push 的改动不会上线**(只停工作树)。这是有意为之的墙,不是 bug。
- 🔴 storage 与 .env 已抽到 `api-deploy/shared/`(L1凭证持久层),别动;工作树 storage/.env 是软链。备份脚本已指 shared。
- 回滚: `ln -sfn $(readlink /www/wwwroot/api-deploy/previous) /www/wwwroot/api-deploy/current && kill -USR2 $(cat /www/server/php/82/var/run/php-fpm.pid)`。
- 前端(nezha.am)暂未改,仍走 nzbuild.sh。

---
## 🔴 跨窗提交两道防线(2026-06-22 路线B + 防覆盖墙) — 所有窗口必读
单一共享工作树 + 共享 `.git/index` 的两个跨窗"提交卫生"事故已各砌一道墙。（注: catastrophic 的"带半成品上线/出货旧版"已被【前后端都已上线的 release 发布边界】另行杀死，deploy 只 archive origin/main、永不读工作树。）

1. **index 串味**(别窗 `git add`/`git commit -a` 卷走我已暂存的文件、归属混乱) → 用私有 index 提交助手：
   `bash /www/wwwroot/nzcommit.sh <仓库路径> -F <消息文件> <文件...>`   (中文消息可用 `-b <base64消息>`，或 `-m "消息"`)
   它把暂存放进私有临时 index，别窗看不见、卷不走；提交后只把我这几个文件回同步共享 index。
   ⚠️ 它仍从【共享工作树】读文件内容，提交前务必先 `git diff HEAD -- <文件>` 确认没扫进别窗 WIP。

2. **物理覆盖 / 旧备份回退**(别窗把旧 `.bak` 盖到盘上文件再提交，悄悄退掉我已 push 的新代码；2026-06-21 customerKb 被退案) → 两仓 `.git/hooks/commit-msg` 装了【防覆盖墙】：
   本次提交相对 origin/main **净删 > 15 行**即拦下，点名文件与行数。
   确是【有意】大删除 → 在 commit message 末尾加 `[force-revert]` 重提；应急 `git commit --no-verify`(自负风险)。
   注: 墙比的是本地 origin/main ref(未 fetch 会偏旧)，是提交期 best-effort；catastrophic 路径 push/deploy 自己会 fetch。

这两道只堵"提交卫生"。两窗同时物理改同一文件盖盘仍靠【认领区】。🔴2026-07-22 更新：原「完整 worktree 隔离(路线A)暂缓，待并发变密集再升级」已作废——并发确实变密集了（实测 31 棵 worktree、9 个 pm2 外裸起的 next-server），**worktree 隔离已定为默认开工姿势，见 §1.5**。

---
## 认领区〔0723 已整体迁出〕
本区所有条目（含曾散落在文件头与文件尾的三块）已一字不改迁至仓库根 **`CLAIMS.md`**（活跃）与 `AGENTS_ARCHIVE.md`（归档）。登记/完成/战情板/归档命令见 §3。**别再往本文件追加任何认领/发现行**——写这里等于混进规则正文，别的窗口扫不到。
