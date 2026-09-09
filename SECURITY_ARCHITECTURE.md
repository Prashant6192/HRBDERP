# Security architecture

This document describes how HRBD ERP decides who may do what, how the
formulations are protected, and what the audit trail guarantees.

The short version: **authorisation happens on the server, before any data is
loaded.** The interface hides what a user cannot do as a courtesy. It is never
the control.

---

## 1. Authentication

Laravel Fortify handles sign-in, sign-out, password reset and email
verification. Passwords are hashed with bcrypt through Laravel's hasher; no
password is ever stored, logged, or written to the audit trail.

| Concern          | How it is handled                                                                                                                     |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Sign-in          | Email and password, throttled per email and IP                                                                                        |
| Password storage | bcrypt via Laravel's `hashed` cast                                                                                                    |
| Password reset   | Signed, expiring tokens                                                                                                               |
| Session          | Encrypted cookies; regenerated on sign-in                                                                                             |
| Two-factor       | Fortify's TOTP support, already wired                                                                                                 |
| Passkeys         | Fortify passkeys, already wired                                                                                                       |
| SSO              | Not yet configured — the structure supports adding Google Workspace and Microsoft as Socialite drivers without changing authorisation |

### There is no self-registration

`/register` does not exist. This is a company ERP, not a public product:
accounts are created by an administrator under **Administration → Users**, who
also assigns the roles that decide what the person can reach.

A self-registered account would hold no roles and so could do nothing useful —
but it would still be an unexplained row in the user list, and a foothold for
anyone who found the address. Two tests assert the routes are absent, so
re-enabling the package's registration feature has to be a deliberate act with
a failing test to explain itself.

Password reset remains open, so an employee who forgets their password does not
need an administrator.

### Account state takes effect immediately

An account carries a status: `active`, `inactive` or `suspended`. Only `active`
may authenticate.

Checking that at sign-in alone would not be enough. The case that matters is an
employee who is _already signed in_ when their access is withdrawn — blocking
only the next sign-in would leave them working until their session expired.
`EnsureUserIsActive` therefore runs on every authenticated web request: a
deactivated account is signed out on its next request, and the sign-out is
recorded.

Accounts are deactivated, never deleted. A departing employee's name is
attached to years of stock movements and approvals, and those records have to
keep resolving to someone. Deletion is soft; the row survives.

**Nobody can delete or deactivate their own account.** Locking yourself out is
the one mistake with no way back.

---

## 2. Authorisation

### The permission catalogue

`App\Domain\Access\PermissionCatalogue` is the single source of truth for all 97
permissions. Every name follows `<module>.<ability>` — `inventory.receive`,
`formula.approve`, `raw_material.import`.

Nothing outside that class invents a permission name. The seeder builds the
permission table from it, roles are defined in terms of it, and the test suite
asserts that every role resolves to permissions that actually exist. A typo
becomes a failing test rather than a silent hole.

### Where it is enforced

Three layers, in order:

1. **Route middleware** — everything under `auth` + `verified` + `EnsureUserIsActive`.
2. **Policies** — every controller action calls `authorize()` before it touches
   data. One policy per module, all extending `ModulePolicy`, which maps the
   standard verbs onto `<module>.<ability>`.
3. **The database** — foreign keys, unique indexes and `CHECK` constraints, so
   that even a bug in the layers above cannot write an impossible row.

The frontend receives the user's permission list so it can hide unusable
controls. That list is presentation only. A user who edits it in the browser
console gets a nicer-looking page and exactly the same 403s.

### The Super Admin bypass, and its one exception

Super Admin passes every permission check through a `Gate::before` hook rather
than holding thousands of individual rows.

There is a deliberate exception: **a Super Admin does not bypass a check about
their own account.** The rules forbidding self-deletion, self-deactivation and
granting yourself a role exist precisely for the account most able to lock the
company out of its own ERP, or to quietly widen its own access. A blanket
bypass would waive those rules for the only user they were written for. Acting
on their own record, a Super Admin goes through the ordinary policy; they still
hold every permission row, so nothing legitimate is lost.

**Only a Super Admin may assign roles**, and never to themselves. Role
assignment is the one permission that can be used to grant every other, so it
is gated more tightly than `user.edit`.

---

## 3. Formula protection

Product formulations are the company's principal trade secret. Holding
`formula.view` is necessary but **not sufficient**.

