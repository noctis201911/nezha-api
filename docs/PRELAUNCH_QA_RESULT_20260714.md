# 2026-07-14 上线前全平台 QA 结果

结论：**NO-GO**。可由自动化和隔离环境安全覆盖的测试已经完成；商家移动付款凭证审核入口、`/checkout` 登录态购物车恢复、直付退款阶段语义、首页 N+1/轻负载与 exact-main 测试/格式门均已关闭，但当前 production 尚未包含这些修复，且合规、开关、演示数据、真实商家就绪和外部人工签收仍是硬阻断。本轮没有部署 production、没有切换 production release，也没有执行演示数据破坏性回退。

## 验收对象与深度

- 生产前端：应用提交 `2f81803`，release `20260714-101004-2f81803`，BUILD `Mguty8CEfSrUIu5FXJ52G`；共享 staging 仍为直付退款阶段 BUILD `n4VGKngOQXDelVRDdK9yN`。首页性能应用 `6be4453` 已在 fixed-SHA 平行 staging 完成 build/Chromium/load 验收；随后 exact-main 格式/浏览器门以应用提交 `a9e5007` 收口，最终隔离 production BUILD 为 `n-lmCUI6GWVnOkqc1cDMo`。两者均未部署 shared staging 或 production。
- 生产后端：应用提交/release `e044d34` / `20260714-070255-e044d34`。
- 最新后端 main 候选为 `ab346c42`，包含首页餐厅批量取数修复 `6cf7cf9`、此前直付退款阶段 `fa13808`、隔离测试夹具/SQLite 兼容修复 `69502957` 与退款阶段契约对齐；相对 production `e044d34` 的 migration 文件 diff 为 0，且未运行 API reset/migration/restart。
- 浏览器深度：真实 Chromium 390×844 与 1440×900；生产公共页、生产登录态临时顾客（已由前序 QA 清零）以及本轮生产备份隔离恢复环境。Firefox 因服务器缺 `libgtk-3.so.0` 未启动，WebKit/物理 iPhone Safari 不冒充已测。
- 写入深度：生产备份恢复到一次性数据库后完成真实 API/浏览器写流程、资金账本和并发竞态；没有真实付款、链上广播或生产订单。

## 18 维度结果

