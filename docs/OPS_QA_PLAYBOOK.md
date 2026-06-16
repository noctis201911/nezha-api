# 哪吒 服务器 / 运维 QA Playbook

> **这是什么**：产品/前端 QA 只"透过浏览器看产品"，照不到服务器本身（负载、跑飞进程、磁盘、cron 是否在跑、备份是否成功、日志报错……）。
> 2026-06-16 一个孤儿空循环烧满 1 个 CPU 核 7 天、致全站间歇变慢，所有产品走查都没发现——就是因为没人查这一层。本 Playbook 专补这个盲区。
> **怎么用**：把下面【触发提示词】整段贴进任意 Claude 窗口即可；或任何窗口直接读本文逐轴执行。

---

## 【触发提示词】（复制这一段发给 Claude）

```
做一次"服务器/运维 QA"（不是产品QA）。逐轴执行 docs/OPS_QA_PLAYBOOK.md，每轴给"🟢正常/🟡注意/🔴异常"判定+证据(命令实测输出)，最后汇总🔴🟡清单+建议。只读取证不擅自改动；要 kill 进程/改配置先停下让我拍板。重点：揪出产品QA看不见的服务器层问题(跑飞进程/过载/磁盘/cron没跑/备份失败/日志报错/证书到期/服务挂了)。
```

---

## 体检轴（逐条要"实测证据"，不靠猜）

### A. 一键体检（先跑这个，覆盖 A 大半）
- `node nz.js run "bash /www/wwwroot/api.nezha.am/nzhealth.sh"`
- 看：负载 vs 核心数 / CPU TOP5 / 孤儿空转进程 / 内存 swap / 磁盘 / php-fpm 饱和 / 源站延迟。
- load 报🔴先看 TOP5 谁在吃（构建期 node 占 CPU 属正常）。

### B. 跑飞 / 孤儿进程（这次的病根）
- `ps -eo pid,ppid,pcpu,etime,args --sort=-pcpu | head` —— 揪 %CPU 高 + 跑很久的。
- 重点：**PPID=1（孤儿）且高 CPU**、`until/while` 空循环。确认无用 → 人工 kill（需用户授权；kill 非自己起的进程会被分类器拦）。

### C. 服务存活（任一挂了就是事故）
- `pm2 list`（nezha-web 在线?）· `systemctl is-active mysql nginx php-fpm-82 redis`（按实际服务名）。
- `curl` 自检：`curl -s -o /dev/null -w '%{http_code}' https://nezha.am/home` 和 `https://api.nezha.am/api/v1/config`。

### D. 定时任务是否真在跑（"配了" ≠ "在跑"）
- `crontab -l` 看条目还在。
- **Laravel 调度**：`node nz.js run "cd /www/wwwroot/api.nezha.am && php artisan schedule:list"` 看任务列表；近期是否执行看 `storage/logs/laravel.log`。
- 重点核对：sync-fx-rate(汇率)、3 个 PII purge(合规 L1-7)、备份——这些"沉默失败"没人会注意。

### E. 备份是否成功（出事才发现没备份=灾难）
- `tail -5 /root/nezha-backup/backup.log` 看最近一次时间 + 有没有报错。
- `ls -lh` 备份产物目录，确认最新备份文件**今天/昨天**有、体积正常（不是 0 字节）。
- ⚠️ 备份密钥 `/root/.nezha/backup.key` 还在。

### F. 磁盘 / 大文件 / 日志膨胀
- `df -h` 根分区 <85%。
- `du -sh /www/wwwroot/*/storage/logs /root/.pm2/logs /www/server/*/logs 2>/dev/null` —— 日志有没有暴涨吃满盘。
- 大文件：`find /www /root -type f -size +500M 2>/dev/null`。

### G. 日志里的报错（产品没崩但后台在刷错）
- 前端：`tail -50 /root/.pm2/logs/nezha-web-error-0.log`。
- 后端：`tail -50 /www/wwwroot/api.nezha.am/storage/logs/laravel.log`。
- nginx：`tail -50 /www/wwwroot/*/log/*error*.log` 或 `/www/server/nginx/logs/error.log`。
- php-fpm：慢日志 / `tail` fpm error。看有没有近期反复出现的 500/异常/超时。

### H. 延迟 / 性能（不只"能开",还要"够快·够稳")
- 源站直连(绕CF)采样 5 次：`for i in 1 2 3 4 5; do curl -s -k -o /dev/null -w '%{time_total}s\n' --resolve api.nezha.am:443:127.0.0.1 https://api.nezha.am/api/v1/config; done` —— 看有没有间歇尖峰(尖峰=有东西在抢资源，回 B)。
- 对比经 CF 与直连，定位慢在网络层还是应用层。

### I. 安全 / 访问（轻量扫一眼）
- `fail2ban-client status sshd` 近期封禁是否异常飙升。
- SSL 证书到期：`echo | openssl s_client -servername nezha.am -connect nezha.am:443 2>/dev/null | openssl x509 -noout -enddate`（CF 接管则看 CF；源站证书也别过期）。
- 异常登录 / 新增 root 进程 / webroot 里冒出的可疑脚本（参见收尾清单 §3）。

### J. 看门狗自身存活（盯防者也要被盯）
- `crontab -l | grep nzwatch` 在。
- `tail /tmp/nzwatch.log`（或其日志）最近有跑、无报错。
- 手测邮件链路：`bash /www/wwwroot/api.nezha.am/nzwatch.sh test`（会发一封测试邮件）。

---

## 判定与汇报
- 每轴给 🟢/🟜/🔴 + 证据；最后汇总所有 🔴🟡 + 建议动作。
- **只读取证**；要 kill 进程 / 改配置 / 删文件，先停下让用户拍板。
- 发现 🔴 但拿不准的，按 memory `[[surface-risks-proactively]]` 主动说明 + 给建议。
