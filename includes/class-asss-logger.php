<?php
if (!defined('ABSPATH')) exit;

class ASSS_Logger {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'asss_logs'; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            level VARCHAR(20) NOT NULL,
            supplier VARCHAR(50) NOT NULL DEFAULT 'sanmar',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            PRIMARY KEY (id), KEY created_at (created_at), KEY level (level)
        ) $charset;");
    }
    public static function log(string $message, string $level='info', array $context=[]): void {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'created_at'=>current_time('mysql'), 'level'=>$level, 'supplier'=>'sanmar',
            'message'=>$message, 'context'=>$context ? wp_json_encode($context) : null
        ], ['%s','%s','%s','%s','%s']);
    }
    public static function recent(int $limit=100): array {
        global $wpdb;
        $limit = max(1,min(500,$limit));
        return $wpdb->get_results("SELECT * FROM ".self::table()." ORDER BY id DESC LIMIT $limit", ARRAY_A) ?: [];
    }
}
