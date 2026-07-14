# 2026-07-14 上线前全平台 QA 结果

结论：**NO-GO**。可由自动化和隔离环境安全覆盖的测试已经完成；商家移动付款凭证审核入口与 `/checkout` 登录态购物车恢复均已在 staging 关闭，但当前 production 尚未包含这些修复，且仍有 API、性能、退款语义、合规、开关、演示数据和真实商家就绪硬阻断。本轮没有部署 production、没有切换 production release，也没有执行演示数据破坏性回退。

## 验收对象与深度

- 生产前端：应用提交 `2f81803`，release `20260714-101004-2f81803`，BUILD `Mguty8CEfSrUIu5FXJ52G`；本轮商家凭证相邻顾客修复为 `6dad4fd`，staging BUILD `HzPSvq6ar3kDsNwz35-_X`，尚未部署 production。
- 生产后端：应用提交/release `e044d34` / `20260714-070255-e044d34`。
- 最新后端应用候选：本轮商家付款凭证审核修复 `e42035c`，基于重新 fetch 的 `origin/main 7aeed6c`；相对 production 本任务无 migration 差异，且未运行 staging API reset/migration/restart。
- 浏览器深度：真实 Chromium 390×844 与 1440×900；生产公共页、生产登录态临时顾客（已由前序 QA 清零）以及本轮生产备份隔离恢复环境。Firefox 因服务器缺 `libgtk-3.so.0` 未启动，WebKit/物理 iPhone Safari 不冒充已测。
- 写入深度：生产备份恢复到一次性数据库后完成真实 API/浏览器写流程、资金账本和并发竞态；没有真实付款、链上广播或生产订单。

## 18 维度结果

