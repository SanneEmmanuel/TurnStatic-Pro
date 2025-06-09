<?php
/*
Plugin Name: TurnStatic Pro
Plugin URI: https://github.com/sanneemmanuel
Description: Converts WordPress sites to static HTML with inlined resources. Now with progress tracking, enhanced reliability, and better resource handling.
Version: 2.0
Author: Dr. Sanne Karibo (SenseTech Resources)
Author URI: https://github.com/sanneemmanuel
License: GPL2
*/

defined('ABSPATH') or die('Restricted access');

class TurnStaticPro_Enhanced {
    private $export_id;
    private $batch_size = 3;
    private $max_retries = 3;
    private $retry_delay = 2;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_turnstatic_init_export', [$this, 'ajax_init_export']);
        add_action('wp_ajax_turnstatic_process_batch', [$this, 'ajax_process_batch']);
        add_action('wp_ajax_turnstatic_finalize_export', [$this, 'ajax_finalize_export']);
        add_action('wp_ajax_turnstatic_cancel_export', [$this, 'ajax_cancel_export']);
        add_action('admin_post_turnstatic_download', [$this, 'handle_download']);
    }

    public function add_menu() {
        add_menu_page(
            'TurnStatic Pro',
            'TurnStatic Pro',
            'manage_options',
            'turnstatic-pro',
            [$this, 'render_admin_page'],
            'dashicons-download',
            6
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_turnstatic-pro') return;
        
        wp_enqueue_style('turnstatic-css', plugin_dir_url(__FILE__) . 'turnstatic-pro.css');
        wp_enqueue_script('turnstatic-js', plugin_dir_url(__FILE__) . 'turnstatic-pro.js', ['jquery'], '2.0', true);
        
        wp_localize_script('turnstatic-js', 'TurnStatic', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'downloadUrl' => admin_url('admin-post.php?action=turnstatic_download'),
            'nonce' => wp_create_nonce('turnstatic_nonce'),
            'whatsapp' => '+2348109995000',
            'batchSize' => $this->batch_size,
            'maxRetries' => $this->max_retries,
            'retryDelay' => $this->retry_delay * 1000,
        ]);
    }

    public function render_admin_page() {
        $server_checks = $this->check_server_capabilities();
        $requirements_met = !in_array(false, array_column($server_checks, 'status'), true);
        ?>
        <div class="turnstatic-container">
            <h1>TurnStatic Pro</h1>
            <p>Convert your WordPress site to static HTML with all resources inlined</p>
            
            <div class="server-check">
                <h3>Server Requirements:</h3>
                <ul>
                    <?php foreach ($server_checks as $check): ?>
                        <li class="<?= $check['status'] ? 'success' : 'error' ?>">
                            <?= $check['label'] ?>: 
                            <span><?= $check['status'] ? '✓' : '✗ ' . $check['message'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div id="turnstatic-controls" class="<?= $requirements_met ? '' : 'disabled' ?>">
                <button id="turnstatic-start" class="turnstatic-button">
                    <?= $requirements_met ? 'Export Site as ZIP' : 'Requirements Not Met' ?>
                </button>
                <button id="turnstatic-cancel" class="turnstatic-button cancel" style="display:none;">
                    Cancel Export
                </button>
            </div>

            <div id="turnstatic-progress">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <div class="progress-text">Status: Ready</div>
                <div id="turnstatic-details" class="progress-details"></div>
            </div>
        </div>

        <div id="turnstatic-whatsapp" class="turnstatic-whatsapp-popup" style="display:none;">
            <p>Need support? Chat with us on WhatsApp:</p>
            <a href="https://wa.me/<?= esc_attr('+2348109995000') ?>" target="_blank" rel="noopener noreferrer">
                <strong>+2348109995000</strong>
            </a>
            <button id="turnstatic-whatsapp-close">Close</button>
        </div>
        <?php
    }

    private function check_server_capabilities() {
        return [
            [
                'label' => 'PHP Version (>=7.4)',
                'status' => version_compare(PHP_VERSION, '7.4', '>='),
                'message' => 'Upgrade PHP to 7.4 or higher'
            ],
            [
                'label' => 'ZipArchive Extension',
                'status' => class_exists('ZipArchive'),
                'message' => 'Install Zip extension for PHP'
            ],
            [
                'label' => 'DOMDocument Support',
                'status' => class_exists('DOMDocument'),
                'message' => 'Install PHP XML extension'
            ],
            [
                'label' => 'cURL Enabled',
                'status' => function_exists('curl_init'),
                'message' => 'Enable cURL in PHP config'
            ],
            [
                'label' => 'Write Permissions',
                'status' => is_writable(sys_get_temp_dir()),
                'message' => 'Make temp directory writable'
            ],
            [
                'label' => 'Memory Limit (>=256M)',
                'status' => wp_convert_hr_to_bytes(ini_get('memory_limit')) >= 268435456,
                'message' => 'Increase memory_limit in php.ini'
            ],
            [
                'label' => 'Max Execution Time (>=300)',
                'status' => (ini_get('max_execution_time') >= 300 || ini_get('max_execution_time') == 0),
                'message' => 'Set max_execution_time to 300+'
            ],
        ];
    }

    public function ajax_init_export() {
        $this->verify_request();
        
        // Create unique export session
        $this->export_id = 'turnstatic_' . uniqid();
        $zip_path = sys_get_temp_dir() . '/' . $this->export_id . '.zip';
        
        // Initialize ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_send_json_error('Could not create ZIP file');
        }
        $zip->close();
        
        // Get all URLs to process
        $urls = $this->get_site_urls();
        $media_count = $this->count_media_files();
        $total_items = count($urls) + $media_count;
        
        // Save initial state
        set_transient($this->export_id, [
            'step' => 'processing',
            'zip_path' => $zip_path,
            'urls' => $urls,
            'media_files' => $this->get_media_files(),
            'processed_urls' => [],
            'current' => 0,
            'total' => $total_items,
            'errors' => []
        ], HOUR_IN_SECONDS);
        
        wp_send_json_success([
            'message' => 'Export initialized',
            'total' => $total_items,
            'export_id' => $this->export_id
        ]);
    }

    public function ajax_process_batch() {
        $this->verify_request();
        $this->export_id = $_POST['export_id'];
        $state = get_transient($this->export_id);
        
        if (!$state) {
            wp_send_json_error('Export session expired or invalid');
        }
        
        // Increase time limit for this batch
        set_time_limit(300);
        
        $zip = new ZipArchive();
        if ($zip->open($state['zip_path']) !== true) {
            wp_send_json_error('Could not open ZIP file');
        }
        
        // Process a batch of URLs
        $batch_count = 0;
        $urls = array_diff($state['urls'], $state['processed_urls']);
        
        foreach (array_slice($urls, 0, $this->batch_size) as $url) {
            $html = $this->get_inlined_html($url, $state['errors']);
            
            if ($html) {
                $filename = $this->url_to_filename($url);
                $zip->addFromString($filename, $html);
            }
            
            $state['processed_urls'][] = $url;
            $state['current']++;
            $batch_count++;
        }
        
        // Update state
        $zip->close();
        $state['remaining'] = count($urls) - $batch_count;
        
        // Check if URL processing is complete
        if (count($state['processed_urls']) >= count($state['urls'])) {
            $state['step'] = 'media';
        }
        
        set_transient($this->export_id, $state, HOUR_IN_SECONDS);
        
        wp_send_json_success([
            'processed' => $state['current'],
            'total' => $state['total'],
            'step' => $state['step'],
            'remaining' => $state['remaining'] ?? 0,
            'errors' => $state['errors']
        ]);
    }

    public function ajax_finalize_export() {
        $this->verify_request();
        $this->export_id = $_POST['export_id'];
        $state = get_transient($this->export_id);
        
        if (!$state) {
            wp_send_json_error('Export session expired or invalid');
        }
        
        $zip = new ZipArchive();
        if ($zip->open($state['zip_path']) !== true) {
            wp_send_json_error('Could not open ZIP file for finalization');
        }
        
        // Add media files to ZIP
        $added_files = 0;
        foreach ($state['media_files'] as $file) {
            $relative_path = 'uploads/' . str_replace(wp_upload_dir()['basedir'] . '/', '', $file);
            if ($zip->addFile($file, $relative_path)) {
                $added_files++;
                $state['current']++;
            }
        }
        
        // Add .htaccess for proper routing
        $zip->addFromString('.htaccess', 
            "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)$ $1.html [L]");
        
        $zip->close();
        
        // Create download token
        $token = wp_generate_password(32, false);
        set_transient('turnstatic_download_' . $token, $state['zip_path'], 15 * MINUTE_IN_SECONDS);
        
        // Clean up session
        delete_transient($this->export_id);
        
        wp_send_json_success([
            'download_token' => $token,
            'added_files' => $added_files,
            'errors' => $state['errors']
        ]);
    }

    public function ajax_cancel_export() {
        $this->verify_request();
        $export_id = $_POST['export_id'];
        $state = get_transient($export_id);
        
        if ($state && file_exists($state['zip_path'])) {
            @unlink($state['zip_path']);
        }
        
        delete_transient($export_id);
        wp_send_json_success('Export cancelled');
    }

    public function handle_download() {
        if (!isset($_GET['token']) || !current_user_can('manage_options')) {
            wp_die('Invalid request');
        }
        
        $token = sanitize_text_field($_GET['token']);
        $transient_key = 'turnstatic_download_' . $token;
        $zip_path = get_transient($transient_key);
        
        if (!$zip_path || !file_exists($zip_path)) {
            wp_die('Download link has expired');
        }
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="turnstatic-export-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        
        // Cleanup
        delete_transient($transient_key);
        @unlink($zip_path);
        exit;
    }

    private function get_site_urls() {
        $urls = [home_url('/')];
        
        // Get all public post types
        $post_types = get_post_types(['public' => true], 'names');
        
        // Add posts and pages
        $items = get_posts([
            'post_type' => $post_types,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids'
        ]);
        
        foreach ($items as $id) {
            $urls[] = get_permalink($id);
        }
        
        // Add taxonomies (categories, tags)
        $taxonomies = get_taxonomies(['public' => true]);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
            foreach ($terms as $term) {
                $urls[] = get_term_link($term);
            }
        }
        
        // Add author pages
        $authors = get_users(['who' => 'authors']);
        foreach ($authors as $author) {
            $urls[] = get_author_posts_url($author->ID);
        }
        
        return array_unique(array_filter($urls));
    }

    private function count_media_files() {
        $upload_dir = wp_upload_dir();
        $directory = new RecursiveDirectoryIterator($upload_dir['basedir']);
        $iterator = new RecursiveIteratorIterator($directory);
        return iterator_count($iterator) - 1; // Subtract directory entries
    }

    private function get_media_files() {
        $upload_dir = wp_upload_dir();
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload_dir['basedir']));
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->isReadable()) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }

    private function url_to_filename($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        $path = $path ? $path : 'index';
        
        // Convert to filesystem-safe name
        $filename = sanitize_title($path);
        $filename = str_replace('-', '/', $filename); // Maintain directory structure
        return $filename . '.html';
    }

    private function get_inlined_html($url, &$errors) {
        $retry_count = 0;
        
        while ($retry_count < $this->max_retries) {
            try {
                $response = $this->enhanced_remote_get($url);
                
                if (is_wp_error($response)) {
                    throw new Exception($response->get_error_message());
                }
                
                $html = wp_remote_retrieve_body($response);
                
                if (empty($html)) {
                    throw new Exception('Empty response body');
                }
                
                return $this->process_html($html, $url);
                
            } catch (Exception $e) {
                $retry_count++;
                sleep($this->retry_delay);
                
                if ($retry_count >= $this->max_retries) {
                    $errors[$url] = $e->getMessage();
                    error_log("TurnStatic Pro failed to process $url: " . $e->getMessage());
                }
            }
        }
        
        return false;
    }

    private function enhanced_remote_get($url) {
        // First try with WP HTTP API
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'redirection' => 5
        ]);
        
        // Fallback to cURL if WP HTTP fails
        if (is_wp_error($response) && function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $html = curl_exec($ch);
            
            if (curl_errno($ch)) {
                return new WP_Error('curl_error', curl_error($ch));
            }
            
            $response = [
                'body' => $html,
                'response' => ['code' => 200]
            ];
            
            curl_close($ch);
        }
        
        return $response;
    }

    private function process_html($html, $base_url) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        
        $xpath = new DOMXPath($doc);
        
        // Process CSS
        foreach ($xpath->query("//link[@rel='stylesheet']") as $link) {
            $href = $link->getAttribute('href');
            $css = $this->fetch_resource($href, $base_url);
            
            if ($css) {
                $style = $doc->createElement('style', $css);
                $link->parentNode->replaceChild($style, $link);
            }
        }
        
        // Process JavaScript
        foreach ($xpath->query("//script[@src]") as $script) {
            $src = $script->getAttribute('src');
            $js = $this->fetch_resource($src, $base_url);
            
            if ($js) {
                $new_script = $doc->createElement('script', $js);
                $script->parentNode->replaceChild($new_script, $script);
            }
        }
        
        // Process images and other assets
        $this->process_assets($xpath, $doc, $base_url);
        
        // Remove WordPress-specific elements
        $this->remove_dynamic_elements($xpath);
        
        return $doc->saveHTML();
    }

    private function fetch_resource($url, $base_url) {
        // Convert to absolute URL
        if (!parse_url($url, PHP_URL_SCHEME)) {
            $url = $this->resolve_relative_url($url, $base_url);
        }
        
        // Skip external resources
        if (parse_url($url, PHP_URL_HOST) !== parse_url(home_url(), PHP_URL_HOST)) {
            return false;
        }
        
        $response = $this->enhanced_remote_get($url);
        return is_wp_error($response) ? false : wp_remote_retrieve_body($response);
    }

    private function resolve_relative_url($url, $base) {
        if (strpos($url, '//') === 0) {
            return parse_url($base, PHP_URL_SCHEME) . ':' . $url;
        }
        
        $base_parts = parse_url($base);
        $base_path = isset($base_parts['path']) ? dirname($base_parts['path']) : '';
        
        if ($url[0] === '/') {
            return $base_parts['scheme'] . '://' . $base_parts['host'] . $url;
        }
        
        return $base_parts['scheme'] . '://' . $base_parts['host'] . $base_path . '/' . $url;
    }

    private function process_assets($xpath, $doc, $base_url) {
        $elements = [
            'img' => 'src',
            'link' => 'href',
            'script' => 'src',
            'source' => 'src',
            'audio' => 'src',
            'video' => 'src',
            'track' => 'src',
        ];
        
        foreach ($elements as $tag => $attr) {
            foreach ($xpath->query("//{$tag}[@{$attr}]") as $el) {
                $url = $el->getAttribute($attr);
                
                if (!$url) continue;
                
                $absolute_url = $this->resolve_relative_url($url, $base_url);
                $relative_path = str_replace(home_url('/'), '', $absolute_url);
                
                $el->setAttribute($attr, $relative_path);
            }
        }
    }

    private function remove_dynamic_elements($xpath) {
        $dynamic = [
            '//comment()',
            '//*[contains(@class, "dynamic-class")]',
            '//form',
            '//*[@id="wpadminbar"]',
            '//link[@rel="edituri"]',
            '//meta[@name="generator"]',
            '//script[contains(@src, "wp-includes")]',
            '//link[contains(@href, "wp-includes")]'
        ];
        
        foreach ($dynamic as $query) {
            while ($node = $xpath->query($query)->item(0)) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private function verify_request() {
        check_ajax_referer('turnstatic_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied', 403);
        }
    }
}

new TurnStaticPro_Enhanced();
