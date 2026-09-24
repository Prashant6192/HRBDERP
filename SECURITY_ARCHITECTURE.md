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
| Password reset   | Emailed single-use link, 60 minutes; see [Password reset](#password-reset) below                                                      |
| Session          | Encrypted cookies; regenerated on sign-in; every other session ends when the password changes                                         |
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

### Password reset

An employee who forgets their password asks for a link on the sign-in card,
receives it by email, and chooses a new one. No administrator is involved.

- **The form reveals nothing.** Every well-formed request gets the same reply —
  "if that address belongs to an account here, a link is on its way" — whether
  the address is unknown, deactivated, rate-limited or real. The framework's
  own reply ("we can't find a user with that email address") is replaced, so
  the form cannot be used to find out who works here.
- **A deactivated account gets no email**, and a link issued before the account
  was withdrawn is refused when used. A reset link is never a way back in.
- **Rate limits.** Three requests per address per fifteen minutes, after which
  the next ones send nothing but get the same reply, so the limit itself says
  nothing about the address. Ten requests per network per fifteen minutes,
  after which the form says to wait, since that limit is the same for
  everyone. On top of the framework's one request per account per minute.
- **The link** is single-use and expires after 60 minutes. The email says when
  and from which browser and IP address it was requested, and that ignoring it
  changes nothing.
- **Afterwards,** every other session signed in with the old password is
  signed out on its next request (`AuthenticateSession`, independent of the
  session driver), "keep me signed in" cookies stop working, a forced
  password change is cleared, and the reset is written to the audit trail.
  Two-factor authentication still applies at the next sign-in.
- **Sent at once, not queued.** Nothing else in the ERP runs through a queue
  yet, and a reset email waiting on a worker that was never started would fail
  without anyone knowing. A mail provider refusing is logged for us and not
  shown to the visitor, since telling them would say the address exists.

It depends on mail being configured: see the mail settings in
[DEPLOYMENT.md](DEPLOYMENT.md). With the default `MAIL_MAILER=log`, the email
is written to the log and nobody receives it.

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

`App\Domain\Access\PermissionCatalogue` is the single source of truth for all 116
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

### Facility assignments

Permissions say what; assignments say where. `FacilityAccess` sits beside
the policies: a stock action at a facility — booking opening stock, posting
a goods receipt, approving, starting or completing a manufacturing order,
dispatching or receiving a transfer, raising a plan — needs the permission
**and** an active `employee_assignments` row for that facility (or for the
specific store, when the assignment is that narrow). Denials are ordinary
403s. Super Admin, Owner, Director and Management act company-wide, and so
does a person with no assignment at all: assignments narrow, they never
grant, so nothing that worked before facilities existed stops working until
an administrator assigns people. Pickers (facilities, stores) are scoped the
same way, and that scoping is presentation only — the check runs again on
every write.

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

### Three gates

To see a formulation a user must:

1. Be authenticated with an active account.
2. Hold `formula.view` — which opens only the list of formula names, codes
   and statuses. No ingredient is loaded for that screen.
3. Clear a **second verification**: the formula PIN (4–8 digits), or a
   re-entry of the account password when `ERP_FORMULA_REQUIRE_PIN=false`.

The third gate is the `formula.unlocked` middleware (`EnsureFormulaUnlocked`)
on every route that would load a recipe — viewing, editing, scaling,
importing. A user without a current unlock is sent to verify (or to set a PIN
first), with the page they wanted remembered.

Clearing it grants an unlock of `ERP_FORMULA_ACCESS_TTL_MINUTES` (default 20),
clamped by `config/erp.php` to between 5 and 60 so no environment setting can
turn it into a permanent one. The unlock is bound to the browser session
through a random token kept in session data and stored hashed on the
`formula_unlocks` row: another session of the same account — a phone, a
second laptop — must verify for itself. Verifying again anywhere revokes
every other unlock the account holds. Locking, changing the PIN and an
administrator's PIN reset all revoke it early.

The PIN is set with the account password and hashed exactly as a password is.
It is never stored in readable form, never returned by any endpoint, and is on
the model's `$hidden` list. Verification fails closed: an account with no PIN
returns false rather than throwing, so a caller cannot tell "wrong PIN" from
"no PIN" by the exception type. Five failed attempts lock the module for
fifteen minutes; the unlock endpoint is additionally rate-limited.

### The access trail

`formula_access_logs` records every unlock, failed attempt, lockout, lock,
PIN change, view, scaling, edit, activation and import, with formula, version,
IP and user agent. The model refuses updates and deletes; production should
`REVOKE UPDATE, DELETE, TRUNCATE` on it as on `audit_logs`. Security events
(unlock, failure, PIN change) are also written to the general audit trail;
views are not, so the audit timeline does not double as a record of who
studies which recipe.

### Where formula data must not appear

Authorisation happens **before** the server loads formulation data, and the
implementation keeps recipes out of:

- the formula list and every other screen — only the unlocked recipe screens
  carry ingredients, and their responses are sent
  `Cache-Control: no-store` so no proxy or back-forward cache keeps them;
- the general audit log — `FormulaIngredient` is deliberately not audited,
  because an old/new diff of that row _is_ the recipe, and the audit log is
  readable by roles that may not see formulations; a test asserts no
  percentage or ingredient reaches it;
- the frontend bundle, logs, notifications and exception pages (`APP_DEBUG`
  must be `false` in production; Laravel Telescope must not be installed);
- uploaded import workbooks — held in private storage under a random token
  only while the plan is reviewed, and deleted the moment the import commits
  or is discarded; the plan itself is never written to a log.

What **does** carry quantities, by design, are production plans, material
requests and manufacturing orders: the store must know how many kilograms of
each material a batch takes, and purchase must know what to order. Those
screens sit behind `planning.view`, `purchase.view` and `production.view`,
which the Designer and no outside party hold. They show quantities for one
batch; they do not show the recipe, and they are the normal working papers of
any factory.

### Versioning

Formulations are versioned, never overwritten. A change is a new draft
version that supersedes the active one on activation; earlier versions stay
readable. The database guarantees one active version per formula with a
partial unique index. Every view of a version is a row in the access trail.

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
php artisan erp:lock-audit-trail   # REVOKE UPDATE, DELETE, TRUNCATE on both trails
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

### Artwork

Artwork — a client's or the company's own — is stored on the private `local`
disk under `clients/artworks/` and opened only through `artworks.document`,
which allows anyone who may see the product, the client or production; the
client page's own route additionally checks the client. The file is what the
packing line matches the pack against, so the floor sees it; it is never
reachable by URL alone.

### Backups

A backup from Administration → Data holds every table, password hashes and
formulations included, and every upload. It is produced only for the Super
Admin, over HTTPS, streamed and deleted from the server as it is sent; the
copies kept before a restore sit under `backups/` on the private disk. Treat
the file as the most sensitive thing the company has: encrypt it at rest
wherever it is kept, and keep it off the web server.

### Supplier bills

A bill uploaded on the goods receipt screen is stored on the private `local`
disk under `goods-receipts/intake/` and served only through
`goods-receipts.document`, which checks the receipt's view policy and streams
the file. Its contents are sent to Anthropic's API to be read; nothing else
goes with them — no stock, price or formula data — and the reply is treated as
untrusted until a person has looked at it on the screen. A bill the reader
could not read is deleted at once; a bill nobody books a receipt from is not
attached to anything and may be pruned.

Keying a receipt in by hand, or changing quantities, rates and batch details
the reader found, needs `purchase.receive_manual`, held by the Factory Manager
and Super Admin by default. Everyone else books receipts from the scan alone.

### Transfer challans and transport documents

A dispatched stock transfer carries an eight-character inward code, printed
under the QR on its challan. The code is a workflow gate, not a secret in the
cryptographic sense: it proves the paperwork that travelled with the lorry
reached the destination, so nothing is booked in against a consignment that
has not arrived. It is therefore kept off the screen at the destination —
only someone who can dispatch from the source facility may print the challan,
and the transfer's page never sends the code to the browser. The gate is
enforced in `StockTransferService::receive`, so no route books in unverified
stock. Comparison is constant-time.

The transporter's invoice or LR uploaded at the scan is stored on the private
`local` disk under `stock-transfers/inward/` and served only through
`transfers.document` behind the transfer's view policy. It is sent to
Anthropic's API only to be read for the transfer it names; a document that
names another consignment, or that cannot be read, is deleted at once.

### Marketplace labels

Label PDFs carry customers' names and addresses. They are stored on the
private `local` disk under `online-orders/` exactly as uploaded and served
only through `online-orders.files.show`, which requires the right to print
or upload labels **and** that the person may see the batch: an E-commerce
Agency account only its own brands, everyone else only the facilities they
are assigned to. The response is `private, no-store`. Printing reassembles
pages in the browser from that response; nothing is written back to the
server.

Meesho and Flipkart labels are read on the server from their own text and
go nowhere else. Amazon and Myntra labels (pictures) are sent to
Anthropic's API to be read — the file alone, with nothing else from the
ERP — and the reply is shown as read, never trusted as fact: a parcel whose
AWB or product could not be read is flagged, and packing checks the label
against the ERP by scanning it.

The agency's account belongs to an outside party. Its role grants two
abilities, the menu shows it nothing else, and every route it can reach
checks the brand. Give it only the brands it runs, and deactivate the
account when the agency changes.

---

## 6. Production checklist

- [ ] `php artisan erp:lock-audit-trail --check` reports `audit_logs` and `formula_access_logs` locked
- [ ] `ERP_FORMULA_REQUIRE_PIN=true` and the access TTL within 5–60 minutes
- [ ] Every person who holds `formula.view` has set their own PIN

Before the system holds real company data:

- [ ] `APP_DEBUG=false` and `APP_ENV=production`
- [ ] `APP_KEY` generated and backed up somewhere other than the server
- [ ] HTTPS enforced, HSTS enabled
- [ ] `SESSION_ENCRYPT=true`
- [ ] Database password not the development default; database not reachable from the public internet
- [ ] `php artisan erp:lock-audit-trail` run (the `REVOKE` on `audit_logs` and `formula_access_logs`)
- [ ] Object storage buckets private
- [ ] Laravel Telescope absent
- [ ] Demo accounts absent — they are seeded only outside production, but confirm
- [ ] Every account has a real password; `password` appears nowhere
- [ ] Mail configured and a password reset tried end to end on your own account
- [ ] Every account's email address is one its owner actually reads
- [ ] Backups running **and a restore rehearsed** — see [BACKUP_RESTORE.md](BACKUP_RESTORE.md)
- [ ] Error monitoring receiving events
- [ ] Queue worker and scheduler running under a supervisor
