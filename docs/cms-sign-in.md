# CMS sign-in

`features.cms_sign_in` makes Core the only way into the control panel. The
CP has no passwords: a person signs in to Core, Core hands them back with an
envelope sealed with its own key, and the site asks Core again before letting
them in, so Core's answer, not the envelope, decides what they get. Every CP
account is created by the hand-off, stamped with `core_user_id`, and holds no
password.

```php
return [
    'features' => ['cms_sign_in' => true],
    'cms_sign_in' => [
        // Core CMS grants => Statamic role handles. Admins become super users.
        'roles' => ['cms_editor' => 'editor'],
    ],
];
```

It needs the site's Core (`core.url` / `core.client_id`, or `CORE_URL` / `CORE_CLIENT_ID`) and `CORE_CLIENT_SECRET`: the site's
app client is the audience of every envelope and the token it calls Core back
with. No secret is shared with Core.

**Moving a site onto it.** A site that already signs its CP in from Core
with its own code removes that code in the same change that switches the
feature on, since both claim the same addresses:

- its hand-off route (usually `/admin-sso`) and controller, and its
  offboarding route (`/api/core/cms-users/revoke`);
- its CP login redirect middleware, its SSO user provider and the
  `auth.providers.*.driver` it set for it (put Statamic's `statamic` back; the
  feature swaps it);
- its reconcile command and that command's schedule entry;
- its own rate limiter and tests for these;
- any shared hand-off secret (`*_HANDOFF_SECRET`): only Core's own seal is
  accepted.

Switch the driver back to `statamic` before turning the feature on: with the
CP guard on any other driver the boot fails, `php artisan` included, so a
cached config holding the old driver has to be cleared by deleting
`bootstrap/cache/config.php`.

Map the roles its code granted in `cms_sign_in.roles`, and if its accounts
are stamped with something other than `core_user_id`, copy that value into
`core_user_id` first: an account without it can no longer sign in.

| Step | Request | Checks |
|---|---|---|
| Sign-in | `/admin/auth/login`, or any CP page a guest opens, redirects to `{core}/sso/cms?site=<key>&next=<path>`; Core hands back `GET /admin-sso?payload=…&signature=…&key_id=…&next=…` | Core's signature, `aud` = `CORE_CLIENT_ID`, expiry, purpose `cms_sso`, a `site` claim naming this site, the nonce spent here |
| Confirm | `GET {core}/api/app/cms-sso/client/verify?site=<key>&payload=…&signature=…&key_id=…` on the client's token | Core re-reads the person's access and answers `{user_id, name, email, is_admin, cms_roles, purpose}`. Only a 403 `{"error": "not_admitted"}` strips the person; any other failure is a 503 that changes nothing |
| Offboarding | Core posts `POST /api/core/cms-users/revoke`, sealed to the request (the `X-Core-*` headers, purpose `cms_revoke`), JSON body `{site, user_id, email}` | The account Core provisioned for that person is deleted, by a queued `ForgetCmsUserJob` |
| Reconcile | `core:reconcile-cms-users` (nightly, `cms_sign_in.reconcile_schedule`) posts `{site, emails}` to `{core}/api/app/cms-users/client/reconcile` on the client's token | Deletes accounts whose person is gone from Core, strips the grant of those without CMS access, takes super from those no longer admins. Every verdict must be complete before anything changes; `--dry-run` reports only |

What the feature holds the CP to:

- Password login, password reset, account activation, registration and
  passkey login are refused, in the CP and on the front end
  (`RedirectCpLoginToCore`, a global middleware).
- An expired CP session never asks for a password: Statamic's CP auth is
  switched off per request, so the session timer sends the browser to the
  login page, and from there through Core back to the page it was on.
- An account without `core_user_id` cannot sign in, and the hand-off never
  adopts one, whatever its address (`SsoUserProvider`, `CmsUsers::canBind`).
  The feature switches the CP guard's provider to the `core-web-sso` driver
  when it is Statamic's own (`statamic`); a site on another driver wires
  `SsoUserProvider` in itself.
- Roles assigned by hand outside `cms_sign_in.roles` are left alone; the
  mapped ones and the super flag are rewritten at every sign-in.
- Keep `users/` out of git, and out of `statamic.git.paths` where Statamic's
  git integration commits CP saves: accounts live on the server that created
  them, and every sign-in saves the account.

**Several sites, one Core.** `cms_sign_in.site` (`CORE_CMS_SITE`) is this
site's key in Core: the login redirect names it, the hand-off must carry it,
and the confirm and reconcile calls pass it. Every site needs an app client
of its own, since the client keeps a hand-off on the right host and the key
keeps it on the site it was minted for.

**Local development.** A local machine signs in through a local Core, set
as `CORE_URL` with a client of its own, exactly as a live site does: the
local Core must know the site under its `cms_sign_in.site` key
(`CORE_CMS_SITE`, `production` when unset) and serve https (`CORE_CA_BUNDLE`
for a self-signed certificate). Without one, a checkout with `content_sync`
on signs in through the live site instead: after `php artisan content:login`,
the CP login asks the live site who the machine's token belongs to (its sync
API's `me`) and signs into a local account mirroring that one's super flag,
roles and groups, stamped `core_web_live_user_id`. It asks on every sign-in,
so a revoked machine or a changed grant lands at the next one, and the live
site must run a core-web that answers `me`. A local account Core
provisioned (`core_user_id`) is never taken over, and the mirrored account
stops resolving once the machine's login is gone (`content:logout`). Offline,
`cms_sign_in.local_password_sign_in` set to `true` restores Statamic's own
login, and only while `APP_ENV` is `local`; with none of these, the CP login
answers 503, naming the ways in when `APP_DEBUG` is on. Data from production comes
through `content:login` and `content:pull` / `core:pull`, not through a
connection to the production Core.

Core serves the other half: `/sso/cms`, the confirm and reconcile routes and
the offboarding notices. Its paths are `cms_sign_in.core.*`.
