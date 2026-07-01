# 商家退出结算/押金账户（组④⑤）— Debate 归档

本目录归档「押金账户 + 商家退出结算」设计过程中**三轮 /debate（9 路独立红队）**的原始报告，供追溯推理细节。所有修法已折叠进正本设计 `docs/DESIGN_merchant_offboard.md`；本归档是**点对点历史快照**，不随设计更新。

## 正本（活文档，勿以本归档为准）
- 政策/L1 决策：`docs/PLAN_merchant_offboard.md`
- 实现设计（v3 实装就绪）：`docs/DESIGN_merchant_offboard.md`（§A–§J 设计 + §K 三轮 debate 摘要）
- 红线：`INVARIANTS.md` L1-8 + `docs/compliance/CHANGELOG.md`

## 冻结快照（git，无漂移）
- PLAN 定稿：`7851735`
- DESIGN v1：`b695a58` · v2：`deb46b1` · v2+§L（第三轮结论全文）：`623b87b` · **v3：`60acd47`**

## 三轮 debate 报告

### 第一轮 — 政策核验（对 PLAN，verdict 全 🔴 阻断上线 → 定 L1 决策 + 押金法币-only）
| 文件 | 红队角度 | verdict |
|---|---|---|
| `debate_1_fund_safety.md` | 资金安全 | 🔴 阻断 |
| `debate_2_compliance.md` | 合规 L1 | 🔴 阻断 |
| `debate_3_abuse_control.md` | 防薅/滥用/内控 | 🔴 阻断 |
> 5 阻断项：净额竞态/三腿原子性/制裁前置门缺失(L1-6)/无来路字段/退出门被当押金人质。

### 第二轮 — 设计核验（对 DESIGN v1，verdict 需补后可实现 → v2）
| 文件 | 红队角度 | verdict |
|---|---|---|
| `debate2_1_compliance.md` | 合规落地/加密 PII | 需补后可实现 |
| `debate2_2_fund_correctness.md` | 资金正确性/并发/状态机 | 需补后可实现 |
| `debate2_3_feasibility.md` | 实现可行性/StackFood 集成 | 需补后可实现 |
> 5🔴：冻结接缝错/net 悬空(penalty 不存在)/制裁读入驻旧快照/KYC 门卡死 100% 存量商家(线上 0 档)/加密 PII 跨 vendor 匹配硬墙。

### 第三轮 — v2 核验（verdict 不一致但收敛 → v3）
| 文件 | 红队角度 | verdict |
|---|---|---|
| `debate3_1_concurrency.md` | 并发/状态机/回归 | 还需补 3(2🔴) |
| `debate3_2_compliance.md` | 合规/KYC/指纹落地 | 可进代码需补 |
| `debate3_3_endtoend.md` | 端到端/边界/运营 | 7 断点(3🔴死循环) |
> 4🔴：状态机无回流边(3 死循环)/C1 漏 POS 建单入口/`order_transactions` 无唯一约束双扣佣(既有 LIVE bug，独立任务 `task_fb41eea8`)/C5 语义错位+C4 DoS。

## 三轮一致确认站得住（骨架）
资金账目自洽 · 三腿原子性+逐腿幂等 · 隔离 INV-1 · 停接单/停扣佣接缝正交 · net=三账户和删 penalty · active_uniq NULL 5.7 部分唯一 · 制裁实时 re-screen · 直付单 410 终态 · 迁移 additive 可回滚 · 合规修法无假落地。

## 下一步
不再跑第四轮 debate（收敛已定）。按 `docs/DESIGN_merchant_offboard.md` §I 灰度实装，step 0 先修 `order_transactions` 双扣佣既有 bug（`task_fb41eea8`），staging 下单 harness 作资金正确性唯一验收。

*归档时间 2026-07-01。相关 memory `project_nezha-merchant-accounts-reconciliation-refund`。*
