# 本地化 / i18n QA Playbook（第 9 层）

> **触发词**：`本地化QA` / `i18n QA`
> **查什么**：多语言完整性（中文用户不该见到英文）·币种 ֏ + ≈¥/$·日期数字时区格式·插值占位·语言决策。
> **读法**：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/I18N_QA_PLAYBOOK.md"`
> 由来：2026-06-17 首次系统化扫查（此前只有零散 i18n 修复轮 c9c8683/fb90dc0，无方法论）。

## 0. 前置认知（哪吒 i18n 怎么跑的，先懂再查）
- 前端 i18next，**活跃语言写死 `lng:'zh-CN'`，`fallbackLng:'en'`**（`src/language/i18n.js`）。
- 用 **i18next 自然键**：`t("Nearby Restaurants")` 的 key 本身就是英文原文。
  - zh-CN 有该 key → 显中文；**zh-CN 缺该 key → 回退 en → 直接显英文**（key 就是英文）。
  - **所以「英文未翻译」= zh-CN.js 里缺这个 key，或值仍是英文**。这是头号失败模式。
- 译文文件：`src/language/zh-CN.js`（中）/ `en.js`（英，自然键源）/ ar/bn/es。
- 后端 `translate()` 用 `resources/lang/zh/messages.php`，**两套别混**（memory [[nezha-frontend-i18n-location]]）。
- 🔴 **改 zh-CN.js 是多窗口热点**：先 `git status` 重查 + 备份 + 服务器端插入（别本地 get→整文件 push，会互相覆盖，参 [[zh-messages-whole-file-push-clobber]]）；改完精确 add 锁历史。

## 1. 八条失败模式轴（逐条走，每条要证据）

### A. 缺失/未译 key（头号）——「用到 ∩ zh-CN 缺失」才算真泄漏
静态扫"zh-CN 比 en 少多少 key"会**虚高**（StackFood 大量死键）。正确做法：
1. 提取**代码里真正 `t('字面量')` 用到的 key**（walk src，正则 `t(\s*('...'|"...")`）。
2. 对每个用到的 key：zh-CN 缺失 → **英文泄漏**；zh-CN 值 == key 且 key 是真英文词 → **未译**。
3. 过滤：key 本身是中文的（如 `"币安USDT"`）回退显 key=中文，**不算泄漏**；纯符号/数字（`"% OFF"` `"(A-Z)"`）不强译。
- 本轮：1004 个用到的 key 中，66 个 zh-CN 缺失（扣 3 个中文 key = 63 真候选），已全补译。

### B. 死代码 key——别把不渲染的 key 报成"用户看得到的英文"
"key 缺失"≠"用户会看到"。**先确认宿主组件真被 import、真渲染**再下结论（[[verify-users-actual-path-not-proxy]]）。
- 查法：`grep -rln 组件名`（无人 import = 死码）；被 import 仍要看是否被新组件替换。
- 本轮死码簇：`AppDownloadBanner`/`FooterTopSection`/`TrendingFoodTabs`/`Homes.js`/`landingpage/*` 全无人 import；旧地址弹层 `AddressReselectPopover` 已被 `SimpleLocationModal` 替换（06-16）。这些 key 译了无害（惰性条目）但**不渲染、无法像素验证**，报告里要如实标注。

### C. 硬编码英文/裸串——不走 t() 的连接词、模板字面量
扫描组件里**没进 `t()` 的可见英文**，尤其标题模板的连接词。
- 查法：`grep -rn ' on \${config\|} on {\|>[A-Z][a-z]* ' pages components`（按需调）。
- 本轮：8 个列表页标题模板 `${t('X')} on ${business_name}` 渲染成"最新餐厅 **on** 哪吒外卖"，裸 " on " 已统一改 ` · ` 中点（语言中立 + SEO 通用）。

