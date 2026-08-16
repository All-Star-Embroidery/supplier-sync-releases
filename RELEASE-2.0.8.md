# All Star Supplier Sync v2.0.8

Momentec catalog import UX refinement.

- Every Momentec catalog row now has its own **Import** action.
- Clicking Import on a style that still needs customer-specific details automatically queues that one style for GitHub hydration.
- Queued/processing styles show **Import queued** instead of instructional text.
- Failed hydration rows show **Retry Import**.
- Ready styles show **Import** and open the existing review/color-selection/import screen.
- Search, brand, category, and catalog page are preserved after queueing a row.
- Bulk checkboxes remain available as an optional **Prepare Selected for Import** action.
- Removes the confusing "Select this row above" / "Fetch this row above" style of workflow.
- Momentec credentials remain exclusively in GitHub Actions Secrets.