> **Status:** the security model below is designed and its configuration is in
> place (`config/erp.php`, the `formula_pin_hash` column, `User::setFormulaPin`
> and `verifyFormulaPin`, and the audit actions). The formulation module itself
> is the next thing to be built — see
> [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md). This section is the
> specification that module will be built against, not a description of code
> that already runs.

### Three gates

To see a formulation a user must:

1. Be authenticated with an active account.
2. Hold `formula.view`.
3. Clear a **second verification** — a dedicated formula PIN, or a
   re-entry of their account password, configurable by `ERP_FORMULA_REQUIRE_PIN`.

Clearing the third gate grants a short-lived unlock, defaulting to 20 minutes
and bounded to between 5 and 60 by `config/erp.php` so the setting cannot be
widened into a permanent unlock. When it expires, verification is required
again.

The PIN is hashed exactly as a password is. It is never stored in readable
form, never returned by any endpoint, and is on the model's `$hidden` list so
it cannot leak through serialisation. Verification fails closed: an account
with no PIN set returns false rather than throwing, so a caller cannot tell
"wrong PIN" from "no PIN" by the exception type.

Failed verifications are throttled — five attempts, then a fifteen-minute
lockout — and each one is written to the audit trail.

### Where formula data must not appear

Authorisation happens **before** the server loads formulation data, not after.
The following are explicit non-goals of any formulation endpoint:

- HTML source and the Inertia page payload
- Frontend JavaScript, and anything reachable from the browser console
- API responses to unauthorised callers
- Debug logs and exception pages
- Notifications and their previews
- Exports and reports
- Search results and autocomplete
- Laravel Telescope — which must not be installed in production at all

`APP_DEBUG` must be `false` in production. A Laravel exception page renders
local variables, and a stack trace through the formulation service would
display the very data this section exists to protect.

### Versioning

Formulations are versioned, never overwritten. A change creates a new version;
earlier versions are archived and stay readable. Only one approved version is
active at a time. Every view of a formulation is recorded — "Director viewed
Hydra Smooth Shampoo V4" is an audit entry in its own right, which is why
`AuditAction::FormulaViewed` exists separately from `Updated`.

---

## 4. The audit trail

`audit_logs` is append-only. It records who, what, which record, the values
before and after, the IP address, the user agent, the session and the route.

The user is recorded twice: as a foreign key, and as a name and email copied at
the time of the action. The copy is what survives if the user record is later
renamed or removed, and it is what a reader of a two-year-old entry actually
needs. The same is true of the record label — an audit entry still names the
warehouse it refers to after that warehouse is gone.

### Immutability, in three layers

1. **The model** throws on `updating` and `deleting`.
2. **There is no route** that writes to it. A test asserts that no verb other
   than `GET` is registered against any audit URL, so there is nothing to
   authorise incorrectly.
3. **The database.** In production, revoke write access from the application
   role. This is the layer that actually matters, because the first two only
   bind code that goes through Eloquent:

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hrbderp;
```

Run it once, after migrating, as the database owner. Inserts continue to work.

### What is never written

Hidden attributes are stripped before anything reaches the table: passwords,
the formula PIN hash, remember tokens, two-factor secrets and recovery codes.
A failed sign-in records the email that was attempted and never the password
that was tried. There is a test for each of these.

---

## 5. Files

Documents — quotations, invoices, certificates of analysis, QC reports,
artwork — are stored through Laravel's filesystem. Development uses local
storage; production should use S3 or Cloudflare R2.

Buckets must be **private**. Files are served through authorised download
routes that check a policy and then stream the object, or through short-lived
signed URLs. A file must never be reachable by guessing its URL.

---

## 6. Production checklist

Before the system holds real company data:

- [ ] `APP_DEBUG=false` and `APP_ENV=production`
- [ ] `APP_KEY` generated and backed up somewhere other than the server
- [ ] HTTPS enforced, HSTS enabled
- [ ] `SESSION_ENCRYPT=true`
- [ ] Database password not the development default; database not reachable from the public internet
- [ ] `REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs` applied
- [ ] Object storage buckets private
- [ ] Laravel Telescope absent
- [ ] Demo accounts absent — they are seeded only outside production, but confirm
- [ ] Every account has a real password; `password` appears nowhere
- [ ] Backups running **and a restore rehearsed** — see [BACKUP_RESTORE.md](BACKUP_RESTORE.md)
- [ ] Error monitoring receiving events
- [ ] Queue worker and scheduler running under a supervisor
