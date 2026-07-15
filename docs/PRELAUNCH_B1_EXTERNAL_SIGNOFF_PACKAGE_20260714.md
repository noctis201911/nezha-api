# B1 外部签收包（2026-07-14）

状态：**已备妥、未签收、production NO-GO**。2026-07-15 对本轮可访问签回入口复核后，外部返回材料仍为 **0 件**；不得把模板、自动化证据或当前配置冒充签回。

本包把上线前仍需外部人员裁决的事实、问题和证据栏固定下来；它不是批准书，也不授予 production/shared staging 部署、migration、数据、配置、开关、资金、真实通知或 demo rollback 权限。所有空白签收项均保持未完成，当前配置值不得当成批准值。

## 1. 固定对象与只读锚点

| 对象 | 固定值 / 2026-07-15 只读复核值 | 边界 |
|---|---|---|
| API 当前 `origin/main` | `b14c9c58bee66b59a45bb338f2d742609a3466f3`；parent `98efcc2a625e8ba19b068747251d2ed3d66a497d` | 2026-07-15 06:20 CEST fetch 后的新运行时代码提交；未改 B1 文档、无 migration，但不在原 B1 自动/人工证据覆盖内 |
| API B1 包提交 | `98efcc2a625e8ba19b068747251d2ed3d66a497d`；parent `589a5366633f951fc9692810cc2a4c21c553b629`；tree `d4c9c871011a76f6ca28021b53579a20d92b9a49` | B1 五份表单与三份正本的固定提交；只含文档，不代表已部署 |
| API 应用候选（表单记录值） | `589a5366633f951fc9692810cc2a4c21c553b629` | 已落后当前 `origin/main`；最终流转前必须冻结新候选、重测并重封 SHA，不能在旧值上签最终通过 |
| Web `origin/main` | `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed` | 当前候选锚点，不代表已部署 |
| production API | current `20260714-070255-e044d34`；previous `20260714-063310-75c6e4c` | B1 未切换 release |
| production Web | current `20260714-101004-2f81803`，BUILD `Mguty8CEfSrUIu5FXJ52G`；previous `20260714-074400-b66c0d1` | B1 未切换 release |
| shared staging Web | HEAD `ef54278551a3f8818661380f919fa894e47cc50c`，BUILD `n4VGKngOQXDelVRDdK9yN`，10 tracked + 6 untracked | 保留现有 WIP，不清理、不 reset、不部署 |
| shared staging API | detached HEAD `f766dd62bd949613898e31031cf5636527488d8f`，37 tracked + 2 untracked | 保留现有 WIP，不清理、不 reset、不部署 |
| production migration | `e044d34..b14c9c58` migration 文件 diff=0；`migrate:status` 460 Ran / 0 Pending | 只读事实；B1 未运行 migration |
| production queue | 两个 worker online、重启累计各 90；`exit_code=0`、`unstable_restarts=0`；Redis `PONG`；failed jobs=0 | `--max-time=3600` 的周期重拉；不等于真实送达 |

服务器根盘本次复核为 83% 使用、剩余约 13G，仍处于磁盘冻结线；因此 B1 使用本地干净独立 worktree，没有在服务器新增 worktree，也没有清理既存临时目录。

## 2. 签收工件

| 工件 | 外部 owner | 当前状态 | 关闭条件 |
|---|---|---|---|
| [26 类 demo 关联数据裁决表](PRELAUNCH_B1_DEMO_ASSOCIATION_DECISIONS_20260714.md) | 数据清理 owner + 各数据域 owner | 未裁决 | 26 类逐项决定，31 个关联订单单独签收，精确 ID/动作/保留义务/可逆证据齐全 |
| [律师/会计固定事实包](PRELAUNCH_B1_LEGAL_ACCOUNTING_FACTS_20260714.md) | 拟运营主体负责人、亚美尼亚律师、当地会计师、USDT/AML 合规律师 | 未签收 | 书面意见、主体资料、强制整改清单与版本均固定 |
| [三项开关签收表](PRELAUNCH_B1_SWITCH_SIGNOFF_20260714.md) | 产品 owner、商家运营、运营 owner、内容 owner | 未签收 | 每项目标值由 owner 明写；人工证据与签名齐全 |
| [物理设备／真实通知／专业渗透签收表](PRELAUNCH_B1_EXTERNAL_QA_SIGNOFF_20260714.md) | QA/安全 owner、设备测试人、真实收件人、独立渗透机构 | 未执行/未签收 | 设备矩阵、三渠道回执、渗透报告及复测门全部关闭 |

