<?php
/**
 * Plugin Name: Node Service Bridge
 * Description: Integrates WordPress with Node.js service for enhanced functionality
 * Version: 1.0.0
 * Author: Kitchen Sink Addon
 * Site: {{SITE_NAME}}
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Node Service Bridge - Main Plugin Class
 *
 * This plugin provides bidirectional communication between WordPress
 * and a Node.js service running alongside the site.
 */
class NodeServiceBridge {
    /**
     * Node service port
     * @var int
     */
    private $service_port;

    /**
     * Service base URL
     * @var string
     */
    private $service_url;

    /**
     * Site identifier
     * @var string
     */
    private $site_id = '{{SITE_ID}}';

    /**
     * Constructor - Initialize the bridge
     */
    public function __construct() {
        $this->discover_service_port();
        $this->service_url = "http://localhost:{$this->service_port}";

        // Core hooks
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_notices', array($this, 'check_service_status'));
        add_action('wp_ajax_node_service_status', array($this, 'ajax_service_status'));

        // WordPress event hooks for Node.js notifications
        add_action('publish_post', array($this, 'notify_post_published'), 10, 2);
        add_action('user_register', array($this, 'notify_user_registered'));
        add_action('woocommerce_order_status_completed', array($this, 'notify_order_completed'));

        // Add helper functions to global scope
        if (!function_exists('node_service_call')) {
            function node_service_call($endpoint, $data = array(), $method = 'POST') {
                global $node_service_bridge;
                return $node_service_bridge->call_service($endpoint, $data, $method);
            }
        }

        // Set global reference
        $GLOBALS['node_service_bridge'] = $this;
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Add shortcodes
        add_shortcode('node_service_status', array($this, 'shortcode_service_status'));

        // Enqueue admin scripts if needed
        if (is_admin()) {
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }
    }

