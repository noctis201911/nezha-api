# 性能 / 负载 QA Playbook（第 6 层）

> 触发词：**负载QA** / 性能QA / 压测。读法：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/PERF_LOAD_QA_PLAYBOOK.md"`
> 定位：回答"**上线天花板在哪**"——单 2 核 / 3.8GB 机的真实并发容量、瓶颈在哪一层、什么先崩。
> 由来：2026-06-16 首轮 just-in-time 跑出 Next 单 fork SSR 在 ~10 req/s 饱和、并发16 拥塞崩溃。

## 🔴 压测前安全铁律（破坏性，先判再压）
1. **先看真实流量 + 当地时间**：`tail nginx access log` + `TZ=Asia/Yerevan date`。有真实顾客在用就**别压公网**（会拖慢真人）。本项目当前近零流量，晚高峰也 0 请求 → 压测安全；这条会随上线改变，每次重判。
2. **隔离分层压**：压前端走 `localhost:3000`（绕 CF/nginx/限速，纯测 Next）；压后端注意 nginx 限速 1200/min/IP=20rps 会先挡住单 IP。
3. **有界**：固定时长(≤10s/档)、并发爬坡、全程 `uptime` 盯 load（>核数即过载）、看 pm2 重启数有没有跳（OOM）。
4. **只读端点**：`/home`、`/api/v1/config` 等 GET；**绝不压下单/支付/写库**端点。
5. 工具：服务器无 ab/wrk，用临时 Node 脚本（keep-alive + p50/p95/p99），**跑完即删**。

## 六轴
- **A 容量天花板配置**：php-fpm `pm.max_children`、pm2 exec mode/实例数、MySQL `max_connections`、nginx worker。
- **B 满载内存账**：每 worker 真实 RSS × 上限 + Next + MySQL + OS vs 总内存 → 会不会 swap/OOM。
- **C 空载延迟基线**：单发 curl 暖态 ttfb（前端 SSR / 后端 API 分开）。
- **D 并发饱和曲线**：并发爬坡 1→4→8→16，找"吞吐拐点"（吞吐不再涨只堆延迟）+ "崩溃点"（吞吐反降）。
- **E 缓存层**：页面/接口是否有 CDN/nginx/ISR 缓存（决定容量差几个数量级）。看响应头 cf-cache-status / cache-control。
- **F 慢点**：MySQL 慢查询日志、pm2 OOM 重启、跑飞进程（见 `OPS_QA_PLAYBOOK` 第1轴）。

## 2026-06-16 首轮基线（回归对照用）
- **配置(A)**：php-fpm dynamic max_children=50 / start5 / min5 max20；Next.js **pm2 fork 单实例**(port3000)；MySQL max_connections=500(峰值仅37)；nginx worker_processes auto worker_connections51200。
- **内存(B)**：php-fpm 每 worker ≈50MB → 50 满载≈2.5GB；Next 单 fork 184MB；机器 3.8GB 总 / 当前可用 ~2.1GB / swap 已用 ~450MB。**结论：max_children=50 对 3.8GB 偏大，API 满载会 swap。**
- **延迟(C)**：前端 SSR /home 暖 ~200ms(冷2.2s)；后端 API /config 暖 ~300-360ms。
- **饱和曲线(D, Next /home localhost)**：并发1=4.2rps/p50 199ms → 并发4=**10rps**/p50 337ms(拐点) → 并发8=9.9rps/p50 716ms(饱和) → 并发16=**5.8rps↓**/p50 1510ms/**p95 5982ms**/load 3.35(**拥塞崩溃**)。全程零错误(优雅排队不崩)。
- **缓存(E)**：🔴 首页 `cache-control:no-store` + `cf-cache-status:DYNAMIC` → **页面零缓存，每次访问都打单 fork SSR**。nginx 有 cache_one 区(5g)但 app 路由未启用。
- **慢点(F)**：MySQL 慢查询(>3s) 0 条；pm2 重启数 385(部署+OOM阈值800MB累加，单 fork 内存冲高即重启清空页缓存)。

## 天花板结论
**绑定瓶颈 = 无缓存的 Next 单 fork SSR，~10 页面渲染/秒封顶**（每个页面访问都过它）。粗算可撑"几十个同时浏览的用户"，晚高峰齐涌会排队到多秒。初期埃里温小社区够用；**任何增长/推广前必须先做缓存。**