六类 NO-GO 的 owner 和上线门仍以 [收口台账](PRELAUNCH_CLOSURE_LEDGER_20260714.md) 为正本。本包不覆盖真实商家店 12 的营业时间、经营者、收款归属、通知接收、受控订单和激活签收，也不关闭备份 `utf8mb4` 字符保真失败。

## 3. 填写与证据规则

1. 所有签名必须包含实名、角色/机构、日期时间与时区；机构意见附报告版本或文件 SHA-256，不能只勾选复选框。
2. “通过”必须紧邻证据：截图/录像、设备与系统版本、请求或事件 ID、报告页码、只读命令输出或固定提交；敏感收件地址、token、证件和账户只保留遮蔽值。
3. 签收对象必须写明 API SHA、Web SHA、运行环境和数据快照时间。任何 SHA、开关、素材、数据或环境变化都会使受影响签收失效，需重测并重新签字。
4. 数据裁决的“删除”只代表 owner 的业务/法律意见，不授权执行；真实删除、导出、备份、配置回退或通知发送另取精确 Go。
5. 律师/会计只能对其专业结论签字；平台事实由主体负责人签字。AI、自动化测试和仓库维护者不能代签外部结论。
6. 任一必填项空白、证据无法回溯、Critical/High 未关闭、真实通知任一渠道无回执，或 26 类数据未逐项裁决，production 继续 NO-GO。

## 4. 2026-07-15 签回与证据完整性验收

### 4.1 返回材料清单

- runtime 复核时间：2026-07-15 06:12 CEST（服务器观测 `2026-07-15T04:12:54+00:00`）；第二次 Git fetch：06:20 CEST（`2026-07-15T04:20:29+00:00`）。
- 可访问范围：本地项目工作区、Desktop、Downloads 的文件名/内容关键词检索，Documents 最近 24 小时文件复核，以及 API 远端 heads/tags/提交包含关系复核。该范围不包含未接入本任务的外部邮箱、网盘或纸质文件。
- 结果：没有发现任何填写后的 B1 表单、律师/会计意见、owner 决策、物理设备报告、真实通知回执、渗透报告或独立签名附件。API `origin/main` 在本轮中前移到 `b14c9c58`，但该提交是后台账号邮箱隔离代码，`98efcc2a..b14c9c58` 对八份 B1/正本文档 diff=0；远端没有发现签回分支或 tag。
- 因收到材料为 0 件，可校验的外部文件 SHA-256、报告版本、实名/机构、日期时间/时区也均为 0；这是“尚未收到/未提供”，不是外部机构已经拒绝或测试失败。

### 4.2 固定 Git 对象与文档哈希

- B1 提交：`98efcc2a625e8ba19b068747251d2ed3d66a497d`；parent `589a5366633f951fc9692810cc2a4c21c553b629`；tree `d4c9c871011a76f6ca28021b53579a20d92b9a49`；`git diff --check` 通过。
- Git 提交签名状态为 `%G?=N`（无 GPG/SSH commit signature）。这不能代替外部实名签收，也不改变下表内容可由 commit/tree/SHA-256 重算的事实。

| `98efcc2a` 中的文件 | SHA-256 |
|---|---|
| `PRELAUNCH_QA_MASTER.md` | `3cac510685f663ce03bed0e9628f6e59c694aec1d2023f1e2a4b7188b1c6597c` |
| `PRELAUNCH_QA_RESULT_20260714.md` | `8289f5e9024a1bd8b723be30e24d81fee3f67618082bbb8ce06730c91e6d5890` |
| `PRELAUNCH_CLOSURE_LEDGER_20260714.md` | `58559c60abbbdab589873ccbd496b379f71a9fe6ef5322563183143a2efb7229` |
| `PRELAUNCH_B1_EXTERNAL_SIGNOFF_PACKAGE_20260714.md` | `60f0d03fa33c1c4b1e1ec06f80d49fe9d9edac1d57f440599ea8f72d98ac7871` |
| `PRELAUNCH_B1_DEMO_ASSOCIATION_DECISIONS_20260714.md` | `c70f2c194464a5bd4c16390723bd1b6abcbad40451ca15fafcbbd58c3e441587` |
| `PRELAUNCH_B1_LEGAL_ACCOUNTING_FACTS_20260714.md` | `66b1e2dd79940a10f68fb92db5ac93124cd3116a85fd114df16bb27a1c741e00` |
| `PRELAUNCH_B1_SWITCH_SIGNOFF_20260714.md` | `b4ddb136ada4ea4e95dde6df259d96af799e02350c8d6d10be4a529ff8834d0e` |
| `PRELAUNCH_B1_EXTERNAL_QA_SIGNOFF_20260714.md` | `1a1d99d32b169e04ed1979f2d3c3cc162757c9f2318719361ebeeec4957bce9d` |