### D. 币种 ֏ + ≈¥/$ 换算
正式币种德拉姆（`global.currency_symbol` + `getAmount`），价格旁须带 ≈¥/≈$（`PriceConvert`/`getConvertedHint`）。别写死 `$`/`¥`/`元`（[[nezha-currency-dram-with-conversion]]）。
- 查法：①语言文件值里的 `$/¥/元`（注意 `${...}` 模板会被误判，排除）②组件里 `grep -n '[\$￥¥][0-9]\|[0-9] *元'`。
- 本轮：语言文件无真币种 bug；组件 `home/index.js` 用 `{cur}` 变量（正确，非硬编码）；**`pages/supermarket/index.js` 天天特价是硬编码 `$0`/`$2.99` 假商品**（同时踩假数据轴，见下，未自动改=产品判断）。
- 🟢 遗留产品判断点：home 餐厅卡片 meta「{minOrder}起送」**无 ≈¥/$ 换算**（卡片密集，是否加待定）。

### E. 乱码 / mojibake
扫 zh-CN 值里的 `�`/`Â`/`Ã` 等替换字符（整文件 push 编码出错的征兆）。本轮：0。

### F. 日期 / 数字 / 时区格式
埃里温 UTC+4。后端裸墙钟序列化用 `App\Traits\SerializesLocalDates`（订单/聊天/会话已接），前端 moment 按浏览器时区换算——非 +4 浏览器会偏移。查订单/聊天/追踪时间。本轮沿用既有修复，未发现新偏移。

### G. 用户语言决策
`lng` 写死 zh-CN（面向埃里温华人合理）；语言切换器要显**语言中文名**不显代码（c9c8683 已修 zh-CN→中文名）。检查切换后 cookie `languageSetting` 生效、刷新保持。

### H. 插值占位被丢进 t()
带 `{{var}}` 或 JS 模板 `${...}` 的整句丢进 `t()` **永不匹配** → 恒显英文。要把插值拆成「可翻译前缀 + 变量」（ac7243c 找回密码、c9c8683 OTP 提示已拆）。本轮补译保留 `{{days}}` 占位。

## 2. 真机验证（QA 铁律——发现要证据，修完要证明）
- 移动端注入 `localStorage zoneid='[3,2]'` + `location='Yerevan, Armenia'` + GPS `40.1872,44.5152`，`locale:'zh-CN'`。
- **程序化取证比肉眼强**：抓 `document.body.innerText`，检测目标英文串是否出现（比翻截图可靠）。
- **默认页零泄漏≠没问题**：泄漏多藏在交互/条件/登录态后（设置面板、筛选、弹层、卡片营业状态、专用路由标题）。要驱动交互 + 走专用路由 + 区分空/假/真三态。
- 本轮证据：`/restaurants/latest`、`/restaurants/recommended` 标题改前显英文、改后中文（截图存档）；主流程 home/profile/餐厅列表/supermarket 改前后均零泄漏零 console error。
- ⚠️ 登录态可达面（如设置面板 Preferences/Dark/Light/语言）需登录过 captcha 才能像素验证；本轮代码确证其无条件渲染 + 已补译，但未登录像素复验（如实标注）。

## 3. 工具技法（脚本一次性、用完删；技法沉淀在此）
- 提取用到的 key：node walk src + 正则 `t(\s*('(?:[^'\\]|\\.)*'|"...")`。
- key→文件映射 + 死/活分类：对每个 key `new RegExp("t\\(\\s*(['\"])"+esc+"\\1")` 全 src 搜，宿主在死码名单则标 DEAD。
- 插入译文：读 JSON 增量 → 过滤已存在 key → 在末尾 `};` 前插入 → **eval 校验对象合法 + 计数核对**才写 + 带时间戳备份。
- 🔴 脚本须 base64 传（`push` 会折叠反斜杠毁正则）；凭证走 env、用完删（[[dev-script-exhaust-blindspot]]）。

## 4. 本轮（2026-06-17）结论
- **主流程本地化已相当完整**（前几轮 c9c8683/fb90dc0 打底好），无一进页面就英文的硬伤。
- **已修**：63 个缺失 key 补译（含设置面板/堂食/筛选/购物车/订阅等）；8 列表页标题 " on " → " · "。两轮构建健康门全 200。
- **遗留给用户拍板**（产品判断，未自动改）：①`supermarket` 天天特价假商品 + 硬编码 `$0`（假数据红线）②home 卡片 meta 无 ≈ 换算。
- **维护**：新增前端文案优先走 `t()` + 同步 zh-CN.js；新页标题别再用 ` on ` 连接。
