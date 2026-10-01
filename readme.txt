=== Beltoft Media Offload ===
Contributors: internal
Tags: media, offload, s3, minio, storage
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offloads media to any S3-compatible bucket. A server rule serves files missing locally from the bucket, so local copies can be deleted safely.

== Description ==

Beltoft Media Offload uploads WordPress media (the original, every
generated size, the unscaled original WordPress keeps for large photos,
and `.webp`/`.avif` siblings a plugin like beltoft-webp places next to
them) to an S3-compatible bucket: AWS S3, MinIO, DigitalOcean Spaces,
Cloudflare R2 and others.

How it stays simple and safe:

* **Saved content holds local upload URLs.** Front-end pages link
  offloaded media to the bucket through WordPress's own URL filters
  (attachment URLs, srcset, images in post content). The admin, editors,
  page builders, AJAX, REST, cron and WP-CLI keep local URLs, so what
  editors save doesn't depend on the bucket, and taking files out of the
  bucket or deactivating the plugin breaks no links. (Page caches and
  data other plugins build while a page is viewed can still hold bucket
  URLs; page caches are purged when bucket URLs stop working.)
* **A web server rule serves files missing locally from the bucket.** A
  request for an upload that isn't on disk is redirected to the same path
  in the bucket. That covers every link to every file, wherever it's
  stored: content, page builders, CSS, emails, other plugins' data,
  caches. On Apache the rule is written to the uploads directory's
  `.htaccess` automatically; on nginx you add a short snippet shown on the
  settings page.
* **Everything is checked the way a visitor sees it.** A test file is
  fetched from the bucket's public URL (front-end pages link to the
  bucket only when that works), and a file missing locally must redirect
  there while files on disk are still served ("Delete local files" only
  acts when that works). Internal addresses (like a Docker host name)
  are refused. Checked on every settings change, daily, and with
  `wp media-offload check-server`; a failing check leaves the previous
  rule in place, and a check older than three days pauses local deletes.
* **One bucket per site.** Endpoint, bucket, path-style and SSL can't
  change while media is offloaded, since the rule sends every missing
  file to one place.

= Features =

* Automatic offload on upload; bulk tool (Media > Bulk Offload) and
  WP-CLI for existing media. Uploads are streamed and verified.
* Optional "Delete local files after upload". Image editing, cropping,
  thumbnail regeneration and code that asks for it
  (`beltoft_media_offload_ensure_local()`, the
  `beltoft_media_offload_fetch_on_miss` filter) download files back when
  needed; they're removed again later.
* Deleting an attachment deletes its bucket files. Failed deletes are
  retried hourly. Attachments sharing a file (media translations with
  Polylang or WPML, duplicators) keep it until the last one is deleted.
* New uploads don't reuse the name of an existing attachment's files or
  of a file in the bucket.
* WooCommerce's protected downloads (`woocommerce_uploads/`) and any paths
  you list are never offloaded.
* Works with plugins that generate files in the background (beltoft-webp):
  local files are kept until they're done.
* Multisite: each site uses its own settings and key prefix
  (`sites/<id>/`).

= WP-CLI =

* `wp media-offload status`
* `wp media-offload check-server` — write the Apache rule and verify the
  server rule
* `wp media-offload run [--batch=<n>]` — offload everything pending
* `wp media-offload resync` — upload files added to offloaded attachments
  outside WordPress (runs after `wp beltoft-webp backfill`)
* `wp media-offload retry-deletes`
* `wp media-offload finish-deferred [--force]`
* `wp media-offload restore <id>... | --all` — bring files back locally
* `wp media-offload unoffload <id>... | --excluded` — take files out of
  the bucket for good

= Licensing =

Everything works without a license. A free key only enables automatic
updates through a self-hosted updater (beltoft.net), so `wp plugin check`
reports `plugin_updater_detected`; that's expected for a plugin not
distributed through WordPress.org.

= Limitations =

Without a working server rule (nginx config you can't change, a host that
blocks the site from requesting its own URLs), "Delete local files" stays
off; offloading and bucket URLs on the front end still work. Hosts that
block loopback requests but have the rule in place can confirm it with
the `beltoft_media_offload_server_rule_verified` filter.

Code that reads media straight from disk outside the contexts above won't
find deleted files; use `beltoft_media_offload_ensure_local()` there.

Buckets can't choose a format by the browser's `Accept` header, so
`.webp`/`.avif` siblings are uploaded but offloaded images are served in
their original format. To keep serving WebP/AVIF by negotiation, leave
local files in place and filter `beltoft_media_offload_bucket_urls` to
false. On nginx, the snippet's uploads location replaces other locations
for uploads: add cache headers and WebP/AVIF `try_files` variables
there.
Files over 500 MB aren't offloaded during upload; use the bulk tool or
WP-CLI. The vendored async-aws/s3 and Symfony HTTP Client are not
namespace-prefixed.

The uploads `.htaccess` rule is kept on deactivation and uninstall, so
files whose local copy is gone keep working.

== Installation ==

1. Upload the plugin and activate it.
2. Settings > Media Offload: enter the endpoint, bucket, credentials and,
   if the bucket is served through a CDN or custom domain, the public
   domain. Use Test Connection.
3. On nginx, add the snippet shown under "Server rule" and reload nginx.
   Check that the status says "Working".
4. Turn on "Enabled" and, if you want, "Delete local files after upload".
5. Offload existing media under Media > Bulk Offload or with
   `wp media-offload run`.

== Frequently Asked Questions ==

= Does this work with AWS S3? =

Yes, and any S3-compatible service. Leave the endpoint blank for AWS S3
in the configured region; turn off path-style addressing unless your
bucket name contains dots. The bucket (or the CDN in front of it, set as
the public domain) must allow public reads.

= Why a server rule instead of rewriting URLs? =

Links to media end up everywhere: post content, page builders, CSS,
emails, other plugins' tables, caches. Rewriting them all in WordPress
is never complete. A server rule answers every request for a missing
file, wherever the link came from.

= Do I need a license key? =

No. It only enables automatic updates.

== Changelog ==

= 2.0.0 =
* Initial release.
