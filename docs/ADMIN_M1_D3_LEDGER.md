# 超管后台 M1 D3 — 116 入口去向台账

> 对照 `fable-brief/admin-shots/manifest.json`(116 编号入口,#117-119 为详情页非独立导航项)。
> 完备性已用进程内渲染 harness 核对: 116/116 全覆盖(106 条可见 href + 10 条 D2 已知合法藏匿),0 条遗失。
> 生成于 2026-07-08,随 [Nezha M1 D3] 提交。

## ① 今天(占位)
| # | 入口 | 去向 |
|---|---|---|
| 1 | dashboard | ①「数据看板」(M2 建驾驶舱前占位,不改名) |

## 藏匿(D2 已处理,路由/控制器保留,不计入 D3 分组)
| # | 入口 | 藏匿依据 |
|---|---|---|
| 2 | pos | §A#1 代下单场景不存在 |
| 13 | order/list/dine_in | §A#2 堂食从未启用 |
| 15 | order/subscription | §A#2 佣金模式非订阅制 |
| 16 | dispatch/list/searching_for_deliverymen | §A#3 平台无自营骑手 |
| 17 | dispatch/list/on_going | §A#3 同上 |
| 44 | cashback | §A#8 返现未启用 |
| 63 | customer/loyalty-point-report | §A#8 忠诚积分未启用 |
| 67 | report/disbursement-report/restaurant | §A#11 结算腿危险面 |
| 68 | report/disbursement-report/delivery_man | §A#11 同上 |
| 84 | provide-deliveryman-earnings | §A#6/#9 打款腿已拔 |

## ② 订单
| # | 入口 | 去向 |
|---|---|---|
| 3-12 | order/list/{all,scheduled,pending,accepted,processing,food_on_the_way,delivered,canceled,failed,refunded} | ②订单(原样保留,状态子项未收敛,收敛挪 M2) |
| 14 | order/offline/payment/list/all | ②订单(orders 子项) |

## ③ 钱·风控(风控中心原样迁入 + 原"交易/资金管理"组 deposit 系 5 项合并)
| # | 入口 | 去向 |
|---|---|---|
| 18-20 | nezha-risk/{queue,logs,settings} | ③钱·风控(风控中心) |
| 21-23 | nezha-refund/{records,overdue,disputes} | ③钱·风控(风控中心) |
| 24 | nezha-kyc | ③钱·风控(风控中心) |
| 77-81 | nezha-{deposit,offboard,topup,topup/refunds,consolidation} | ③钱·风控(原交易组迁入) |

## ④ 商家
| # | 入口 | 去向 | 备注 |
|---|---|---|---|
| 25 | zone | ④商家 | ⚠️判断call: §B原文未提zone,按"商家基础设施"就近归入 |
| 27-30 | restaurant/{add,list,pending/list,bulk-import(+bulk-export无编号)} | ④商家 | 显式 |
| 55 | vendor-feedback | ④商家 | 显式(与 nezha-cs 拆组) |
| 61 | merchant-lead/list | ④商家 | 显式 |

## ⑤ 内容·审核
| # | 入口 | 去向 | 备注 |
|---|---|---|---|
| 26 | cuisine/add | ⑤内容·审核 | 显式(原代码在"商家"组,§B裁决挪⑤) |
| 31-33 | category/{add,add-sub-category,bulk-import(+bulk-export无编号)} | ⑤内容·审核 | 显式 |
| 34-36 | addon/{addon-category,add-new,bulk-import(+bulk-export无编号)} | ⑤内容·审核 | ⚠️判断call: §B原文未提addon,按"食品配置"域就近归入 |
| 37-40 | food/{add-new,list,reviews,bulk-import(+bulk-export无编号)} | ⑤内容·审核 | 显式 |
| 41-42 | campaign/{basic,item}/list | ⑤内容·审核 | 显式 |
| 43 | coupon/add-new | ⑤内容·审核 | 显式 |
| 45-46 | banner/{add-new,promotional-banner} | ⑤内容·审核 | 显式 |
| 47-51 | advertisement/{create,requests,list,auction-settings,ad-recharge} | ⑤内容·审核 | 显式 |
| 57-59 | local-life/{list,categories/list,merchants/list} | ⑤内容·审核 | 显式 |
| 60 | restaurant-report/list(顾客举报商家) | ⑤内容·审核 | 显式(0707复核修正,原三组重复已收敛为单一家) |

## ⑥ 顾客·客服
| # | 入口 | 去向 |
|---|---|---|
| 52 | notification/add-new | ⑥顾客·客服(原营销组迁入) |
| 53 | message/list?tab=customer | ⑥顾客·客服 |
| 54 | nezha-cs | ⑥顾客·客服(与 vendor-feedback 拆组) |
| 56 | contact/list | ⑥顾客·客服 |
| 62 | customer/list | ⑥顾客·客服 |
| 64 | customer/subscribed | ⑥顾客·客服(业主已拍板) |

## ⑦ 洞察(留 4 张报表 + 搜索需求/取消理由)
| # | 入口 | 去向 | 备注 |
|---|---|---|---|
| 65 | report/transaction-report | ⑦洞察 | 留4张之一 |
| 70 | report/order-report | ⑦洞察 | 留4张之一;原"Order_Report"2子项父级拆分,兄弟(71)挪⑧存档 |
| 72 | report/restaurant-report(商家排行) | ⑦洞察 | 留4张之一;原"restaurant_report"2子项父级拆分,兄弟(Subscription_report)挪⑧存档 |
| 75 | report/admin-earning-report | ⑦洞察 | 留4张之一 |
| 82 | nezha-search-demand | ⑦洞察 | 显式 |
| 83 | nezha-order-cancel-demand | ⑦洞察 | 显式 |
| — | 反馈日报 | 不设条目 | 已裁决:活在 nezha-cs 页面内部,非独立路由 |

## ⑧ 系统 — 常规
| # | 入口 | 去向 | 备注 |
|---|---|---|---|
| 88 | business-settings/business-setup | ⑧系统 | 显式 |
| 89 | business-settings/email-setup | ⑧系统 | ⚠️判断call: §B未单独提及,与 business-setup 同簇就近归入(非折叠) |
| 95-100 | business-settings/pages/{terms,privacy,about,refund,shipping,cancellation} | ⑧系统「政策页」子项 | 显式("政策页(095-100)") |
| 85-87 | custom-role/create, employee/{add-new,list} | ⑧系统「员工管理」 | 显式("employee/custom-role(085-087)") |
| 新增 | nezha-audit/logs(安全审计日志) | ⑧系统 | 已裁决新建:路由存在但侧栏此前从无入口 |

## ⑧ 系统 — 集成子分组(原"第三方与集成"父项,原 3rd_Party_&_Configurations)
| # | 入口 | 去向 | 备注 |
|---|---|---|---|
| 102 | business-settings/payment-method | ⑧集成 | 显式(M1 只藏侧栏权重,403 禁用留 M6) |
| 103 | business-settings/fcm-index | ⑧集成 | 显式 |
| 104 | business-settings/offline-payment | ⑧集成 | 显式(收款方式配置❓已裁决=此项) |
| 105 | business-settings/restaurant/join-us/setup | ⑧集成 | ⚠️判断call: 原代码即为该父项子项,结构就近保留 |
| 106 | business-settings/marketing/analytic-setup | ⑧集成 | 显式 |
| 107 | business-settings/open-ai | ⑧集成 | 显式 |
| 109-110 | business-settings/{notification-setup,notification-messages} | ⑧集成 | 显式;原为独立顶层项,现收作集成子项 |

## ⑧ 系统 — 装配折叠(§A#12,一次性配置)
| # | 入口 |
|---|---|
| 90 | business-settings/theme-settings |
| 91 | file-manager/index |
| 92 | login-settings/login-setup |
| 93 | business-settings/invoice-setup |
| 94 | business-settings/social-media |
| 101 | business-settings/registration-page/react/hero |
| 108 | business-settings/app-settings |
| 111 | landing-page/setup |
| 112 | react-landing-page/header |
| 113 | page-meta-data |
| 115 | addon-activation |
| 116 | system-addon |

## ⑧ 系统 — 危险区折叠(M6 禁用项预留位)
| # | 入口 |
|---|---|
| 114 | business-settings/db-index(clean database) |

## ⑧ 系统 — 报表(存档)折叠(§A#11 非留4张部分)
| # | 入口 | 备注 |
|---|---|---|
| 66 | report/expense-report | |
| 69 | report/food-wise-report | |
| 71 | report/campaign-order-report | 原"Order_Report"父级另一子项 |
| 73 | customer/overview/report | |
| 74 | report/vendor-wise-taxes(Restaurant_VAT_Report) | |
| 76 | report/restaurant-earning-report | |
| 无编号 | report/getTaxReport(Tax_Report) | manifest 未编号,§A#11 footnote 已说明 |
| 无编号 | Subscription_report | 恒 dead-gated(subscription_check()==false),随存档折叠一起保留 |

## 非独立导航项(不计入116,contextual详情页)
| # | 入口 |
|---|---|
| 117 | order/details/{id} |
| 118 | restaurant/view/{id} |
| 119 | customer/view/{id} |

## 未采纳的 §B 文字项(核实结果)
- **nzwatch 可用性监控**: `grep routes/` 无匹配,只是 CLI 脚本(`nzhealth.sh`)+ cron 告警,无 web 路由可挂载,本轮不新建导航项。
- **反馈日报**: 见⑦洞察行,已裁决不设独立条目。

## 3 处标记「⚠️判断call」的说明
§B 映射表原文对 zone(025)/addon(034-036)/email-setup(089)/join-us(105) 四处未给出明确分组指令。均按"就近同域"原则placed(zone→商家基础设施同域;addon→食品配置同域;email-setup→business-setup同簇;join-us→原结构同一父项子项不拆散)。风险低、可逆(单一 config 数组条目挪组),如需调整请回 Fable 复核后我再挪动。

## M2 结构变化台账（D2–D5 后续 · 2026-07-08）
> 上表是 M1-D3 时点的 116 入口快照；M2 后侧栏发生下列新增/收敛/收编，补记于此（防后续窗口对不上）。

- **新增「今天」驾驶舱**（M2-D4）：`admin/nezha-today`（`admin.nezha-today` → `Admin\DashboardController@nezhaToday`，登录 admin 即可见·无 module 闸）。①今天组**第一项**，数据全走 `App\CentralLogics\NezhaAdminDashboard` 只读聚合单一真相源（逾期退款/订单异常/资金审核/审核台/商家健康 + 今日经营/系统健康/反馈日报/差评预警）。**先并存**：第二项「数据看板」(旧 dashboard) 保留，默认登录落点暂不改（稳定一周后 1 行 commit 切默认）。**非 116 原入口，M2 新增。** 说明：评价审核段后台无对应功能已去除（另立项 task_ed6425af）；TG 未绑行/cron·发信额度健康行无现成数据源按规则不显示。
- **订单收敛**（M2-D2）：②订单组 12 状态子项 → 单条「订单」(徽标=grp_pending) + 「离线付款核验」独立；页内组 tab 承接状态细分；旧 `admin/order/list/{status}` 全保留可达。
- **浮窗收编 + 铃铛收编 provider**（M2-D3/D4）：两个漂浮红浮窗（异常订单/逾期退款）收编进顶栏铃铛通知栈（旧浮窗 `@if(false)` 封存）；D4 起铃铛数据源 `SystemController::restaurant_data` 收编读 `NezhaAdminDashboard::counts()`（与驾驶舱卡①③/侧栏徽标同源，60s 缓存·修此前每次轮询重算）。
- **侧栏新徽标**（M2-D4）：③钱·风控组「逾期未退款」「退款争议裁决」加计数徽标（同源 `NezhaAdminDashboard`，满足 DoD#1「侧栏=卡=列表=铃铛」对账；0 无徽标）。
- **搜索框接活 + 环境徽章**（M2-D5）：侧栏搜索框过滤菜单；顶栏环境徽章（生产/STAGING）。
