# 顾客 H5 Telegram 登录决策与启用手册

## 已裁决范围

- 只接入顾客 H5，不改变商家端、骑手端或后台登录。
- 使用名称和头像与“哪吒外卖”一致的顾客登录专用 Telegram bot / OIDC client。
- 平台不发送短信 OTP。只在用户明确同意 `phone` scope 后读取 Telegram 已验证手机号。
- Telegram 手机号撞到老账号时不自动合并、不新建第二个账号；必须用该老账号的邮箱密码或同邮箱 Google 身份重新验证，成功后仅新增 Telegram 登录身份。
- 普通 Google 登录继续使用原接口和原账号逻辑。Google 只在 Telegram 撞号页被用户主动选择时作为“验证被锁定老账号”的方式；该分支不能创建或合并用户。

## 身份与状态规则

`user_external_identities` 是外部身份归属正本：每个 Telegram `sub` 只能归属一个顾客，每个顾客最多绑定一个 Telegram `sub`。

| 当前事实 | 处理 |
| --- | --- |
| Telegram `sub` 已绑定 | 始终登录绑定的顾客；手机号变化只记不含手机号的安全日志 |
| `sub` 未绑定、已验证手机号命中顾客 | 返回 `link_required`，要求验证命中的同一个顾客 |
| `sub` 未绑定、手机号未命中、允许新账号 | 在同一事务中创建顾客和 Telegram 身份 |
| `sub` 未绑定、手机号未命中、禁止新账号 | 返回 `registration_unavailable`，不创建数据 |
| 命中顾客已封禁或不存在 | 拒绝登录，不创建替代账号 |

Telegram 的 `phone` scope 返回 Telegram 已验证的 `phone_number`；官方协议不承诺另有 `phone_number_verified` claim。服务端在完整 OIDC 验签（签名、issuer、audience、nonce）后接受合法 `phone_number`，但若可选 `phone_number_verified` 明确为 `false` 则失败关闭。当前示例可能把号码表示为不带 `+` 的国际号码，服务端统一规范化为平台现有的 `+E.164` 格式后再撞号。

## 协议与安全边界

- Authorization Code + PKCE (`S256`)；服务端校验 `state`、`nonce`、JWT 签名、`iss`、`aud`、`exp` 和 `sub`。
- BotFather 高级签名算法使用默认 `RS256`，以支持 `profile` 和 `phone` scope。
- 浏览器只保存短期随机 browser secret；数据库只保存其哈希。授权码交换凭证、nonce 和临时 profile payload 均短期保存，敏感临时字段使用 Laravel encrypted cast。
- `user_external_identities` 与 `external_identity_login_attempts` 在 MySQL 5.7 上必须显式启用并复核 `ENCRYPTION='Y'`；keyring / 表空间加密不可用时 migration 失败关闭，不能静默落成明文表。SQLite 测试环境跳过 MySQL 专属 DDL。
- 临时登录 attempt 的 `expires_at` 是唯一清理时钟；`nezha:purge-external-identity-attempts` 每 15 分钟删除全部过期行，因此正常留存上界是 attempt TTL（默认 10 分钟）加一个调度间隔。`user_external_identities` 是防错绑所需的长期身份归属账本，不随临时 attempt 清理。
- 回调 URL 只携带一次性交换码，不携带手机号、Telegram token 或平台 access token。
- 日志不得记录手机号、Telegram token、Google credential、browser secret 或完整 claim。

