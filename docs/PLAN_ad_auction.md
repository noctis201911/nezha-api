# 哪吒商家广告「实时竞价」系统 — 实施方案（锁定 v1）

> 状态：方案已锁定，经 `/debate` 三路对抗核验收紧后定稿（2026-07-01），待开工第一期。
> 资金定级 **L2**（沿用 `advertisement_fee` 保证金扣款类型；平台收自有广告服务费、不碰顾客钱、非二清）。
> 计费总开关 `nezha_ad_auction_status` 默认 **0（关）**，与现有「按天计费 CPT」`nezha_ad_billing_status` 解耦，可灰度。
> 前置依据：`PLAN_advertisement_billing.md`（CPT 包时段广告，已上线）。本方案落地其 §4「V1 不做」推迟的「按点击计费 CPC」。

## 0. 现状基线（开工前已核实）
- 现有广告 = StackFood「包时段 CPT」：商家提交 → 超管审核 → 到投放首日 `ChargeAdOnStart` 从保证金一次性扣 单价×天数；付费店在综合排序统一 **+0.5 拉平加权**（`RestaurantLogic.php:147-155` 的 `EXISTS` 子查询，二值、不分出价）。
- 顾客端首页广告位 API（`Api/V1/AdvertisementController@get_adds`）已通，但前端 `useGetAdds` 写死 `enabled:false`、无人引用——首页广告位前端从未做。首页横滑卡组件已有「推广」角标（`home/index.js:2266/2335`）。
- 竞价（bid/auction/CPC）= 零代码，本方案全新建。

## 1. 锁定决策
| 项 | 锁定值 |
|---|---|
| 计费模型 | **CPC**（出价 = 每次点击最高愿付价，德拉姆） |
| 清算规则 | **首价**（出多少扣多少，封顶日预算）。GSP 否决：小市场竞标者寥寥、复杂度收益为负、对新手商家讲不清 |
| 排序 | eCPM = 出价 × 质量分；质量分只用难刷信号（完单率 / 差评率 / 出餐时长，剔除裸点击率与自点击） |
| 竞价计算 | **近实时**：每 N 分钟后台重算赢家并物化（非每请求实时跑），保住首页/列表缓存、不抬 P99 |
| 资金账户 | **独立 `ad_balance`**：CPC 只扣它、永不碰 `deposit_balance`（杜绝广告烧空保证金触发停业闸） |
| 计费身份 | 服务端可信（登录 + 有真实下单史用户点击才扣费）；不可信流量 `charged=0` 只记录 |
| 广告位标识 | **全不打「推广/广告」标**（首页 + 列表均不打，业主 2026-07-01 决定）。唯一红线：付费位不得贴**虚假 merit 标签**（热门/精选/评分最高），见 §6 |
| 商家界面 | 只给三旋钮：① 每天最多花 X ֏ ② 想多靠前 低/中/高 ③ 今日已花 / 带来几次点击。出价/eCPM/清算全藏后台 |
| 资金定级 | L2，沿用 `advertisement_fee` 流水类型 |

## 2. 七条不变量（任何时候必须成立，违反即资损/合规事故）
- **INV-1**　广告费只扣 `ad_balance`、**永不碰 `deposit_balance`**——从结构上根除「买广告把自己店买下线」。
- **INV-2**　广告扣费一律走**原子条件 UPDATE**，**禁用 `$wallet->save()` 整行写**；封顶靠 `WHERE spent_today + cost <= daily_budget`（受影响 0 行 = 到顶，拒绝计费）。
- **INV-3**　全站资金锁序统一：`restaurant_wallets → advertisements → 写流水`，三条路径（订单佣金 / `ChargeAdOnStart` / 点击扣费）同序，杜绝跨路径死锁。
- **INV-4**　计费身份服务端可信；不可信流量 `charged=0` 只记录。
- **INV-5**　付费位**不打「推广/广告」标**（首页 + 列表均不打，业主决策 §6）。唯一约束：付费位不得带**虚假 merit 宣称**（热门/精选/评分最高等），以免触亚美尼亚广告法「整体观感误导/不公平」条款。
- **INV-6**　L2，钱永不经顾客；`ad_balance` 走商家 B2B 预付充值，不碰顾客钱。
- **INV-7**　首价；`floor > 0`（防免费）；`daily_budget` 设平台硬上限；单次点击费设上限。

