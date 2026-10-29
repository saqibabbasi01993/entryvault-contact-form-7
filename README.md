# EntryVault for Contact Form 7

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/entryvault-contact-form-7?label=version)](https://wordpress.org/plugins/entryvault-contact-form-7/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/entryvault-contact-form-7)](https://wordpress.org/plugins/entryvault-contact-form-7/#reviews)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/entryvault-contact-form-7)](https://wordpress.org/plugins/entryvault-contact-form-7/advanced/)
[![Requires WP](https://img.shields.io/wordpress/plugin/wp-version/entryvault-contact-form-7)](https://wordpress.org/plugins/entryvault-contact-form-7/)
[![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/entryvault-contact-form-7)](https://wordpress.org/plugins/entryvault-contact-form-7/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

> Capture, manage, search, filter, and export Contact Form 7 submissions from a clean admin dashboard. EntryVault is an independent third-party plugin.

**Download from WordPress.org:** https://wordpress.org/plugins/entryvault-contact-form-7/

## Description & Features

**EntryVault for Contact Form 7** is an independent third-party plugin that stores every successful Contact Form 7 submission in custom database tables, gives you a searchable and filterable admin dashboard, lets you view individual entries, and exports to CSV or JSON in one click.

*This plugin is not affiliated with, endorsed by, or in any way officially connected with the Contact Form 7 project or its author.*

### Key Features
* **Automatic capture** – hooks into `wpcf7_mail_sent`; zero configuration needed.
* **Custom DB tables** – scalable two-table design (`wp_cf7_entries` + `wp_cf7_entry_meta`).
* **Admin dashboard** – `WP_List_Table`-based entries list with sortable columns, search, form filter, date-range filter, and pagination.
* **Single entry view** – clean detail page showing every submitted field.
* **Bulk & single delete** – with nonce-protected confirmation.
* **CSV export** – UTF-8 BOM included for Excel compatibility; respects current filters.
* **JSON export** – machine-readable; respects current filters.
* **CFDB7 import** – import previously exported CSV files from the Contact Form CFDB7 plugin, with full duplicate detection.
* **REST API** – `GET/DELETE /cf7em/v1/entries`, `GET /cf7em/v1/entries/{id}`, `GET /cf7em/v1/analytics` (admin-only).
* **Analytics page** – per-form submission counts with progress bars.
* **Settings page** – control whether data is preserved or deleted on plugin removal.
* **Developer hooks** – `do_action('evcf7_after_entry_saved', ...)` and `apply_filters('evcf7_entry_data', ...)`.
* **Performance** – indexed DB columns; assets loaded only on plugin pages; batch meta queries.
* **Security** – nonces on all forms and actions; all inputs sanitised; all outputs escaped; fully prepared SQL queries.

### Requirements
* WordPress 6.0 or later
* PHP 7.4 or later
* [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) (any recent version)

### Developer Hooks
**Action – fired after an entry is saved:**

    do_action( 'evcf7_after_entry_saved', $entry_id, $form_id, $fields, $contact_form );

**Filter – modify field data before it is stored:**

    add_filter( 'evcf7_entry_data', function( $fields, $form_id, $contact_form ) {
        unset( $fields['your-hidden-field'] );
        return $fields;
    }, 10, 3 );

**Action – fired after a CFDB7 CSV row is imported:**

    do_action( 'evcf7_after_cfdb7_import', $entry_id, $cfdb7_id, $fields );

### REST API
All endpoints require `manage_options` capability (administrator).

    GET    /wp-json/cf7em/v1/entries
    GET    /wp-json/cf7em/v1/entries/{id}
    DELETE /wp-json/cf7em/v1/entries/{id}
    GET    /wp-json/cf7em/v1/analytics

Supported query parameters for the list endpoint: `form_id`, `date_from`, `date_to`, `search`, `per_page` (max 100), `page`, `order` (ASC|DESC).

## Installation

1. Upload the `entryvault-contact-form-7` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. A new **EntryVault CF7** menu appears in the WordPress admin sidebar.
4. Ensure Contact Form 7 is installed and active.

## FAQ (highlights)

### Does this plugin modify Contact Form 7 in any way?
No. It only listens to the `wpcf7_mail_sent` action hook, which CF7 fires after a form is submitted successfully. No CF7 files are touched.

### Is this plugin officially affiliated with Contact Form 7?
No. EntryVault for Contact Form 7 is an independent third-party plugin. It is not affiliated with, endorsed by, or in any way connected with the Contact Form 7 project or its author.

### Will existing submissions be imported automatically?
Not automatically. You can import a previously exported CFDB7 CSV via **EntryVault CF7 → Import CSV**.

### Can I export only a subset of entries?
Yes. Apply your filters (date range, search keyword) on the entries page before clicking Export CSV or Export JSON.

### Where is the data stored?
In two custom database tables: `{prefix}cf7_entries` and `{prefix}cf7_entry_meta`. No data is sent to any external server.



See the full FAQ on [WordPress.org](https://wordpress.org/plugins/entryvault-contact-form-7/#faq).

## Changelog (recent)

### 1.4.2
* Fixed: Entries are now saved even when email delivery fails (wpcf7_mail_failed hook added), matching CFDB7 behaviour — no submission is ever lost.
* Improved: Single-entry sidebar now shows Mail Status (Delivered / Failed) and a warning banner for failed-mail entries.
* Updated: Contributor list updated; Author field corrected.

### 1.4.1
* Fixed: Updated "Tested up to" to WordPress 7.1 to resolve `outdated_tested_upto_header` error on WordPress.org.
* Improved: Forms landing page now uses full-width layout with WordPress native table styling, no custom CSS.
* Improved: Entries list toolbar rebuilt using WP native search_box(), tablenav, alignleft/alignright — zero custom styling required.

### 1.4.0
* Fixed: Single-entry detail card now uses inline `style` attributes instead of relying solely on enqueued/inline stylesheets, guaranteeing correct rendering in all hosting environments (including WordPress Playground) regardless of how the CSS pipeline behaves.
* Added: `Requires Plugins: contact-form-7` header so WordPress checks Contact Form 7 is installed/active before allowing activation.
* Fixed: Removed raw `<style>` tag; styles now output exclusively via `wp_enqueue_style()` / `wp_add_inline_style()` and inline `style` attributes, per WordPress.org Plugin Check requirements.

### 1.3.0
* Rebranded: Plugin renamed from "CF7 Entries Manager & Export" to "EntryVault for Contact Form 7".
* Updated: Text Domain changed to `entryvault-contact-form-7` to match WordPress.org slug.
* Updated: Admin menu labels and page titles updated to new branding.
* Compatibility: All existing database tables, option names, hook names, and REST API endpoints are fully preserved — no migration required for existing users.



Full changelog: [readme.txt](readme.txt).

## Author

Developed by **[SK Freelancers](https://skfreelancers.com)** — [WordPress.org profile](https://profiles.wordpress.org/saqibabbasi/).

## License

GPLv2 or later. See [LICENSE](LICENSE).
