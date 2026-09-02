from pathlib import Path
import textwrap


def must_replace(text, old, new, label, count=1):
    actual = text.count(old)
    if actual < count:
        raise SystemExit(f'{label}: expected at least {count}, found {actual}')
    return text.replace(old, new, count)

main = Path('all-star-supplier-sync.php')
t = main.read_text()
t = must_replace(t, 'Version: 2.0.32', 'Version: 2.0.33', 'version header')
t = must_replace(t, "define('ASSS_VERSION', '2.0.32');", "define('ASSS_VERSION', '2.0.33');", 'version constant')
main.write_text(t)

bg = Path('includes/class-asss-background-jobs.php')
t = bg.read_text()
t = must_replace(t, '    private ASSS_Sync $sync;\n    private const GROUP', "    private ASSS_Sync $sync;\n    private string $current_job_id = '';\n    private const GROUP", 'job property')
t = must_replace(t, "        add_action('asss_run_background_job', [$this, 'run_job'], 10, 1);\n        add_action('wp_ajax_asss_job_status', [$this, 'ajax_status']);", "        add_action('asss_run_background_job', [$this, 'run_job'], 10, 1);\n        add_action('asss_import_progress', [$this, 'capture_progress'], 10, 4);\n        add_action('wp_ajax_asss_job_status', [$this, 'ajax_status']);", 'progress hook')
insert = textwrap.dedent('''
    public function capture_progress(string $stage, int $current, int $total, string $message = ''): void {
        if ($this->current_job_id === '') return;
        $job = $this->get_job($this->current_job_id);
        if (!$job || (string)($job['status'] ?? '') !== 'running') return;
        $current = max(0, $current); $total = max(0, $total);
        $job['stage'] = sanitize_key($stage);
        $job['progress_current'] = $current;
        $job['progress_total'] = $total;
        $job['progress_percent'] = $total > 0 ? min(100, (int)floor(($current / $total) * 100)) : 0;
        $job['heartbeat_at'] = time();
        if ($message !== '') $job['message'] = sanitize_text_field($message);
        $this->save_job($job);
    }

''')
t = must_replace(t, '    private function key(string $job_id): string {', insert + '    private function key(string $job_id): string {', 'capture method')
t = must_replace(t, "            'engine' => '',\n        ];", "            'engine' => '',\n            'stage' => 'queued',\n            'progress_current' => 0,\n            'progress_total' => 0,\n            'progress_percent' => 0,\n            'heartbeat_at' => time(),\n        ];", 'job progress fields')
t = must_replace(t, "        $job['status'] = 'running';\n        $job['started_at'] = time();\n        $job['message'] = 'Background worker is processing the supplier product…';", "        $job['status'] = 'running';\n        $job['started_at'] = time();\n        $job['heartbeat_at'] = time();\n        $job['stage'] = 'starting';\n        $job['message'] = 'Background worker is preparing the supplier product…';\n        $this->current_job_id = $job_id;", 'run start')
t = must_replace(t, "        } catch (Throwable $e) {\n            $this->mark_failed($job, $e->getMessage());", "        } catch (Throwable $e) {\n            $this->current_job_id = '';\n            $this->mark_failed($this->get_job($job_id) ?: $job, $e->getMessage());", 'catch refresh')
t = must_replace(t, "        if (is_wp_error($result)) {\n            $this->mark_failed($job, $result->get_error_message());", "        $this->current_job_id = '';\n        $job = $this->get_job($job_id) ?: $job;\n        if (is_wp_error($result)) {\n            $this->mark_failed($job, $result->get_error_message());", 'post result refresh')
t = must_replace(t, "        $job['status'] = 'completed';\n        $job['finished_at'] = time();", "        $job['status'] = 'completed';\n        $job['stage'] = 'complete';\n        $job['progress_percent'] = 100;\n        $job['heartbeat_at'] = time();\n        $job['finished_at'] = time();", 'complete fields')
ajax_start = t.index('        wp_send_json_success([', t.index('    public function ajax_status(): void {'))
ajax_end = t.index('        ]);', ajax_start) + len('        ]);')
ajax_new = textwrap.dedent('''
        $status = (string)$job['status'];
        $heartbeat = (int)($job['heartbeat_at'] ?? ($started ?: $created));
        $heartbeat_age = max(0, time() - $heartbeat);
        if ($status === 'running' && $heartbeat_age >= 240) $status = 'stalled';
        wp_send_json_success([
            'id' => (string)$job['id'],
            'type' => (string)$job['type'],
            'status' => $status,
            'message' => (string)$job['message'],
            'stage' => (string)($job['stage'] ?? ''),
            'current' => (int)($job['progress_current'] ?? 0),
            'total' => (int)($job['progress_total'] ?? 0),
            'percent' => (int)($job['progress_percent'] ?? 0),
            'heartbeat_age' => $heartbeat_age,
            'elapsed' => $elapsed,
            'product_id' => (int)($job['product_id'] ?? 0),
            'redirect_url' => (string)($job['redirect_url'] ?? ''),
        ]);
''').strip('\n')
t = t[:ajax_start] + ajax_new + t[ajax_end:]
bg.write_text(t)

