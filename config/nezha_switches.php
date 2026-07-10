<?php

/**
 * 哪吒超管 M3「开关注册表」—— 全部灰度/合规/业务开关的结构化单源。
 *
 * 🔴 这是 config 文件而非 DB 表(已裁决): 改开关清单=代码变更走 review, 防后台误编辑。
 * 🔴 本文件不翻任何闸、不存开关的"真实值"——真实值一律 live 读 business_settings(见 NezhaSwitchLedger)。
 *    这里只登记"每个开关是什么/应该是什么/翻它有什么坑/去哪翻"。
 *
 * 正本仍是 docs/PRELAUNCH_SWITCHES.md(人读人维护); 本表是它的机器可核对镜像。
 * `php artisan nezha:switches-verify` 三方对账(注册表 ↔ md ↔ DB), 防表与实漂移。
 *
 * 字段:
 *   key            business_settings 键(individual config 来源的另注 value_type=special/config)
 *   label          中文名
 *   section        A 上线开 | B 安全轨 | C 必须关 | D 未就绪 | E 业务决策 | F 已开记录
 *   level          L1-x(带条款) | L2 | L3 | core | ''(无)
 *   l1_clause      L1 条款原文一行(仅 L1 开关; 台账 hover 显示, 危险动作现场标注取用)
 *   expected       B=1 / C=0 / D=0 / A,E,F=null(无预期不告警)。live 读到的 status 与此不符=🔴偏离预期
 *   prereq         开启前提一句话(照 md 摘)
 *   settings_route 后台设置页路由名; null=无专用后台开关 UI(见 ops_note)
 *   ops_note       翻闸坑注记(照 md 原文摘)——本表最救命的一列
 *   value_type     bool(标量 0/1) | json_status({"status":".."}) | param(数值参数) | special(非布尔·env/外部驱动)
 *
 * 增删开关: 同步改 docs/PRELAUNCH_SWITCHES.md 对应行, 跑 verify 命令确认 0 漂移。
 */

// L1 条款速记(与 INVARIANTS.md / docs/compliance 同义, 台账 hover 一行)
$L1 = [
    'L1-1'  => '平台不碰钱·不经手资金(呈现纪律: 不出现平台营收/流水字样)',
    'L1-2'  => '退款只原路退回',
    'L1-3'  => 'USDT 只退原地址·不可提现到中国',
    'L1-5'  => '二清打款腿已拔不可恢复·链上留存≥5年',
    'L1-6'  => '制裁名单命中即拒',
    'L1-7'  => 'PII 加密 + 到期删除',
    'L1-8'  => '资金流出路径·高额净额强制审批 + 制裁 re-screen + 户名三方核对',
];

// 翻 business_settings 后的通用坑注记(激活类进程内 static 缓存)
$OPS_FORGET = "改 DB 后须 Cache::forget('business_settings_all_data')";
$OPS_ACTIVATE = "激活类翻转须 Cache::forget('business_settings_all_data') + kill -USR2 \$(FPM master) 刷 worker(get_business_settings 进程内 static 缓存, 否则间歇读旧值)";

