# 哪吒 QA 总索引（所有 QA 层 / 触发词 / 状态）

> **怎么用**：用户说某个"触发词"，任何窗口先读本表 → 找到对应层 → 若 playbook 已建就照它跑；**没建就先 build 再跑**（像 OPS_QA 那样 just-in-time，不一次性全囤）。
> **读法**：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/QA_MASTER.md"`
> **上线前总验收**走 `PRELAUNCH_QA_MASTER.md`(18维度 go/no-go 硬门)；本表是日常各层索引。
> 由来：2026-06-16 从"登录慢"挖到"产品 QA 照不到服务器层"，顺势盘了一遍**所有该有的 QA 层**，固化成本表。

## 一、QA 层总表

| # | QA 层 | 固定触发词 | 查什么（一句话） | playbook | 优先级 |
|---|---|---|---|---|---|
| 1 | 产品 / 前端 QA | **产品QA** / 全面QA | 渲染·按钮有反应·翻译·布局·分页·空/假/真三态 | ✅ `QA_PLAYBOOK.md`(14轴) | 持续 |
| 2 | 运维 + 服务器 QA | **运维+服务器QA** | 负载·跑飞进程·cron在跑·备份成功·日志报错·证书·服务存活·延迟 | ✅ `OPS_QA_PLAYBOOK.md`(10轴) | 持续 |
| 3 | 应用层安全 QA | **应用层安全QA** | **越权(IDOR)**·注入(SQL/XSS)·文件上传·认证授权·密钥泄露 | ✅ `SECURITY_QA_PLAYBOOK.md`(11轴) | 🔴 最高 |
| 4 | 资金 + 合规实跑 QA | **资金合规QA** | 佣金/保证金对账·退款只原路·订单状态机·并发双花·**PII真删·制裁拒·二清拔**真生效 | ✅ `FUNDS_COMPLIANCE_QA_PLAYBOOK.md`(8轴) | 🔴 高 |
| 5 | 端到端真实资金闭环 QA | **资金闭环QA** | 下单→真付商家(RMB/USDT)→确认→扣佣 全链用真金额走通 | ✅ `QA_FUNDLOOP_PLAYBOOK.md` | 🟡 中 |
| 6 | 性能 / 负载 QA | **负载QA** | 真实并发容量·压测·上线天花板在哪(单2核机)·**N+1/查询数随规模(G轴)** | ✅ `PERF_LOAD_QA_PLAYBOOK.md`(+G轴N+1扫描·QueryCountGuard护栏) | 🟡 中 |
| 7 | 容灾 / 备份恢复 QA | **容灾QA** | **备份真能恢复**(没演练≈没备份)·单点·宕机RTO | ⬜ 待建 | 🟡 中 |
| 8 | 兼容性 / 多端真机 QA | **兼容QA** | 真机矩阵·Android·老机型·PWA·弱网/离线 | ⬜ 待建（零散真机已修, 无矩阵化QA） | 🟡 中 |
| 9 | 本地化 / i18n QA | **本地化QA** | 多语言(亚/俄/英?)·币种֏+¥/$·日期数字格式·用户语言决策 | ✅ `I18N_QA_PLAYBOOK.md`(8轴) | 🟢 低 |
| 10 | 业务流程交叉验证 QA | **全平台业务交叉验证** / 交叉验证QA | 顾客↔商家↔平台 三方握手·四类路径(正/逆/异常/合规)·两登录态·**跨界面数字一致性(轴I)·裸DB计数代码味道** | ✅ `CROSSCHECK_QA_PLAYBOOK.md` | 🔴 核心 |

> 触发词认变体（如"安全QA""资金QA"），但建议固定上面这几个，对齐最稳。

## 一·B、尚未完成的 QA（真没做，单独列）

> 这里只列**确认还没建 playbook** 的 QA 层。注意区分"QA 层"和"功能"：第 8/9 层的功能已落地，缺的是**系统化扫一遍**的 QA。
> 维护：某层建好 playbook 后，从这里删掉 + 上表对应行改 ✅。