## 修复建议（按性价比，均待用户拍板，多数影响线上行为勿擅改）
1. **🟢 最高杠杆：热页面加缓存**(home/餐厅列表等，个性化在客户端 localStorage→SSR HTML 可缓存)。nginx 微缓存 10-30s 或 Next ISR → 容量 10rps→数千。**先验 SSR HTML 无逐用户数据再做(防缓存投毒/串号)。**
2. **🟡 便宜翻倍：pm2 cluster `-i 2`** 吃满双核 → SSR ~10→18rps。代价：+184MB/实例 + 改 nzbuild.sh 重启逻辑。
3. **🟡 内存护栏：php-fpm max_children 50→~30**(L2 可调，防 API 满载 swap)。
4. **🟡 max_memory_restart 800MB** 单 fork 内存冲高即重启清空页缓存；缓存做了后压力自降，先监控。
5. **核数是 SSR 硬顶**：2 核封死；缓存杠杆用尽前不急于升配。
6. **站外 uptime 监控**(QA_MASTER §3)：拥塞变慢不触发崩溃告警，需站外探针。

## 复跑清单
- [ ] 重判压测安全(真实流量/当地时间)
- [ ] A 配置 4 项 / B 内存账 / C 延迟基线
- [ ] D 并发爬坡找拐点+崩溃点(对照上方基线看有无回归)
- [ ] E 缓存头 / F 慢查询+OOM重启
- [ ] 临时脚本删干净 + 确认机器 load 恢复

---

## 2026-06-17 修复轮 (修正首轮根因 + 已实施修复)

首轮结论被证伪: 首轮说瓶颈=Next单fork SSR渲染CPU 是错的(只看吞吐曲线没profile CPU去向)。压测时profile发现打/home时吃满CPU的是 php-fpm 不是 next-server(next-server仅13%CPU)。

真根因(全站性): 前端 src/pages/_document.js 的 getInitialProps 每个SSR页面每次请求都 fetch /api/v1/config/get-analytic-scripts (且该接口返回空数组纯空跑), 无缓存 => 把php-fpm打爆。这才是~10rps天花板真因, 与Next单核无关。教训记入轴D: 饱和时必profile CPU到底在哪个进程, 别只看吞吐就归因渲染。

已实施修复 (均服务器配置·非git·已备份·BT面板改站点设置可能覆盖需留意):
1. API fastcgi微缓存(核心): 新增 extension/api.nezha.am/nezha_apicache.conf + proxy.conf加fastcgi_cache_path区。仅缓存白名单 config 与 config/get-analytic-scripts 60s, 默认no_cache=1 fail-safe + 仅GET/HEAD + 不ignore Set-Cookie(三重保险,订单认证端点永不缓存,已验customer/info→302 BYPASS)。config已验全局(带不带token一致/en=zh/无Set-Cookie)。
2. php-fpm max_children 50→30, max_spare 20→15 (php-fpm.conf, 防3.8GB机swap, L2)。
3. 前端proxy_cache加language-aware键 (cache_key追加 cookie_languageSetting): 根治既有语言串味——全局proxy_cache一直缓存餐厅HTML但键不含语言, zh/en用户最长60s串味; 已验en/zh现分桶。
4. cluster试了并还原: 改ecosystem cluster -i 2实测吞吐零提升(瓶颈在后端非NextCPU), 已还原fork/1省内存。next start+pm2 cluster端口共享对CLI本就不灵。

修复后实测(/home localhost压测):
- 并发8: 9.9rps/p50 716ms → 177rps/p50 35ms (18倍)
- 并发16: 5.8rps崩溃/p95 5982ms → 221rps/p95 94ms (38倍), load 3.35→0.62
- 全站每个SSR页都受益(_document全站调用)。

回滚: 删nezha_apicache.conf + proxy.conf去掉fastcgi_cache_path行 + 前端proxy去掉proxy_cache_key行 + php-fpm.conf还原.bak, 各 nginx -t 后 reload / php-fpm reload。备份均在原文件旁 .bak.时间戳。

剩余建议(未做,待用户): 根治应在代码层——_document不该每请求空跑get-analytic-scripts(缓存或删,因返回空数组); 但改前端需构建,当时构建门被别窗口WIP卡住故走nginx层。下次构建顺手修_document后可撤掉API fastcgi缓存。