return [

    /* ───────────── D4 安全态(手工登记字段, 业主轮换时改此处一行) ─────────────
     * 后台锁(nginx basic auth)无可查询数据源 → 只能手工登记。
     * 2FA 状态相反有真实 DB 源(admins.two_factor_enabled) → NezhaSwitchLedger 直接 live 读, 不在此硬编码。 */
    'security' => [
        'basic_auth' => [
            'enabled'    => true,
            'rotated_at' => '2026-07-10',   // 🔑 双密码 0710 业主亲手轮换(0708 明文泄露事件闭环)
            'note'       => 'nginx admin_basicauth.conf 拦 ^/admin 全路径; 密码运行时现问业主, 勿落盘',
        ],
        // admin_2fa: 不硬编码——live 读 admins.two_factor_enabled(见 ledger securityRow())
        'admin_2fa_roadmap' => '安全路线图 T 批次(见 fable-brief/HANDOFF_security_roadmap.md)',
    ],

    /* ───────────── A-F 开关注册表 ───────────── */
    'switches' => [

        /* ═══ A. 上线前【要打开】(现关·上线应开; 无硬预期不告警) ═══ */
        [
            'key' => 'nezha_refund_overdue_status', 'label' => '逾期未退款兜底', 'section' => 'A', 'level' => 'L2',
            'expected' => null, 'value_type' => 'bool',
            'prereq' => '上线激活: 自动催办+记风控+告警; 先亲测催办邮件真发出',
            'settings_route' => 'admin.nezha-refund.overdue',
            'ops_note' => $OPS_ACTIVATE . '。配套 nezha_refund_overdue_auto_suspend 默认手动停接单',
        ],
        [
            'key' => 'nezha_guides_status', 'label' => '生活攻略板块总闸', 'section' => 'A', 'level' => 'L1-1邻',
            'l1_clause' => $L1['L1-1'] . '(纯信息展示)', 'expected' => null, 'value_type' => 'bool',
            'prereq' => '五篇攻略录入完成 + 业主验收后再开(内容未就绪不开·可晚于上线)',
            'settings_route' => 'admin.guides.list',
            'ops_note' => "翻 1 后读不到新值: {$OPS_FORGET} + kill -USR2 \$(FPM master)。=0 时列表空/详情走空态不 404/入口条不渲染",
        ],

        /* ═══ B. 必须【保持开启】的安全轨(expected=1, ≠1 即红) ═══ */
        [
            'key' => 'nezha_risk_control_status', 'label' => '下单风控', 'section' => 'B', 'level' => 'L2',
            'expected' => 1, 'value_type' => 'bool', 'prereq' => '安全轨·上线确认仍=1',
            'settings_route' => 'admin.nezha-risk.settings', 'ops_note' => '单笔/单日/频次/大额风控',
        ],
        [
            'key' => 'nezha_refund_control_status', 'label' => '退款护栏(原路锁定+限额)', 'section' => 'B', 'level' => 'L1-2',
            'l1_clause' => $L1['L1-2'], 'expected' => 1, 'value_type' => 'bool', 'prereq' => '安全轨·上线确认仍=1',
            'settings_route' => 'admin.nezha-risk.settings', 'ops_note' => null,
        ],
        [
            'key' => 'nezha_refund_usdt_verify_status', 'label' => 'USDT 退款链上核验', 'section' => 'B', 'level' => 'L1-3',
            'l1_clause' => $L1['L1-3'], 'expected' => 1, 'value_type' => 'bool', 'prereq' => '安全轨·上线确认仍=1',
            'settings_route' => 'admin.nezha-risk.settings', 'ops_note' => null,
        ],
        [
            'key' => 'nezha_sanction_screen_status', 'label' => 'USDT 收款来源制裁筛查', 'section' => 'B', 'level' => 'L1-6',
            'l1_clause' => $L1['L1-6'], 'expected' => 1, 'value_type' => 'bool', 'prereq' => '命中即拒·上线确认仍=1',
            'settings_route' => 'admin.nezha-risk.settings', 'ops_note' => null,
        ],
        [
            'key' => 'nezha_kyc_sanction_screen_status', 'label' => '商家入驻 KYC 人名制裁筛查', 'section' => 'B', 'level' => 'L1-6',
            'l1_clause' => $L1['L1-6'], 'expected' => 1, 'value_type' => 'bool', 'prereq' => '命中即拒·上线确认仍=1',
            'settings_route' => null, 'ops_note' => "无专用后台开关 UI(DB flag)。{$OPS_FORGET}",
        ],
        [
            'key' => 'nezha_timeout_status', 'label' => '订单超时自动处理', 'section' => 'B', 'level' => 'L1-1邻',
            'l1_clause' => $L1['L1-1'] . '(邻区)', 'expected' => 1, 'value_type' => 'bool', 'prereq' => '催/取消未付单/升级·上线确认仍=1',
            'settings_route' => null, 'ops_note' => "无专用后台开关 UI(DB flag)。{$OPS_FORGET}",
        ],
        [
            'key' => 'nezha_yandex_link_purge_status', 'label' => 'Yandex 链接 PII 到期清除', 'section' => 'B', 'level' => 'L1-7',
            'l1_clause' => $L1['L1-7'], 'expected' => 1, 'value_type' => 'bool', 'prereq' => 'PII 到期清除·上线确认仍=1',
            'settings_route' => null, 'ops_note' => "无专用后台开关 UI(DB flag)。{$OPS_FORGET}",
        ],
        [
            'key' => 'home_delivery', 'label' => '配送(唯一在运营的履约类型)', 'section' => 'B', 'level' => 'core',
            'expected' => 1, 'value_type' => 'bool', 'prereq' => '核心履约·上线务必开',
            'settings_route' => null, 'ops_note' => "履约类型全局开关。改后须清 business_settings_keys + all_data 两缓存键(见 [[project_fulfillment-types-live]])",
        ],
        [
            'key' => 'offline_payment', 'label' => '顾客直付商家(B方案核心付款)', 'section' => 'B', 'level' => 'core',
            'expected' => null, 'value_type' => 'special',
            'prereq' => '🔴 关了没人能下单·上线务必确认开',
            'settings_route' => 'admin.business-settings.offline',
            'ops_note' => 'business_settings 无此布尔 key → 无法机器核对(value_type=special)。直付是否启用见「离线付款方式」配置页, 上线人工确认',
        ],
        [
            'key' => 'recaptcha', 'label' => 'reCAPTCHA 注册防刷', 'section' => 'B', 'level' => '',
            'expected' => 1, 'value_type' => 'json_status', 'prereq' => '注册防刷·上线确认开',
            'settings_route' => 'admin.business-settings.recaptcha_index', 'ops_note' => '值形状 {"status":"1",..}, 读 .status',
        ],
        [
            'key' => 'mail', 'label' => '邮件(找回/通知等)', 'section' => 'B', 'level' => '',
            'expected' => null, 'value_type' => 'special',
            'prereq' => '上线确认能发信(注册找回/催办邮件依赖)',
            'settings_route' => null,
            'ops_note' => '走 .env / Mailgun(APP 环境驱动), 非 business_settings 布尔 → 无法机器核对(special)。business_settings.mail_config 是 StackFood 内置 SMTP 覆盖(status=0=用 env 默认), 别混淆',
        ],

        /* ═══ C. 必须【保持关闭】(expected=0, ≠0 即红) ═══ */
        [
            'key' => 'cash_on_delivery', 'label' => '货到付款(COD)', 'section' => 'C', 'level' => 'L1-1',
            'l1_clause' => $L1['L1-1'], 'expected' => 0, 'value_type' => 'json_status',
            'prereq' => 'COD 与 B方案(平台不碰钱)冲突; 部署脚本有 COD 硬自检, 开了拦上线',
            'settings_route' => 'admin.business-settings.payment-method', 'ops_note' => '值形状 {"status":"0"}, 读 .status',
        ],
        [
            'key' => 'maintenance_mode', 'label' => '维护模式(全站下线)', 'section' => 'C', 'level' => '',
            'expected' => 0, 'value_type' => 'bool', 'prereq' => '上线时必须为 0',
            'settings_route' => null, 'ops_note' => '=1 时全站下线',
        ],
        [
            'key' => 'digital_payment', 'label' => '在线支付网关', 'section' => 'C', 'level' => '',
            'expected' => 0, 'value_type' => 'json_status', 'prereq' => 'B方案直付无网关, 保持关',
            'settings_route' => 'admin.business-settings.payment-method', 'ops_note' => '值形状 {"status":"0"}, 读 .status',
        ],

        /* ═══ D. 未就绪/有前置——【暂不开】(expected=0, ≠0 即红) ═══ */
        [
            'key' => 'nezha_refund_dispute_status', 'label' => '退款争议流(denied 凭证)', 'section' => 'D', 'level' => 'L1-2',
            'l1_clause' => $L1['L1-2'], 'expected' => 0, 'value_type' => 'bool',
            'prereq' => 'R1-R4 全实装 dormant·🔴真开须业主批准+亲测整条争议链(商家发起→超管裁决→逾期恢复)',
            'settings_route' => 'admin.nezha-refund.disputes', 'ops_note' => $OPS_ACTIVATE,
        ],
        [
            'key' => 'nezha_ad_auction_status', 'label' => '广告实时竞价 CPC', 'section' => 'D', 'level' => 'L2',
            'expected' => 0, 'value_type' => 'bool',
            'prereq' => '🔴 C1 后端加固 + C2 前端触发正确性 + 去 merit 标签, 三者齐前不得真开(见 docs/PLAN_ad_auction.md §11)',
            'settings_route' => 'admin.advertisement.requestList', 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_ad_billing_status', 'label' => '广告 CPT 按天计费', 'section' => 'D', 'level' => 'L2',
            'expected' => 0, 'value_type' => 'bool', 'prereq' => '广告变现未启动·现广告免费·想收费再开(确认单价/知情/充值通道)',
            'settings_route' => null, 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_deposit_mode_status', 'label' => '预存佣金/扣佣模式', 'section' => 'D', 'level' => 'L2',
            'expected' => 0, 'value_type' => 'bool',
            'prereq' => '一阶段免佣免押·开=商家要充保证金才能接单(商业决策)·nezha_min_deposit_threshold 现 0',
            'settings_route' => 'admin.nezha-deposit.index', 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_notif_async_status', 'label' => '订单通知异步化灰度', 'section' => 'D', 'level' => 'L3',
            'expected' => 0, 'value_type' => 'bool',
            'prereq' => '代码已上线·激活待 /debate + staging 下单 QA + 你签字(关键路径, 异步化搞错会漏通知)',
            'settings_route' => null, 'ops_note' => "{$OPS_FORGET}。金丝雀 REVERT=0 清缓存(见 memory project_nezha-capacity-queue-redis-staging-isolation)",
        ],
        [
            'key' => 'nezha_offboard_status', 'label' => '商家退出结算', 'section' => 'D', 'level' => 'L1-8',
            'l1_clause' => $L1['L1-8'], 'expected' => 0, 'value_type' => 'bool',
            'prereq' => 'step4-4/step5 实装 dormant·资金流出路径·灰度存量 7 店 KYC 未录→保持关·真开前 staging 单店试跑',
            'settings_route' => 'admin.nezha-offboard.index', 'ops_note' => "{$OPS_FORGET}。超管审批队列 admin/nezha-offboard 不受本闸限、始终可见",
        ],
        // 〔selfserve / merchant_notes 原在 D·2026-07-10 业主拍板 reclassify 至 F 已开(已放量)——见文件末 F 区〕
        [
            'key' => 'nezha_topup_status', 'label' => '自助充值申请总闸', 'section' => 'D', 'level' => 'L2',
            'expected' => 0, 'value_type' => 'bool',
            'prereq' => '🔴 开前先配好平台收款账户(nezha_topup_alipay_account/_name/_holder/_qr)否则收款码空',
            'settings_route' => 'admin.nezha-topup.index', 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_topup_guarantee_status', 'label' => '押金账户自助充值腿', 'section' => 'D', 'level' => 'L2',
            'expected' => 0, 'value_type' => 'bool', 'prereq' => '总闸开且此开才亮押金腿',
            'settings_route' => 'admin.nezha-topup.index', 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_topup_ad_status', 'label' => '广告账户自助充值腿', 'section' => 'D', 'level' => 'L2',
            'expected' => 0, 'value_type' => 'bool', 'prereq' => '总闸开且此开·广告计费未上线前不亮',
            'settings_route' => 'admin.nezha-topup.index', 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_topup_refund_status', 'label' => '押金中途退款', 'section' => 'D', 'level' => 'L1-8',
            'l1_clause' => $L1['L1-8'], 'expected' => 0, 'value_type' => 'bool',
            'prereq' => '🔴 前置: nezha_offboard_status 未开→本开关不得开(护栏共用)',
            'settings_route' => 'admin.nezha-topup.refunds', 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_topup_min_amd', 'label' => '自助充值下限(AMD)', 'section' => 'D', 'level' => 'L2',
            'expected' => null, 'value_type' => 'param', 'default' => 5000,
            'prereq' => '参数非布尔·后台可调', 'settings_route' => 'admin.nezha-topup.index', 'ops_note' => null,
        ],
        [
            'key' => 'nezha_topup_max_amd', 'label' => '自助充值上限(AMD)', 'section' => 'D', 'level' => 'L2',
            'expected' => null, 'value_type' => 'param', 'default' => 2000000,
            'prereq' => '参数非布尔·后台可调', 'settings_route' => 'admin.nezha-topup.index', 'ops_note' => null,
        ],

        /* ═══ E. 上线前【业务决策】(无对错·无预期不告警) ═══ */
        [
            'key' => 'take_away', 'label' => '自取', 'section' => 'E', 'level' => '',
            'expected' => null, 'value_type' => 'bool',
            'prereq' => '上线要不要开自取? 现只运营配送·自取 2026-06-20 关的(纯 DB 开关)',
            'settings_route' => null, 'ops_note' => "改后须清 business_settings_keys + all_data 两缓存键。dine_in=NULL 从未启用",
        ],
        [
            'key' => 'nezha_kyc_required_status', 'label' => '强制商家 KYC 才能经营', 'section' => 'E', 'level' => '',
            'expected' => null, 'value_type' => 'bool',
            'prereq' => '上线要不要强制 KYC? 现不强制(制裁筛查 B 类仍照跑, 本开关只管是否必须完成 KYC)',
            'settings_route' => 'admin.nezha-kyc.index', 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'customer_verification', 'label' => '顾客手机验证', 'section' => 'E', 'level' => '',
            'expected' => null, 'value_type' => 'bool',
            'prereq' => '上线要不要开顾客手机验证? 现关(省 SMS, 靠 reCAPTCHA+限流·亚美尼亚 SMS≈$0.14/条)',
            'settings_route' => null, 'ops_note' => '空值=关',
        ],

        /* ═══ F. 已开着的其它(仅记录·无预期) ═══ */
        // 〔原 D·2026-07-10 业主拍板已放量归 F：商户轻管理面 0709 有意翻开、笔记总闸 0710 翻开〕
        [
            'key' => 'nezha_local_merchant_selfserve_status', 'label' => '本地生活商户轻管理面总闸', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool',
            'prereq' => '五增量 live·2026-07-09 业主翻开·/m/login 已 live(改→过审→顾客端生效)',
            'settings_route' => null, 'ops_note' => "翻此闸须 php artisan cache:clear(business_settings 缓存)。关=整 /m 面板 404·驾驶舱商户资料 chip 恒 0 隐藏",
        ],
        [
            'key' => 'nezha_merchant_notes_status', 'label' => '商家页笔记内容层总闸', 'section' => 'F', 'level' => 'L1-1',
            'l1_clause' => $L1['L1-1'] . '(纯信息墙)', 'expected' => null, 'value_type' => 'bool',
            'prereq' => '批N·2026-07-10 翻开·过审笔记在商家页「笔记」卡展示(前端卡待补时整卡不显·不影响开关语义)',
            'settings_route' => null, 'ops_note' => $OPS_FORGET,
        ],
        [
            'key' => 'nezha_feedback_digest_status', 'label' => '反馈日报', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool', 'prereq' => '已开', 'settings_route' => null, 'ops_note' => null,
        ],
        [
            'key' => 'nezha_cs_ai_status', 'label' => 'AI 客服', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool', 'prereq' => '已开', 'settings_route' => null,
            'ops_note' => "get_business_settings 进程内 static·翻转须清缓存 + kill -USR2(见 memory project_nezha-cs-overhaul-plan)",
        ],
        [
            'key' => 'nezha_cs_merchant_relay_status', 'label' => '客服商家中继', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool', 'prereq' => '已开', 'settings_route' => null, 'ops_note' => null,
        ],
        [
            'key' => 'nezha_cs_vendor_tg_relay_status', 'label' => '商家↔顾客 TG 中继', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool', 'prereq' => '已开', 'settings_route' => null, 'ops_note' => null,
        ],
        [
            'key' => 'nezha_search_log_status', 'label' => '搜索需求探针', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool', 'prereq' => '已开', 'settings_route' => null, 'ops_note' => null,
        ],
        [
            'key' => 'order_delivery_verification', 'label' => '送达验证', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool', 'prereq' => '已开', 'settings_route' => null, 'ops_note' => null,
        ],
        [
            'key' => 'nezha_busy_mode_status', 'label' => '商家忙碌模式/定时挂起', 'section' => 'F', 'level' => 'L3',
            'expected' => null, 'value_type' => 'bool', 'prereq' => '2026-07-08 go-live·一次性功能总闸(非日常操作·无后台 UI)',
            'settings_route' => null,
            'ops_note' => "🔴 只有翻这个总闸本身才须 php artisan cache:clear + /etc/init.d/php-fpm-82 restart(单 Cache::forget+graceful reload 不够·实测 FPM worker 仍读旧值)。日常商家翻自己店的忙碌/暂停不动此闸",
        ],
    ],
];