| # | 维度 | 结果 | 证据/边界 |
|---|---|---|---|
| 1 | 产品/前端 | staging 通过，production 版本阻断 | 首页、餐厅、购物车、结算、地址、返回、支付抽屉、订单历史、商家/后台主路径已验；`/checkout` 登录态直达/刷新已由 `c6e8b26` 在 staging 关闭，但 production `2f81803` 尚未包含。 |
| 2 | 运维/服务器 | 通过（当前运行态） | nginx/PHP-FPM/MySQL/Redis/PM2/cron/SSL/fail2ban/磁盘/内存检查；Redis `PONG`、failed jobs=0。2026-07-14 最终复核时两个 queue worker 均 online，80 次重启为 `--max-time=3600` 历史累计，每小时正常 SIGINT 重启，不是崩溃循环；本轮未见 kernel OOM。 |
| 3 | 应用层安全 | 候选自动化通过，生产版本阻断 | 租户/管理员边界、IDOR、商家表面扫描、跨商户发票 404 通过；生产仍停在 `e044d34`，未包含 `87d677b`、`78aaeb7`、`4fa715c` 等应用修复。非专业渗透测试。 |
| 4 | 资金合规 | staging 语义通过，production 版本阻断 | COD 数据库与 API 自检为关闭；直付退款原路锁定、禁止第三方地址、账本回滚通过。`fa13808` 以 `nezha_refund_records.status` 为阶段 owner：管理员批准只进入“待商家退款”，商家原子认领成功后才进入“商家已标记退款”，平台人工核实使用“平台已核实退款”。production 尚未包含。 |
| 5 | 资金闭环 | 隔离全链通过 | 订单 `2000000114` 从下单、商家确认、制作、配送、送达、管理员退款到商家标记退款完整跑通；优惠后应付 7,200，佣金/平台券/保证金扣回及退款反转后保证金精确回到 1,000,000。没有真实资金对账。 |
| 6 | 性能/负载/N+1 | fixed-SHA 平行 staging 通过，production 版本阻断 | 生产只读数据旧实现 N=1/2/7 为 49/65/145 SQL，API `6cf7cf9` 为 51/51/51，N=7 完整响应 98,422 B 且 SHA-256 `231e5a7e451405604f0998592636b8ee097efab7f5a019d7500fa54c014a1b78` 与旧实现逐字节一致。staging-config/data 平行环境中，精确旧 baseline `0626875` 为 49/66/66，候选为 48/48/48，N=1/2/7 完整响应逐字节一致；优惠券、订阅餐厅、自配送与车辆距离边界矩阵 6 tests/35 assertions 通过。Web `6be4453` 的 Chromium 390×844/1365×900 首页、首卡跳转、刷新、console/请求/图片/溢出通过；同口径 5 并发/5 秒为 215/215 HTTP 200、42.21 req/s、p50 95.71 ms、p95 210.14 ms、p99 238.77 ms，相比此前 p95 2768.63 ms 红灯下降约 92.4%。候选未部署 shared staging 或 production。 |
| 7 | 容灾/备份恢复 | 结构通过、字符保真失败 | 17:26 加密数据库备份可恢复 192 表、orders=44，`mysqlcheck` bad=0；但行级指纹发现 21 行 `local_life_posts.cover_emoji` 在备份中均为 `?`（hex `3F`），production 为 4-byte emoji，且备份脚本 `mysqldump` 未显式固定 `utf8mb4`。因此此前“恢复通过”只覆盖结构/计数，不能证明字符保真或作为 demo 清理唯一回滚点。需修备份参数、生成新加密备份并在全新隔离库做 `utf8mb4`/行指纹复核；真人整机/跨机故障切换仍未做。 |
| 8 | 兼容/多端 | 部分通过 | Chromium 390×844/1440×900 无横向溢出；本地容器路由契约已按 D1–D6 固定，但 Firefox/WebKit、物理 iPhone Safari、Android Chrome、微信/TG WebView 和商家 Android App 均未完成真机矩阵。Chromium 返回为 `navigationType=back_forward`，但 `pageshow.persisted=false`，不能证明 iOS bfcache 命中。 |
| 9 | i18n/币种 | 当前单语言通过 | 顾客端语言列表实际只有简体中文；֏ 主币种及 ¥/$ 参考换算在顾客/商家/后台显示。不能把浏览器 English locale 仍显示中文称为英文 UI 验收。 |
| 10 | 三方交叉流程 | staging 完整通过，production 版本待升级 | 顾客↔商家↔平台正向、退款逆向、凭证金额不一致负向、无重复转账提示均跑通；`e42035c` 已将截图/渠道/金额/后果与确认、拒绝统一到可见现代详情 owner，390×844、320×720、1365×900 完成拒绝→重传→确认，单次请求/写入/通知成立。production 尚未包含。 |
| 11 | 演示数据清除 | 阻断（工具已 fail closed） | marker 精确命中 vendors=7、restaurants=7、local_life_merchants=6、local_life_posts=21，总表行数分别为 9/9/10/21，二者不可混用；`/config` 仍返回 `Demo Banner`。版本化工具的精确 scope 覆盖 31 订单、22 评价、0 add-on 和 6 个商家当前名称；补齐备份已损失的 21 个 emoji 后，production 只读 PLAN `c13203f4…` 与隔离库 PLAN `55bdf110…` 的 20/20 类目标行指纹一致。schema 全列审计的 48 个非零引用入口收敛为 26 类 manifest 外 blocker，新增关键项包括 seed category 关联但 manifest 外 food 58、coupon claim 4、message 2、order timeout event 38、review report 1、offline payment 23；两端 blocker/计数一致，rehearsal 在事务前 exit 4 拒绝。旧 production 本地 untracked 8 子脚本禁止直接执行；先由数据 owner 逐类裁决并补可逆证据。 |
| 12 | 真实商家就绪 | 阻断 | 店 12 `active=0`、营业时间记录=0，38/38 菜品上架；支付宝码和 TRC20 地址存在，BEP20 地址不存在。当前 `nezha_deposit_mode_status=0`、最低保证金阈值=0、店 12 佣金关闭，因此保证金/余额为 0 **不是当前免佣免押阶段的独立阻断**。硬门是营业时间、实际经营者、收款资料归属、通知接收和最终激活均未签收。 |
| 13 | 开关上线态 | 阻断 | 56 个开关中 `nezha_preorder_status=1`、`nezha_notif_async_status=1`、`nezha_merchant_video_status=1` 与签收状态不闭环；`schedule_order=true`，故预订单是“依赖已开但 owner 未签”，不是隐藏依赖仍关。production 当前 release + 共享 storage 只读核对商户 12 视频 stored=2、normalized=2，技术可见性成立，剩余是内容 owner 对两条封面/标题/外跳的批准。`nezha_autooffline_status=1` 符合 A 类目标，仅原台账现值过期。禁止静默把当前值改写成批准值。 |
| 14 | 通知送达 | 本地 R2 语义/编译与隔离 migration 通过，真实设备/渠道阻断 | 既有退款通知幂等保持；新增顾客多安装实例、token 轮换/重新绑定/当前实例退出、D1 标准 Web Push、D2 Android Chrome FCM、商家 Android App FCM 与 D3–D6 核心 H5 路由契约已自动化验证。2 个 API migration 在一次性 MySQL 8.4 隔离库完成 `up→down→up` 与加密/解密/索引/外键验证并精确清理，但 shared staging/production 未应用；真实 Firebase 配置、正式 release signing/Play 审核与物理 D1–D6/商家 App、FCM/邮件/Telegram 真实回执均缺失，不能称真实送达通过。 |
| 15 | 手册/客服/退款流程 | staging 通过，production 版本阻断 | B 方案“平台不碰钱、商家原路退”与产品语义现已一致；顾客、商家、管理员列表/详情均区分待商家退款、争议核实中和已标记退款。production 尚未包含。 |
| 16 | 法律/政策 | 阻断 | 隐私政策声称数据库整体静态加密；2026-07-14 复核共 192 个 InnoDB 表，其中 33 个无 `ENCRYPTION='Y'`，系统盘为普通 ext4、未见 LUKS/全盘加密，事实与政策不一致。仍需亚美尼亚律师审校。 |
| 17 | 专业渗透 | 未完成 | 已有仓库级安全回归不能替代独立专业渗透。 |
| 18 | 实体/税务/跨境合规 | 未完成 | 需律师/会计/合规负责人确认，AI 无法签收。 |

