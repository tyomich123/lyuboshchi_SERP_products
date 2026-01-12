<?php
/**
 * Адміністративна панель плагіна
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Додавання меню в адмінці
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Реєстрація налаштувань
        add_action('admin_init', array($this, 'register_settings'));
        
        // AJAX обробники
        add_action('wp_ajax_spr_recalculate_all', array($this, 'ajax_recalculate_all'));
        add_action('wp_ajax_spr_clear_data', array($this, 'ajax_clear_data'));
        
        // Додавання колонки релевантності в список продуктів
        add_filter('manage_product_posts_columns', array($this, 'add_relevance_column'));
        add_action('manage_product_posts_custom_column', array($this, 'display_relevance_column'), 10, 2);
        
        // Додавання стилів
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Додавання меню в адмінці
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Smart Product Ranking', 'smart-product-ranking'),
            __('Product Ranking', 'smart-product-ranking'),
            'manage_woocommerce',
            'smart-product-ranking',
            array($this, 'render_settings_page'),
            'dashicons-sort',
            56
        );
        
        add_submenu_page(
            'smart-product-ranking',
            __('Налаштування', 'smart-product-ranking'),
            __('Налаштування', 'smart-product-ranking'),
            'manage_woocommerce',
            'smart-product-ranking',
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            'smart-product-ranking',
            __('Статистика', 'smart-product-ranking'),
            __('Статистика', 'smart-product-ranking'),
            'manage_woocommerce',
            'spr-statistics',
            array($this, 'render_statistics_page')
        );
    }
    
    /**
     * Реєстрація налаштувань
     */
    public function register_settings() {
        register_setting('spr_settings_group', 'spr_settings', array($this, 'sanitize_settings'));
        
        // Секція вагових коефіцієнтів
        add_settings_section(
            'spr_weights_section',
            __('Вагові коефіцієнти ранжування', 'smart-product-ranking'),
            array($this, 'weights_section_callback'),
            'smart-product-ranking'
        );
        
        add_settings_field(
            'title_weight',
            __('Вага назви продукту', 'smart-product-ranking'),
            array($this, 'number_field_callback'),
            'smart-product-ranking',
            'spr_weights_section',
            array('field' => 'title_weight', 'default' => 10)
        );
        
        add_settings_field(
            'description_weight',
            __('Вага опису продукту', 'smart-product-ranking'),
            array($this, 'number_field_callback'),
            'smart-product-ranking',
            'spr_weights_section',
            array('field' => 'description_weight', 'default' => 5)
        );
        
        add_settings_field(
            'sales_weight',
            __('Вага продажів', 'smart-product-ranking'),
            array($this, 'number_field_callback'),
            'smart-product-ranking',
            'spr_weights_section',
            array('field' => 'sales_weight', 'default' => 3)
        );
        
        add_settings_field(
            'reviews_weight',
            __('Вага відгуків', 'smart-product-ranking'),
            array($this, 'number_field_callback'),
            'smart-product-ranking',
            'spr_weights_section',
            array('field' => 'reviews_weight', 'default' => 2)
        );
        
        add_settings_field(
            'views_weight',
            __('Вага переглядів', 'smart-product-ranking'),
            array($this, 'number_field_callback'),
            'smart-product-ranking',
            'spr_weights_section',
            array('field' => 'views_weight', 'default' => 1)
        );
        
        // Секція додаткових налаштувань
        add_settings_section(
            'spr_additional_section',
            __('Додаткові налаштування', 'smart-product-ranking'),
            array($this, 'additional_section_callback'),
            'smart-product-ranking'
        );
        
        add_settings_field(
            'enable_semantic_matching',
            __('Семантичне порівняння', 'smart-product-ranking'),
            array($this, 'checkbox_field_callback'),
            'smart-product-ranking',
            'spr_additional_section',
            array('field' => 'enable_semantic_matching', 'label' => __('Включити семантичне порівняння текстів', 'smart-product-ranking'))
        );
        
        add_settings_field(
            'track_anonymous_users',
            __('Відстеження анонімних користувачів', 'smart-product-ranking'),
            array($this, 'checkbox_field_callback'),
            'smart-product-ranking',
            'spr_additional_section',
            array('field' => 'track_anonymous_users', 'label' => __('Відстежувати перегляди анонімних користувачів', 'smart-product-ranking'))
        );
        
        add_settings_field(
            'cache_duration',
            __('Тривалість кешу (секунди)', 'smart-product-ranking'),
            array($this, 'number_field_callback'),
            'smart-product-ranking',
            'spr_additional_section',
            array('field' => 'cache_duration', 'default' => 3600)
        );
    }
    
    /**
     * Колбеки для секцій
     */
    public function weights_section_callback() {
        echo '<p>' . __('Налаштуйте вагу кожного фактору для обчислення релевантності. Чим більше число, тим більший вплив фактор має на ранжування.', 'smart-product-ranking') . '</p>';
    }
    
    public function additional_section_callback() {
        echo '<p>' . __('Додаткові параметри роботи плагіна.', 'smart-product-ranking') . '</p>';
    }
    
    /**
     * Колбеки для полів
     */
    public function number_field_callback($args) {
        $settings = get_option('spr_settings', array());
        $field = $args['field'];
        $value = isset($settings[$field]) ? $settings[$field] : $args['default'];
        
        echo '<input type="number" name="spr_settings[' . esc_attr($field) . ']" value="' . esc_attr($value) . '" step="0.1" min="0" class="regular-text">';
    }
    
    public function checkbox_field_callback($args) {
        $settings = get_option('spr_settings', array());
        $field = $args['field'];
        $checked = isset($settings[$field]) && $settings[$field] ? 'checked' : '';
        
        echo '<label>';
        echo '<input type="checkbox" name="spr_settings[' . esc_attr($field) . ']" value="1" ' . $checked . '>';
        echo ' ' . esc_html($args['label']);
        echo '</label>';
    }
    
    /**
     * Санітізація налаштувань
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        $number_fields = array('title_weight', 'description_weight', 'sales_weight', 'reviews_weight', 'views_weight', 'cache_duration');
        
        foreach ($number_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = floatval($input[$field]);
            }
        }
        
        $sanitized['enable_semantic_matching'] = isset($input['enable_semantic_matching']) ? true : false;
        $sanitized['track_anonymous_users'] = isset($input['track_anonymous_users']) ? true : false;
        
        return $sanitized;
    }
    
    /**
     * Рендеринг сторінки налаштувань
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('spr_settings_group');
                do_settings_sections('smart-product-ranking');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Інструменти', 'smart-product-ranking'); ?></h2>
            
            <div class="spr-tools">
                <p>
                    <button type="button" class="button button-primary" id="spr-recalculate-all">
                        <?php _e('Перерахувати релевантність для всіх продуктів', 'smart-product-ranking'); ?>
                    </button>
                    <span class="spinner" style="float: none; margin: 0 10px;"></span>
                    <span class="spr-message"></span>
                </p>
                
                <p class="description">
                    <?php _e('Це оновить скор релевантності для всіх продуктів. Може зайняти деякий час.', 'smart-product-ranking'); ?>
                </p>
                
                <hr>
                
                <p>
                    <button type="button" class="button button-secondary" id="spr-clear-data">
                        <?php _e('Очистити всі дані відстеження', 'smart-product-ranking'); ?>
                    </button>
                    <span class="spr-clear-message"></span>
                </p>
                
                <p class="description">
                    <?php _e('Це видалить всі дані про перегляди та релевантність. Використовуйте з обережністю!', 'smart-product-ranking'); ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Рендеринг сторінки статистики
     */
    public function render_statistics_page() {
        global $wpdb;
        
        $views_table = $wpdb->prefix . 'spr_product_views';
        $relevance_table = $wpdb->prefix . 'spr_product_relevance';
        
        $total_views = $wpdb->get_var("SELECT COUNT(*) FROM $views_table");
        $unique_products_viewed = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM $views_table");
        $total_relevance_scores = $wpdb->get_var("SELECT COUNT(*) FROM $relevance_table");
        
        // Топ 10 найбільш переглянутих продуктів
        $top_viewed = $wpdb->get_results("
            SELECT v.product_id, p.post_title, COUNT(*) as views
            FROM $views_table v
            LEFT JOIN {$wpdb->posts} p ON v.product_id = p.ID
            WHERE v.view_date > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY v.product_id
            ORDER BY views DESC
            LIMIT 10
        ");
        
        // Топ 10 продуктів з найвищою релевантністю
        $top_relevant = $wpdb->get_results("
            SELECT r.product_id, p.post_title, r.relevance_score, t.name as category_name
            FROM $relevance_table r
            LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
            LEFT JOIN {$wpdb->terms} t ON r.category_id = t.term_id
            ORDER BY r.relevance_score DESC
            LIMIT 10
        ");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Статистика ранжування', 'smart-product-ranking'); ?></h1>
            
            <div class="spr-stats-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
                <div class="spr-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1;">
                    <h3 style="margin: 0 0 10px 0;"><?php _e('Всього переглядів', 'smart-product-ranking'); ?></h3>
                    <p style="font-size: 32px; margin: 0; font-weight: bold;"><?php echo number_format($total_views); ?></p>
                </div>
                
                <div class="spr-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a;">
                    <h3 style="margin: 0 0 10px 0;"><?php _e('Унікальних продуктів', 'smart-product-ranking'); ?></h3>
                    <p style="font-size: 32px; margin: 0; font-weight: bold;"><?php echo number_format($unique_products_viewed); ?></p>
                </div>
                
                <div class="spr-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #d63638;">
                    <h3 style="margin: 0 0 10px 0;"><?php _e('Розраховано скорів', 'smart-product-ranking'); ?></h3>
                    <p style="font-size: 32px; margin: 0; font-weight: bold;"><?php echo number_format($total_relevance_scores); ?></p>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin: 20px 0;">
                <div>
                    <h2><?php _e('Топ 10 найбільш переглянутих продуктів (30 днів)', 'smart-product-ranking'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Продукт', 'smart-product-ranking'); ?></th>
                                <th><?php _e('Перегляди', 'smart-product-ranking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_viewed)) : ?>
                                <?php foreach ($top_viewed as $item) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo get_edit_post_link($item->product_id); ?>">
                                                <?php echo esc_html($item->post_title); ?>
                                            </a>
                                        </td>
                                        <td><?php echo number_format($item->views); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="2"><?php _e('Немає даних', 'smart-product-ranking'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div>
                    <h2><?php _e('Топ 10 продуктів за релевантністю', 'smart-product-ranking'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Продукт', 'smart-product-ranking'); ?></th>
                                <th><?php _e('Категорія', 'smart-product-ranking'); ?></th>
                                <th><?php _e('Скор', 'smart-product-ranking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_relevant)) : ?>
                                <?php foreach ($top_relevant as $item) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo get_edit_post_link($item->product_id); ?>">
                                                <?php echo esc_html($item->post_title); ?>
                                            </a>
                                        </td>
                                        <td><?php echo esc_html($item->category_name); ?></td>
                                        <td><?php echo round($item->relevance_score, 2); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="3"><?php _e('Немає даних', 'smart-product-ranking'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Перерахунок релевантності
     */
    public function ajax_recalculate_all() {
        check_ajax_referer('spr-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Недостатньо прав', 'smart-product-ranking')));
        }
        
        $ranking_engine = SPR_Ranking_Engine::get_instance();
        $ranking_engine->bulk_update_relevance();
        
        wp_send_json_success(array('message' => __('Релевантність успішно перераховано!', 'smart-product-ranking')));
    }
    
    /**
     * AJAX: Очищення даних
     */
    public function ajax_clear_data() {
        check_ajax_referer('spr-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Недостатньо прав', 'smart-product-ranking')));
        }
        
        global $wpdb;
        
        $views_table = $wpdb->prefix . 'spr_product_views';
        $relevance_table = $wpdb->prefix . 'spr_product_relevance';
        
        $wpdb->query("TRUNCATE TABLE $views_table");
        $wpdb->query("TRUNCATE TABLE $relevance_table");
        
        wp_cache_flush();
        
        wp_send_json_success(array('message' => __('Дані успішно очищено!', 'smart-product-ranking')));
    }
    
    /**
     * Додавання колонки релевантності
     */
    public function add_relevance_column($columns) {
        $columns['spr_relevance'] = __('Релевантність', 'smart-product-ranking');
        return $columns;
    }
    
    /**
     * Відображення колонки релевантності
     */
    public function display_relevance_column($column, $post_id) {
        if ($column === 'spr_relevance') {
            global $wpdb;
            
            $relevance_table = $wpdb->prefix . 'spr_product_relevance';
            
            $avg_score = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(relevance_score) FROM $relevance_table WHERE product_id = %d",
                $post_id
            ));
            
            if ($avg_score !== null) {
                echo round($avg_score, 2) . '%';
            } else {
                echo '—';
            }
        }
    }
    
    /**
     * Підключення скриптів адмінки
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'smart-product-ranking') === false) {
            return;
        }
        
        wp_enqueue_script(
            'spr-admin',
            SPR_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            SPR_VERSION,
            true
        );
        
        wp_localize_script('spr-admin', 'sprAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spr-admin-nonce')
        ));
    }
}