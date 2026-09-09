# Your first deployment

A step-by-step for getting HRBD ERP onto a real address you can open from any
device. Written to be followed without knowing how any of it works.

[DEPLOYMENT.md](DEPLOYMENT.md) is the reference version with every option and
setting. This is the short path.

---

## What you end up with

- The ERP at `https://erp.yourdomain.com`, reachable from your laptop, your
  phone, and the factory floor
- A managed PostgreSQL database that is backed up automatically
- Redis, a queue worker and the scheduler, all running without your attention
- Automatic deployment: when the code changes, the site updates

---

## What only you can do

Four things. Everything else I handle.

|                                | Why it has to be you                                          |
| ------------------------------ | ------------------------------------------------------------- |
| **Create the hosting account** | It is billed to you and owned by you                          |
| **Add a payment method**       | Obvious                                                       |
| **Change your domain's DNS**   | Only the domain owner can, and giving that away is a bad idea |
| **Choose passwords**           | I should not know them                                        |

Set aside about 30 minutes. Most of it is waiting for things to build.

**A note on cost:** Laravel Cloud bills by usage. A small ERP — one application,
a modest managed database, one queue worker — sits at the low end of their
range. I cannot see live pricing from here, so check
[cloud.laravel.com](https://cloud.laravel.com) before you commit, and tell me if
the numbers look wrong for your situation; a Forge-managed VPS is cheaper at
scale and I can prepare that instead.

---

## Before you start

Have ready:

1. **The domain** you want to use — `erp.yourcompany.com`, or whatever you
   prefer. It must be a domain you control the DNS for.
2. **A GitHub account** with access to `Prashant6192/HRBDERP`. You already have
   this; the code is pushed there.
3. **A payment card.**

---

## Step 1 — Create the Laravel Cloud account

1. Go to [cloud.laravel.com](https://cloud.laravel.com).
2. Sign up, and choose **Sign in with GitHub** when offered — it saves
   connecting the two later.
3. Add your payment method when prompted.

**Nothing is needed from me between here and Step 6.** Work straight through;
if a step does not look like this description, stop and tell me rather than
improvising.

---

## Step 2 — Connect the repository

1. **Create application** → choose **GitHub** as the source.
2. Grant access to `Prashant6192/HRBDERP` when GitHub asks.
3. Select the repository.
4. **Branch:** select **`main`**.

> ### About the branch
>
> `main` now exists and holds everything described here — it was created for
> exactly this purpose. Development continues on a working branch and lands in
> `main` when it is ready to go live, so what you deploy is always a deliberate
> choice rather than whatever was last saved.
>
> One optional tidy-up, which you have to do yourself because it is a
> repository setting: GitHub still lists the old working branch as this repo's
> _default_. It changes nothing about deployment — you pick the branch in
> Laravel Cloud regardless — but it makes GitHub open on `main` when you visit
> it. To change it: **GitHub → the repo → Settings → General → Default
> branch → switch to `main`**.

---

## Step 3 — Add the services

In the Laravel Cloud application, add:

| Service        | Setting                                                                     |
| -------------- | --------------------------------------------------------------------------- |
| **PostgreSQL** | Version 16. The smallest size is plenty to start                            |
| **Redis**      | Smallest size                                                               |
| **Worker**     | Command: `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600` |
| **Scheduler**  | Enable it. It runs `schedule:run` every minute                              |

---

## Step 4 — Environment variables

Laravel Cloud fills in the database and Redis values itself once those services
exist. Set these yourself, in the application's **Environment** settings:

```dotenv
APP_NAME="HRBD ERP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.yourdomain.com

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_ENCRYPT=true

LOG_LEVEL=warning

ERP_FORMULA_ACCESS_TTL_MINUTES=20
ERP_FORMULA_REQUIRE_PIN=true
```

`APP_DEBUG` **must** be `false`. With it on, an error page shows the contents of
the application's memory to whoever triggered it — including, eventually,
formulation data.

`APP_KEY` is generated for you. **Copy it into your password manager.** It
encrypts sessions and any encrypted column; a database restored without it is
partly unreadable.

---

## Step 5 — Deploy

Press **Deploy**. It takes a few minutes: installing dependencies, building the
frontend, running migrations, seeding the units, departments, roles and
permissions.

Demo accounts are **not** created — the seeder refuses when `APP_ENV=production`,
so no account with a published password ever exists on your live system.

---

## Step 6 — Point your domain at it **[you]**

1. Laravel Cloud shows the DNS record to add — usually a `CNAME` for
   `erp` pointing at their hostname.
2. Add it wherever your domain is managed (GoDaddy, Cloudflare, Namecheap…).
3. Wait. DNS usually takes minutes; occasionally an hour.
4. The certificate is issued automatically once DNS resolves.

---

## Step 7 — Two things to run once

Open the application's **Console** in Laravel Cloud.

**a. Lock the audit trail.** In the database console:

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM your_db_user;
```

This is what makes the audit trail genuinely tamper-proof rather than
tamper-proof-by-convention. Without it, the rule lives only in application code.

**b. Create your account.** Run `php artisan tinker`, then paste:

```php
$user = App\Models\User::create([
    'name' => 'Prashant',
    'email' => 'you@yourcompany.com',
    'password' => 'CHOOSE A LONG UNIQUE PASSWORD',
    'status' => App\Domain\Identity\Enums\UserStatus::Active,
    'email_verified_at' => now(),
]);

$user->assignRole(App\Domain\Access\Enums\RoleName::SuperAdmin->value);
```

Change the name, email and password first. Use a password manager — this account
can reach everything.

Then sign in and create everyone else through the interface, where each account
is recorded in the audit trail.

---

## Step 8 — Check it works

- [ ] `https://erp.yourdomain.com` loads with a padlock
- [ ] You can sign in
- [ ] A wrong password is refused, and repeated attempts get "Too many attempts"
- [ ] The dashboard shows zeroes — correct, there is no data yet
- [ ] Create a warehouse. It saves and appears in the list
- [ ] Open **Audit Log**: creating that warehouse is recorded, with your name
- [ ] Open it on your phone. The navigation collapses and the tables scroll

If all eight pass, it is live and working.

---

## Then tell me

Once you have clicked around, tell me what you want changed. Useful things to
report:

- Anything confusing, mislabelled, or in the wrong place
- Fields the business needs that are not there
- Anything that errors — copy the message
- Whether the terminology matches how your team actually talks

Then I will build the inventory ledger, which is what makes the stock figures
real, and the formulation module behind its second lock.

---

## If something goes wrong

**Deployment fails.** Copy the build log and send it to me. Almost always a
missing environment variable.

**"500 Server Error" after deploying.** Usually `APP_KEY` missing or the
database not connected. Check the application's logs in Laravel Cloud and send
me what they say.

**The site loads but is unstyled.** The asset build did not run. Re-deploy.

**DNS is not resolving after an hour.** Check the record was added to the right
domain and the name is exactly what Laravel Cloud asked for.

In every case: send me the error text. Do not start changing settings to see
what happens — that turns one problem into two.