## 2026-07-15 通知 R2 只读/本地验收增补

### 第一版保证矩阵

| 目标 | 保证 | 边界 |
|---|---|---|
| D1 iPhone Safari | 核心 H5；符合平台条件并安装到主屏幕后验标准 Web Push | 不承诺 FCM；需系统支持、用户授权和有效订阅 |
| D2 Android Chrome | 核心 H5 + FCM | 需 Play 服务/网络、浏览器权限和有效 token |
| D3/D4 微信 WebView | 核心 H5 | 不承诺 FCM；离场通知依赖已验邮件/Telegram 角色渠道 |
| D5/D6 Telegram WebView | 核心 H5 | 不承诺 FCM；Telegram 机器人消息是独立服务端渠道 |
| 商家 Android App | 商家核心 H5 + FCM；提供通知/频道/全屏提醒/勿扰/电池/厂商自启动状态与设置入口 | 不绕过用户控制；最终表现受 Android、Play 服务、ROM、权限和频道设置约束 |
| 邮件 / Telegram | 按角色×事件保证服务端投递 | 以真实收件箱/机器人回执关门，不按容器重复实现 |

顾客采用多安装实例模型：同一账号可以有多个设备或浏览器订阅；token 轮换/重新绑定保持实例身份，退出只注销当前实例，不能连带关闭其他设备。

### 本地证据与实际关闭范围

