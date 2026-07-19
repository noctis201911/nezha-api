# Merchant two-factor authentication contract

Status: approved for implementation on 2026-07-19. The production deadline for
pre-existing merchant owners remains a release parameter until the owner supplies
the grace-period length.

## Scope and invariants

- Every `vendors` owner and `vendor_employees` employee has an independent TOTP
  secret and independent recovery codes. Secrets are never shared across people.
- Password verification alone never creates a full Web session or App bearer
  token when enrollment or a TOTP challenge is required.
- New accounts must enroll after their first successful password check. Existing
  accounts remain in persisted `two_factor_grace_pending` state until an exact,
  timezone-qualified deadline is explicitly recorded; after it expires,
  enrollment is mandatory on Web and App.
- Merchant Web login does not offer persistent authentication. It may remember an
  email address, but never a password or a fully authenticated merchant session.
- A normal TOTP code can complete login and sensitive step-up. A recovery code is
  accepted only on the login/recovery path; consuming it atomically revokes every
  session/token and forces fresh enrollment before access is restored.
- Confirming or rejecting a payment-address change is owner-only and requires the
  current password plus a fresh, single-use TOTP counter on every operation.
  Recovery codes are not valid for that step-up.
- Password change/reset, authenticator or recovery-code replacement, support
  recovery, restaurant suspension, and employee disable/delete revoke the affected
  actor's sessions and App token. Restaurant suspension covers the owner and all
  employees.
- Support never asks for TOTP or recovery codes. Emergency recovery requires two
  distinct super-admin approvers, a reason, an audit event, complete revocation,
  a no-secret notification, and re-enrollment.
- Challenge errors are deliberately non-enumerating. Attempts are limited by both
  account and IP. Only one App challenge remains active per actor, and repeated
  challenge creation is bounded. Audit metadata must never contain a password, TOTP secret, TOTP
  code, recovery code, App token, cookie, or full session identifier.

## State machine

An actor is in exactly one access state derived from its persisted fields:

| State | Condition | Password result | Allowed next action |
| --- | --- | --- | --- |
| Grace | 2FA disabled and either `two_factor_grace_pending` is true or `two_factor_required_at` is in the future | Web session/App token may be issued without persistence | enroll now |
| Enrollment required | 2FA disabled and deadline is null or due | pending challenge only | confirm a newly generated secret |
| Challenge required | 2FA enabled with a secret | pending challenge only | submit TOTP or login-only recovery code |
| Authenticated | current Web session/App token has the actor's current `auth_generation` | normal access | logout, security management, step-up |
| Recovery required | recovery code consumed or support reset performed | no normal access | enroll a new secret |
| Disabled | actor or owning restaurant is inactive | no access | administrative reactivation; 2FA state is retained unless recovery reset was explicit |

`auth_generation` is monotonically incremented on every revoke-all event. Web
sessions and App authentication must present the generation captured at issue
time; status and generation are checked on every guarded request.

## Fact-gap matrix and minimum UI

| Surface | Current source of truth | Missing state | Approved minimum |
| --- | --- | --- | --- |
| Merchant Web login | `LoginController`, `auth/login.blade.php`, vendor guards | pending enrollment/challenge | keep the existing login shell; add dedicated setup/challenge views; do not authenticate the guard before completion |
| Merchant profile | `Vendor/ProfileController`, `vendor-views/profile/index.blade.php` | authenticator and recovery management | add one security section to the existing profile; do not add a dashboard or separate application |
| Merchant App auth | vendor auth routes and `VendorLoginController` | one-time pre-token challenge | add short-lived one-time challenge endpoints; bearer token is returned only after completion |
| Payment address decision | `NezhaPaymentAddressChangeController` and the existing wallet-method partial | password + TOTP step-up | add the two fields inline to both confirm and reject forms; do not add a page |
| Recovery/support | login challenge and the existing admin CLI precedent | atomic recovery and audited dual approval | recovery stays in the login challenge; support recovery is CLI-only for phase one |

Interrupted owner onboarding is also authentication-bound: business-plan and
subscription writes require the exact restaurant recorded in the same browser
session after password and any required second-factor step. App subscription
writes require an owner bearer token issued after the same challenge boundary.

## Persistence contract

Both merchant actor tables carry encrypted secret material, hashed recovery codes,
the enforcement deadline, enrollment time, last accepted TOTP counter, and
`auth_generation`. A dedicated challenge table stores only a hash of an opaque App
ticket plus encrypted pending setup material, expiry, attempt count, and consumed
time. A dedicated event table stores actor/action/approver/reason and redacted
request metadata.

Web setup material is encrypted again before it is stored in the server-side
session. Responses containing a setup secret, challenge ticket, recovery codes,
or App token use `Cache-Control: no-store, private`.

All successful TOTP verification, recovery-code consumption, challenge
consumption, and security-state transitions use a database transaction with row
locking. An accepted TOTP counter must be greater than the actor's last accepted
counter. A challenge or recovery code can therefore succeed at most once even
under concurrent requests.

## Release gates

1. Migrations and concurrency semantics pass on a new disposable MySQL 5.7.44
   instance. SQLite is supplementary only.
2. Focused security tests, adjacent IAM tests, aggregate tests, syntax, formatting,
   and `diff --check` pass.
3. Staging proves owner and employee setup/challenge/recovery/revocation, App
   no-token-before-challenge, payment-address step-up, browser states, logs,
   migration state, and rollback.
4. Production enforcement is not scheduled until the existing-owner grace period
   is explicitly supplied. A fixed SHA, backup, migration plan, and immutable
   rollback point are required before release.