| 层 | 缺口（确认未做） | 现状证据 | 优先级 |
|---|---|---|---|
| 7 容灾 / 备份恢复 | **从没演练过"从备份恢复"**（没演练≈没备份） | 备份在生成(cron 每天 04:17 加密备份 + /www/backup 有文件)，但恢复演练 = 0 | 🟡 中（建议提到最高：唯一"出事不可逆"的盲区） |
| 8 兼容 / 多端真机 | 无 Android / 老机型 / 弱网 矩阵化 QA | 零散真机已修(iOS PWA 安全区 / touch 模糊等)，无系统矩阵 | 🟡 中 |

一次性运维加固里**真没做**的（详见第三节）：root/admin 密码轮换（站外 uptime 监控 ✅ 2026-06-17 已完成，见第三节）。

## 二、需要真人、AI 只能打第一遍底稿
- **法律 / 政策审校**：用户协议·隐私·退款政策在亚美尼亚 + B 支付模式下是否站得住 → **真律师**。（locallife PII 同意采集已标"律师审校前绝不可开"）
- **专业渗透测试**：真实收钱上线前理想请人做 pentest。AI 可做第一遍 = 第 3 层「应用层安全QA」，**不替代专业**。

## 三、一次性运维加固待办（不是反复跑的 QA，是该做一次的事）
- [x] **站外 uptime 监控**（2026-06-17 完成）：用 **HetrixTools 免费档**（商用可用、1 分钟间隔、4 个欧洲拨测点 ams/fra/lon/waw、邮件告警）从站外拨测 4 个公开入口——①前端首页 `https://nezha.am/home`（关键词 `__NEXT_DATA__`）②商家登录页 `https://api.nezha.am/login/restaurant`（HTTP 200）③后端 API `https://api.nezha.am/api/v1/config`（关键词 `business_name`）④餐厅列表非空 `https://api.nezha.am/api/v1/restaurants/get-restaurants/all?offset=1&limit=10`（关键词 `"restaurants":[{`，防"能开但列表空"假活）。告警发 noctis201914@gmail.com，已用"Up→Down 跌落"探针实测邮件送达。补的正是 nzwatch 自身盲区（服务器/网络全挂时它发不出告警）。
- [ ] **root / admin 密码轮换**：memory `[[dev-script-exhaust-blindspot]]` 记着"视为已泄露待轮换"，至今未轮换。
- [x] **PII 清除任务**（06-17 确证已挂回）：`bootstrap/app.php` 已挂 3 个 purge 任务(03:30/40/50) + cron `* * * * * artisan schedule:run` 在跑；06-16 dry-run 均 0 命中后经用户批准接回（memory `[[nezha-laravel12-scheduler-unwired]]`）。

## 四、状态维护约定
- 每建好一层的 playbook，把本表该行 ⬜ 改 ✅ + 填文件名，一起 commit。
- 每做完一个第三节待办，勾掉。
- 这张表是 QA 的**单一真相源**；新增 QA 层先在这登记再建 playbook。


## 五、运营期例行 QA 节奏（上线后生效 · 触发词「日常巡检 / 运维QA / 运营期排错」）

> 定位：上线后"什么时候跑哪本"的日历 + "出了症状先查什么"的路由。各轴正文都在既有 playbook，本节不重复只指路。报告一律走证据账本（每 ✅ 带证据 + 「未测：…」行）。