- API：本地集成 `763786c`，其中生命周期修复 `d91100d`，锚定当时最新 `origin/main=45a8e8c6b5aeb84bcd7ed93babc7db825b1ac7de`；通知聚焦 `40 tests / 154 assertions`、浏览器桥接脚本 `4/4`、5 个触及 PHP 文件 lint、JS syntax 与 `git diff --check` 均通过。测试在强制 SQLite `:memory:` 下运行，不连接真实 DB 或通知渠道。
- Web：`bb550ec`（含 `52b8ad5`、离线 build `12e3546`、凭据闸门 `d65bd1c`、容器路由 `17c64e3`、安装实例 `5e7c462`）；安装实例/容器/公共凭据来源/通知发布配置矩阵 `17/17` 通过。普通 `npm run build` 在公共登录凭据缺失时 exit 1；即使公共登录凭据模式已开启，只要 `NEXT_PUBLIC_FIREBASE_*`、Firebase VAPID 或标准 Web Push VAPID 公共值有任一缺失，仍在编译前 exit 1、列出缺失键名且不输出值。显式 `npm run build:compile-only` 使用受标记的环境空值模块、无需手工创建 ignored 文件，并跳过 analytics 的 production/占位 API 外部读取，Next `15.5.20` production build exit 0，BUILD ID `M17jOYkyV0XrbRMHb8o9e`。`outputFileTracingRoot` 固定到当前应用，父目录多 lockfile warning 已消失；该最终 build 无占位域名 TLS warning。Next 固定的 `postcss@8.4.31` 以同 major `8.5.19` npm override 修复，重新 `npm ci` 后 `npm ls` 精确命中 override，audit 为 0；没有采用 audit 的错误 Next 9.3.3 降级建议，`yarn.lock` 的 build 副作用已精确恢复。
- 商家 Android App：`53ddcda`（含原功能 `cac9975`）；JDK 21 / Android SDK 下 token hash/轮换 `4/4` 实质单测、`assembleDebug`、`lintDebug` 通过。lint `0 errors / 40 warnings`，XML `14/14` 可解析；debug APK 4,867,057 B，SHA-256 `B396AEC64B85460057D1F946E27A129676CA1609389654B69FDC41CC0A37ED5D`。Manifest 不含 `RECEIVE_BOOT_COMPLETED` 或 `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`，`allowBackup=false`，云备份/D2D 规则排除凭据和设备域。`assembleRelease` 已接 `verifyReleaseReadiness`，缺 Firebase 文件与仓库外签名输入时在 `preReleaseBuild` 前 exit 1，不再产出可误认的 unsigned/无 FCM release。
- 本轮实际关闭：多安装实例、token rebinding/rotation/logout、App 有界重试/refresh、容器路由等本地代码语义，Web compile-only/真实凭据/通知公共配置构建模式分离，以及编译、实质 token 单测、lint、静态权限、备份规则和 Web/Android release fail-closed 子门。
- migration 隔离验证：本机 MySQL `8.4.3`、`127.0.0.1:23316`、一次性库 `nezha_notification_r2_isolated`，只建立 `users`/旧版 `vendor_device_tokens` fixture；2 个目标 migration 顺序 `up→down→up`。forward 证明安装实例表 11 列、1 外键、2 业务唯一键，商家 token `text` + `char(64)` hash 唯一键，2/2 hash、0 明文、2/2 可解密；down 证明 2/2 明文、hash 列 0、旧唯一键和 `varchar(512)` 恢复、安装实例表 0；最终 forward 记录 2/2、hash 2/2、明文 0。最小 fixture 缺 `business_settings` 只产生 provider INFO，迁移/验证均成功；最终进程、端口、数据目录全部清理。
- 仓库外非生产材料：标准 Web Push VAPID public/private decoded 为 65/32 B，public SHA-256 `563A6EC395DB15FDF8E70A9A147E8911DB7B7E4409A3DBA019F9774A781D22DC`；API private env SHA-256 `A472B1A9669BEA477905007CCB0AA3C802FC746099BDB0A43E1596B0762DED7D`，Web public env SHA-256 `111A79E3B0F58DFE2576313AC4B01F42D3E6FDDD773696171422EF33D3E6C0AF`。一次性 PKCS#12 测试 signer SHA-256 `9AB071A44EAA7165EA8D3CAD4CE8AFE58DDAADEC8D47F9FBA42EFE61DEA2BAF3`，raw PEM 已删除，ACL 仅 SYSTEM/本机 Administrator；未入 Git、未用于 Play/production。
- 本轮仍没有关闭：shared staging/production migration 与运行记录；真实非生产 Firebase 项目、Android `google-services.json` 和 Firebase VAPID（控制台停在 Google 登录页，未创建项目）；正式 release signing/Play key custody；Play full-screen intent/`specialUse` 声明与审核；`adb`/`emulator`、物理 Android/iPhone、D1–D6 与商家 App；真实 FCM/邮件/Telegram 回执；外部专业报告。相对 `origin/main` 共出现 3 个 migration 文件 diff，其中 2 个为新增 schema migration，既有 `create_vendor_alert_outbox` 文件的第三处差异只改状态注释、无可执行/schema 变化。Firebase 配置缺失使当前 APK 只能证明可编译，不能作为可用 FCM 测试包；此前 2 个 moderate npm audit 项已由 `postcss@8.5.19` override 关闭，不再列为开放门。
- 店 12 属于朋友且尚未正式真实经营，不能作为真实商家/收款/履约证据。内部 owner 无需向 Codex 提交实名；本轮外部材料 0 件，可验附件 SHA-256、实名/角色/机构、签署日期时间/时区仍全部为 0。
- B1 的五份 `PRELAUNCH_B1_*` 文档和内容快照 `fc026a78130709ce13af356914ce01c50000d866` 保持不动。固定 B1 API/Web SHA 仍是历史签收对象；R2 是尚未部署的新本地集成，不能把两者混称同一上线候选。production 继续 **NO-GO**。

