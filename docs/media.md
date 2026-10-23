# Media

`features.media` renders a site's pictures and videos through the
transforming CDN (core-cdn), whatever they are: a Statamic Asset, an assets
field (its Value, Collection or query builder), a URL, or a file from Core
(`url`/`original_url`, `alt`, `focus`/`focal_point`, `width`, `height`,
`mime_type`). It also defines the asset disks on the site's bucket.

## The components

```blade
<x-core-img :src="$set['image']" :widths="[480, 960, 1440]" sizes="(min-width: 1024px) 50vw, 100vw" ratio="4/3" class="h-full w-full object-cover" />
<x-core-video :src="$set['video']" class="h-full w-full object-cover" />
<x-core-media :src="$set['media']" ... />
```

- **`<x-core-img>`**: an image under a CDN origin gets `format=auto` (the CDN
  picks AVIF, WebP or the original per browser), the `width`, `height`,
  `quality`, `fit` and `trim` you pass, a srcset from `widths`, and a 24px
  blurred placeholder (`placeholder="none"` turns it off). `ratio` reserves
  the box and bounds each rendition's height. Anything else (a local file, a
  foreign host, an SVG) renders as is, without CDN transforms. A local
  Asset (JPEG, PNG or WebP) still gets a srcset from the site's Glide
  presets named `w<px>` (`w640`, `w1080`, …), those narrower than the
  original plus the original at its own width, and `sizes` defaults to
  `100vw`: pass a real `sizes`. A `w<px>` preset resizes by width only
  (`fit=max`), or its candidates come out the wrong shape. Only presets are
  rendered, so keep
  `statamic.assets.image_manipulation.generate_presets_on_upload` on (files
  uploaded before the presets existed render on their first view); if any
  preset fails to render, the image has no srcset. The editor's focal point (an
  Asset's `focus`, Core's `focalPoint`) becomes `object-position` unless you
  pass a `style`, and `alt` defaults to the file's own.
- **`<x-core-video>`**: a video under a CDN origin plays CDN versions, 1280px
  wide and 720px on phones (`width`, `small-width`), silent when it plays
  muted (`audio=false` makes them smaller), with a frame of the video as its
  poster (`poster-time`, or `poster` for an image of your own) and a 24px
  version of that poster blurred behind it until the poster or the first
  frame arrives (`placeholder="none"` turns it off; `media.images.placeholder`
  sets the default for videos too). Anything else plays the
  file itself. It autoplays muted in a loop by default: `:autoplay`,
  `:muted`, `:loop`, `controls`, `preload`.
- **`<x-core-media>`**: one or the other, by the source, with each getting only
  its own options: for a field an editor fills with either.

`width` and `height` on `<x-core-img>` are the rendition's size, not HTML
attributes: give the box its size in CSS or with `ratio`, or the page shifts
as the image arrives. The video poster is one frame at `width`, for phones too.

The CDN builds a video version on its first request (30 to 60 seconds for a
large file) and keeps it a year, so open each new video once after uploading.
Its limits are Cloudflare's: a source under 100 MB and 10 minutes, and a
version at most 60 seconds long.

The placeholder and the reduced-motion pause need the shipped CSS and script:

```css
@import '../../vendor/inly/core-web/resources/css/core-media.css';
```

```js
import coreMedia from '../../vendor/inly/core-web/resources/js/core-media.js'

coreMedia() // before Alpine.start()
```

## The disks

Each disk in `media.disks` is defined unless the app's
`config/filesystems.php` has one by that name, so **remove the site's own
`assets` disk** (Statamic's skeleton ships one) and any other it lists there;
`media:check` says when the app's definition is the one in use (on Laravel
Cloud a `local` one is moved to the bucket instead). The bucket
needs `composer require league/flysystem-aws-s3-v3`.

| Where | With `CORE_MEDIA_KEY` | Without |
|---|---|---|
| production | the bucket | the local folder `public/<folder>` |
| staging before go-live (`CORE_MEDIA_LAYER=false`) | the bucket | the local folder |
| Laravel Cloud, with a bucket attached and no key | its bucket (no layer unless `CORE_MEDIA_LAYER=true`) | n/a |
| `local`, pulling content (`features.content_sync` and a `remote_url`) | the bucket, behind a layer | the live site's pulled index, behind a layer |
| anywhere else | the bucket, behind a layer | the local folder |

