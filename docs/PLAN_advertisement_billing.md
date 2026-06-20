# 哪吒商家广告/推广位 计费系统 — 实施方案（已锁定 v2）

> 状态：方案已锁定，待开工。资金定级 **L2**（保证金新扣款类型 `advertisement_fee`，平台收自己的广告服务费、不碰顾客钱、非二清）。
> 计费总开关 `nezha_ad_billing_status` 默认 **0（关）**，关时完全走现有免费审核流程、零行为变化，可灰度。

## 0. 背景与现状基线
- 商家提交广告：已通 — `Vendor/AdvertisementController.php@store`，建记录 `status=pending`、`is_paid=0`、发邮件超管。
- 超管审核：已通 — `Admin/AdvertisementController.php@status`（通过/拒绝/暂停）。
- 对外取广告：已通 — `Api/V1/AdvertisementController.php@get_adds`，`/api/v1/advertisement/list` 按 zone 返回有效广告。
- 前端展示：缺 — `useGetAdds.js` 写死 `enabled:false` 且无人 import。
- 收费：缺 — `is_paid` 永远 0，广告表无价格字段，未接保证金扣款。

## 1. 锁定决策
| 项 | 锁定值 |
|---|---|
| 展示方式 | 现有 banner / 推广位 / 首页横滑卡 **全不动**；投广 = 给商家在**综合排序加曝光权重**；**+** 复用现成 get_adds API 做一个专门广告位（核心权重做完后做） |
| 计费模型 | 按天单价 × 投放天数 |
| 单价默认值 | **1000 ֏/天**（≈$2.5/天；纯 L2 后台参数随时改） |
| 扣费时机 | 审核**通过不扣、商家可免费取消**；到「有效期起始日」**正式投放时扣全额**，扣后**不可取消、不可退** |
| 不可退披露 | 商家提交前必须在提交页确认框 + MERCHANT_GUIDE 明示「投放开始后扣费、不可取消不可退」 |
| 平台主动下架 | 商家自停不退；**平台/超管强制下架**已扣费广告按未投放天数**按比例退**回保证金 |
| 起始日余额不足 | 直接不投放 + 邮件提醒充值（不做宽限重试） |
| 资金定级 | L2，复用保证金引擎，新增流水类型 `advertisement_fee` |

## 2. 投放生命周期
```
商家提交(填起始日) → 超管审核
   ├ 拒绝 → 不扣，结束
   └ 通过 → status=approved【未扣费·商家可免费取消】
              │ （此阶段可取消）
              ▼
      到达起始日 → 定时任务扣全额(单价×天数)
              ├ 余额不足 → 不投放 + 提醒充值
              └ 扣成功 → is_paid=1【锁死·不可取消不可退】→ 综合排序加权重 → 到结束日自动失效
```

## 3. 任务单
### T1 曝光权重注入（后端，核心）
- `RestaurantLogic.php`（约 146 行综合排序 orderByRaw `0.45距离+0.30评分+0.25销量`）加付费加权项：餐馆存在「已扣费 + 在投放期内」广告时权重 +`nezha_ad_boost_weight`（后台 L2，设上限防排序失真，L3 可调）。
- 只影响排序、不伪造数据、不改卡片内容。

### T1b 专门广告位（前端，低成本，核心后做）
- 复用 `/api/v1/advertisement/list` + `useGetAdds`（去 `enabled:false`），做广告位横滑卡组件。无过审广告整块不渲染（不出假位）。

### T2 计费字段 + 后台配置（后端）
- 迁移 `advertisements` 加 `price` / `paid_at` / `deposit_transaction_id`。
- `BusinessSetting`：`nezha_ad_billing_status`(默认0)、`nezha_ad_price_per_day`(默认1000)、`nezha_ad_boost_weight`、`nezha_ad_refund_on_platform_takedown`(默认1)。
- 商家提交页：显示「预估费用 = 单价×天数 + 当前保证金余额」+ 显著「投放开始扣费、不可取消不可退」确认框。

### T3 定时扣费任务（后端，替代审核时扣）
- 新建 `nezha:charge-ad-on-start` 每日跑：当天到起始日 + 已通过 + 未扣费的广告 → `lockForUpdate` 扣 `deposit_balance` + 写流水 `type=advertisement_fee` + `is_paid=1` + 激活权重。照抄 `OrderLogic.php:230-246` 并发样板（事务 + lockForUpdate）。
- 🔴 调度写进 `bootstrap/app.php->withSchedule`（Laravel12 后 Kernel::schedule 失效）。
- 起始日余额不足 → 不投放 + `DepositLowBalanceMail`。
- 取消：`approved && !is_paid` 商家可取消；`is_paid=1` 后取消入口关闭。
- 平台主动下架已扣费广告：按未投放天数比例退回保证金（写 `advertisement_fee` 冲正流水）。

### T4 文档
- `INVARIANTS.md` 保证金扣款类型补 `advertisement_fee`（标 L2）。
- `ADMIN_GUIDE.md`：超管设单价/加权系数/审核/看扣费/强制下架退费。
- `MERCHANT_GUIDE.md`：商家投广流程/按天计费/从保证金扣/不可取消不可退政策/余额不足充值。
- `docs/compliance/CHANGELOG.md`：记「新增平台广告服务费（保证金扣款，非二清，L2）」。

## 4. V1 不做（划边界防膨胀）
- 按点击/曝光计费（CPC/CPM）。
- 商家中途暂停按比例退费（仅平台主动下架退）。
- 保证金在线充值通道（沿用现有充值方式）。

## 5. 验证要求
- 前端 T1b：Playwright 真机三态（无广告/有广告/点击跳转）+ console 0 error。
- 后端扣费：构造「通过未扣→到起始日→扣费」全链路真跑，验保证金流水 + is_paid + 余额；并发扣费仿真；余额不足分支。
- 综合排序加权：真 API 对比加权前后顺序变化。

## 6. 合规/税务提醒（非红线，落地前同步）
- 广告费 = 平台经营收入，按亚美尼亚 10% 流转税口径报税开票（与记账/律师同步）。见本机法律税务结构规划文档。