## 自动化与浏览器证据

- exact-main API 红灯已关闭：`69502957` 将隔离夹具与 SQLite 日期平均值兼容修复合入当前线，`ab346c42` 对齐“商家已标记退款”契约，`b14c9c58` 增加后台账号邮箱隔离。最终候选 `a53cfb5c967daa5917ce2cb4c2489d6799434ff2` 在独立本地克隆中完成 Feature `191 tests / 1013 assertions`、Unit `15 tests / 46 assertions`，均 exit 0；邮箱边界聚焦为 `5 / 51`，8 个运行时 PHP 文件语法通过。输出仍含既有 PHPUnit XML/doc-comment deprecation 与 SQLite 无 `business_settings` 的启动日志；不得把 warning 隐去或记成失败。
- 本地有效回归前有两类环境失败，均未进入业务断言：Laravel 12 未把 Windows 盘符缓存路径识别为绝对路径，首次聚焦为 5 errors / 0 assertions；Unit 首次因本机 PHP 未加载 OpenSSL 配置而 1 failure。测试专用 Windows 路径前缀覆盖在验证后精确撤回，Git blob 与候选一致；Unit 仅给子进程加载 Laragon 自带 `openssl.cnf`。候选源文件最终无测试覆盖残留，不能把这两次环境失败冒充业务回归，也不能从完整结果中隐去。
- 开关非生产收口：API exact-main 在 bootstrap 强制 SQLite `:memory:` 安全门下，DB 安全门 + 异步通知 + 全部预约聚焦文件合计 `63 tests / 162 assertions` 通过（预约 53、异步通知 7、安全门 3）；Web exact-main `src/utils/nezhaCancelAction.test.js` 通过。仅有既存 PHPUnit schema/doc-comment deprecation 与 SQLite 无 `business_settings` 的测试日志。自动语义已关闭，但预约真实浏览器完整链、FCM/邮件/TG 真实收达和 owner 签收仍未完成。
- exact-main Web 格式门已关闭：`a9e5007` 对 13 个候选文件执行仓库固定 Prettier 2.5.1，全部 `src/utils/*.test.js`、首页性能契约、页面数据契约、`git diff --check` 与 Next 15.5.20 production build 通过，BUILD `n-lmCUI6GWVnOkqc1cDMo`。格式化前不稳定的页面数据正则已改为允许 JSX 空白，不改变动作 owner。
- Web Chromium 390×844/1365×900 复核 `/home`、`/checkout`、`/tracking`：首页/空车/追踪表单无横向溢出、破图或基线 console error。回归额外发现历史提交 `eb3ecd4` 无条件删除追踪手机号输入，而匿名 API 仍要求 `contact_number`；`a9e5007` 恢复为“仅匿名用户显示并校验手机号，数字不足 8 位不发请求，登录用户仍只按订单号”。空号验证显示中文“手机号为必填项”，网络中没有 `/order/track`；填入号码后只读负例带正确 `contact_number` 并得到预期 404/“订单不存在”。证据位于 `output/playwright/exact-main-format-gate-20260714/`。
- 商家移动付款凭证审核 `e42035c`：聚焦契约 1 test/11 assertions 通过；整组 `NezhaMerchantOrderUiContractTest` 为 16 tests/125 assertions，其中 14 通过，2 个既存 staging 树/夹具契约（移动订单列表 CSS、跨店发票 fixture）失败，不冒充整组全绿。Blade 编译、compiled PHP lint 和 diff check 通过。
- 顾客拒绝态重传 `6dad4fd`：页面数据契约与 production build 通过，最终 staging BUILD `HzPSvq6ar3kDsNwz35-_X`。该任务当时没有以全文件格式化扩大 diff，三个旧文件的 Prettier 红灯已由后续 exact-main 应用提交 `a9e5007` 收口关闭。
- 直付退款阶段 `fa13808` / `b516b8a`：后端聚焦回归 `42 tests / 220 assertions` 通过（1 个既存 PHPUnit deprecation），全部触及 PHP lint 与 diff check 通过；前端状态契约 `30/30`、样式契约 `29/29` 与 production build 通过。最终 staging BUILD `n4VGKngOQXDelVRDdK9yN` 在 Chromium 390×844 覆盖 `pending_merchant_refund`、`merchant_refunded`、`disputed`、刷新和离开后返回，无横向溢出、0 console error、0 第一方失败请求。测试使用浏览器路由合成只读订单 `990001`，没有创建订单、付款、退款、点击提醒/确认按钮或发送外部通知；当前没有 staging 管理员/商家认证夹具，因此两端真实登录浏览器仅沿用前序覆盖，本轮以控制器/Blade 契约和聚焦测试验证新阶段文案与动作 owner，不冒充本轮三端都做了登录态浏览器实操。
- 首页性能候选 API `6cf7cf9` / Web `6be4453`：API PHP lint 3/3、Pint 新测试文件和原聚焦 PHPUnit `2 tests / 17 assertions` 通过；平行 staging 增补优惠券 >10 取前 10、restaurant-wise active/date、订阅餐厅 latest-active self-delivery、佣金不变、3 active + 1 inactive 车辆与 11 个距离边界，合并为 `6 tests / 35 assertions` 通过（仅有既存 PHPUnit schema deprecation 与 SQLite 缺测试无关 `business_settings` 启动日志）。精确旧 baseline `0626875` 在实际 staging 数据 N=1/2/7 为 49/66/66，候选为 48/48/48；三组完整响应分别以 SHA-256 `7c449c532efebdb3134bfc4a4d9c21f1b42b11ff2905f99b84f93cb1c3fbc639`、`aa6b5c1ca823b5320266532063a94e93cffda5c9d4eecf8e2ae4e44d831a1d9f`、`4d7213dd1f2ca20e46a2c369e0202e51c6323b95b470dbc285fc758a84097635` 逐字节相等。Web 单飞/语言隔离/TTL 失败回退契约、Prettier 与 Next 15.5.20 production build 通过；平行 BUILD `Jgmj0TjeB-wDfR03cUi0T`。真实 Chromium 390×844/1365×900 均无横向溢出或破图，首页→`/restaurants/6`、刷新及全部第一方请求通过，0 console error；唯一 warning 是 Chromium 阻止通知权限。5c/5s `/home` 负载为 215/215 HTTP 200、p95 210.14 ms，证据位于 `output/playwright/home-perf-parallel-staging-20260714/`。shared staging 与 production 均未改动。
- 真实触发链已收敛：`Admin/OrderController::update_status(refunded)` 保留原始订单 `order_status=refunded` 兼容契约，但直付单先创建 `pending_merchant_refund`，抑制通用 `Helpers::send_order_notification()` 的 refunded 完成文案，改由 `OrderLogic::notify_customer_direct_pay_refund_pending()` 生成“待商家退款”；`Vendor/OrderController::mark_refunded()` 或 `Admin/NezhaRefundController::mark_refunded()` 只有在 `NezhaRefundRecord::transitionPendingToMerchantRefunded()` 原子转换成功时，才生成“商家已标记退款”或“平台已核实退款”。顾客订单追踪/历史/通知、商家 API 与管理员/商家 Blade 都消费同一 `nezha_refund` 投影，刷新后不再仅凭原始订单 refunded 推断资金已退。
- 订单并发：三组各 20 单。cancel vs confirm 全部 canceled 且最多一条退款；confirm 后 cancel 全部 processing、cancel=403；double cancel 全部 canceled 且最多一条退款；违规数均 0。
- 维护模式：按后台实际数据形态设置 `react_website` 后，`/home` 与 `/checkout` 均 307 到 `/maintenance`，保留 `private, no-cache, max-age=0, must-revalidate`；测试后在隔离库恢复关闭。
- 顾客浏览器：餐厅图片/菜系兜底/列表返回、空车、登录门、登录态有车、服务器权威价、优惠、默认地址、Google 地址入口、支付抽屉、订单历史、凭证驳回和重新上传均覆盖；无 `/restaurants/null`、无第一方失败请求、无横向溢出。隔离域名 Google Maps 因 referer 白名单拒绝，生产登录态真实 Google 搜索/详情/保存证据沿用前序已清零 QA。
- 商家/后台浏览器：仪表盘、订单列表/详情、履约、退款、发票租户边界覆盖。生产 2FA 没有关闭，隔离会话仅用于一次性数据库。
- 浏览器截图归档：本地 `artifacts/nezha-prelaunch-browser-evidence-20260714.tgz`（SHA-256 `532a1725d659a8d02a6c23a744777bd2f63b040ef1ffbc852e884fd2882735a3`）和 `artifacts/nezha-prelaunch-browser-evidence-current-20260714.tgz`（SHA-256 `c6cf5cb5abddc056543f431c58345996c08c1e0dd5ee84fbfa1b86109eb29dc4`）。