The layer is what makes the shared bucket safe outside production. Writes,
edits (an asset's `.meta` included) and deletes land in it, reads try it
first, and a file only the bucket holds cannot be deleted or moved: staging
and a laptop see production's files and never change them. On staging the
layer is `_staging/` in the bucket; in the `local` environment it is
`public/media-layer/` (add it to `.gitignore`).

Before go-live there is no production content to protect, and staging is
often where editors build it, so a staging server set up then gets
`CORE_MEDIA_LAYER=false` and writes the bucket directly. It is a server's
setup, not the site's code, so it is env: `main` and `develop` stay alike.
At go-live, remove it from staging's env (the launch checklist's step): the
layer is on from then on, and what staging uploaded before is already in the
bucket at the paths production serves, so nothing is copied. A production
server on a temporary domain writes the bucket anyway.

Refusals are loud: deleting, renaming or moving one of production's files
throws `ProductionFileLocked` whatever the disk's `throw`, so Statamic stops
before it strips the asset from entries or saves its new path. A layer file at
the same path as one of production's hides it, and deleting it shows
production's again.

A file in the layer renders from the layer's URL in the components. Anywhere
else, Statamic's `Asset::url()` points at the bucket, where it is not: use
`Inly\CoreWeb\Media\MediaSource::urlOf($asset)` for a URL of your own (an
og:image, a feed). The control panel's links to it 404; its thumbnails, which
Glide reads through the disk, show.

Without a key a disk is its committed local folder: a fresh clone, CI and a
server missing the key all render from it. The exception is a laptop that
pulls content (`APP_ENV=local`, `features.content_sync` and `features.media`
on, `content_sync.remote_url` set): there the disk reads three layers, first
match wins. On top is `public/media-layer/`, as with a key: uploads, and
edits to a pulled file's alt or focus, land there and survive the next
pull. Below it the committed folder `public/<folder>`, read only. At the
bottom the live site's asset index as `content:pull` last brought it
([Content pull](content-pull.md) → Assets), so no key is needed to see and
use production's files: it lists them, serves their `.meta` from the index
and reads their bytes from their public URLs (Glide's CP thumbnails fetch
them over HTTP, a file at a time, never following a redirect). Deleting or
moving a committed or pulled file throws `ProductionFileLocked`. Before the
first pull the disk shows the layer and the committed folder.

In this mode `Asset::url()` is a local route, `/!/core-web/media/<disk>/<path>`
(registered only when `APP_ENV` is `local` or `testing`), that redirects to
whichever layer holds the file: the layer's or the committed folder's URL,
else the live file's public URL from the index, else a 404. Statamic builds
every asset's URL on its container's one URL, so the route is what lets a
local upload and a live file both resolve. The components skip the redirect
and render the file's own URL, so the CDN still transforms live files. So
does the CP: a file only the index holds gets its thumbnail from the CDN at
the CP's thumbnail size (the plain public URL when the CDN does not serve
it; a video a CDN frame, or no thumbnail) and its preview from its public
URL. Glide could read it through the index, but would download every live
file to make one thumbnail the CDN already renders. Uploads and committed
files keep Glide's thumbnails.

Everything but the key and secret is committed in the site's
`config/core-web.php`. Unlike the other top-level keys, a site's `media`
block keeps the package's defaults for what it leaves out, one group deep
(the key and secret from env, the CDN, the layer); `disks` is taken whole:

```php
'media' => [
    'bucket' => [
        'endpoint' => 'https://ams3.digitaloceanspaces.com',
        'region' => 'ams3',
        'bucket' => 'my-site',
        'url' => 'https://cdn.inlycore.com/my-client/my-site',
    ],
    'disks' => ['assets' => 'assets', 'files' => 'files'],
],
```

Peak's `favicons` and `social_images` containers keep their handles but
write through their disks, so they can be media disks too: list them and
remove their `local` definitions from `config/filesystems.php`. On Laravel
Cloud the `cloud` feature does that for them, and for any `local` disk in
`media.disks`. For R2, set `endpoint` to the account's R2 endpoint and
`acl` to `false`: R2 has no object ACLs, so the bucket itself is public.

