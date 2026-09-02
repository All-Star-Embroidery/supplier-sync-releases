<?php
if (!defined('ABSPATH')) exit;

/**
 * Background job wrapper for expensive Supplier Sync admin operations.
 *
 * v2.0.32 deliberately keeps the proven importer methods intact. The browser
 * request only validates input, creates a job, and queues one Action Scheduler
 * action. The worker then calls the same importer method that previously ran
 * synchronously.
 */
class ASSS_Background_Jobs {
    private ASSS_Importer $importer;
    private ASSS_Sync $sync;
    private const GROUP = 'all-star-supplier-sync';
    private const TTL = 172800;

    public function __construct(ASSS_Importer $importer, ASSS_Sync $sync) {
        $this->importer = $importer;
        $this->sync = $sync;
        add_action('asss_run_background_job', [$this, 'run_job'], 10, 1);
        add_action('wp_ajax_asss_job_status', [$this, 'ajax_status']);
    }

    private function key(string $job_id): string {
        return 'asss_job_' . sanitize_key($job_id);
    }

    private function get_job(string $job_id): array {
        $job = get_transient($this->key($job_id));
        return is_array($job) ? $job : [];
    }

    private function save_job(array $job): void {
        if (empty($job['id'])) return;
        set_transient($this->key((string)$job['id']), $job, self::TTL);
    }

    public function enqueue(string $type, array $payload, int $user_id): string|WP_Error {
        $allowed = [
            'sanmar_import','sanmar_link',
            'ss_import','ss_link',
            'momentec_import','momentec_link',
            'quick_repair',
        ];
        if (!in_array($type, $allowed, true)) {
            return new WP_Error('asss_job_type', 'Unsupported Supplier Sync background job.');
        }

        $job_id = wp_generate_uuid4();
        $job = [
            'id' => $job_id,
            'type' => $type,
            'status' => 'queued',
            'user_id' => $user_id,
            'payload' => $payload,
            'message' => 'Queued for background processing…',
            'created_at' => time(),
            'started_at' => 0,
            'finished_at' => 0,
            'product_id' => 0,
            'redirect_url' => '',
            'action_id' => 0,
            'engine' => '',
        ];
        $this->save_job($job);

        if (function_exists('as_enqueue_async_action')) {
            $action_id = as_enqueue_async_action('asss_run_background_job', [$job_id], self::GROUP, true);
            if (!$action_id) {
                delete_transient($this->key($job_id));
                return new WP_Error('asss_job_queue', 'WooCommerce Action Scheduler could not queue the Supplier Sync job.');
            }
            $job['action_id'] = (int)$action_id;
            $job['engine'] = 'action-scheduler';
        } else {
            $scheduled = wp_schedule_single_event(time() + 1, 'asss_run_background_job', [$job_id], true);
            if (is_wp_error($scheduled) || !$scheduled) {
                delete_transient($this->key($job_id));
                return is_wp_error($scheduled) ? $scheduled : new WP_Error('asss_job_queue', 'WordPress could not queue the Supplier Sync job.');
            }
            $job['engine'] = 'wp-cron';
            if (function_exists('spawn_cron')) spawn_cron();
        }

        $this->save_job($job);
        ASSS_Logger::log('Supplier Sync background job queued', 'info', [
            'job_id' => $job_id,
            'type' => $type,
            'engine' => $job['engine'],
        ]);
        return $job_id;
    }

    private function mark_failed(array $job, string $message): void {
        $job['status'] = 'failed';
        $job['message'] = $message !== '' ? $message : 'Supplier Sync background job failed.';
        $job['finished_at'] = time();
        $this->save_job($job);
        ASSS_Logger::log('Supplier Sync background job failed', 'error', [
            'job_id' => (string)($job['id'] ?? ''),
            'type' => (string)($job['type'] ?? ''),
            'error' => $job['message'],
        ]);
    }