## 当前必须先修的工程阻断

已关闭但尚未进入 production 的工程门：首页 N+1/轻负载与 exact-main API 全量测试/Web 格式门。以下仍是 NO-GO 阻断：

1. 收敛 production 与已验 staging 候选的版本差：production Web 仍缺 `/checkout` hydration、顾客重传与退款阶段修复，production API 仍缺商家凭证审核及退款阶段修复；只能在其余发布门通过并取得 production Go 后用固定 SHA 部署、再做只读与浏览器验收。
2. 首页性能阻断已由 fixed-SHA 平行 staging 验收关闭；shared staging 仍保留 Web 10 tracked + 6 untracked、API 37 tracked + 2 untracked WIP，现有脚本仍会对移动 ref 执行 `reset --hard`，API 脚本还无条件 `migrate --force`，不得为追平候选而运行。任何后续 shared-staging/production 动作必须先由 WIP owner checkpoint/迁移，再用精确 40-hex SHA、零 migration diff/pending 和新的明确授权。
3. 让隐私政策的静态加密陈述与真实存储保护一致；这是安全/合规改造，不得边测边批量 ALTER 33 表。
4. 对三项开关漂移逐项取得 owner 签收，并清理演示数据、确认真实商家营业/保证金/收款资料。店 12 属于朋友且尚未正式经营，当前不能关闭这一门。
5. 修复加密数据库备份的 `utf8mb4` 字符保真：旧备份把 21 个已知 emoji 恢复为 `?`，必须用新备份在全新隔离库完成字符样本与目标行指纹一致性复核；结构/计数/mysqlcheck 通过不能替代这一门。
6. 通知 R2 已有本地语义/编译与一次性 MySQL `up→down→up` 证据，但没有任何 shared staging/production 应用或运行记录；Firebase 配置/正式 release signing/Play 审核、物理设备矩阵和真实 FCM/邮件/Telegram 回执仍未关闭。不得把隔离 migration 或仓库外测试 key 误记为上线/送达证据。

