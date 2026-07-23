# 哪吒外卖 — 数据保护与隐私政策 (Data Protection)

**版本**: 0.99 (本地生活 UGC 加固②:违禁词/举报/免责 + 修复 local_life_posts 加密缺口) 　**更新**: 2026-06-15

> 描述平台对用户个人信息(PII)与支付凭证的保护措施。区分**已实施**与**规划中(组③数据安全加固)**,如实陈述。

## 1. 收集的个人数据
| 数据 | 用途 | 敏感级别 |
|---|---|---|
| 姓名、联系电话、邮箱 | 下单联系、账户 | 中 |
| 配送地址、定位坐标 | 配送 | 中-高 |
| 支付付款凭证(转账截图)、USDT 来源地址 | 收款核对、合规 | 高 |
| 登录凭据(密码哈希、第三方登录ID) | 账户安全 | 高 |
| 商家入驻线索(店名、联系人、电话、微信、地址)`merchant_leads` | MVP 人工入驻联系 | 中 |
| 本地生活 UGC 帖联系方式(电话/微信等)+ 上传图片 `local_life_posts.contact_info/images` | 信息墙撮合，双方私下联系 | 中 |
| 顾客举报商家内容 `restaurant_reports.description` | 举报核实、平台治理 | 中 |

## 2. 已实施的保护
- 账户密码以哈希存储(非明文)。
- 全站 HTTPS/TLS 传输。
- 后台访问需登录认证;敏感后端接口需令牌鉴权;后台路径另加 nginx Basic Auth(见运维记录)。
- 风控与操作记录通过 git 版本管理,变更可追溯。
- **(2026-06-14，2026-07-23 纠偏)支付凭证保留期自动清除**:顾客离线支付凭证(payment_info/method_fields/customer_note 及关联截图)超过保留期(默认 90 天,后台 `nezha_payment_proof_retention_days` 可调)由计划任务 `nezha:purge-payment-proofs` 每日自动抹除 PII,保留必要交易行/状态/时间戳；旧文“链上/交易/审计一律另留 ≥5 年”已撤销，实际留存必须按税务、会计、争议或主管机关要求逐字段确定。
- **(2026-06-14)离线支付付款截图文件上传(端到端落地)**:顾客可在下单页/订单详情上传付款截图(人民币方式**必填**、USDT 方式**可选**)。文件存于 public 磁盘 `offline_payment/` 目录,`payment_info` 内记**完整相对路径**(`offline_payment/xxx.webp`)。**该截图为高敏 PII**,已确认被上述两道既有机制完整覆盖:① 随**全表静态加密**(at-rest,见下文);② 到期由 `nezha:purge-payment-proofs` **连同截图文件一并删除**(以 `--dry-run` 实测:回填 120 天前的测试行,命中并标记删除 `offline_payment/...webp`)。后端仅接受图片扩展名(png/jpg/jpeg/webp/gif),从源头杜绝"清除/展示判定盲区"。截图在顾客端与后台订单详情可点开查看。
- **(2026-06-14)后台管理员两步验证(2FA/TOTP)**:可选开启(opt-in),登录在密码之外需认证器 App 动态码;含 8 个一次性恢复码;认证器丢失可经 SSH `php artisan nezha:2fa-disable <email>` 应急关闭。
- **(2026-06-14)代码库密钥泄露扫描**:已核查前后端 git 全历史,`.env`/密钥从未入库,`.gitignore` 已屏蔽 .env/OAuth key/凭据文件,历史中无已知机密命中。
- **(2026-06-14)数据库 PII 静态加密(at-rest)**:对应用库全部含 PII/敏感数据的 InnoDB 表启用 MySQL 表空间加密（2026-06-17 资金合规QA 实测：154 张 InnoDB 表中 153 已加密，唯一未加密 `nezha_sanction_addresses` 为公开名单·非 PII）(`ENCRYPTION='Y'` + `keyring_file` 插件,`early-plugin-load` 启动加载),覆盖姓名/电话/邮箱/地址/坐标/证件号/USDT 地址等全部 PII 字段。透明加密,应用搜索/派单不受影响(已验证读写正常 + API 200)。密钥置于 `/www/server/mysql-keyring/`(在数据目录与备份目录之外)。**诚实边界**:防的是磁盘/数据文件/备份被盗;密钥与数据同在一台机器,**防不了**服务器被攻破或宿主机层面访问;MySQL 5.7 下 redo/undo log 不被加密、binlog 加密不支持(本机 binlog 当前已关闭,少一个明文漏点)。**新表注意**:5.7 下新建 InnoDB 表**不会自动继承**全库加密,迁移须显式 `ALTER TABLE ... ENCRYPTION='Y'`——`local_life_posts`(窗口④建)曾因此漏加密,已于 2026-06-15 修复(见下方 UGC 加固条)；`merchant_leads`与含链上地址的`nezha_refund_records`同因此漏加密，2026-06-17 资金合规QA 实测发现并补齐(见 CHANGELOG)。加密不决定留存期限。
- **(2026-06-14)加密备份**:每日自动 `mysqldump` 经 AES-256-CBC(PBKDF2)加密落盘到 `/www/backup/database/nezha-enc/`,密钥存 `/root/.nezha/backup.key`(在备份目录之外),保留最近 14 份;明文备份不落盘,恢复方法见 `/root/nezha-backup/README-RESTORE.txt` 与 ADMIN_GUIDE 6.3。**(2026-06-17 容灾QA 扩展)**:备份同时覆盖 `storage/app` 与 `.env`，另有加密异地副本。备份是灾难恢复手段，不是绕过删除的历史库；恢复后必须运行顾客注销 restore replay，重新清除已完成账号的个人资料。
- **(2026-06-15)本地生活 UGC 帖 PII 保护**:顾客自助发帖(`local_life_posts`,`source='user'`)的**联系方式(contact_info)为 PII**——① 列表接口白名单**永不含** contact_info;② 详情接口仅对持有效 token 的登录顾客返回,游客一律置 null;③ 帖子默认 `status=待审核`,审核通过前不公开;④ 静态加密(at-rest)——**注**:`local_life_posts` 建表时漏带 `ENCRYPTION`,2026-06-15 已 `ALTER ... ENCRYPTION='Y'` 补加密(见下条)。**到期清除**:发帖时设 `expires_at=+60天`(2026-07-07 由 +30 天延长为 +60 天单时钟——与新增「上架生命周期」`listing_status` 共用同一 expires_at, 业主批准, 见 CHANGELOG 2026-07-07),计划任务 `nezha:purge-locallife-pii` 每日 03:40 抹除已过期 UGC 帖的 contact_info + 删除上传图片文件,**保留帖子行/状态供审计**(`--dry-run` 可预演)。**续期上界**:用户可续期(`renew` 重置 expires_at=+60 天)但受 `created_at+180 天` 总寿命硬顶封顶, 故单帖 PII 留存上界=180 天(封顶无限续期);联系方式已被到期清则拒绝续期(引导重发)。**到期失效**:另有每日 03:35 `nezha:expire-locallife-posts` 把到期在售帖转 `listing_status=expired`(只翻生命周期态, 不碰 PII)。发帖入口总开关 `locallife_ugc_enabled`;每用户每日发帖上限(`locallife_ugc_daily_limit` 默认 5)防刷。L1-1:本地生活仅信息墙,发帖表单/接口**不含**任何支付/押金/代收/下单/担保字段。
- **(2026-06-15)本地生活 UGC 加固②(违禁词/举报/反滥用/免责 + 修复加密缺口)**:① **违禁词过滤**——顾客发帖及后台录入均扫 title+description+contact_info,命中即拒(422,不回显命中词),词库 `business_settings.locallife_banned_words` 后台可配;② **顾客举报**——新表 `local_life_reports`(`POST /api/v1/local-life/posts/{id}/report`,**auth:api 禁匿名**),理由白名单+「其他」必填说明+去重+每日上限 `locallife_report_daily_limit`(默认20);后台举报数徽章 + 下线帖/驳回举报;③ **反滥用**——最小发帖间隔 `locallife_ugc_min_interval_sec`(默认60s)+ 24h 同标题重复拦截;④ **平台免责声明**——列表/详情常驻短提示 + 发帖必勾同意《本地生活信息发布规则》,文案 `business_settings.locallife_disclaimer`/`locallife_terms` 可配。**🔴 L1-7 加密缺口修复**:核查发现 `local_life_posts`(含 contact_info PII)实际**未加密**(5.7 新表不继承全库加密,与本文档前述声称不符)→ 已 `ALTER TABLE local_life_posts ENCRYPTION='Y'` 补加密;新建 `local_life_reports`(`detail` 可能含联系方式 PII)显式 `ENCRYPTION='Y'`,两表均确认 `ENCRYPTION="Y"`。`local_life_reports.detail` 视为 PII(随表加密;到期清理见第 4 节,2026-06-15 已实施)。**L1-1**:违禁词/举报/免责全程不引入任何支付/押金/代收/下单/担保字段。
- **(2026-06-15)本地生活《个人数据处理通知》同意采集骨架(默认关，未采集)**：为将来合规上线《个人数据处理通知与同意》预置骨架——后端 `business_settings` 新增 `locallife_pii_notice{,_en,_ru,_hy}`(通知正文/多语言) + 独立总开关 `locallife_pii_consent_enabled`(**默认 0/关**)；发帖接口 `POST /api/v1/local-life/posts` 加 `agree_pii` 同意守卫，H5 发帖表单加**第二个必勾同意项**(门控渲染)。**两道门**(开关开 且 通知正文非空)同时满足才生效——当前开关关、正文空，故**线上不展示通知、不采集任何个人数据处理同意，发帖流程与既往逐字一致(零行为变化，已 Playwright 真机 + API 双验)**。🔴 **正式开启(采集同意)属新增对外法律行为，前置硬条件**：① 已有**亚美尼亚注册实体**并在正文写明「数据控制者＝公司全称＋注册地址」；② 文案经**当地律师审校**(亚语须母语法律译者)。后台「护栏与文案设置」页对该开关与正文框配有 🔴 红色警示。当前状态：**未满足前置 → 保持默认关**(用户决定:暂无注册实体)。同步见 `legal/local-life-terms.md` 待办段。