    public function run_job(string $job_id): void {
        $job_id = sanitize_key($job_id);
        $job = $this->get_job($job_id);
        if (!$job || in_array((string)($job['status'] ?? ''), ['completed','failed'], true)) return;

        $job['status'] = 'running';
        $job['started_at'] = time();
        $job['message'] = 'Background worker is processing the supplier product…';
        $this->save_job($job);

        $type = (string)($job['type'] ?? '');
        $p = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        try {
            switch ($type) {
                case 'sanmar_import':
                    $result = $this->importer->import_style(
                        sanitize_text_field((string)($p['brand'] ?? '')),
                        sanitize_text_field((string)($p['style'] ?? '')),
                        array_values(array_filter(array_map('sanitize_text_field', (array)($p['colors'] ?? []))))
                    );
                    break;
                case 'sanmar_link':
                    $result = $this->importer->link_sanmar_style_to_product(
                        absint($p['product_id'] ?? 0),
                        sanitize_text_field((string)($p['brand'] ?? '')),
                        sanitize_text_field((string)($p['style'] ?? '')),
                        array_values(array_filter(array_map('sanitize_text_field', (array)($p['colors'] ?? []))))
                    );
                    break;
                case 'ss_import':
                    $result = $this->importer->import_ss_style(
                        absint($p['brand_id'] ?? 0),
                        absint($p['style_id'] ?? 0),
                        array_values(array_filter(array_map('sanitize_text_field', (array)($p['colors'] ?? []))))
                    );
                    break;
                case 'ss_link':
                    $result = $this->importer->link_ss_style_to_product(
                        absint($p['product_id'] ?? 0),
                        absint($p['brand_id'] ?? 0),
                        absint($p['style_id'] ?? 0),
                        array_values(array_filter(array_map('sanitize_text_field', (array)($p['colors'] ?? []))))
                    );
                    break;
                case 'momentec_import':
                    $result = $this->importer->import_momentec_style(
                        sanitize_text_field((string)($p['style'] ?? '')),
                        array_values(array_filter(array_map('sanitize_text_field', (array)($p['colors'] ?? []))))
                    );
                    break;
                case 'momentec_link':
                    $result = $this->importer->link_momentec_style_to_product(
                        absint($p['product_id'] ?? 0),
                        sanitize_text_field((string)($p['style'] ?? '')),
                        array_values(array_filter(array_map('sanitize_text_field', (array)($p['colors'] ?? []))))
                    );
                    break;
                case 'quick_repair':
                    $result = $this->importer->update_style(absint($p['product_id'] ?? 0));
                    break;
                default:
                    $result = new WP_Error('asss_job_type', 'Unsupported Supplier Sync background job.');
            }
        } catch (Throwable $e) {
            $this->mark_failed($job, $e->getMessage());
            return;
        }

        if (is_wp_error($result)) {
            $this->mark_failed($job, $result->get_error_message());
            return;
        }

        $product_id = is_numeric($result) ? absint($result) : 0;
        if (!$product_id) {
            $this->mark_failed($job, 'The background importer completed without returning a WooCommerce product.');
            return;
        }

        $job['status'] = 'completed';
        $job['finished_at'] = time();
        $job['product_id'] = $product_id;

        if (str_ends_with($type, '_import')) {
            $job['message'] = 'WooCommerce product created/updated successfully.';
            $job['redirect_url'] = add_query_arg([
                'post' => $product_id,
                'action' => 'edit',
                'asss_imported' => 1,
            ], admin_url('post.php'));
        } elseif (str_ends_with($type, '_link')) {
            $job['message'] = 'Supplier linked to the existing WooCommerce product.';
            $job['redirect_url'] = add_query_arg([
                'page' => 'asss-manage-suppliers',
                'product_id' => $product_id,
                'asss_msg' => 'Supplier linked successfully in the background.',
            ], admin_url('admin.php'));
        } else {
            $job['message'] = 'Product repair completed successfully.';
            $job['redirect_url'] = add_query_arg([
                'page' => 'asss-active-products',
                'asss_msg' => 'Quick repair finished for product #' . $product_id . '. Existing color selections were preserved.',
            ], admin_url('admin.php'));
        }

        $this->save_job($job);
        ASSS_Logger::log('Supplier Sync background job completed', 'info', [
            'job_id' => $job_id,
            'type' => $type,
            'product_id' => $product_id,
        ]);
    }

    public function ajax_status(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        check_ajax_referer('asss_job_status', 'nonce');
        $job_id = sanitize_key((string)($_GET['job_id'] ?? ''));
        $job = $this->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => 'This Supplier Sync job is no longer available.'], 404);
        }
        if ((int)($job['user_id'] ?? 0) !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'This job belongs to another administrator.'], 403);
        }

        $started = (int)($job['started_at'] ?? 0);
        $created = (int)($job['created_at'] ?? time());
        $finished = (int)($job['finished_at'] ?? 0);
        $end = $finished ?: time();
        $elapsed = max(0, $end - ($started ?: $created));

        wp_send_json_success([
            'id' => (string)$job['id'],
            'type' => (string)$job['type'],
            'status' => (string)$job['status'],
            'message' => (string)$job['message'],
            'elapsed' => $elapsed,
            'product_id' => (int)($job['product_id'] ?? 0),
            'redirect_url' => (string)($job['redirect_url'] ?? ''),
        ]);
    }
}
