# CF7 Prevent Duplicate Submissions

A lightweight WordPress plugin that stops [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) from sending the same submission twice.

## What it does

- Hashes each submission's field content (ignoring CF7 internals, WP nonces, reCAPTCHA, honeypot fields, tracking params like `utm_*`/`gclid`/`fbclid`, and file upload temp paths) to build a stable signature.
- If an identical signature was seen recently, the submission is blocked and the visitor sees a friendly notice instead of a duplicate email going out.
- Disables the form's submit button while a request is in flight, and re-enables it on success, validation error, spam flag, or mail failure — so double-clicks and slow connections can't trigger duplicate sends in the first place.
- Lock duration is configurable from the WordPress admin.

## Installation

1. Download this repository as a ZIP (or clone it).
2. Upload the `cf7-prevent-duplicates.php` file's containing folder to `/wp-content/plugins/`, or zip it up and use **Plugins → Add New → Upload Plugin** in wp-admin.
3. Activate **CF7 Prevent Duplicate Submissions** from the Plugins screen.
4. Requires [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) to be installed and active.

## Settings

Go to **Settings → CF7 Duplicate Protection** in wp-admin to set the duplicate-submission lock period (in hours or days). Default is 1 hour.

## How it works, briefly

The plugin hooks into CF7's `wpcf7_before_send_mail` filter. On each submission it builds a normalized, recursively-sorted copy of the posted data, strips out fields that legitimately vary between otherwise-identical submissions (nonces, tokens, tracking params, etc.), and hashes what's left with MD5. That hash is stored as a WordPress transient for the configured duration. If the same hash arrives again before the transient expires, the send is aborted and the form displays a message asking the visitor to modify the content or wait.

## Versioning

Releases are tagged to match the `Version` header in `cf7-prevent-duplicates.php` (e.g. `v2.0.1`), so you can track what's installed against what's tagged here.

## License

GPL-2.0+. See [LICENSE](LICENSE).
