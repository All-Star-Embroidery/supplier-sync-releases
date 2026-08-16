# All Star Supplier Sync v2.0.12

Momentec import reliability and media performance update.

- Creates the complete selected Momentec product/variation structure before heavy image downloads.
- Downloads only the parent featured image during the interactive import when available.
- Processes variation galleries and the remaining parent gallery with background Action Scheduler jobs.
- Deduplicates media jobs and retries failed variation image downloads up to three times.
- Keeps in-progress background media from being reported as an import failure.
- Prevents large Momentec styles from timing out after the first variation.