- **(2026-06-20)评价图 UGC 审核 + 保护**: 顾客写评价可上传图片(`reviews.attachment`, 存 public 盘 `review/` 目录)。① **审核门控**——带图评价提交即 `status=3 待审核`(`active` 作用域只取 status=1, 审核通过前不公开), 纯文字评价即时公开; 审核(AI 预审 + 后台人工兜底)通过才公开并计入评分。② **违禁词**——评价正文复用 `locallife_banned_words` 词库, 命中拒收(422)。③ **图片类型**——后端仅接受 png/jpg/jpeg/webp/gif, 源头杜绝非图。④ **举报**——新表 `nezha_review_reports`(`POST /api/v1/restaurants/reviews/{id}/report`, **auth:api 禁匿名**, 理由白名单+其他必填+去重), 显式 `ENCRYPTION='Y'`(detail 可能含 PII)。⑤ **PII 处置**——写评价页**上传前提示**「请勿上传含身份证/人脸/他人隐私的图片」; 审核环节人工剔除含 PII 的图(驳回时自动删除该评价图文件, 评价行保留供审计)。**留存定性(与本地生活帖不同)**: 评价图是**菜品/到店产品内容(社会证明)**, 不属于"联系方式"那类到期必删 PII, 故**永久保留、不进 30 天 purge**(用户 2026-06-20 决定); 含 PII 的图靠"上传前提示 + 审核人工剔除"控制, 而非定时删除。reviews 表本就在全库静态加密内(`ENCRYPTION="Y"`)。