## Moving a site's own bucket disk onto these

A site with a hand-rolled S3 disk keeps its bucket paths and URLs. With an
old disk of `root: web` and `url: https://cdn.example.com/site/storage/`:

```php
'media' => [
    'bucket' => [
        'endpoint' => 'https://ams3.digitaloceanspaces.com',
        'region' => 'ams3',
        'bucket' => 'site',
        'url' => 'https://cdn.example.com/site/storage',
    ],
    'disks' => ['assets' => 'web'],   // the folder the files already sit in
],
```

The disk's key is `root/<folder>`, as before, and its URLs are
`url/root/<folder>/<path>`. Then point the container at the disk (`disk:
assets` in `content/assets/<container>.yaml`), delete the old disk from
`config/filesystems.php` and its variables from `.env.example`, and set
`CORE_MEDIA_KEY`/`CORE_MEDIA_SECRET` on every server before the deploy that
switches (a server without them falls back to the local folder). A server
that runs a non-production `APP_ENV` while it serves the live site sets
`CORE_MEDIA_LAYER=false` until launch. Developers delete the old variables
from their `.env` and run `content:pull --only=assets`.

## On Laravel Cloud

With no `CORE_MEDIA_KEY`, the disks use the bucket Laravel Cloud attaches to
the environment: the entry in Cloud's `LARAVEL_CLOUD_DISK_CONFIG` whose disk
is `media.bucket.cloud_disk` (`CORE_PUBLIC_DISK` when set, else `media`, the
public bucket Core Cloud attaches to a website), else the first that is not a
scoped disk, gives the key, secret, bucket, endpoint and region. Core Cloud
attaches a private bucket beside it for form uploads, so the name keeps the
asset disks off that one whatever order Cloud lists them in. A key in config still wins. Cloud's
buckets are R2, so `acl` is `false` for them whatever config says. The files'
URL is the committed `media.bucket.url`, else `CORE_CDN_BASE_URL`, which Core
Cloud's CDN integration sets to the CDN in front of the public bucket (used
only when the bucket was found by name, so it is that bucket), else the
bucket's own URL, which serves files untransformed. The first two are CDN
URLs, so the components transform the files. A committed CDN URL must have this Cloud
bucket as its origin, or new uploads land in one bucket while their URLs
point at another.

Cloud attaches a bucket per environment, so a Cloud bucket gets no layer: a
Cloud environment before go-live (on `APP_ENV=staging` for noindex) writes
its bucket directly, as editors building the content need. A Cloud staging
environment that shares production's bucket sets `CORE_MEDIA_LAYER=true`.

- Name the Cloud bucket's disk anything but a disk in `media.disks`
  (`assets`, `files`): Cloud registers its disk before core-web, so a clash
  leaves Cloud's plain disk in place of core-web's, and `media:check` reports
  it as the app's own.
- The public bucket answers CORS itself, since core-cdn redirects every
  untransformed file to it: allowed origins `*`, for `GET` and `HEAD`.
  `media:check` reports a bucket that does not.
- The first deploy copies the git-tracked images into the bucket with
  `php artisan media:sync push --force`, on the Cloud credentials like any
  other key.

## Commands

- **`media:sync push|pull [--disk=] [--dry-run] [--overwrite]`** copies each
  disk's files, `.meta` folders included, between its committed local folder
  and the bucket, and never deletes. `push` is the move to a bucket: it adds
  the files the bucket lacks and leaves the rest, since a file of a different
  size there is usually an editor's later change (`--overwrite` replaces
  those). It asks first; `--force` skips the question. `pull` refreshes the
  committed folder from the bucket. Neither touches a layer. Dry run first; a
  second dry run after a push finds nothing.
- **`media:check [path] [--disk=assets]`** says what the disk is here and, with
  a key, checks a file (the first image when no path is given): its URL is
  under a CDN origin, the raw file answers with `Cache-Control` and CORS, and a
  CDN rendition answers with an image.

## Another CDN

`media.cdn.scheme` names the `Inly\CoreWeb\Media\UrlScheme` that builds the
transform URLs: `serves()` (which URLs it transforms), `image()`, `video()`
and `frame()`. `CoreCdnScheme` speaks core-cdn's v1 query params and clamps
every value to the ranges the CDN accepts.
