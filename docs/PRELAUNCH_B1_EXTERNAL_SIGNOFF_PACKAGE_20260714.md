# B1 外部签收包（2026-07-14）

状态：**已重封、未签收、production NO-GO**。2026-07-15 对本轮可访问签回入口复核后，外部返回材料仍为 **0 件**；不得把模板、自动化证据或当前配置冒充签回。

本包把上线前仍需外部人员裁决的事实、问题和证据栏固定下来；它不是批准书，也不授予 production/shared staging 部署、migration、数据、配置、开关、资金、真实通知或 demo rollback 权限。所有空白签收项均保持未完成，当前配置值不得当成批准值。

## 1. 固定对象与只读锚点

| 对象 | 固定值 / 2026-07-15 只读复核值 | 边界 |
|---|---|---|
| API 应用候选（五份表单统一记录值） | `a53cfb5c967daa5917ce2cb4c2489d6799434ff2` | 最终已验应用候选；覆盖 `b14c9c58bee66b59a45bb338f2d742609a3466f3` 运行时代码，后续仅有本轮认领/重封文档提交，不代表已部署 |
| API B1 原始包提交（历史） | `98efcc2a625e8ba19b068747251d2ed3d66a497d`；parent `589a5366633f951fc9692810cc2a4c21c553b629`；tree `d4c9c871011a76f6ca28021b53579a20d92b9a49` | 只用于追溯旧包；旧 `589a5366` 不再是最终签收对象 |
| API B1 重封内容快照提交 | `fc026a78130709ce13af356914ce01c50000d866`；parent `79e61a0d14806e8a70fcc5a7eea839818cacddbb`；tree `de6f0d0a7b902f03fafd5db57e61829bcccb4c59` | 本次五份表单与三份正本的 8 文件快照；后续审计提交只回填本行、下表哈希及三份正本的快照引用，不改变应用候选或外部签收空白 |
| Web `origin/main` | `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed` | 当前候选锚点，不代表已部署 |
| production API | current `20260715-042928-dea5dd1`；previous `20260714-070255-e044d34` | 只含从旧 production 基线制作的邮箱隔离热修；不是最终 main 候选，B1 本轮未切换 release |
| production Web | current `20260714-101004-2f81803`，BUILD `Mguty8CEfSrUIu5FXJ52G`；previous `20260714-074400-b66c0d1` | B1 未切换 release |
| shared staging Web | HEAD `ef54278551a3f8818661380f919fa894e47cc50c`，BUILD `n4VGKngOQXDelVRDdK9yN`，10 tracked + 6 untracked | 保留现有 WIP，不清理、不 reset、不部署 |
| shared staging API | detached HEAD `f766dd62bd949613898e31031cf5636527488d8f`，37 tracked + 2 untracked | 保留现有 WIP，不清理、不 reset、不部署 |
| production migration | `dea5dd11a2a57b57660608e9c37a4fd528aa4efe..a53cfb5c967daa5917ce2cb4c2489d6799434ff2` migration 文件 diff=0；`migrate:status` 460 Ran / 0 Pending | 只读事实；B1 未运行 migration |
| production queue | 两个 worker online、重启累计各 93；`unstable_restarts=0`；Redis `PONG`；failed jobs=0 | `--max-time=3600` 的周期重拉；不等于真实送达 |

服务器根盘本次复核为 84% 使用、剩余约 13G，仍处于磁盘冻结线；因此 B1 使用本地干净独立克隆，没有在服务器新增 worktree，也没有清理既存临时目录。

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

- 最终候选回归与 runtime 复核：2026-07-15 06:48–06:55 CEST；重封前 API/Web refetch 均未前移。API 随后只新增本轮 `AGENTS.md` 认领提交 `79e61a0d14806e8a70fcc5a7eea839818cacddbb`，`a53cfb5c..79e61a0` 的非 `AGENTS.md` 文件数为 0，故应用候选仍固定为 `a53cfb5c...`。
- 可访问范围：本地项目工作区、Desktop、Downloads 的文件名/内容关键词检索，Documents 最近 24 小时文件复核，以及 API 远端 heads/tags/提交包含关系复核。该范围不包含未接入本任务的外部邮箱、网盘或纸质文件。
- 结果：没有发现任何填写后的 B1 表单、律师/会计意见、owner 决策、物理设备报告、真实通知回执、渗透报告或独立签名附件。原 B1 之后的后台账号邮箱隔离运行时代码已在固定候选 `a53cfb5c...` 上完成本地隔离回归并重封；远端仍没有发现签回分支或 tag。
- 因收到材料为 0 件，可校验的外部文件 SHA-256、报告版本、实名/机构、日期时间/时区也均为 0；这是“尚未收到/未提供”，不是外部机构已经拒绝或测试失败。

### 4.2 固定 Git 对象与文档哈希

- B1 重封内容快照提交：`fc026a78130709ce13af356914ce01c50000d866`；parent `79e61a0d14806e8a70fcc5a7eea839818cacddbb`；tree `de6f0d0a7b902f03fafd5db57e61829bcccb4c59`；精确 8 文件，`git diff --check` 通过。下表 SHA-256 对该提交中的 Git blob 原始字节计算；当前审计提交只回填这些锚点，因此当前包文件本身会与快照 blob 不同，须按指定 commit 重算，不能对工作树文件误比。
- Git 提交签名状态为 `%G?=N`（无 GPG/SSH commit signature）。这不能代替外部实名签收，也不改变下表内容可由 commit/tree/SHA-256 重算的事实。