| # | 维度 | 结果 | 证据/边界 |
|---|---|---|---|
| 1 | 产品/前端 | staging 通过，production 版本阻断 | 首页、餐厅、购物车、结算、地址、返回、支付抽屉、订单历史、商家/后台主路径已验；`/checkout` 登录态直达/刷新已由 `c6e8b26` 在 staging 关闭，但 production `2f81803` 尚未包含。 |
| 2 | 运维/服务器 | 通过（当前运行态） | nginx/PHP-FPM/MySQL/Redis/PM2/cron/SSL/fail2ban/磁盘/内存检查；Redis `PONG`、failed jobs=0。queue worker 的 73 次为 `--max-time=3600` 历史累计，每小时正常 SIGINT 重启，不是崩溃循环。 |
| 3 | 应用层安全 | 候选自动化通过，生产版本阻断 | 租户/管理员边界、IDOR、商家表面扫描、跨商户发票 404 通过；生产仍停在 `e044d34`，未包含 `87d677b`、`78aaeb7`、`4fa715c` 等应用修复。非专业渗透测试。 |
| 4 | 资金合规 | 隔离机制通过，生产就绪阻断 | COD 数据库与 API 自检为关闭；直付退款原路锁定、禁止第三方地址、账本回滚通过。管理员将直付单设为 refunded 时会在商家实际退款前给顾客发送“退款已处理”，语义错误，为阻断。 |
| 5 | 资金闭环 | 隔离全链通过 | 订单 `2000000114` 从下单、商家确认、制作、配送、送达、管理员退款到商家标记退款完整跑通；优惠后应付 7,200，佣金/平台券/保证金扣回及退款反转后保证金精确回到 1,000,000。没有真实资金对账。 |
| 6 | 性能/负载/N+1 | P2 通过，首页负载阻断 | 生产 `/home` pageProps 35,814 B、gzip 21,511 B、回环 TTFB 中位 60.54 ms；`/checkout` 20,972 B、gzip 11,653 B、中位 22.86 ms。5 并发/5 秒本机压测：home 66/66 为 200，但 p95 2,768.63 ms；checkout 549/549 为 200、p95 59.91 ms。餐厅接口持续 145–146 SQL/请求，超过 120 告警阈值。 |
| 7 | 容灾/备份恢复 | 隔离自动恢复通过 | 最新加密文件备份 70,153,072 B、数据库备份 1,190,112 B；隔离恢复得到 192 表、orders=44/users=8/settings=254，`mysqlcheck` bad=0，临时数据库已销毁。未做真人整机/跨机故障切换。 |
| 8 | 兼容/多端 | 部分通过 | Chromium 390×844/1440×900 无横向溢出；Firefox/WebKit、物理 iPhone Safari、微信/TG WebView 未测。Chromium 返回为 `navigationType=back_forward`，但 `pageshow.persisted=false`，不能证明 iOS bfcache 命中。 |
| 9 | i18n/币种 | 当前单语言通过 | 顾客端语言列表实际只有简体中文；֏ 主币种及 ¥/$ 参考换算在顾客/商家/后台显示。不能把浏览器 English locale 仍显示中文称为英文 UI 验收。 |
| 10 | 三方交叉流程 | staging 完整通过，production 版本待升级 | 顾客↔商家↔平台正向、退款逆向、凭证金额不一致负向、无重复转账提示均跑通；`e42035c` 已将截图/渠道/金额/后果与确认、拒绝统一到可见现代详情 owner，390×844、320×720、1365×900 完成拒绝→重传→确认，单次请求/写入/通知成立。production 尚未包含。 |
| 11 | 演示数据清除 | 阻断 | 只运行无写入预览；vendors=7、restaurants=7、local_life_merchants=6、local_life_posts=21 仍带 demo，且 `/config` 仍返回 `Demo Banner`。没有执行 `nzdemo-rollback.sh GO`。 |
| 12 | 真实商家就绪 | 阻断 | 店 12 在营业时间内仍 `active=0`、钱包保证金/余额为 0；38/38 菜品虽上架，但真实首页商家均不可下单。收款方式列表存在支付宝和 USDT TRC20/BEP20，不等于商家已完成上线签收。 |
| 13 | 开关上线态 | 阻断 | 56 个开关中 `nezha_preorder_status=1`、`nezha_notif_async_status=1`、`nezha_merchant_video_status=1` 与当前上线正本/签收状态不闭环；禁止静默把“当前值”改写成“已批准值”。 |
| 14 | 通知送达 | 站内留痕通过，真渠道未测 | 隔离订单的顾客/商家通知入库与状态关联通过；外部 FCM/邮件/Telegram 在隔离环境关闭，物理设备收达与生产真实推送未验。 |
| 15 | 手册/客服/退款流程 | 文档可执行，产品语义阻断 | B 方案“平台不碰钱、商家原路退”文档和退款留痕可执行；但前述提前“退款已处理”通知与机制冲突。 |
| 16 | 法律/政策 | 阻断 | 隐私政策声称数据库整体静态加密；实测 33 个 InnoDB 表无 `ENCRYPTION='Y'`，系统盘为普通 ext4、未见 LUKS/全盘加密，事实与政策不一致。仍需亚美尼亚律师审校。 |
| 17 | 专业渗透 | 未完成 | 已有仓库级安全回归不能替代独立专业渗透。 |
| 18 | 实体/税务/跨境合规 | 未完成 | 需律师/会计/合规负责人确认，AI 无法签收。 |

## 自动化与浏览器证据

