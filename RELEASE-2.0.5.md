# All Star Supplier Sync v2.0.5

Momentec architecture correction: **WordPress <> GitHub Actions <> Momentec**.

- Momentec username/password are held only as GitHub Actions Secrets in the private development repository.
- WordPress no longer accepts or stores Momentec credentials or supplier connection details.
- Legacy Momentec username/password/API key/base URL/account/environment values are purged from `asss_settings`.
- WordPress shows the required GitHub secret names and points administrators to the Momentec Credentials Preflight workflow.
- Existing `ASSS_WP_URL` and `ASSS_BRIDGE_TOKEN` secrets remain the authenticated GitHub-to-WordPress transport.
- Live Momentec supplier calls remain disabled until its exact staging authentication contract is verified.