## 3. 第一期任务单（后端核心 · 文件级落点）
- **T1 数据层**：`RestaurantWallet` 加 `ad_balance` 列（扣费只原子动这列 = 隔离）；`advertisements` 加 `bid_amount / pricing_model(cpt&cpc 并存) / daily_budget / spent_today / budget_reset_date / slot / quality_score`；新表 `ad_events`（事件 + `dedup_key` 唯一索引 + `charged_amount` + ip/ua hash，schema 精简）；`business_settings` 加 `nezha_ad_auction_status(默认0) / floor_price / max_daily_budget / dedup_window_sec / natural_reserved_slots / max_share_per_store / recompute_interval_min`。
- **T2 近实时物化竞价**：新命令 `nezha:recompute-ad-auction`，每 N 分钟（写进 `bootstrap/app.php` withSchedule，**不是** Kernel——踩过的坑）算 eCPM = 出价 × 质量分 → 首价排序 → 物化「当前各位置赢家 + 展示价」。质量分用难刷信号、剔除自点击。
- **T3 排名接入**：改 `RestaurantLogic.php:147-155`，拉平 +0.5 → 读物化的出价驱动 boost（设 cap），保留可缓存；输出 `is_promoted` 供前端（首页打标用；列表不打标）。
- **T4 投放/计费端点**：`get_adds` 读物化赢家服务首页位；新 `click` 端点（可信身份校验 → 服务端现算首价 ≤bid ≥floor → dedup → 原子事务：锁序 wallet→ad，`UPDATE ... SET ad_balance = ad_balance - cost WHERE ad_balance >= cost` + `UPDATE advertisements SET spent_today = spent_today + cost WHERE spent_today + cost <= daily_budget`，任一受影响 0 行 = 拒绝计费 charged=0 → 写 `ad_events` + `advertisement_fee` 流水，同一事务，charged_amount = 实扣）；`impression` 端点只记录、可信去重计数、不进裸 CTR。
- **T5 预算重置（惰性）**：扣费时若 `budget_reset_date < 今天(Asia/Yerevan)` 先清零 `spent_today` 再判封顶，不纯靠 cron。
- **T6 充值通道**：`ad_balance` 充值入口，沿用现有保证金充值方式（B2B 预付，不碰顾客钱）。

## 4. 死亡测试 / 验证清单（核实）
1. **首价清算单测**：bid / floor / 单一竞标者付 floor / 平局确定性 tie-break。
2. **原子封顶并发**：并行 N 点击逼近日预算 → 总扣 ≤ daily_budget（**零超扣**）；受影响 0 行分支 charged=0。
3. **隔离验证（直接证伪「自残」）**：把 `ad_balance` 扣到 0 → `deposit_balance` 不变 → `nezha_store_paused` 仍 false、店不下线。
4. **锁序 death-test**：同 vendor 三路并发（佣金扣 deposit × 点击扣 ad_balance × 整点重算）→ 无死锁、各列对账无丢更新。
5. **可信身份**：伪造 `guest_id` / 无下单史游客狂点 → charged=0、对手预算不动。
6. **dedup**：可信身份窗口内重复点 → 只计一次。
7. **排名物化**：出价高低致顺序变 + 非广告店不被误伤 + 首页带推广标·列表不带 + 缓存仍命中。
8. **保底配额/曝光上限**：多广告构造 → 前 N 自然位保留、单店曝光不超顶。
9. **对账**：`ad_events.charged_amount` 合计 == `advertisement_fee` 流水合计（同源）。
10. **⬜ 需用户亲测**：真机广告位渲染、真人点击、真实刷量压力——做不到，如实标。

