# Mail sender

Every site sends from `<local part>@<domain>` on the agency transactional
domain, with the site's Resend key restricted to that domain. So the site
commits only its local part in its `config/core-web.php`, and core-web
builds `mail.from.address`. It is always on and needs no feature switch:

```php
'mail' => [
    'from' => [
        'local_part' => env('MAIL_FROM_LOCAL_PART', 'acme'),
    ],
],
```

| Config | Default | Env var that overrides it |
|---|---|---|
| `core-web.mail.from.local_part` | none: without one, `mail.from` is left alone | `MAIL_FROM_LOCAL_PART` |
| `core-web.mail.from.domain` | `transactional.inly.dev` | `MAIL_FROM_DOMAIN` |

core-web fills the address only while `mail.from.address` is unset or
Laravel's stock `hello@example.com`, so `MAIL_FROM_ADDRESS`, or an address
the site's `config/mail.php` commits, still wins. A site's `mail` block keeps
the package's domain when it sets only the local part. Change the domain only
together with the key: Resend refuses a From address on a domain the key is
not restricted to. The sender's name stays Laravel's `MAIL_FROM_NAME`, which
defaults to `APP_NAME`.

## Sending through core-gateway

On a site Core Cloud provisions, mail goes through core-gateway instead of a
Resend key in the site's environment. core-web registers a `core` mailer
beside the site's own; a site sends through it only when its environment
names it:

```env
MAIL_MAILER=core
CORE_TOKEN=…
```

Core Cloud sets both, with the From address on the sending domain it set up
for the site. The mailer posts each message as Resend's JSON to
`https://gateway.inlycore.com/mail/emails` with `CORE_TOKEN` as the bearer
token, the `APP_URL` host as `X-Core-Environment` and the Message-ID as
`Idempotency-Key`: the same call as Core's own `core` mailer. core-services
sends it with the key restricted to the domain the message is from, so the
site never holds a Resend key, and a From on a domain Core Cloud did not set
up for the site is refused with a 403.

A send fails when `CORE_TOKEN` is empty, when the gateway refuses the
message, or when it cannot be reached in two attempts. A message needs a To address; copies and blind copies go with it.

| Config | Default | Env var that overrides it |
|---|---|---|
| `core-web.mail.gateway.url` | `https://gateway.inlycore.com` | none |
| `core-web.mail.gateway.token` | none | `CORE_TOKEN` |

A site that declares its own `core` mailer in `config/mail.php` keeps it.
