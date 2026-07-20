# Merchant two-factor authentication contract

Status: approved on 2026-07-19 as voluntary opt-in for merchant owners and
employees. Merchant 2FA must not be scheduled, defaulted, or indirectly required.

## Scope and invariants

- Every `vendors` owner and `vendor_employees` employee may independently enable
  TOTP from the authenticated Web profile. Secrets and recovery codes are never
  shared across people.
- A disabled actor completes Web login with the password and may receive an App
  bearer token. Legacy `two_factor_grace_pending` and `two_factor_required_at`
  values are inert compatibility data and never change access.
- An enabled actor with a valid secret must complete a TOTP challenge after the
  password before a Web session or App token is issued. An enabled actor whose
  secret is missing fails closed to recovery/setup; it is never downgraded to
  password-only access.
- Phase one has no App enrollment endpoint and no merchant self-disable endpoint.
  The existing authenticated Web profile is the only opt-in entry. Those product
  surfaces require a separate decision before introduction.
- Merchant Web login does not offer persistent authentication. It may remember an
  email address, but never a password or a fully authenticated merchant session.
- A recovery code is accepted only during login. Its atomic one-time consumption
  clears the old 2FA material, revokes every existing Web session and App token,
  and completes the current password-authenticated login with 2FA disabled. It
  never forces re-enrollment.
- Password change and payment-address confirm/reject always require the current
  password. A fresh, non-replayed TOTP code is additionally required only when
  that actor has voluntarily enabled 2FA. Recovery codes never approve sensitive
  step-up.
- Password reset, authenticator or recovery-code replacement, support recovery,
  restaurant suspension, and employee disable/delete revoke affected sessions
  and App tokens. Restaurant suspension covers the owner and every employee.
- Support never asks for a TOTP secret, TOTP code, or recovery code. Emergency
  recovery requires two distinct super-admin approvers, a reason, an audit event,
  complete revocation, and a no-secret notification. It leaves 2FA disabled.
- Challenge errors are non-enumerating and limited by account and IP. Only one App
  challenge remains active per actor. Audit metadata never contains a password,
  TOTP secret/code, recovery code, App token, cookie, or full session identifier.

## State machine

| State | Condition | Password result | Allowed next action |
| --- | --- | --- | --- |
| Optional | `two_factor_enabled=false`, regardless of legacy grace/deadline fields | Web session/App token issued | continue normally or voluntarily open Web profile setup |
| Challenge required | enabled with a secret | pending challenge only | submit TOTP or login-only recovery code |
| Inconsistent enabled | enabled without a usable secret | no normal access | recovery or explicit replacement/setup |
| Authenticated | current Web session/App token remains valid for the actor | normal access | logout, security management, conditional step-up |
| Disabled account | actor or owning restaurant inactive | no access | administrative reactivation; 2FA material is retained unless recovery reset is explicit |

`auth_generation` increases on revoke-all events. Web sessions carry the captured
generation and are checked on each guarded request; App tokens are cleared by the
same transitions. An authenticated 2FA management request must also pass current
actor/restaurant status and generation checks.

## Entry matrix and minimum UI

| Surface | Disabled actor | Enabled actor |
| --- | --- | --- |
| Web login | password creates full session | password creates only a five-minute pending state; TOTP/recovery completes login |
| Web profile | clearly labelled optional setup; cancel keeps the current session | manage recovery codes or explicitly replace the authenticator |
| App login | password issues token | returns a short-lived one-time challenge and no token until completion |
| App enrollment | not provided in phase one | not applicable |
| Password change | current password | current password plus fresh TOTP |
| Payment-address confirm/reject | owner current password | owner current password plus fresh TOTP |
| Recovery/support reset | disables 2FA and revokes all authentication | next password login is normal; re-enrollment remains optional |

Interrupted owner onboarding follows the same rule: password is sufficient while
2FA is disabled; an enabled owner must complete the challenge. Restaurant binding,
owner-only authorization, fingerprint checks, and the payment-address state machine
are unchanged.

## Persistence and concurrency

The already deployed actor, challenge, and event schema remains in place. No new
migration is required for voluntary mode. Secret material remains encrypted,
recovery codes remain hashed, setup secrets remain encrypted in the server-side
session, and secret/ticket/recovery/token responses remain `no-store, private`.

Enrollment, TOTP/recovery consumption, and security transitions use transactions
with row locks. An accepted TOTP counter must exceed the last accepted counter;
one App challenge or recovery code can therefore succeed at most once under
concurrency. The enforcement scheduling command and service are permanently
fail-closed and perform no writes.

## Release gates

1. Disabled owner/employee Web and App login pass for new, legacy-scheduled,
   expired, future, and null deadline rows. Enabled actors still challenge.
2. Recovery, step-up, revocation, and exactly-once semantics pass focused tests;
   database concurrency conclusions use a disposable MySQL 5.7.44 instance.
3. No migration delta or pending migration exists. Staging proves the optional
   profile setup/cancel flow, both login states, conditional sensitive fields,
   browser console/request health, and rollback.
4. Deploy the optional-access code before clearing legacy schedule values. After
   data cleanup, never roll back to a release that treats a null deadline as
   mandatory enrollment.