## 5. V1 不做 / 推迟 V2（防膨胀）
- GSP 次价清算（用首价）。
- 价格完整性 token 签名（首价 + 服务端点击时现算价，不需要）。
- 每请求实时竞价（用近实时物化）。
- 复杂 eCPM 质量分（只用难刷信号）。
- 搜索关键词广告（仅列表排名 + 首页位）。
- 匀速 pacing 防一分钟烧光（用日预算硬封顶兜底）。

## 6. 合规
- **L2 非二清（已数构成要件）**：二清三要件 = 无牌主体 + 归集顾客自有资金 + 再分发第三方；广告费从不经顾客钱（顾客直付商家不变）、平台收自有服务费进自己账户、不再分发 → 缺第 ②③ 要件。CPC 与 CPT 资金路径同构，颗粒度变更不升级定级。`docs/compliance/CHANGELOG.md` 记一笔即可，不动 L1。
- **广告位全不打标（业主决策 · 已核实亚美尼亚法）**：业主 2026-07-01 决定首页 + 列表竞价位均不打「推广/广告」标（含去掉首页横滑卡现有「推广」标）。**已 WebSearch + 抓两份律所实务综述核实**（Chambers / Grata，2026-07）：亚美尼亚《广告法》是**实质性反误导**框架（禁误导/虚假/不公平广告、禁利用消费者信任与缺乏经验），**无 EU 式「广告须可识别 / 付费位须标识」的强制形式要求**（一份明确「substance over form」）。红队三此前援引的「EU UCPD 付费排序须可识别」**不适用亚美尼亚**（属过度套用欧盟规范，本次核实已纠正）。因平台从不宣称该位按人气/好评排序，未作虚假陈述 → 不构成误导。**唯一红线**（源自「整体观感即便字面属实仍可能不公平」条款）：付费位**不得贴虚假 merit 标签**（热门/精选/评分最高），中性展示即可。残留：非律师意见、英文法源有限，规模化前由现有记账/律师渠道确认。
- **税**：广告费 = 平台经营收入，CPC 把按天费碎成成百上千笔小额点击费；对商家出账与 10% 流转税申报一律**按月聚合**成一张广告服务发票（金额 = 当月 `advertisement_fee` 流水合计），留「发票↔流水」对账链。记账/律师同步项。

## 7. `/debate` 三路对抗核验结论（2026-07-01）
三路红队（资金正确性 / 防刷 / 合规+过度设计）各自读线上真代码后均判「阻断原设计」，收敛为 3 根因，已在本方案修掉：
- **根因 A**　广告钱与经营保证金共池 → 烧空触发停业闸（已核实 `OrderController.php:2305-2353` `nezha_store_paused` → `order_validation_check` 返回 403「该店休息中」）+ 负余额（`deposit_balance` 无 CHECK）。修：独立 `ad_balance`（INV-1）。
- **根因 B**　按点击实时扣 + 锁错行 + save 整行 → 并发穿顶/死锁/丢更新（已核实 `OrderLogic.php:225-323`：`$vendorWallet->save()` 整行回写锁外快照，致 `total_earning`/`collected_cash` 丢更新；`deposit_balance` 因事务内 `lockForUpdate` 重读而安全）。修：原子条件 UPDATE + 统一锁序 + 禁 save 整行（INV-2/3）。**另：现有 OrderLogic `total_earning` 丢更新是存量窄 bug（B 方案直付单豁免），单独修。**
- **根因 C**　计费身份客户端可伪造（`guest_id` 客户端自报、list 端点无 auth/throttle）→ 无限身份刷爆对手预算。修：服务端可信计费身份（INV-4）。
- **过度设计裁剪**：每请求竞价 → 近实时物化；GSP → 首价；token → 不做；复杂质量分 → 难刷信号。

## 8. 第二期（前端 + 界面）
首页广告位前端（带推广标）+ 列表竞价位（不打标）+ 商家三旋钮看板（日预算 / 想多靠前低中高 / 已花费）+ 超管竞价参数页 + `ADMIN_GUIDE.md`/`MERCHANT_GUIDE.md`/`docs/compliance/CHANGELOG.md` 同步。