- **(2026-06-28)顾客举报商家(餐厅)PII 保护**:新增顾客举报餐厅入口(`POST /api/v1/customer/restaurant/{id}/report`,中间件 auth:api,仅登录可举报),新表 `restaurant_reports`。① **静态加密(at-rest)**——`description`(举报补充说明,可能含联系方式/姓名等 PII)随表加密,迁移显式 `ALTER TABLE restaurant_reports ENCRYPTION='Y'`(5.7 新表不继承全库加密),实测 `CREATE_OPTIONS=ENCRYPTION="Y"`。② **到期清除**——计划任务 `nezha:purge-restaurant-reports` 默认 180 天置空 `description`(见第 4 节),保留举报行供审计。③ **举报人身份服务端取定**——user_id(取自登录 Passport token)与 vendor_id 一律不信 body,实测 body 注入伪造值被忽略,防伪造他人身份(IDOR)。④ **防刷**——同用户对同店待处理去重 + 同用户+同店 10 分钟限频 + 每人每日 10 次。**L1-1**:全程不含任何支付/押金/代收/下单/担保字段,平台不碰钱;举报仅入后台人工审核队列,不自动惩罚商家。

## 3. 规划中 (组③ 数据安全加固 — 尚未实施,实施后本节升级为"已实施")
- **顾客自动注销 V6（2026-07-23 亚美尼亚最低合规修正候选，默认关闭）**：顾客只在下单确认页主动选择；受理仅限已验证邮箱，供真实销毁后通知。`我的`展示状态和显式撤销；后续未勾选订单只会新增义务、暂停并在事项结束后重新计算完整 72 小时，不会静默取消。受理、倒计时、执行、清除四闸默认 `0`；执行与清除未同时开启时不得封号。完成通知写入单独加密 outbox，发送或撤销后立即清除收件地址；目标为销毁后 3 个工作日内送达并留技术结果。姓名、电话、邮箱、地址、备注、客服对话、评价/帖子/上传文件、退款与支付自由文本、付款地址/哈希等清除；订单金额、商家、时间、状态等必要交易事实去主体化保留；依法冻结仅限明确范围与期限。产品 72 小时流程不替代查阅、更正、删除等正式个人数据请求，后者通过隐私政策渠道按法定期限办理。生产仍以运营者法定全称、注册/办公地址与联系方式完成中/亚文披露为硬门。
- **传输**:强制 HSTS;敏感 API 增加短期令牌 + 速率限制。
- **PII 静态加密(at-rest)**:✅ **已实施(2026-06-14,用户批准)** — 采用 MySQL 表空间加密方案,详见第 2 节。(应用层 `encrypted` cast 因破坏搜索/派单已否决。)
- **访问控制**:操作审计日志;按角色最小权限(RBAC)。(2FA 已实施,见第 2 节)
- **服务器**:防火墙仅开必要端口;禁用 root 远程登录(已改 key-only);fail2ban(已开);自动安全更新;定期加密备份(**已实施**,见第 2 节)并验证可恢复。
- **密钥**:所有 API/支付密钥置于环境变量,不入代码库(已核查无泄露);轮换机制;可加 pre-commit 钩子防误提交。