    /**
     * Discover the Node service port
     */
    private function discover_service_port() {
        // Method 1: Try to read port from file (fastest)
        // ABSPATH is typically /path/to/site/app/public/
        // We need to go up to site root and into node-wp-bridge

        // Try multiple possible locations (handles different Local configurations)
        // Remove trailing slash from ABSPATH for consistent path handling
        $abs_clean = rtrim(ABSPATH, '/');

        $possible_paths = array(
            // Standard Local path structure: /path/to/site/app/public -> /path/to/site
            dirname($abs_clean, 2) . '/node-wp-bridge/.port',  // Up 2 from public
            // Alternative if there's extra nesting
            dirname($abs_clean, 3) . '/node-wp-bridge/.port',  // Up 3 levels
            // Direct replacement method (most reliable for Local)
            str_replace('/app/public', '', $abs_clean) . '/node-wp-bridge/.port'
        );

        foreach ($possible_paths as $port_file) {
            if (file_exists($port_file)) {
                $port_content = trim(file_get_contents($port_file));
                if (is_numeric($port_content)) {
                    $this->service_port = intval($port_content);
                    // Debug: Log where we found the port
                    error_log("Node Service Bridge: Found port $this->service_port in $port_file");
                    return;
                }
            }
        }

        // Method 2: Check database option
        $saved_port = get_option('node_wp_bridge_port');
        if ($saved_port) {
            $this->service_port = intval($saved_port);
            error_log("Node Service Bridge: Using database port $this->service_port");
            return;
        }

        // Method 3: Default port
        $this->service_port = 3000;
        error_log("Node Service Bridge: Using default port 3000. ABSPATH=" . ABSPATH);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Service status endpoint
        register_rest_route('bridge/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_service_status'),
            'permission_callback' => '__return_true'
        ));

        // Update service port endpoint
        register_rest_route('bridge/v1', '/port', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_update_service_port'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        // Proxy endpoint for Node service
        register_rest_route('bridge/v1', '/proxy/(?P<endpoint>[a-zA-Z0-9\-\/]+)', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'rest_proxy_to_node'),
            'permission_callback' => '__return_true',
            'args' => array(
                'endpoint' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // Webhook receiver from Node
        register_rest_route('bridge/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_receive_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * REST: Get service status
     */
    public function rest_get_service_status() {
        $response = wp_remote_get("{$this->service_url}/health", array(
            'timeout' => 5
        ));

        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => 'Node service not responding',
                'port' => $this->service_port,
                'error' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        return array(
            'status' => 'connected',
            'port' => $this->service_port,
            'response' => json_decode($body, true)
        );
    }

    /**
     * REST: Update service port
     */
    public function rest_update_service_port($request) {
        $port = intval($request->get_param('port'));

        if ($port < 1024 || $port > 65535) {
            return new WP_Error('invalid_port', 'Port must be between 1024 and 65535');
        }

        update_option('node_wp_bridge_port', $port);
        $this->service_port = $port;
        $this->service_url = "http://localhost:{$port}";

        return array('success' => true, 'port' => $port);
    }

    /**
     * REST: Proxy requests to Node service
     */
    public function rest_proxy_to_node($request) {
        $endpoint = $request->get_param('endpoint');
        $method = $request->get_method();

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json'
            )
        );

        if ($method === 'POST') {
            $args['body'] = json_encode($request->get_json_params());
        }

        $response = wp_remote_request("{$this->service_url}/{$endpoint}", $args);

        if (is_wp_error($response)) {
            return new WP_Error('proxy_error', $response->get_error_message());
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * REST: Receive webhook from Node
     */
    public function rest_receive_webhook($request) {
        $data = $request->get_json_params();

        // Process webhook based on type
        $event_type = isset($data['type']) ? $data['type'] : 'unknown';

        // Log webhook
        error_log("Received webhook from Node service: {$event_type}");

        // Trigger WordPress action for other plugins to hook into
        do_action('node_service_webhook', $event_type, $data);

        return array('received' => true, 'event' => $event_type);
    }

    /**
     * Call Node service endpoint
     */
    public function call_service($endpoint, $data = array(), $method = 'POST') {
        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json'
            )
        );

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request("{$this->service_url}/{$endpoint}", $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        return array(
            'success' => true,
            'data' => json_decode(wp_remote_retrieve_body($response), true)
        );
    }

    /**
     * Notify Node service when post is published
     */
    public function notify_post_published($ID, $post) {
        $this->call_service('webhook', array(
            'event' => 'post_published',
            'data' => array(
                'id' => $ID,
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'author' => $post->post_author,
                'date' => $post->post_date
            )
        ));
    }

    /**
     * Notify Node service when user registers
     */
    public function notify_user_registered($user_id) {
        $user = get_userdata($user_id);
        $this->call_service('webhook', array(
            'event' => 'user_registered',
            'data' => array(
                'id' => $user_id,
                'email' => $user->user_email,
                'username' => $user->user_login,
                'display_name' => $user->display_name
            )
        ));
    }

    /**
     * Notify Node service when order is completed (WooCommerce)
     */
    public function notify_order_completed($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        $this->call_service('webhook', array(
            'event' => 'order_completed',
            'data' => array(
                'order_id' => $order_id,
                'total' => $order->get_total(),
                'customer_email' => $order->get_billing_email(),
                'items' => array_map(function($item) {
                    return array(
                        'name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total' => $item->get_total()
                    );
                }, $order->get_items())
            )
        ));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Node Service Bridge',
            'Node Service',
            'manage_options',
            'node-service-bridge',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Node Service Bridge</h1>

            <div class="card">
                <h2>Service Status</h2>
                <p>Port: <strong><?php echo esc_html($this->service_port); ?></strong></p>
                <p>URL: <strong><?php echo esc_html($this->service_url); ?></strong></p>
                <div id="service-status">Checking status...</div>
            </div>

            <div class="card">
                <h2>Test Endpoints</h2>
                <p>
                    <button class="button" onclick="testNodeEndpoint('/health')">Test Health</button>
                    <button class="button" onclick="testNodeEndpoint('/sync')">Test Sync</button>
                    <button class="button" onclick="testNodeEndpoint('/config')">Get Config</button>
                </p>
                <pre id="test-result"></pre>
            </div>

            <div class="card">
                <h2>Usage Examples</h2>
                <h3>PHP:</h3>
                <pre>
// Call Node service from PHP
$result = node_service_call('ai/process', array(
    'prompt' => 'Generate a blog post about...'
));

// Get service instance
global $node_service_bridge;
$status = $node_service_bridge->call_service('health');
                </pre>

                <h3>JavaScript:</h3>
                <pre>
// Call from JavaScript
fetch('/wp-json/bridge/v1/proxy/ai/process', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        prompt: 'Generate content...'
    })
}).then(response => response.json());
                </pre>
            </div>
        </div>

        <script>
        function testNodeEndpoint(endpoint) {
            const resultEl = document.getElementById('test-result');
            resultEl.textContent = 'Loading...';

            fetch('/wp-json/bridge/v1/proxy' + endpoint.replace(/^\//, ''))
                .then(response => response.json())
                .then(data => {
                    resultEl.textContent = JSON.stringify(data, null, 2);
                })
                .catch(error => {
                    resultEl.textContent = 'Error: ' + error.message;
                });
        }

        // Check status on load
        fetch('/wp-json/bridge/v1/status')
            .then(response => response.json())
            .then(data => {
                const statusEl = document.getElementById('service-status');
                if (data.status === 'connected') {
                    statusEl.innerHTML = '<span style="color: green;">✓ Connected</span>';
                } else {
                    statusEl.innerHTML = '<span style="color: red;">✗ Not Connected</span>';
                }
            });
        </script>
        <?php
    }

    /**
     * Check service status and show admin notice if not running
     */
    public function check_service_status() {
        // Only show on admin pages, not on every request
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // Check if we recently checked (cache for 5 minutes)
        $last_check = get_transient('node_service_last_check');
        if ($last_check !== false) {
            return;
        }

        $response = wp_remote_get("{$this->service_url}/health", array(
            'timeout' => 2
        ));

        set_transient('node_service_last_check', time(), 300); // Cache for 5 minutes

        if (is_wp_error($response)) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong>Node Service:</strong> The Node.js service is not responding on port <?php echo esc_html($this->service_port); ?>. Some features may be unavailable.</p>
            </div>
            <?php
        }
    }

    /**
     * AJAX handler for service status
     */
    public function ajax_service_status() {
        $response = wp_remote_get("{$this->service_url}/health", array(
            'timeout' => 5
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Service not responding',
                'port' => $this->service_port
            ));
        }

        wp_send_json_success(json_decode(wp_remote_retrieve_body($response), true));
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ('tools_page_node-service-bridge' !== $hook) {
            return;
        }

        wp_enqueue_script('node-service-admin', plugin_dir_url(__FILE__) . 'admin.js', array('jquery'), '1.0.0', true);
        wp_localize_script('node-service-admin', 'nodeService', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('node-service'),
            'port' => $this->service_port
        ));
    }

    /**
     * Shortcode: Display service status
     */
    public function shortcode_service_status($atts) {
        $response = wp_remote_get("{$this->service_url}/health", array(
            'timeout' => 2
        ));

        if (is_wp_error($response)) {
            return '<span class="node-service-status offline">Node Service: Offline</span>';
        }

        return '<span class="node-service-status online">Node Service: Online</span>';
    }
}

// Initialize the bridge
new NodeServiceBridge();

// Optional: Add custom WP-CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('node-service', 'NodeServiceCLI');

    class NodeServiceCLI {
        /**
         * Check Node service status
         */
        public function status() {
            global $node_service_bridge;
            $result = $node_service_bridge->call_service('health', array(), 'GET');

            if ($result['success']) {
                WP_CLI::success('Node service is running');
                WP_CLI::log(print_r($result['data'], true));
            } else {
                WP_CLI::error('Node service is not responding: ' . $result['error']);
            }
        }

        /**
         * Call Node service endpoint
         */
        public function call($args, $assoc_args) {
            global $node_service_bridge;

            $endpoint = $args[0];
            $method = isset($assoc_args['method']) ? $assoc_args['method'] : 'GET';
            $data = isset($assoc_args['data']) ? json_decode($assoc_args['data'], true) : array();

            $result = $node_service_bridge->call_service($endpoint, $data, $method);

            if ($result['success']) {
                WP_CLI::success('Service call successful');
                WP_CLI::log(print_r($result['data'], true));
            } else {
                WP_CLI::error('Service call failed: ' . $result['error']);
            }
        }
    }
}