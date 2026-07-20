# Merchant two-factor authentication contract

Status: approved on 2026-07-19 as voluntary opt-in for merchant owners and
employees. Merchant 2FA must not be scheduled, defaulted, or indirectly required.

Amended on 2026-07-20 (owner decision): merchant recovery codes are withdrawn
entirely, merchants self-disable from the authenticated Web profile with a
current password plus a fresh TOTP code, and admin emergency reset requires one
super-admin approver with an optional second. The voluntary opt-in direction of
the 2026-07-19 decision is unchanged.

## Scope and invariants

- Every `vendors` owner and `vendor_employees` employee may independently enable
  TOTP from the authenticated Web profile. Secrets are never shared across
  people, and no bearer backup codes are issued at all.
- A disabled actor completes Web login with the password and may receive an App
  bearer token. Legacy `two_factor_grace_pending` and `two_factor_required_at`
  values are inert compatibility data and never change access.
- An enabled actor with a valid secret must complete a TOTP challenge after the
  password before a Web session or App token is issued. An enabled actor whose
  secret is missing fails closed to admin reset or setup; it is never
  downgraded to password-only access.
- Phase one has no App enrollment endpoint. The existing authenticated Web
  profile is the only opt-in entry, and it is also where a merchant
  self-disables 2FA with a current password and a current TOTP code. App
  enrollment remains a separate decision before introduction.
- Merchant Web login does not offer persistent authentication. It may remember an
  email address, but never a password or a fully authenticated merchant session.
- Self-disable is accepted only from an authenticated Web session and requires
  the current password plus a fresh, non-replayed TOTP code. It clears the 2FA
  material and revokes every existing Web session and App token; the acting
  browser session is immediately re-established so the merchant is not signed
  out. Login never accepts a bearer code in place of TOTP, and an actor who
  lost the authenticator has no self-service path and uses admin emergency
  reset.
- Password change and payment-address confirm/reject always require the current
  password. A fresh, non-replayed TOTP code is additionally required only when
  that actor has voluntarily enabled 2FA. No credential other than a fresh TOTP
  code can satisfy that second factor.
- Password reset, authenticator replacement, merchant self-disable, admin
  emergency reset, restaurant suspension, and employee disable/delete revoke
  affected sessions and App tokens. Restaurant suspension covers the owner and
  every employee.
- Support never asks for a TOTP secret or a TOTP code. Emergency recovery
  requires one super-admin approver, with an optional second approver, a
  reason, an audit event, complete revocation, and a no-secret notification.
  It leaves 2FA disabled.
- Challenge errors are non-enumerating and limited by account and IP. Only one App
  challenge remains active per actor. Audit metadata never contains a password,
  TOTP secret/code, App token, cookie, or full session identifier.

## State machine

| State | Condition | Password result | Allowed next action |
| --- | --- | --- | --- |
| Optional | `two_factor_enabled=false`, regardless of legacy grace/deadline fields | Web session/App token issued | continue normally or voluntarily open Web profile setup |
| Challenge required | enabled with a secret | pending challenge only | submit TOTP |
| Inconsistent enabled | enabled without a usable secret | no normal access | admin reset or explicit replacement/setup |
| Authenticated | current Web session/App token remains valid for the actor | normal access | logout, security management, conditional step-up |
| Disabled account | actor or owning restaurant inactive | no access | administrative reactivation; 2FA material is retained unless an admin reset is explicit |

`auth_generation` increases on revoke-all events. Web sessions carry the captured
generation and are checked on each guarded request; App tokens are cleared by the
same transitions. An authenticated 2FA management request must also pass current
actor/restaurant status and generation checks.

## Entry matrix and minimum UI

| Surface | Disabled actor | Enabled actor |
| --- | --- | --- |
| Web login | password creates full session | password creates only a five-minute pending state; a TOTP code completes login |
| Web profile | clearly labelled optional setup; cancel keeps the current session | self-disable with a current password and TOTP code, or explicitly replace the authenticator |
| App login | password issues token | returns a short-lived one-time challenge and no token until completion |
| App enrollment | not provided in phase one | not applicable |
| Password change | current password | current password plus fresh TOTP |
| Payment-address confirm/reject | owner current password | owner current password plus fresh TOTP |
| Admin emergency reset | disables 2FA and revokes all authentication | next password login is normal; re-enrollment remains optional |

Interrupted owner onboarding follows the same rule: password is sufficient while
2FA is disabled; an enabled owner must complete the challenge. Restaurant binding,
owner-only authorization, fingerprint checks, and the payment-address state machine
are unchanged.

## Persistence and concurrency

The already deployed actor, challenge, and event schema remains in place. No new
migration is required for voluntary mode. Secret material remains encrypted,
setup secrets remain encrypted in the server-side session, and
secret/ticket/token responses remain `no-store, private`.

Enrollment, TOTP consumption, self-disable, and security transitions use
transactions with row locks. An accepted TOTP counter must exceed the last
accepted counter, so one App challenge can succeed at most once under
concurrency. Self-disable takes the same row lock and the same strictly
increasing counter, so the same argument applies to it by construction; that
argument has not yet been re-proven against MySQL 5.7 (see Release gates).
The enforcement scheduling command and service are permanently fail-closed and
perform no writes.

## Release gates

1. Disabled owner/employee Web and App login pass for new, legacy-scheduled,
   expired, future, and null deadline rows. Enabled actors still challenge.
2. Self-disable, step-up, revocation, and exactly-once semantics pass focused tests;
   database concurrency conclusions use a disposable MySQL 5.7.44 instance.
3. No migration delta or pending migration exists. Staging proves the optional
   profile setup/cancel flow, both login states, conditional sensitive fields,
   browser console/request health, and rollback.
4. Deploy the optional-access code before clearing legacy schedule values. After
   data cleanup, never roll back to a release that treats a null deadline as
   mandatory enrollment.

Outstanding as of the 2026-07-20 amendment: gate 2's MySQL 5.7 leg has not been
re-run for `disableTwoFactor` (`NezhaMerchantTwoFactorMySql57ConcurrencyTest` is
env-gated behind `NEZHA_MERCHANT_2FA_MYSQL57_CONCURRENCY=1` and no disposable
5.7.44 instance was available), and gate 3's staging walkthrough of the new
self-disable form has not been performed. The in-memory SQLite suite covers the
logic; neither result should be read as a MySQL 5.7 concurrency conclusion.
