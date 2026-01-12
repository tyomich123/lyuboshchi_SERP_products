<?php
/**
 * Plugin Name: Smart Product Ranking for WooCommerce
 * Plugin URI: https://yoursite.com
 * Description: Інтелектуальне ранжування продуктів на основі релевантності категорії та поведінкових факторів
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * Text Domain: smart-product-ranking
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.3.7
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Захист від прямого доступу
if (!defined('ABSPATH')) {
    exit;
}

// Визначення констант
define('SPR_VERSION', '1.0.0');
define('SPR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPR_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Перевірка наявності WooCommerce
function spr_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'spr_woocommerce_missing_notice');
        deactivate_plugins(SPR_PLUGIN_BASENAME);
        return false;
    }
    return true;
}

function spr_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('Smart Product Ranking потребує встановленого та активованого WooCommerce!', 'smart-product-ranking'); ?></p>
    </div>
    <?php
}

// Основний клас плагіна
class Smart_Product_Ranking {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Перевірка WooCommerce при активації
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        // Ініціалізація плагіна
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    public function activate() {
        if (!spr_check_woocommerce()) {
            return;
        }
        
        // Створення таблиць в БД
        $this->create_tables();
        
        // Встановлення початкових опцій
        $this->set_default_options();
    }
    
    public function init() {
        if (!spr_check_woocommerce()) {
            return;
        }
        
        // Завантаження файлів
        $this->load_dependencies();
        
        // Ініціалізація компонентів
        $this->init_hooks();
    }
    
    private function load_dependencies() {
        require_once SPR_PLUGIN_DIR . 'includes/class-spr-tracker.php';
        require_once SPR_PLUGIN_DIR . 'includes/class-spr-ranking-engine.php';
        require_once SPR_PLUGIN_DIR . 'includes/class-spr-query-modifier.php';
        require_once SPR_PLUGIN_DIR . 'includes/class-spr-admin.php';
        require_once SPR_PLUGIN_DIR . 'includes/class-spr-semantic-matcher.php';
        require_once SPR_PLUGIN_DIR . 'includes/class-spr-background-recalculator.php';
    }
    
    private function init_hooks() {
        // Ініціалізація трекера поведінки
        SPR_Tracker::get_instance();

        // Ініціалізація модифікатора запитів
        SPR_Query_Modifier::get_instance();

        // Ініціалізація фонового перерахунку
        SPR_Background_Recalculator::get_instance();
        
        // Адмін панель
        if (is_admin()) {
            SPR_Admin::get_instance();
        }
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Таблиця для відстеження переглядів
        $table_views = $wpdb->prefix . 'spr_product_views';
        $sql_views = "CREATE TABLE IF NOT EXISTS $table_views (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            category_id bigint(20) NOT NULL,
            view_date datetime DEFAULT CURRENT_TIMESTAMP,
            user_id bigint(20) DEFAULT NULL,
            session_id varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY category_id (category_id),
            KEY view_date (view_date)
        ) $charset_collate;";
        
        // Таблиця для кешування релевантності
        $table_relevance = $wpdb->prefix . 'spr_product_relevance';
        $sql_relevance = "CREATE TABLE IF NOT EXISTS $table_relevance (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            category_id bigint(20) NOT NULL,
            relevance_score decimal(10,4) DEFAULT 0,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_category (product_id, category_id),
            KEY relevance_score (relevance_score)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_views);
        dbDelta($sql_relevance);
    }
    
    private function set_default_options() {
        $default_options = array(
            'title_weight' => 10,
            'description_weight' => 5,
            'sales_weight' => 3,
            'reviews_weight' => 2,
            'views_weight' => 1,
            'enable_semantic_matching' => true,
            'cache_duration' => 3600,
            'track_anonymous_users' => true
        );
        
        if (!get_option('spr_settings')) {
            update_option('spr_settings', $default_options);
        }
    }
}

// Ініціалізація плагіна
function spr_init() {
    return Smart_Product_Ranking::get_instance();
}

// Запуск плагіна
add_action('plugins_loaded', 'spr_init', 10);

// Деактивація
register_deactivation_hook(__FILE__, 'spr_deactivate');
function spr_deactivate() {
    // Очищення кешу
    wp_cache_flush();
}