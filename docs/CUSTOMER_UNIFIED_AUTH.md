# 顾客统一登录 / 注册裁决与实施契约

**裁决日期**：2026-07-22
**当前状态**：代码候选；未迁移、未部署、所有新开关默认关闭

## 1. 结论

顾客端采用一个统一入口，但“登录即注册”不是无条件创建账号：系统先验证邮箱所有权，再按可信身份状态决定登录、恢复旧账号或创建新账号。密码错误绝不触发注册；未知邮箱在验证码验证之前不暴露是否存在账号。

首轮只开放既有账号登录，注册保持关闭。完成邮件投递 canary、账号删除 V5 令牌前置门、法律版本与真实浏览器验收后，才能单独开放新账号创建。

## 2. 事实—缺口矩阵

| 风险 / 需求 | 当前生产入口与真实路径 | 组件、状态单源与数据来源 | 运行产物与工作树 | 真实证据 / 既有裁决 | 真实缺口 | 最小处理 |
|---|---|---|---|---|---|---|
| 登录页“登录 / 注册”语义 | `/profile`、结算拦截 → 既有 `AuthModal` | H5 `global.centralize_login`；API `business_settings` | 候选基于两仓最新 `origin/main`；尚未部署 | 测试页显示独立注册语义；现有 signup 前端不传 phone，而 API 要求 phone | 文案与真实能力不一致，旧注册链路不可用 | 复用 `AuthModal`；文案严格由登录 / 注册两个开关决定，不新建页面 |
| 任意邮箱能否登录 | 登录弹层手填邮箱 + 密码 | `CustomerAuthController::login` | 现网路径不因本候选自动改变 | 密码登录必须命中既有账号和密码 | 无邮箱所有权验证的统一入口 | 新增一次性邮箱 challenge；只有正确验证码后才判定身份 |
| 旧未验证邮箱被接管 | 邮箱 OTP 或 Google | `users.email_canonical` + `email_verified_at` + challenge | 新 schema 尚未迁移 | 旧库存在未验证邮箱和活跃 token | 仅按 email 相等合并会造成预注册接管 | 单条旧记录须“邮箱 OTP + 原密码”；多条歧义记录只走客服恢复 |
| Google 邮箱碰撞 | 现有 Google 按钮 / redirect 落地 | `user_external_identities(provider, subject)` | 外部身份表已有 migration；新绑定逻辑未部署 | 旧逻辑曾按邮箱进入 social merge | 邮箱不是稳定 provider 主键 | 先按 Google `sub`，再只绑定已验证 canonical owner；未知账号不自动创建 |
| 旧手机号 OTP 隐式注册 | 旧 `/auth/login`、Firebase verify API | `otp_login_status` + `users.is_phone_verified` | H5 已隐藏入口，但 API 仍可直调 | 旧代码在验证码正确后可 `new User` | 旁路可绕过统一注册与条款 | 服务端仅允许已验证既有手机号账号登录；未知 / 未验证账号拒绝 |
| 多入口令牌规则漂移 | 密码、OTP、Google、Telegram | `CustomerLoginFinalizer` | 候选未部署 | 旧控制器多处直接 `createToken()` | 账号状态 / 删除门可能被旁路 | 所有当前顾客 provider 只经 finalizer 签发；V5 删除门必须接入后才可开注册 |
| 手机 / 密码是否必填 | 新邮箱创建 | `users.phone/password` 可空 | schema 既有 migration 已允许可空 | 新用户没有必要先提供手机号或密码 | 旧 signup 强制 phone/password | 新邮箱注册只要已验证邮箱、称呼与显式条款同意；手机和密码为 `NULL` |

没有新增路由页面：入口、退出、弹层 owner 继续是 `AuthModal`；新增的只是弹层内可逆步骤状态。

## 3. 状态机

```text
输入邮箱
  -> 邮件投递成功 / 失败 / 限流
  -> 验证码正确
      -> verified canonical owner: 登录
      -> 单条 legacy raw owner: 原密码复核 -> 吊销旧会话 -> 认领 -> 登录
      -> 多条或无密码 legacy owner: 客服恢复
      -> 无 owner + registration=0: 明确不可注册
      -> 无 owner + registration=1: 显式同意条款 -> 创建无手机/无密码账号 -> 登录
```

challenge 包含随机 public id、浏览器秘密、OTP HMAC、完成令牌 HMAC、10 分钟 TTL、错误次数和单次消费状态；浏览器秘密只留组件内存，不进入 localStorage / sessionStorage。

## 4. API 契约

- `POST /api/v1/auth/email/start`：统一返回 `code_sent`，验证码前不返回账号是否存在。
- `POST /api/v1/auth/email/verify`：只会进入 `authenticated`、`legacy_link_required`、`registration_required` 或 `registration_unavailable`。
- `POST /api/v1/auth/email/legacy-password`：仅消费已经通过邮箱 OTP 的单条旧账号 challenge。
- `POST /api/v1/auth/email/complete`：仅消费 `registration_ready` challenge；必须提交称呼与显式条款同意。

Google 使用服务端 tokeninfo 校验 `aud`、`sub`、`email_verified`，稳定身份键是 `(provider=google, provider_subject=sub)`，不是邮箱。

## 5. 开关与发布顺序

| key | 默认 | 含义 | 启用前置 |
|---|---:|---|---|
| `customer_auth.legacy_signup_enabled` | false | 旧 sign-up / verify / update-info 注册链路 | 不得在生产开启 |
| `email_auth_mail_status` | 0 | 允许新 challenge 发邮件 | 邮件模板、发件域、收件 canary |
| `email_auth_login_status` | 0 | H5 展示并允许邮箱 OTP 登录 | migration、投递、既有账号登录与失败态验收 |
| `email_auth_registration_status` | 0 | 验证后允许创建新账号 | 登录阶段稳定；条款版本签收；V5 删除门接入 finalizer |
| `google_auth_registration_status` | 0 | 预留的 Google 新账号开关 | 当前没有合规完成页，禁止开启 |

顺序：部署时四个 business flag 保持 0 → migration 与加密元数据验证 → 登录-only staging → 邮件 canary → 只开 mail + login → 注册阶段另行批准。关闭 email login / registration flag 是行为回滚；身份 schema 与 consent 记录不做破坏性回滚。

## 6. 硬停点

- 发现 verified canonical 重复、不可 canonicalize 邮箱或意外 pending migration。
- MySQL 5.7 新表 `ENCRYPTION='Y'` 未确认。
- 邮件投递失败、验证码失败次数不持久、challenge 可重放或出现账号枚举。
- 账号删除 V5 尚未在 `CustomerLoginFinalizer` 的令牌签发前执行：允许登录-only 评估，不允许开放新账号。
- Google 新注册没有独立显式条款完成页：`google_auth_registration_status` 必须保持 0。

## 7. 验收证据要求

PHP 语法、单元 / Feature 测试、H5 compile-only build、两种开关语义、移动端全屏抽屉、桌面弹层、成功 / 错码 / 过期 / 邮件失败 / 登录-only 未知邮箱 / legacy 恢复 / 新注册条款 / Google 冲突均需覆盖。migration 必须在可丢弃的目标 MySQL 5.7 隔离库验证；SQLite 不能替代该结论。
