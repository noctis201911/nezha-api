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

Telegram 当前 OIDC 示例把 `phone_number` 表示为不带 `+` 的国际号码。服务端只接受 `phone_number_verified=true`，并统一规范化为平台现有的 `+E.164` 格式后再撞号。

## 协议与安全边界

- Authorization Code + PKCE (`S256`)；服务端校验 `state`、`nonce`、JWT 签名、`iss`、`aud`、`exp` 和 `sub`。
- BotFather 高级签名算法使用默认 `RS256`，以支持 `profile` 和 `phone` scope。
- 浏览器只保存短期随机 browser secret；数据库只保存其哈希。授权码交换凭证、nonce 和临时 profile payload 均短期保存，敏感临时字段使用 Laravel encrypted cast。
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

推荐顺序：

1. 在 BotFather 创建专用 bot，设置哪吒外卖名称和头像；进入 `Bot Settings > Web Login`。
2. 登记站点 origin 和精确回调 URL，安全保存 Client ID / Secret，不在聊天、提交、日志或截图中传递 secret。
3. 先部署 migration 和代码但保持 `TELEGRAM_OIDC_ENABLED=false`；此时 H5 不展示入口。
4. staging 设 `ENABLED=true`、`ALLOW_NEW_ACCOUNTS=false`，验证已绑定登录、撞号密码验证、撞号 Google 验证、取消、拒绝手机号、过期和重放。
5. staging 通过并取得生产 Go 后，生产先以 bind-only 模式启用；确认真实 Telegram claim 与存量手机号格式后，再单独决定是否允许 Telegram 新注册。

生产启用前必须清理 Laravel config cache 并重新缓存，随后确认 `/api/v1/config` 仅在配置完整且总闸开启时返回 `telegram` 登录方式。

## 回滚

首选回滚是把 `TELEGRAM_OIDC_ENABLED=false` 并刷新 config cache：H5 入口消失，新 Telegram 登录立即停止，既有 Google、邮箱密码和已绑定身份数据不受影响。

不要把删除 identity 表或解绑用户作为常规回滚。数据库 migration 的 `down()` 只用于无真实绑定数据的隔离或预发布环境；生产已有绑定后必须保留身份归属，以免未来把同一个 Telegram `sub` 错绑给其他顾客。

## 验收证据

- PHPUnit：服务与 OIDC 共 10 个测试、70 个断言；覆盖绑定归属优先、手机号撞号、密码/Google 精确验证、封禁、注册闸、重放和 JWT 失败。
- MySQL 5.7.43 隔离实例：migration、唯一约束、rollback、重跑均通过。
- 2026-07-19 顾客手机号只读审计：8 个顾客中 7 个有手机号，全部为 `+E.164`，无规范化后重复；1 个为空。
- H5：production build 通过；320×720 与 390×844 无横向溢出，Telegram 入口、撞号页、密码提交和特殊 Google 失败收束均通过真实浏览器检查，console error 为 0。
