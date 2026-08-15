# All Star Supplier Sync v2.0.4

Security-focused Momentec groundwork update.

- Momentec API username/password are no longer stored in the WordPress database.
- Credentials are read from `ASSS_MOMENTEC_USERNAME` and `ASSS_MOMENTEC_PASSWORD` in `wp-config.php` or server environment variables.
- Legacy Momentec credential-shaped option values are automatically purged from `asss_settings` for WooCommerce administrators.
- The Settings screen displays only secure configuration status, never credential values.
- Momentec live API calls remain disabled until the official staging authentication contract and response schema are verified.
- Exact v2.0.3 release source was recovered and used as the clean base for this release.

Release asset SHA-256:

`f4521c161e13975adb5a3aa7cc6c4b195ff4a831ef462a0da433c7a67ed7a470`