> 2026-07-14 只读收口校准：第 4 项中的“保证金”只在未来启用 `nezha_deposit_mode_status`/佣金时才重新成为硬门；当前阶段必须签收的是店 12 营业时间、经营者、收款资料、通知和 `active`。六类 NO-GO 的唯一 owner、签收、自动/人工边界与动作包见 `docs/PRELAUNCH_CLOSURE_LEDGER_20260714.md`。

## B1 外部签收包（已备妥、未签收）

> 2026-07-15 后续阶段裁决（收窄）：仅律师/法律专业方的外部签名、法律书面结论及其追收/验收延期到 MVP 阶段结束后，包括隐私与 USDT/AML 中属于法律意见的部分；会计/税务、非法律 AML、独立专业渗透、真实设备/渠道与通知、demo、开关和内部 owner 工作继续。该裁决不回写本次历史 QA 结果，也不把法律空白项改成通过；任何依法或按现行门禁必须在公开/真实暴露前取得的法律意见仍是该动作的前置门。

- 包入口：`docs/PRELAUNCH_B1_EXTERNAL_SIGNOFF_PACKAGE_20260714.md`；内含 26 类 demo 关联数据裁决、律师/会计固定事实、三项开关，以及物理设备/真实通知/专业渗透四类签收表。2026-07-15 对本轮可访问位置验收后，返回材料仍为 0 件；所有 owner 决策、批准目标值、回执和签名均保持空白，production 仍 NO-GO。
- 五份 B1 表单现统一记录 API `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`、Web `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`，8 文件重封内容快照提交为 `fc026a78130709ce13af356914ce01c50000d866`；旧 API `589a5366633f951fc9692810cc2a4c21c553b629` 只保留历史追溯，不能流转为最终签收对象。`b14c9c58..a53cfb5c` 对 8 个邮箱隔离运行时文件 diff=0；API `98efcc2a..a53cfb5c` 和 production hotfix `dea5dd11..a53cfb5c` 均无 migration 文件差异。Web `a9e5007..b4e0ea0f` 只有 `AGENTS.md`，无应用文件变化。production current/previous 为 API `20260715-042928-dea5dd1` / `20260714-070255-e044d34`、Web `20260714-101004-2f81803` / `20260714-074400-b66c0d1`，Web BUILD 为 `Mguty8CEfSrUIu5FXJ52G`；production 不是最终候选。
- shared staging 只读复核仍为 Web HEAD `ef54278551a3f8818661380f919fa894e47cc50c`、BUILD `n4VGKngOQXDelVRDdK9yN`、10 tracked + 6 untracked；API detached HEAD `f766dd62bd949613898e31031cf5636527488d8f`、37 tracked + 2 untracked。没有清理/reset/部署这些 WIP。
- production 两个 queue worker 本次均 online、累计重启各 93；`unstable_restarts=0`，命令仍含 `--max-time=3600`；Redis `PONG`、failed jobs=0。该运行态仍不能证明真实通知送达。
- 固定 B1 候选 `dea5dd11..a53cfb5c` migration 文件 diff=0，且当时 production `migrate:status` 为 460 Ran / 0 Pending；这不覆盖 2026-07-15 本地通知 R2 分支新增的 2 个 migration。本包使用本地干净独立克隆，没有在服务器新增 worktree、运行 migration、备份脚本/demo 工具、发送真实通知或写 production/shared staging。
- 完整性计数：demo 26/26 `HOLD`、31 个订单未逐项定性；法律/会计 0/4 专业签收；三项开关 0/3 目标值、0/5 汇总签名；外部 QA 封面 0/11、D1–D6 0/6、三渠道回执为空、无渗透报告、总签收 0/6。固定 Git/文档/manifest/既有浏览器包哈希均重算一致，但没有外部附件 SHA-256 可验；详情见 B1 包 §4。
- 候选冻结门已关闭：API/Web 最终候选已冻结、`b14c9c58` 及其后续运行时范围已隔离回归，五份表单已重封到同一 API 40-hex。当前签名仍为 0；任何后续应用 SHA 前移都必须停下重新定界并使受影响签收重测，旧 `589a5366` 上的最终“通过”不得接受。

