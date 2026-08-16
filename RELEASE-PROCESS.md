# Supplier Sync release repository

This repository is the public distribution point for All Star Supplier Sync.

## Permanent GitHub Action

`publish-supplier-sync-release.yml` is the only permanent release Action. It validates a committed `all-star-supplier-sync-X.Y.Z.zip` package and creates or updates the matching GitHub Release.

Version-specific build, recovery, staging, and diagnostic workflows are intentionally removed after a release is complete. They are not production infrastructure.

## Release artifacts

- `latest.json` tells the WordPress updater which version is current.
- Release ZIPs remain available through GitHub Releases.
- Exact released source is preserved in the corresponding `recovery/vX.Y.Z-exact-source` branch when one exists.
- Historical release notes remain in `RELEASE-X.Y.Z.md` files.

The private `rolejarczyk/ASE.ProductSync` repository remains the supplier/GitHub integration layer. Supplier credentials must never be committed here.