| `fc026a78130709ce13af356914ce01c50000d866` 中的文件 | SHA-256 |
|---|---|
| `PRELAUNCH_QA_MASTER.md` | `8dd6d392f09dd8cb9eca3193ed6f35d897993fb7822461545865727cf45de4fa` |
| `PRELAUNCH_QA_RESULT_20260714.md` | `df3e02fa0f06e9cc41f2d12f277d65daf0e1065c8692aa75dc42dd13d7d00b21` |
| `PRELAUNCH_CLOSURE_LEDGER_20260714.md` | `a128afea87200e79126025a257a8d4da7e9ff545b25db02ec8a8882468f142ac` |
| `PRELAUNCH_B1_EXTERNAL_SIGNOFF_PACKAGE_20260714.md` | `c1cf2e3b9c0f35151aee91a58494fec60753fc03d14cca3d8b820a18ad7d9cfd` |
| `PRELAUNCH_B1_DEMO_ASSOCIATION_DECISIONS_20260714.md` | `e570227fc9cbe975bd7b700430cd1696900d2dc9450afccf40ba26310c4e788d` |
| `PRELAUNCH_B1_LEGAL_ACCOUNTING_FACTS_20260714.md` | `f12dab399949827552fb7d283db5ecb6e4b0881cbb5adf57547cf1c303c4beaa` |
| `PRELAUNCH_B1_SWITCH_SIGNOFF_20260714.md` | `7ff25dc8613ffc888b50ea76f3ab6964f5f79c240175dcdf7e86a13664541f9b` |
| `PRELAUNCH_B1_EXTERNAL_QA_SIGNOFF_20260714.md` | `6c051bccbbe8f6174e8aeded51c4dc6a84bca5277d1effe3e7facf43e42aca7a` |

固定 manifest 5/5 均与裁决表登记值一致；浏览器证据包也重算一致：`nezha-prelaunch-browser-evidence-20260714.tgz`=`532a1725d659a8d02a6c23a744777bd2f63b040ef1ffbc852e884fd2882735a3`，`nezha-prelaunch-browser-evidence-current-20260714.tgz`=`c6cf5cb5abddc056543f431c58345996c08c1e0dd5ee84fbfa1b86109eb29dc4`。这些是内部既有证据锚点，不是外部签名附件。

### 4.3 签名完整性与未关闭条件

| 工件 | 2026-07-15 完整性结果 | 判定 |
|---|---|---|
| Demo 裁决 | 26/26 仍为 `HOLD`；31 个关联订单无逐 ID 定性；6 个汇总角色签名为空 | 未签收；demo rollback NO-GO |
| 法律/会计 | F1–F10 主体确认空白；L1–L12、A1–A9 答复空白；0/4 专业签收 | 未签收 |
| 三项开关 | 0/3 批准目标值；preorder/通知/视频人工证据为空；0/5 汇总签名；autooffline 通知/阈值也未签 | 未签收；当前值 `1` 不是批准值 |
| 外部 QA | 执行包封面 0/11；D1–D6 登记与签收 0/6；FCM/邮件/TG 回执均为空；渗透授权、报告、复测和 0/6 总签收均为空 | 未执行/未签收 |
| API 候选 SHA | 五份表单统一记录 `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`；`b14c9c58` 的 8 个运行时文件到该候选无差异，隔离回归通过 | 候选冻结/重测/重封门已关闭；旧 `589a5366` 不得作为最终签收对象 |
| 店 12 与备份 | 不在 B1 包内；店 12 营业/经营者/收款/通知/受控订单/激活未签，`utf8mb4` 新备份恢复证据未生成 | 独立硬门仍开 |

候选 SHA 漂移/重测/重封门已经关闭；六类 NO-GO、production 版本差、隐私/静态加密事实漂移、demo/Banner、店 12、法律税务/USDT、真机/真实通知/专业渗透及备份字符保真仍未关闭。production 继续 NO-GO。

### 4.4 同次只读 runtime 复核

- production current/previous：API `20260715-042928-dea5dd1` / `20260714-070255-e044d34`；Web `20260714-101004-2f81803` / `20260714-074400-b66c0d1`，BUILD `Mguty8CEfSrUIu5FXJ52G`。
- shared staging 未变且继续保留 WIP：Web `ef542785`、BUILD `n4VGKngOQXDelVRDdK9yN`、dirty 16；API detached `f766dd62`、dirty 39。
- 服务器共享源码树和 shared staging 仍非执行对象且保留既有 WIP；本轮只读取状态，没有 fetch/reset/清理。根盘为 84%、可用约 13G。
- 本次只运行 fetch、Git/文件哈希、进程/端口、migration status、queue failed status、Redis 和文件系统只读命令；没有 deploy、migration、真实通知、备份脚本、demo `REHEARSE/GO`、开关/配置/数据/资金写入。

## 5. 建议流转顺序

1. 候选冻结门已完成：五份表单只允许流转 API `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`、Web `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`；任何 SHA 前移先停下重新定界，旧 `589a5366` 不得流转。
2. 平台主体负责人确认固定事实无误，填写主体资料，但不改线上状态。
3. 数据 owner 和各数据域 owner 完成 26 类关联裁决；涉及资金、审计、PII、客服或通知的数据同时交律师/会计复核保留义务。
4. 律师、会计、USDT/AML 顾问分别出具书面意见；平台把强制整改逐项回填到收口台账。
5. 在另行批准的隔离目标和专用身份上完成物理设备、真实通知与独立渗透；B1 本身不授权这些动作。
6. 产品/运营/内容 owner 依据人工证据签收三项开关目标值；如决定改值，另取精确 Go。
7. 全部签字后由 QA/安全 owner 复核证据完整性；仍须重新跑部署必测门并取得 production Go，不能由本包自动升产。