### T0 每日晨检（≤10 分钟，可整段交给任意窗口）
1. `bash /www/wwwroot/api.nezha.am/nzhealth.sh` —— 负载/跑飞进程/内存/磁盘/fpm/源站延迟/COD 门。
2. `bash /www/wwwroot/api.nezha.am/nzdaily.sh` —— 昨日请求量+5xx/499、queue:failed、备份+R2 末行、laravel 新增 ERROR、pm2 重启增量、磁盘。
3. HetrixTools 告警邮箱扫一眼（站外拨测 4 探针：/home、商家登录页、/api/v1/config、餐厅列表非空；补 nzwatch 自身盲区）。
4. 后台业务队列清零检查（人工点开）：需动作订单（超时未接/未出餐）· 待退款+逾期（风控中心）· 争议单（开关开后）· 充值审核 admin/nezha-topup（开后）· 举报商家 · 差评预警 · 商家反馈 · KYC 待审。
5. 全绿 → 一行报告；任何 🔴 → 走 T3 路由，先取证再动手。

### T1 每周深检（30–60 分钟，固定周几由业主定）
- OPS_QA_PLAYBOOK 10 轴全跑（A-J：含备份产物体积、cron 真跑、证书、看门狗自身）。
- 5xx 周环比（nginx 按日计数）+ PERF C 轴延迟基线对照（涨了回 PERF D/F 轴找原因）。
- `grep 'N+1-guard' laravel.log` 收集本周高查询数端点，攒够立修复项。
- PRELAUNCH_SWITCHES「表值 vs 生产现值」核对（防开关漂移，表内自带方法）。
- nginx 手改配置存活核对：api 微缓存 include（nezha_apicache.conf）+ CSP 两处 location —— aaPanel 面板重生成会冲掉（历史吃过亏）。
- 安全轻扫：fail2ban 封禁异常飙升 / auth.log 异常登录 / webroot 新冒可疑文件。
- 商家运营指标：出餐超时率、差评预警存量、退款平均时长、搜索无结果 Top（后台「搜索需求」）。

### T2 每月（半天）
- 🔴 容灾恢复演练（QA_MASTER 第 7 层，至今 0 次）：取最新加密备份真恢复进 staging 库，计时=RTO，对照 README-RESTORE 校订步骤。没演练≈没备份。
- SECURITY_QA 11 轴复跑 + FUNDS_COMPLIANCE 8 轴复跑（PII purge 真删、制裁名单真同步、留存表只增不删）。
- 密码/密钥轮换检查（root/admin 轮换欠账见 §三）。
- 成本盘点：Google Maps 配额/账单（预算告警仍未设）、CF、磁盘增长、SMS（若开 customer_verification）。
- composer audit / npm audit 高危扫一眼。

### T3 症状 → 排错路由（先跑左列取证，再动手）
| 症状 | 先查 | 正本 |
|---|---|---|
| 全站打不开/白屏 | nzhealth [1][2] → pm2 ls(nezha-web) → nginx error log 尾部；刚部署过→/tmp/nezha_build_last.log + previous 回滚；警惕 opcache 假 200 | OPS C 轴 · memory[部署vendor竞态] |
| 单页 500 | pm2 err log + laravel.log 同时刻对表；后端页跑 blade 探针 | memory[blade渲染验证法] |
| 变慢 | PERF C 轴基线对照 → OPS B 轴跑飞进程 → 慢查询/swap | PERF_LOAD_QA_PLAYBOOK |
| 顾客下不了单 | nzcheck-cod → maintenance/offline_payment 开关 → nezha_risk_records 误拦 → zone 覆盖/营业时间/售罄 | PRELAUNCH_SWITCHES + 风控中心 |
| 通知没到 | queue llen+failed → 通知链路 6 分发器 → TG relay 开关 → 在场感知抑制是否误伤 | memory[订单态通知链路] |
| 数字对不上 | 同源对账（横幅==徽标==列表==详情） | CROSSCHECK 轴 I |
| 支付/退款纠纷 | 🔴先读 INVARIANTS(L1) → nezha_refund_records/offline_payments 留痕还原时间线 | FUNDS_COMPLIANCE_QA_PLAYBOOK |
| 疑似被刷 | 限速 1200/min 命中 → fail2ban → reCAPTCHA → nezha_risk_records | memory[防刷盘点] |

维护：触发词已进本机 CLAUDE.md 路由表；改巡检项只改本节，别抄进别处（单一 owner）。