imp = Path('includes/class-asss-importer.php')
t = imp.read_text()
marker = '    private function reconcile_variations(int $product_id, string $brand, string $style, array $rows, bool $allow_create = true): void {'
pos = t.index(marker)
loop = '        foreach ($expected as $key => $row) {'
lp = t.index(loop, pos)
repl = "        $asss_progress_total = count($expected); $asss_progress_current = 0;\n        do_action('asss_import_progress','variations',0,$asss_progress_total,'Preparing exact SanMar variations…');\n        foreach ($expected as $key => $row) {\n            $asss_progress_current++;\n            do_action('asss_import_progress','variations',$asss_progress_current,$asss_progress_total,'Processing SanMar variation '.$asss_progress_current.' of '.$asss_progress_total.'…');"
t = t[:lp] + t[lp:].replace(loop, repl, 1)

marker = '    private function reconcile_ss_variations(int $product_id, array $rows, bool $allow_create = true): array {'
pos = t.index(marker)
loop = '        $created = 0; $updated = 0;\n        foreach ($expected as $row) {'
repl = "        $created = 0; $updated = 0;\n        $asss_progress_total = count($expected); $asss_progress_current = 0;\n        do_action('asss_import_progress','variations',0,$asss_progress_total,'Preparing exact S&S variations…');\n        foreach ($expected as $row) {\n            $asss_progress_current++;\n            do_action('asss_import_progress','variations',$asss_progress_current,$asss_progress_total,'Processing S&S variation '.$asss_progress_current.' of '.$asss_progress_total.'…');"
segment = t[pos:]
if loop not in segment:
    raise SystemExit('S&S variation loop anchor missing')
t = t[:pos] + segment.replace(loop, repl, 1)

marker = '    private function reconcile_momentec_variations(int $product_id,array $rows,bool $allow_create=true): array {'
pos = t.index(marker)
old = "$created=0;$updated=0;$seen=[];\n        foreach($expected as $combo=>$row){"
new = "$created=0;$updated=0;$seen=[];$asss_progress_total=count($expected);$asss_progress_current=0;do_action('asss_import_progress','variations',0,$asss_progress_total,'Preparing exact Momentec variations…');\n        foreach($expected as $combo=>$row){$asss_progress_current++;do_action('asss_import_progress','variations',$asss_progress_current,$asss_progress_total,'Processing Momentec variation '.$asss_progress_current.' of '.$asss_progress_total.'…');"
segment = t[pos:]
if old not in segment:
    raise SystemExit('Momentec variation loop anchor missing')
t = t[:pos] + segment.replace(old, new, 1)
imp.write_text(t)

adm = Path('includes/class-asss-admin.php')
t = adm.read_text()
old = '''                }else if(job.status==="running"){
                    if(detail) detail.textContent="Running in the background. The browser is no longer holding the import request open.";
                }else if(job.status==="completed"){'''
new = '''                }else if(job.status==="running"){
                    if(job.total>0){
                        if(title) title.textContent="Supplier Sync — "+job.percent+"%";
                        if(detail) detail.textContent=(job.current||0)+" of "+job.total+" exact variations processed. Safe to leave this page.";
                        if(bar){bar.style.animation="none";bar.style.width=Math.max(2,job.percent)+"%";bar.style.transform="none";bar.style.background="#2271b1";}
                    }else if(detail) detail.textContent="Running in the background. Safe to leave this page.";
                }else if(job.status==="stalled"){
                    stopped=true;
                    if(title) title.textContent="Supplier Sync may be stalled";
                    if(message) message.textContent="No progress heartbeat has been received for more than 4 minutes.";
                    if(detail) detail.textContent="Do not start a duplicate import yet. Check Supplier Sync logs / Action Scheduler before retrying.";
                    if(bar){bar.style.animation="none";bar.style.width="100%";bar.style.transform="none";bar.style.background="#dba617";}
                    return;
                }else if(job.status==="completed"){'''
if old not in t:
    raise SystemExit('Admin running-state anchor missing')
t = t.replace(old, new, 1)
adm.write_text(t)
