<?php
if (!defined('ABSPATH')) exit;

/**
 * Lightweight GitHub Releases updater.
 *
 * The release repository should be public and contain a ZIP asset whose root
 * folder is `all-star-supplier-sync`. No GitHub token is stored in WordPress.
 */
class ASSS_Updater {
    private ASSS_SanMar $sanmar;
    private string $plugin_file;
    private string $plugin_basename;
    private string $slug = 'all-star-supplier-sync';

    public function __construct(ASSS_SanMar $sanmar, string $plugin_file) {
        $this->sanmar = $sanmar;
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);

        // WordPress 5.8+ routes non-.org plugin updates through a dynamic
        // hook based on the Update URI hostname. Our plugin header points to
        // github.com, so this is the native external-update integration point.
        add_filter('update_plugins_github.com', [$this, 'update_for_github_host'], 20, 4);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('auto_update_plugin', [$this, 'maybe_auto_update'], 20, 2);
        add_action('upgrader_process_complete', [$this, 'clear_cache_after_update'], 10, 2);
        $this->migrate_release_repository();
    }

    private function migrate_release_repository(): void {
        $raw = get_option('asss_settings', []);
        if (!is_array($raw)) return;
        $repo = trim((string)($raw['github_update_repo'] ?? ''));
        if ($repo === '' || strcasecmp($repo, 'robrosco/ASE.SupplierSync-Releases') === 0) {
            $raw['github_update_repo'] = 'rolejarczyk/ASE.SupplierSync-Releases';
            update_option('asss_settings', $raw, false);
        }
    }

    public function configured_repo(): string {
        $settings = $this->sanmar->settings();
        $repo = trim((string)($settings['github_update_repo'] ?? 'rolejarczyk/ASE.SupplierSync-Releases'));
        if ($repo === '' || strcasecmp($repo, 'robrosco/ASE.SupplierSync-Releases') === 0) {
            $repo = 'rolejarczyk/ASE.SupplierSync-Releases';
        }
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : '';
    }

    public function clear_cache(): void {
        delete_transient('asss_github_release_' . md5($this->configured_repo()));
    }

    public function latest_release(bool $force = false) {
        $repo = $this->configured_repo();
        if ($repo === '') return new WP_Error('asss_update_repo', 'GitHub update repository is not configured.');
        $cache_key = 'asss_github_release_' . md5($repo);
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) return $cached;
        }

        $data = $this->fetch_github_release($repo);
        if (is_wp_error($data)) {
            // A tiny public manifest is also supported. This lets the release-only
            // repository serve updates even before a formal GitHub Release exists.
            $data = $this->fetch_repository_manifest($repo);
        }
        if (is_wp_error($data)) return $data;
        set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
        return $data;
    }


    private function fetch_github_release(string $repo) {
        $response = wp_remote_get('https://api.github.com/repos/' . $repo . '/releases/latest', [
            'timeout' => 12,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'All-Star-Supplier-Sync/' . ASSS_VERSION . '; ' . home_url('/'),
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 200) return new WP_Error('asss_update_release_http', 'GitHub Releases returned HTTP ' . $code . '.');
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($body)) return new WP_Error('asss_update_release_json', 'GitHub returned an unreadable release response.');

        $tag = ltrim(trim((string)($body['tag_name'] ?? '')), "vV \t\n\r\0\x0B");
        if ($tag === '') return new WP_Error('asss_update_release_version', 'The latest GitHub release does not have a usable version tag.');
        $package = '';
        foreach ((array)($body['assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            $name = strtolower((string)($asset['name'] ?? ''));
            if (preg_match('#^all-star-supplier-sync(?:-' . preg_quote(strtolower($tag), '#') . ')?\.zip$#', $name)) {
                $package = esc_url_raw((string)($asset['browser_download_url'] ?? ''));
                break;
            }
        }
        if ($package === '') {
            foreach ((array)($body['assets'] ?? []) as $asset) {
                if (!is_array($asset)) continue;
                if (str_ends_with(strtolower((string)($asset['name'] ?? '')), '.zip')) {
                    $package = esc_url_raw((string)($asset['browser_download_url'] ?? ''));
                    break;
                }
            }
        }
        if ($package === '') return new WP_Error('asss_update_release_asset', 'The latest GitHub release does not contain a ZIP asset.');
        return [
            'version' => $tag,
            'package' => $package,
            'url' => esc_url_raw((string)($body['html_url'] ?? ('https://github.com/' . $repo . '/releases'))),
            'published_at' => sanitize_text_field((string)($body['published_at'] ?? '')),
            'name' => sanitize_text_field((string)($body['name'] ?? ('All Star Supplier Sync ' . $tag))),
            'notes' => wp_kses_post((string)($body['body'] ?? '')),
        ];
    }

    private function fetch_repository_manifest(string $repo) {
        $branches = ['main', 'master'];
        $last_error = null;
        foreach ($branches as $branch) {
            $url = 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/latest.json';
            $response = wp_remote_get($url, [
                'timeout' => 12,
                'headers' => ['User-Agent' => 'All-Star-Supplier-Sync/' . ASSS_VERSION . '; ' . home_url('/')],
            ]);
            if (is_wp_error($response)) { $last_error = $response; continue; }
            if ((int)wp_remote_retrieve_response_code($response) !== 200) continue;
            $body = json_decode((string)wp_remote_retrieve_body($response), true);
            if (!is_array($body)) return new WP_Error('asss_update_manifest_json', 'The GitHub update manifest is unreadable.');
            $version = ltrim(trim((string)($body['version'] ?? '')), "vV \t\n\r\0\x0B");
            $package = esc_url_raw((string)($body['package'] ?? ''));
            if ($version === '' || $package === '') return new WP_Error('asss_update_manifest_fields', 'The GitHub update manifest is missing version or package.');
            return [
                'version' => $version,
                'package' => $package,
                'url' => esc_url_raw((string)($body['url'] ?? ('https://github.com/' . $repo))),
                'published_at' => sanitize_text_field((string)($body['published_at'] ?? '')),
                'name' => sanitize_text_field((string)($body['name'] ?? ('All Star Supplier Sync ' . $version))),
                'notes' => wp_kses_post((string)($body['notes'] ?? '')),
            ];
        }
        return $last_error instanceof WP_Error ? $last_error : new WP_Error('asss_update_manifest_http', 'No published GitHub Release or latest.json update manifest was found.');
    }

    public function update_for_github_host($update, array $plugin_data, string $plugin_file, array $locales) {
        if ($plugin_file !== $this->plugin_basename) return $update;
        $release = $this->latest_release();
        if (is_wp_error($release)) return false;
        if (version_compare((string)$release['version'], ASSS_VERSION, '<=')) return false;
        $settings = $this->sanmar->settings();
        return [
            'id' => (string)($plugin_data['UpdateURI'] ?? ('https://github.com/' . $this->configured_repo())),
            'slug' => $this->slug,
            'version' => (string)$release['version'],
            'url' => (string)$release['url'],
            'package' => (string)$release['package'],
            'tested' => function_exists('get_bloginfo') ? (string)get_bloginfo('version') : '',
            'requires_php' => '8.0',
            'autoupdate' => !empty($settings['github_auto_updates']),
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
        ];
    }

    public function plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || (string)($args->slug ?? '') !== $this->slug) return $result;
        $release = $this->latest_release();
        if (is_wp_error($release)) return $result;
        return (object)[
            'name' => 'All Star Supplier Sync',
            'slug' => $this->slug,
            'version' => (string)$release['version'],
            'author' => '<a href="https://allstarembroidery.com">All Star</a>',
            'homepage' => (string)$release['url'],
            'download_link' => (string)$release['package'],
            'requires' => '6.4',
            'requires_php' => '8.0',
            'sections' => [
                'description' => 'Curated SanMar and S&S Activewear synchronization for WooCommerce and All Star Bulk Order.',
                'changelog' => (string)($release['notes'] ?: 'See the GitHub release notes for this version.'),
            ],
        ];
    }

    public function maybe_auto_update($update, $item) {
        if (!is_object($item) || (string)($item->plugin ?? '') !== $this->plugin_basename) return $update;
        $settings = $this->sanmar->settings();
        return !empty($settings['github_auto_updates']) ? true : $update;
    }

    public function clear_cache_after_update($upgrader, array $options): void {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') return;
        $plugins = (array)($options['plugins'] ?? []);
        if (in_array($this->plugin_basename, $plugins, true)) $this->clear_cache();
    }
}
