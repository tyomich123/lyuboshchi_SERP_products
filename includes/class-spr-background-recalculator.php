<?php
/**
 * Клас для фонового перерахунку релевантності
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Background_Recalculator {

    private static $instance = null;
    private $batch_size;

    private const OPTION_KEY = 'spr_recalc_state';
    private const ACTION_HOOK = 'spr_recalc_batch';
    private const ACTION_GROUP = 'spr_recalc';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->batch_size = (int) apply_filters('spr_recalc_batch_size', 200);

        add_action(self::ACTION_HOOK, array($this, 'process_batch'), 10, 2);
        add_action('spr_recalc_watchdog', array($this, 'watchdog'));
        add_action('init', array($this, 'maybe_schedule_watchdog'));
        add_filter('cron_schedules', array($this, 'register_cron_schedule'));
    }

    /**
     * Запуск фонового перерахунку
     */
    public function start() {
        if (!function_exists('as_enqueue_async_action')) {
            return new WP_Error('spr_no_action_scheduler', __('Action Scheduler не доступний.', 'smart-product-ranking'));
        }

        $total = $this->get_total_products();

        $state = array(
            'status' => 'running',
            'total' => $total,
            'processed' => 0,
            'batch_size' => $this->batch_size,
            'next_offset' => 0,
            'started_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'finished_at' => null,
            'last_error' => '',
        );

        $this->update_state($state);

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_HOOK, array(), self::ACTION_GROUP);
        }

        $this->enqueue_next_batch(0);

        return $state;
    }

    /**
     * Отримання поточного стану
     */
    public function get_state() {
        $state = get_option(self::OPTION_KEY, array());

        if (empty($state)) {
            return array(
                'status' => 'idle',
                'total' => 0,
                'processed' => 0,
                'batch_size' => $this->batch_size,
                'next_offset' => 0,
                'started_at' => null,
                'updated_at' => null,
                'finished_at' => null,
                'last_error' => '',
            );
        }

        return $state;
    }

    /**
     * Обробка батча продуктів
     */
    public function process_batch($offset = 0, $limit = 0) {
        $state = $this->get_state();

        if ($state['status'] !== 'running') {
            return;
        }

        $limit = $limit ? (int) $limit : $this->batch_size;
        $offset = (int) $offset;

        $product_ids = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'post_status' => 'publish',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        if (empty($product_ids)) {
            $this->finish($state);
            return;
        }

        $ranking_engine = SPR_Ranking_Engine::get_instance();

        foreach ($product_ids as $product_id) {
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

            if (!empty($categories)) {
                $ranking_engine->update_product_relevance($product_id, $categories);
            }
        }

        $processed = $state['processed'] + count($product_ids);
        $state['processed'] = min($processed, $state['total']);
        $state['next_offset'] = $offset + $limit;
        $state['updated_at'] = current_time('mysql');

        if ($state['processed'] >= $state['total']) {
            $this->finish($state);
            return;
        }

        $this->update_state($state);
        $this->enqueue_next_batch($state['next_offset']);
    }

    /**
     * Перевірка та відновлення процесу
     */
    public function watchdog() {
        $state = $this->get_state();

        if ($state['status'] !== 'running') {
            return;
        }

        if ($this->has_pending_batches()) {
            return;
        }

        $this->enqueue_next_batch($state['next_offset']);
    }

    private function get_total_products() {
        $counts = wp_count_posts('product');
        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    private function enqueue_next_batch($offset) {
        $limit = $this->batch_size;

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                self::ACTION_HOOK,
                array('offset' => (int) $offset, 'limit' => (int) $limit),
                self::ACTION_GROUP
            );
        }
    }

    private function finish($state) {
        $state['status'] = 'completed';
        $state['updated_at'] = current_time('mysql');
        $state['finished_at'] = current_time('mysql');
        $this->update_state($state);
    }

    private function update_state($state) {
        update_option(self::OPTION_KEY, $state, false);
    }

    private function has_pending_batches() {
        if (!function_exists('as_has_scheduled_action')) {
            return false;
        }

        $has_pending = as_has_scheduled_action(self::ACTION_HOOK, null, self::ACTION_GROUP);

        if ($has_pending) {
            return true;
        }

        if (class_exists('ActionScheduler_Store')) {
            $in_progress = as_get_scheduled_actions(array(
                'hook' => self::ACTION_HOOK,
                'group' => self::ACTION_GROUP,
                'status' => ActionScheduler_Store::STATUS_RUNNING,
                'per_page' => 1,
            ));

            return !empty($in_progress);
        }

        return false;
    }

    public function register_cron_schedule($schedules) {
        if (!isset($schedules['spr_five_minutes'])) {
            $schedules['spr_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Кожні 5 хвилин (SPR)', 'smart-product-ranking'),
            );
        }

        return $schedules;
    }

    public function maybe_schedule_watchdog() {
        if (!wp_next_scheduled('spr_recalc_watchdog')) {
            wp_schedule_event(time() + 300, 'spr_five_minutes', 'spr_recalc_watchdog');
        }
    }
}