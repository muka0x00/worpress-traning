<?php
/*
Plugin Name: Export Users Meta (Simple)
Description: Adds an admin menu to export users with all usermeta as JSON or CSV. Secure (nonce + capability) and uses pagination to avoid memory spikes.
Version: 1.1
Author: Ibrahim Sallam
License: GPLv2+
Text Domain: eum
*/

if (!defined('ABSPATH')) {
    exit;
}

class Export_Users_Meta_Plugin {

    /**
     * Default users per page for batched exports.
     * Filterable via 'eum_per_page'.
     */
    private $per_page = 200;

    public function __construct() {
        // Allow other code to change required capability and items per batch.
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_eum_export', [$this, 'handle_export']);

        // load translations (if present)
        load_plugin_textdomain('eum', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Allow external modification of per-page value.
        $this->per_page = (int) apply_filters('eum_per_page', $this->per_page);
    }

    /**
     * Register admin menu page.
     * Capability is filterable via 'eum_capability'.
     */
    public function register_admin_page() {
        $capability = apply_filters('eum_capability', 'manage_options');

        add_menu_page(
            __('Export Users', 'eum'),
            __('Export Users', 'eum'),
            $capability,
            'eum-export-users',
            [$this, 'render_admin_page'],
            'dashicons-download',
            70
        );
    }

    /**
     * Render admin UI for starting export.
     * Uses wp_nonce_field() so handle_export can use check_admin_referer().
     */
    public function render_admin_page() {
        if (!current_user_can(apply_filters('eum_capability', 'manage_options'))) {
            wp_die(__('You do not have permission to access this page', 'eum'));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Export Users with Meta', 'eum'); ?></h1>
            <p><?php esc_html_e('Export all users and their usermeta as JSON or CSV. The export is paginated to avoid memory spikes.', 'eum'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="eum_export">
                <?php wp_nonce_field('eum_export_action', 'eum_nonce'); ?>

                <p>
                    <label>
                        <input type="radio" name="format" value="json" checked> <?php esc_html_e('JSON', 'eum'); ?>
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="radio" name="format" value="csv"> <?php esc_html_e('CSV', 'eum'); ?>
                    </label>
                </p>

                <p>
                    <label>
                        <?php esc_html_e('Include hidden/meta keys (prefix _ )?', 'eum'); ?>
                        <input type="checkbox" name="include_hidden" value="1">
                    </label>
                </p>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Start Export', 'eum'); ?></button>
                </p>
            </form>

            <hr>
            <h2><?php esc_html_e('Ibrahim Sallam', 'eum'); ?></h2>
        <?php
    }

    /**
     * Handle export request (admin_post_eum_export).
     * Verifies capability and nonce and sanitizes input.
     */
    public function handle_export() {
        // capability & nonce checks
        $capability = apply_filters('eum_capability', 'manage_options');
        if (!current_user_can($capability)) {
            wp_die(__('Forbidden', 'eum'), '', ['response' => 403]);
        }

        if (!isset($_POST['eum_nonce']) || !check_admin_referer('eum_export_action', 'eum_nonce')) {
            wp_die(__('Invalid nonce', 'eum'), '', ['response' => 403]);
        }

        // sanitize input
        $format = isset($_POST['format']) && in_array($_POST['format'], ['json', 'csv'], true) ? $_POST['format'] : 'json';
        $include_hidden = !empty($_POST['include_hidden']) ? true : false;

        // Remove time limit for long exports when possible.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Clear output buffers to avoid unexpected memory growth and ensure headers take effect.
        while (ob_get_level()) {
            @ob_end_clean();
        }

        if ($format === 'json') {
            $this->export_json($include_hidden);
        } else {
            $this->export_csv($include_hidden);
        }

        // end script after outputting file
        exit;
    }

    /**
     * Generator to fetch users in batches to keep memory usage low.
     * Yields arrays of WP_User objects.
     */
    private function fetch_users_in_batches() {
        $page = 1;
        $per_page = max(1, (int) $this->per_page);

        while (true) {
            $args = [
                'number' => $per_page,
                'paged'  => $page,
                'fields' => 'all_with_meta',
            ];
            $user_query = new WP_User_Query($args);
            $users = $user_query->get_results();

            if (empty($users)) {
                break;
            }

            yield $users;
            $page++;
        }
    }

    /**
     * Export users as a JSON array streamed to the client.
     */
    private function export_json($include_hidden) {
        // prepare filename and allow filter
        $filename = apply_filters('eum_export_filename', 'users-export-' . current_time('Y-m-d_H-i-s') . '.json', 'json');

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Expires: 0');

        echo '[';
        $firstUser = true;

        foreach ($this->fetch_users_in_batches() as $users) {
            foreach ($users as $user) {
                $userdata = [
                    'ID'              => $user->ID,
                    'user_login'      => $user->user_login,
                    'user_nicename'   => $user->user_nicename,
                    'user_email'      => $user->user_email,
                    'display_name'    => $user->display_name,
                    'roles'           => $user->roles,
                    'user_registered' => $user->user_registered,
                ];

                // get all usermeta. get_user_meta returns arrays for each key.
                $meta = get_user_meta($user->ID);
                if (!$include_hidden) {
                    $meta = array_filter($meta, function($k) {
                        return strpos($k, '_') !== 0;
                    }, ARRAY_FILTER_USE_KEY);
                }

                // flatten meta values
                $flat_meta = [];
                foreach ($meta as $k => $v) {
                    if (is_array($v) && count($v) === 1) {
                        $flat_meta[$k] = maybe_unserialize($v[0]);
                    } else {
                        $flat_meta[$k] = array_map(function($item) {
                            return maybe_unserialize($item);
                        }, $v);
                    }
                }

                $userdata['meta'] = $flat_meta;

                if (!$firstUser) {
                    echo ',';
                } else {
                    $firstUser = false;
                }

                echo wp_json_encode($userdata);
                if (function_exists('flush')) {
                    flush();
                }
            }
        }

        echo ']';
    }

    /**
     * Export users as CSV. Two-pass: collect headers (meta keys), then output rows.
     */
    private function export_csv($include_hidden) {
        $all_meta_keys = [];

        // PASS 1: collect meta keys (batched)
        foreach ($this->fetch_users_in_batches() as $users) {
            foreach ($users as $user) {
                $meta = get_user_meta($user->ID);
                foreach ($meta as $k => $_v) {
                    if (!$include_hidden && strpos($k, '_') === 0) {
                        continue;
                    }
                    $all_meta_keys[$k] = true;
                }
            }
        }

        $meta_keys = array_keys($all_meta_keys);

        // base columns
        $base_cols = ['ID', 'user_login', 'user_nicename', 'user_email', 'display_name', 'user_registered', 'roles'];

        // header row: allow filter to modify columns
        $headers = apply_filters('eum_csv_headers', array_merge($base_cols, $meta_keys), $meta_keys);

        // prepare filename
        $filename = apply_filters('eum_export_filename', 'users-export-' . current_time('Y-m-d_H-i-s') . '.csv', 'csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(__('Could not open output stream', 'eum'));
        }

        // write header row
        fputcsv($output, $headers);

        // PASS 2: output rows
        foreach ($this->fetch_users_in_batches() as $users) {
            foreach ($users as $user) {
                $row = [];
                $row[] = $user->ID;
                $row[] = $user->user_login;
                $row[] = $user->user_nicename;
                $row[] = $user->user_email;
                $row[] = $user->display_name;
                $row[] = $user->user_registered;
                $row[] = implode('|', (array) $user->roles);

                $meta = get_user_meta($user->ID);
                // ensure we iterate the same meta_keys order as header
                $header_meta_keys = array_slice($headers, count($base_cols));
                foreach ($header_meta_keys as $mk) {
                    // header might be modified by filters; if mk is not an actual meta key, try fallback
                    $meta_key = $mk;
                    if (!isset($meta[$meta_key])) {
                        // if headers were filtered to prefix/suffix names, fallback to original meta_keys list
                        $meta_key = $mk;
                    }

                    if (isset($meta[$meta_key])) {
                        $val = $meta[$meta_key];
                        if (is_array($val)) {
                            if (count($val) === 1) {
                                $maybe = maybe_unserialize($val[0]);
                                if (is_array($maybe) || is_object($maybe)) {
                                    $cell = wp_json_encode($maybe);
                                } else {
                                    $cell = (string) $maybe;
                                }
                            } else {
                                $processed = array_map(function($it){ return maybe_unserialize($it); }, $val);
                                $cell = wp_json_encode($processed);
                            }
                        } else {
                            $cell = maybe_unserialize($val);
                        }

                        if (is_array($cell) || is_object($cell)) {
                            $cell = wp_json_encode($cell);
                        } else {
                            $cell = (string) $cell;
                        }
                    } else {
                        $cell = '';
                    }

                    $row[] = $cell;
                }

                fputcsv($output, $row);

                if (function_exists('flush')) {
                    fflush($output);
                    flush();
                }
            }
        }

        fclose($output);
    }
}

new Export_Users_Meta_Plugin();

/*
 * Activation and Deactivation Hooks
 * (kept intentionally minimal)
 */
register_activation_hook( __FILE__, 'eum_activate_plugin' );
register_deactivation_hook( __FILE__, 'eum_deactivate_plugin' );
function eum_activate_plugin() {
    // No activation actions required currently.
}
function eum_deactivate_plugin() {
    // No deactivation actions required currently.
}