## 9. 第一期交付状态（2026-07-02 上线·开关默认关）
后端核心 T1~T6 全部落地并部署（`nezha_ad_auction_status=0` 灰度，关时零行为变化）：
- **T1** 迁移 `2026_07_02_000100_nezha_ad_auction_v1`：`restaurant_wallets.ad_balance` / `advertisements` 加 `bid_amount,pricing_model,daily_budget,spent_today,budget_reset_date,slot,quality_score,mat_boost,mat_rank,mat_at` / 新表 `ad_events`(dedup 唯一索引) / 9 个 business_settings 键。全 additive、可回滚。
- **T2** `nezha:recompute-ad-auction`（bootstrap withSchedule 每 5 分钟）：eCPM=bid×质量分→首价排序→物化 `mat_rank/mat_boost`；质量分=完单率/好评率/出餐速度（难刷信号）；关时清空物化。
- **T3** `RestaurantLogic`：auction 开读 `mat_boost`，关走原 CPT EXISTS（不动）。
- **T4** `Api/V1/AdvertisementController` `click`(auth:api)+`impression`(公开)：可信身份+首价+dedup+原子计费（锁序 wallet→ad→流水）；`get_adds` 读物化赢家。
- **T5** click 内惰性预算重置（`budget_reset_date<今天` 先清零，Asia/Yerevan）。
- **T6** `nezha:credit-ad-balance` CLI 充值入口（B2B 预付，原子 credit，第二期后台按钮复用）。

> **流水类型 = `ad_click_fee`（业主 2026-07-02 拍板，对 §3/§4#9「advertisement_fee」措辞的有意偏离）**：CPC 动的是 `ad_balance` 不是 `deposit_balance`，单独 type 避免与 CPT 的 `advertisement_fee` 混淆 `balance_after` 语义、保 deposit 对账纯净（INV-1）。§4#9 对账据此读 `ad_click_fee`。

死亡测试：`tests/Feature/NezhaAdAuctionTest`（PHPUnit 9/9）+ 真并发脚本（30/50 路零超扣·无死锁·对账一致·零残留）。
⬜ 需业主亲测（开关开后）：真机广告位渲染、真人点击计费、真实刷量压力、live 顾客端竞价排序效果。


## 10. 第二期落地进度

- **Slice A · 超管侧（2026-07-02 · 提交见 CHANGELOG）**：✅ 竞价参数页（`admin/advertisement/auction-settings`，9 键 + 守卫 floor>0/封顶≥floor/日预算≥floor + 总开关翻转写 AdminAuditLog）+ 广告余额充值页（`admin/advertisement/ad-recharge`，复用 `AdBalanceLogic::credit` 单一真相源，只充值不扣减 + 二次确认 + 审计）+ 侧栏两入口。开关仍默认关、纯超管 UI 零 live 影响。验证：进程内真渲染两页 22/22（字段齐 + 隔离证伪 deposit 纹丝不动 + 事务 rollback 零残留）+ route:list 四路由注册。§3/§4#9 的 CLI 充值逻辑已抽到 `App\CentralLogics\AdBalanceLogic::credit`，CLI 与后台按钮共用。
- **Slice B · 商家三旋钮看板**：未做（日预算 / 想多靠前低中高 / 已花费+点击数）。碰 vendor 侧 `routes/vendor.php` + vendor 侧栏，需与"优惠券重做"窗口协调。
- **Slice C · 顾客端广告位**（首页位 / 列表竞价位 / 去首页横滑卡「推广」角标 / 点击·曝光事件接线）：未做；因碰真金"每次有效点击=一笔扣费"，开工前先走 `/debate` 三路对抗核验（业主 2026-07-02 拍板）。重点核验：点击事件不重复触发 / 不在渲染滚动时误发 / 尊重后端 dedup / 全不打标。