## 4. 数据保留与删除
- 支付凭证:默认保留 90 天后自动删除(**已实施 2026-06-14**,后台可调天数)。
- 本地生活 UGC 帖联系方式+图片:默认 **60 天**到期后自动清除(2026-07-07 由 30 天延长, 与上架生命周期共用 expires_at 单时钟, 业主批准见 CHANGELOG; **续期上界 = created_at+180 天硬顶**)(**已实施 2026-06-15**,`nezha:purge-locallife-pii` 每日 03:40),保留帖子行/状态供审计。
- 本地生活举报记录(`local_life_reports`,`detail` 偶含 PII):随表静态加密;**到期清理已实施(2026-06-15)**——`detail`(举报"补充说明","其他"理由必填,可能含联系方式)超过保留期(默认 180 天,后台「护栏与文案设置」页 `locallife_report_retention_days` 可调)由计划任务 `nezha:purge-locallife-pii` 每日 03:40 一并置空,**保留举报行/`reason`/`status` 供审计**(`--dry-run` 可预演)。
- 顾客举报商家记录(`restaurant_reports`,`description` 可能含 PII):随表静态加密(显式 `ENCRYPTION='Y'`);**到期清理已实施(2026-06-28)**——`description`(举报"补充说明","其他"理由必填,可能含联系方式/姓名)超过保留期(默认 180 天,`business_settings.nezha_restaurant_report_retention_days` 可调)由计划任务 `nezha:purge-restaurant-reports` 每日 03:57 置空,**保留举报行/`reason`/`status`/举报人标识 供审计**(`--dry-run` 可预演)。
- 商家入驻线索(`merchant_leads`,含联系人/电话/微信/地址 PII):随表静态加密;**到期清理已实施(2026-06-15)**——线索**结案后**(`status`=2已完成/3无效)超过保留期(默认 90 天,自结案起算,`business_settings.merchant_leads_retention_days` 可调)由计划任务 `nezha:purge-merchant-leads` 每日 03:50 抹除 contact_name/phone/wechat/address/note,**保留 行/store_name/category/status 供审计**;**进行中线索(0待跟进/1跟进中)绝不触碰**,避免误删尚未跟进的真实潜在商家(`--dry-run` 可预演)。
- 链上与退款资料：普通撮合平台不因使用 USDT 就当然承担 AML 五年留存。只保留税务、会计、退款争议或主管机关要求所必要的字段与期限；tx hash、地址、截图、自由文本在目的用尽后清除。若运营者未来实际成为 AML 报告主体，再按适用义务调整。
- 商家 KYC 资料(`vendor_kyc_profiles`)：随表静态加密；只在准入、合同、税务、争议或实际报告主体义务所必要的范围和期限内保留。现阶段不得以“所有商家 AML/CDD 五年”为由无限期豁免删除；运营者身份和具体适用义务确定后建立字段级期限与清除 actor。
- Telegram 登录身份映射(`user_external_identities`,存 Telegram 用户 ID,PII):**留存至账号删除或业主指令删除**;临时登录尝试行(`external_identity_login_attempts`,含 state/nonce/code_verifier 与 provider_payload 临时声明)由计划任务 `nezha:purge-external-identity-attempts` **每 15 分钟**清理(**仅 `expires_at` 过期行**;持久映射表刻意不在其射程内——它是防同一 provider subject 被改绑到另一顾客的归属台账)。Telegram 新注册成功时，姓名和规范化手机号会进入 `users` 账号主数据，按普通账户数据规则保留；不得将其误记为只存在于临时载荷。两张外部身份表均显式 `ENCRYPTION='Y'`(迁移内 fail-closed 校验 CREATE_OPTIONS,未确认加密即抛错)。本条为 **L1-7 留存例外的显式书面豁免**(2026-07-20 补记，2026-07-22 纠正账号主数据范围)。
- 联系意图计次(`local_life_contact_events` 本地生活 / `nezha_restaurant_contact_events` 外卖挂牌店):**零主体标识**设计——表内**不含** user_id / IP / UA / 设备指纹 / platform(业主 2026-07-19 拍板·甲),只落 merchant_id(或 restaurant_id) + channel + question key + created_at 的聚合事实,**故不构成个人数据,L1-7 的加密+到期删除义务不 attach**(无 purge 时钟、无数据主体披露义务)。两表仍显式 `ENCRYPTION='Y'` 作统一姿态,防未来误存 PII。代价(设计已知):计次含同人重复点击,不能去重、不能算独立用户;真要去重须另开包升级并重走本节评估,**禁就地加主体标识列**。L1-1:埋点纯信息层,不含任何交易/下单/收款元素。
- 账户数据:账户存续期间保留,注销后按政策清理。
- 数据库备份:每日加密备份(AES-256),保留最近 14 份(**已实施 2026-06-14**)。

