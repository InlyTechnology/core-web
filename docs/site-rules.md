# Site rules

`rules/` holds PHPUnit suites about how a site uses core-web. Inly's site
gate ([site-ci](https://github.com/InlyTechnology/site-ci)) copies every
`rules/*.php` into the site's `tests/Central` beside its central suites and
runs them, so a rule always matches the core-web version the site runs. A
suite extends the site's `Tests\TestCase` and skips what a switched-off
feature does not use. To run them on a site by hand:
`cp vendor/inly/core-web/rules/*.php tests/Central/ && php artisan test tests/Central`.

| Rule | Fails when |
|---|---|
| media disks are core-web's own | `features.media` is on and the site still defines a disk in `media.disks` itself (see [The disks](media.md#the-disks)) |

A new rule can turn a site's deploy red, so run it against the sites on the
gate before releasing it.
