# B1 外部签收包（2026-07-14）

状态：**已备妥、未签收、production NO-GO**。

本包把上线前仍需外部人员裁决的事实、问题和证据栏固定下来；它不是批准书，也不授予 production/shared staging 部署、migration、数据、配置、开关、资金、真实通知或 demo rollback 权限。所有空白签收项均保持未完成，当前配置值不得当成批准值。

## 1. 固定对象与只读锚点

| 对象 | 2026-07-14 B1 只读复核值 | 边界 |
|---|---|---|
| API `origin/main` | `589a5366633f951fc9692810cc2a4c21c553b629` | B1 文档从此提交创建；签收执行前仍须重新 fetch 固定最终 SHA |
| Web `origin/main` | `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed` | 当前候选锚点，不代表已部署 |
| production API | current `20260714-070255-e044d34`；previous `20260714-063310-75c6e4c` | B1 未切换 release |
| production Web | current `20260714-101004-2f81803`，BUILD `Mguty8CEfSrUIu5FXJ52G`；previous `20260714-074400-b66c0d1` | B1 未切换 release |
| shared staging Web | HEAD `ef54278551a3f8818661380f919fa894e47cc50c`，BUILD `n4VGKngOQXDelVRDdK9yN`，10 tracked + 6 untracked | 保留现有 WIP，不清理、不 reset、不部署 |
| shared staging API | detached HEAD `f766dd62bd949613898e31031cf5636527488d8f`，37 tracked + 2 untracked | 保留现有 WIP，不清理、不 reset、不部署 |
| production migration | `e044d34..API origin/main` migration 文件 diff=0；`migrate:status` Pending=0 | 只读事实；B1 未运行 migration |
| production queue | 两个 worker online、重启累计各 83；Redis `PONG`；failed jobs=0 | 运行态会变化，签收通知前重查；不等于真实送达 |

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

## 4. 建议流转顺序

1. 平台主体负责人确认固定事实无误，填写主体资料，但不改线上状态。
2. 数据 owner 和各数据域 owner 完成 26 类关联裁决；涉及资金、审计、PII、客服或通知的数据同时交律师/会计复核保留义务。
3. 律师、会计、USDT/AML 顾问分别出具书面意见；平台把强制整改逐项回填到收口台账。
4. 在另行批准的隔离目标和专用身份上完成物理设备、真实通知与独立渗透；B1 本身不授权这些动作。
5. 产品/运营/内容 owner 依据人工证据签收三项开关目标值；如决定改值，另取精确 Go。
6. 全部签字后由 QA/安全 owner 复核证据完整性；仍须重新跑部署必测门并取得 production Go，不能由本包自动升产。
