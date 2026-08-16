# All Star Supplier Sync v2.0.7

Momentec full-catalog browsing while preserving **WordPress <> GitHub Actions <> Supplier**.

- Adds a complete browse/search/filter Momentec catalog in Suppliers -> Add Products.
- Catalog discovery uses Momentec's official U.S. stocked-product CSV feed, not storefront scraping or undocumented v2 wildcard behavior.
- The generic public-feed Cost field is not used as account pricing; authenticated v2 Style remains the customer-specific cost/details source.
- Administrators can select one or many catalog styles and queue customer-detail hydration directly from WordPress.
- GitHub polls the authenticated WordPress request queue, calls production v2 Style/Inventory, validates exact sparse Color+Size SKUs, hydrates color galleries, then returns normalized data to WordPress.
- Catalog rows show Needs details, Queued, Fetching, Failed, or Ready states.
- Ready rows flow into the existing Review & Import / multi-supplier linking experience.
- Adds catalog pagination and brand/category/search filters.
- Adds full Momentec status information to the bridge status endpoint.
- Momentec credentials remain only in GitHub Actions Secrets and never enter WordPress.
- Existing hourly targeted inventory and weekly linked-product refresh remain intact.