## 5. 数据泄露应急 (规划中 — 组③)
将编制数据泄露应急流程与通知模板,满足 PDPA/GDPR 的泄露通知时限与内容要求(识别→遏制→评估→通知→复盘)。

## 6. 维护
本政策随组③实施进度更新,变更记入 `CHANGELOG.md`。

- **(2026-06-21)本地生活违规帖「证据冻结」(legal hold) — L1-7 到期删除的有限例外**：`local_life_posts` 加 `legal_hold/legal_hold_reason/legal_hold_at`。运营对**判定违规 / 需配合主管机关调查**的帖手动置 `legal_hold=1` → 清除任务 `nezha:purge-locallife-pii` **跳过该帖**（contact_info + 图片全留供留证）。**边界（防过度留存）**：① 仅运营**人工**设/解，**非用户举报自动触发**（否则任何人可借举报令他人 PII 永久留存）；② 目的限定违规处理 / 配合执法；③ **用尽目的应解除冻结**让其按正常 30 天到期清；④ 举报记录 `detail`(举报人 PII)**不随之冻结**，仍按 180 天清。§5.3 用户承诺文案（`legal/local-life-terms.md` 正本 + 线上 `business_settings.locallife_terms`）同步加「依法/配合调查需保留的除外」例外，保持政策与行为一致。线上文案旧值备份 `_locallife_terms_backup.txt`。
- **(2026-07-01)人工客服／商家沟通经 Telegram 中转（阶段D 双向客服）— 第三方子处理者 + 跨境传输披露**：顾客请求"转人工"、或经平台与商家就订单沟通时，消息内容可能经 Telegram（境外第三方即时通讯，运营方 Telegram FZ-LLC）转交平台客服(超管 chat)或相应商家，便于及时查看回复；属第三方处理且可能涉及个人信息跨境传输。**最小化（防过度外泄）**：① 仅在人工接管/沟通所必需范围转交；② 面向超管/运营的消息一律经 `NezhaCsAssistant::redactPii` 去标识化(屏蔽电话/邮箱/钱包地址)；③ 不主动转交高敏 PII，隐私政策同步提示用户勿在此类对话提供非必要敏感信息。**已实施**：阶段D webhook 双向地基(`d746951`)，**自 setWebhook 激活日起生效**；隐私政策第3条(7)同步披露(版本1.0→1.1，旧值备份 `_privacy_policy_backup_*.html`)。与既有 DeepSeek(AI 客服处理·中国境内)并列为客服链路第三方。技术细节见运维 memory `nezha-cs-overhaul-plan`。