- 后端主 QA 基线（含已推送但未合入 main 的测试夹具修复 `0a87741`）：Unit `13 passed / 29 assertions`；Feature `177 passed / 914 assertions`。随后 `origin/main` 又增加候选修复；最新金额完整性与商家订单 UI 目标测试 `19 passed / 143 assertions`。
- 精确 `origin/main e3ea7fb` 的完整套件并非全绿：Unit `13/29` 通过；Feature 共 180 项、883 assertions，出现 9 errors + 1 failure。9 项均为广告测试夹具缺少 `restaurants.nezha_commission_enabled`，1 项为 Example 临时环境缺 `DOMAIN_POINTED_DIRECTORY`；这正是 `0a87741` 修复但尚未合入 main 的测试基础设施缺口。不得把修复分支的全绿冒充当前 main 全绿。
- 最新前端 `67ec497` 的两个 Node 回归测试通过，隔离 production build 通过（BUILD `LZ2iR9fEWbrIxLrHG4yGE`）；但仓库指定 Prettier 2.5.1 对候选 6 个改动文件全部报格式不合格，且该提交属于尚未闭环的 staging 订单支付任务。它只补充优惠券上下文等待，没有引入 `/checkout` 直达时的购物车初始化请求，已证实的空购物车阻断仍未关闭。
- 商家移动付款凭证审核 `e42035c`：聚焦契约 1 test/11 assertions 通过；整组 `NezhaMerchantOrderUiContractTest` 为 16 tests/125 assertions，其中 14 通过，2 个既存 staging 树/夹具契约（移动订单列表 CSS、跨店发票 fixture）失败，不冒充整组全绿。Blade 编译、compiled PHP lint 和 diff check 通过。
- 顾客拒绝态重传 `6dad4fd`：页面数据契约与 production build 通过，最终 staging BUILD `HzPSvq6ar3kDsNwz35-_X`。Prettier 对三个触及的旧文件仍报红，且同三文件的 `HEAD` 基线通过 stdin 复核也为红；本任务没有以全文件格式化扩大 diff，该格式门仍须在发布候选收口。
- 订单并发：三组各 20 单。cancel vs confirm 全部 canceled 且最多一条退款；confirm 后 cancel 全部 processing、cancel=403；double cancel 全部 canceled 且最多一条退款；违规数均 0。
- 维护模式：按后台实际数据形态设置 `react_website` 后，`/home` 与 `/checkout` 均 307 到 `/maintenance`，保留 `private, no-cache, max-age=0, must-revalidate`；测试后在隔离库恢复关闭。
- 顾客浏览器：餐厅图片/菜系兜底/列表返回、空车、登录门、登录态有车、服务器权威价、优惠、默认地址、Google 地址入口、支付抽屉、订单历史、凭证驳回和重新上传均覆盖；无 `/restaurants/null`、无第一方失败请求、无横向溢出。隔离域名 Google Maps 因 referer 白名单拒绝，生产登录态真实 Google 搜索/详情/保存证据沿用前序已清零 QA。
- 商家/后台浏览器：仪表盘、订单列表/详情、履约、退款、发票租户边界覆盖。生产 2FA 没有关闭，隔离会话仅用于一次性数据库。
- 浏览器截图归档：本地 `artifacts/nezha-prelaunch-browser-evidence-20260714.tgz`（SHA-256 `532a1725d659a8d02a6c23a744777bd2f63b040ef1ffbc852e884fd2882735a3`）和 `artifacts/nezha-prelaunch-browser-evidence-current-20260714.tgz`（SHA-256 `c6cf5cb5abddc056543f431c58345996c08c1e0dd5ee84fbfa1b86109eb29dc4`）。

## 当前必须先修的工程阻断

1. 修复直付退款在商家真实退款前向顾客发送“退款已处理”的通知；应区分“退款待商家执行”和“商家已标记退款”。该项会改变通知语义/时机，需独立精确授权。
2. 收敛 production 与已验 staging 候选的版本差：production Web 仍缺 `/checkout` hydration 与顾客重传修复，production API 仍缺商家凭证审核及其后的应用修复；只能在其余发布门通过并取得 production Go 后用固定 SHA 部署、再做只读与浏览器验收。
3. 解决餐厅接口 N+1 与 home 轻量负载 p95 红灯。
4. 让隐私政策的静态加密陈述与真实存储保护一致；这是安全/合规改造，不得边测边批量 ALTER 33 表。
5. 对三项开关漂移逐项取得 owner 签收，并清理演示数据、确认真实商家营业/保证金/收款资料。
6. 合并/复核 API 测试夹具修复，关闭 exact-main 全量测试红灯，并收敛前端候选 Prettier 红灯；不得把聚焦测试通过冒充最新 main 发布候选全绿。

## 清场与回滚

- 一次性数据库 `nezha_qa_e2e_20260714093149` 已删除并复核不存在；19090–19095 隔离监听均已停止。
- `/tmp/nezha-prelaunch-*`、本轮 QA 脚本、生产 release 中三个 `_prelaunch_*` 探针均已精确删除；生产运行文件、PM2 进程、release/cache 未清理或重启。
- 商家付款凭证 staging QA 已精确删除 1 用户、1 订单、1 order detail、1 offline payment、2 任务日志、2 通知和全部明确用户依赖及 1 个当前 proof 文件；marker 与当前/被替换凭证路径均为 0，商家、设备 token、支付方式、业务/全局/餐厅通知设置与封存原值逐字段一致。
- 当前前端回滚点：`20260714-074400-b66c0d1`；当前后端回滚点：`20260714-063310-75c6e4c`。因本轮未切生产，未产生新的部署回滚点。