## 清场与回滚

- 本轮只读收口工具动作包提交 `9c6b4c5bbbaeb7b27dc19a3c968625862debb233` 与 B1 文档提交 `98efcc2a625e8ba19b068747251d2ed3d66a497d` 已进入 API `origin/main`；后者只含文档，两者相对 production 均无 migration。一次性数据库 `nezha_qa_demo_goa_20260714`、用户 `nezha_qa_goa@localhost` 与 `/tmp/nezha-demo-*` 证据副本均已删除并复核为 0；shared staging 与 production 没有部署、配置/数据/开关/资金写入或进程重启。
- 一次性数据库 `nezha_qa_e2e_20260714093149` 已删除并复核不存在；19090–19095 隔离监听均已停止。
- `/tmp/nezha-prelaunch-*`、本轮 QA 脚本、生产 release 中三个 `_prelaunch_*` 探针均已精确删除；生产运行文件、PM2 进程、release/cache 未清理或重启。
- 商家付款凭证 staging QA 已精确删除 1 用户、1 订单、1 order detail、1 offline payment、2 任务日志、2 通知和全部明确用户依赖及 1 个当前 proof 文件；marker 与当前/被替换凭证路径均为 0，商家、设备 token、支付方式、业务/全局/餐厅通知设置与封存原值逐字段一致。
- 直付退款阶段 staging 只精确叠加应用补丁，没有清理既存 WIP、没有 migration、没有 PHP-FPM/queue/production 重启。API 源码回滚备份为 `/tmp/nezha-refundstage-api-fa13808.bak`；Web 源码回滚备份为 `/tmp/nezha-refundstage-web-b516b8a.bak`，构建回滚目录为 `.next.rollback-refundstage-_AakD0DH68L10ntvMVZOu`，API/Web 反向补丁 `git apply -R --check` 均通过。
- 首页性能候选只进入一次性回环平行环境，未部署 shared staging 或 production；当前代码回滚仍仅需分别 `git revert 6cf7cf9` 与 `git revert 6be4453` 形成可审计反向提交。平行 API/Web 服务、本机隧道、含 staging 配置/密钥副本的临时目录和探针已精确停止/删除，19080/19081 无监听；shared staging 的 10+6 / 37+2 WIP、BUILD `n4VGKngOQXDelVRDdK9yN` 和 production current/previous 均未改变。不得通过清理或硬重置现有 staging WIP 来制造“干净环境”。
- exact-main 收口只在独立 worktree 和 127.0.0.1:19082 临时服务中进行；Chromium 会话、本机隧道、回环服务、依赖/配置副本与独立 worktree 已停止/删除，19082 无监听。应用提交为 Web `a9e5007`、API `ab346c42`；没有 migration、shared staging/production 部署、开关/配置/业务数据写入。代码回滚使用对应 `git revert`，生产运行回滚点因未部署而不变。
- 当前前端回滚点：`20260714-074400-b66c0d1`；当前后端回滚点：`20260714-063310-75c6e4c`。因本轮未切生产，未产生新的部署回滚点。