官方协议正本：[Telegram Login OIDC](https://core.telegram.org/bots/telegram-login)。

## 配置与分阶段启用

默认保持关闭：

```dotenv
TELEGRAM_OIDC_ENABLED=false
TELEGRAM_OIDC_ALLOW_NEW_ACCOUNTS=false
TELEGRAM_OIDC_CLIENT_ID=
TELEGRAM_OIDC_CLIENT_SECRET=
TELEGRAM_OIDC_REDIRECT_URI=https://api.nezha.am/api/v1/auth/telegram/callback
TELEGRAM_OIDC_FRONTEND_URI=https://nezha.am/auth/telegram
```

staging 使用独立 origin / callback，并同样从关闭态起步：

```dotenv
TELEGRAM_OIDC_ENABLED=false
TELEGRAM_OIDC_ALLOW_NEW_ACCOUNTS=false
TELEGRAM_OIDC_CLIENT_ID=<由服务器秘密配置提供>
TELEGRAM_OIDC_CLIENT_SECRET=<由服务器秘密配置提供>
TELEGRAM_OIDC_REDIRECT_URI=https://api-staging.nezha.am/api/v1/auth/telegram/callback
TELEGRAM_OIDC_FRONTEND_URI=https://staging.nezha.am/auth/telegram
```

BotFather 已登记的 staging 值：

- Trusted Origin：`https://staging.nezha.am`
- Redirect URI：`https://api-staging.nezha.am/api/v1/auth/telegram/callback`

Client Secret 只进入服务器秘密配置；不进入 Git、构建参数、前端环境变量、聊天、截图或测试输出。

## 最低合规启用条件

- Telegram 登录的主要身份数据方向是 Telegram 向平台德国服务器返回 ID Token。德国在亚美尼亚主管机关的充分保护国家名单内，该托管链路无需事前跨境许可；不得再把“没有书面许可”写成 Telegram 登录的 NO-GO。
- 生产启用前须确定真实运营者的法定名称、登记信息、地址和可用联系入口，并在隐私政策与 Telegram 授权前入口使用同一组真实资料；占位符不得发布。
- 授权前中、亚双语告知只覆盖实际字段、创建/关联/登录账号目的、德国处理、拒绝后果和法定数据权利。无需新增复选框、GDPR 式制度或母语律师审批门；没有母语复核人不影响文本依法提供，但不得声称已经过母语法律审校。
- Telegram 登录与平台向 Telegram 转发客服/商家消息、向 DeepSeek 发送客服或翻译内容是不同数据流，分别按实际接收方和处理国家判断，不互相外推许可结论。

截至 2026-07-22，运营者身份仍待业主确定，线上隐私政策尚未补 Telegram 登录段落，因此生产继续保持关闭；这不是“所有跨境都要许可”的结论。

推荐顺序：

1. 在 BotFather 创建专用 bot，设置哪吒外卖名称和头像；进入 `Bot Settings > Web Login`。
2. 登记站点 origin 和精确回调 URL，安全保存 Client ID / Secret，不在聊天、提交、日志或截图中传递 secret。
3. 先部署 migration 和代码但保持 staging 的 `TELEGRAM_OIDC_ENABLED=false`；确认两张 MySQL 表实际为 `ENCRYPTION="Y"`、清理命令已注册且调度表达式为 `*/15 * * * *`。此时 H5 不展示入口。
4. staging 设 `ENABLED=true`、`ALLOW_NEW_ACCOUNTS=false`，验证已绑定登录、撞号密码验证、撞号 Google 验证、取消、拒绝手机号、过期和重放。
5. staging 通过并取得新的生产 Go 后，生产才可先以 bind-only 模式启用；确认真实 Telegram claim 与存量手机号格式后，再单独决定是否允许 Telegram 新注册。当前授权不包含 production 配置、migration、部署或开闸。

生产启用前必须清理 Laravel config cache 并重新缓存，随后确认 `/api/v1/config` 仅在配置完整且总闸开启时返回 `telegram` 登录方式。

## 回滚

首选回滚是把 `TELEGRAM_OIDC_ENABLED=false` 并刷新 config cache：H5 入口消失，新 Telegram 登录立即停止，既有 Google、邮箱密码和已绑定身份数据不受影响。

原有 Google 登录不因 Telegram 开关、临时 attempt 清理或 Telegram 表 migration 改变；只有撞号页的专用 Google 复核分支会读取 Telegram attempt，普通 Google 登录仍走原入口和原账号逻辑。

不要把删除 identity 表或解绑用户作为常规回滚。数据库 migration 的 `down()` 只用于无真实绑定数据的隔离或预发布环境；生产已有绑定后必须保留身份归属，以免未来把同一个 Telegram `sub` 错绑给其他顾客。

## 验收证据

- PHPUnit（PHP 8.3 + SQLite）：15 个测试、101 个断言；覆盖绑定归属优先、标准 `phone_number` claim（无非标准 verified 标记）、显式未验证/缺手机号拒绝、UTF-8 姓名兜底、手机号撞号、密码/Google 精确验证、封禁、注册闸、重放、JWT 失败、migration SQLite 幂等、过期清理与 15 分钟调度。
- MySQL 5.7.43 隔离实例：此前 migration、唯一约束、rollback、重跑均通过；本次新增的两表 `ENCRYPTION="Y"` fail-closed 断言需在 staging 部署前用更新后的 `TelegramIdentityMysqlProbe.php` 复跑，未复跑前不把表空间加密标为已验收。
- 2026-07-19 顾客手机号只读审计：8 个顾客中 7 个有手机号，全部为 `+E.164`，无规范化后重复；1 个为空。
- H5：production build 通过；320×720 与 390×844 无横向溢出，Telegram 入口、撞号页、密码提交和特殊 Google 失败收束均通过真实浏览器检查，console error 为 0。
