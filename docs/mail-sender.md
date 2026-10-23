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