固定 manifest 5/5 均与裁决表登记值一致；浏览器证据包也重算一致：`nezha-prelaunch-browser-evidence-20260714.tgz`=`532a1725d659a8d02a6c23a744777bd2f63b040ef1ffbc852e884fd2882735a3`，`nezha-prelaunch-browser-evidence-current-20260714.tgz`=`c6cf5cb5abddc056543f431c58345996c08c1e0dd5ee84fbfa1b86109eb29dc4`。这些是内部既有证据锚点，不是外部签名附件。

### 4.3 签名完整性与未关闭条件

| 工件 | 2026-07-15 完整性结果 | 判定 |
|---|---|---|
| Demo 裁决 | 26/26 仍为 `HOLD`；31 个关联订单无逐 ID 定性；6 个汇总角色签名为空 | 未签收；demo rollback NO-GO |
| 法律/会计 | F1–F10 主体确认空白；L1–L12、A1–A9 答复空白；0/4 专业签收 | 未签收 |
| 三项开关 | 0/3 批准目标值；preorder/通知/视频人工证据为空；0/5 汇总签名；autooffline 通知/阈值也未签 | 未签收；当前值 `1` 不是批准值 |
| 外部 QA | 执行包封面 0/11；D1–D6 登记与签收 0/6；FCM/邮件/TG 回执均为空；渗透授权、报告、复测和 0/6 总签收均为空 | 未执行/未签收 |
| API 候选 SHA | 表单记录 `589a5366`，当前 `origin/main=b14c9c58`；新提交含运行时代码，尚未进入本 B1 QA 证据 | 先冻结/重测新候选并重封 B1，旧 SHA 不得签最终通过 |
| 店 12 与备份 | 不在 B1 包内；店 12 营业/经营者/收款/通知/受控订单/激活未签，`utf8mb4` 新备份恢复证据未生成 | 独立硬门仍开 |

因此六类 NO-GO、候选 SHA 漂移/重测、production 版本差、隐私/静态加密事实漂移、demo/Banner、店 12、法律税务/USDT、真机/真实通知/专业渗透及备份字符保真均未关闭。production 继续 NO-GO。

### 4.4 同次只读 runtime 复核

- production current/previous 未变：API `20260714-070255-e044d34` / `20260714-063310-75c6e4c`；Web `20260714-101004-2f81803` / `20260714-074400-b66c0d1`，BUILD `Mguty8CEfSrUIu5FXJ52G`。
- shared staging 未变且继续保留 WIP：Web `ef542785`、BUILD `n4VGKngOQXDelVRDdK9yN`、dirty 16；API detached `f766dd62`、dirty 39。
- 服务器共享源码树仍非执行对象：Web `fb142145`、dirty 13、`origin/main=b4e0ea0f`；API `5042b39`、dirty 87、第二次 fetch 后 `origin/main=b14c9c58`。根盘仍 83%、可用约 13G。
- 本次只运行 fetch、Git/文件哈希、进程/端口、migration status、queue failed status、Redis 和文件系统只读命令；没有 deploy、migration、真实通知、备份脚本、demo `REHEARSE/GO`、开关/配置/数据/资金写入。

## 5. 建议流转顺序

1. 先冻结最终 API/Web 候选；对 `b14c9c58` 及其后续提交完成与风险相称的自动化/隔离回归，再把五份 B1 表单中的 API SHA 重封为同一最终 40-hex。未重封前不流转最终签名。
2. 平台主体负责人确认固定事实无误，填写主体资料，但不改线上状态。
3. 数据 owner 和各数据域 owner 完成 26 类关联裁决；涉及资金、审计、PII、客服或通知的数据同时交律师/会计复核保留义务。
4. 律师、会计、USDT/AML 顾问分别出具书面意见；平台把强制整改逐项回填到收口台账。
5. 在另行批准的隔离目标和专用身份上完成物理设备、真实通知与独立渗透；B1 本身不授权这些动作。
6. 产品/运营/内容 owner 依据人工证据签收三项开关目标值；如决定改值，另取精确 Go。
7. 全部签字后由 QA/安全 owner 复核证据完整性；仍须重新跑部署必测门并取得 production Go，不能由本包自动升产。
