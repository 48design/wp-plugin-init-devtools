<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class __PLUGIN_CLASSNAME__ {

    public function __construct() {
        $this->add_actions();
    }

    private function add_actions() {
        $actions = [
            /* 'wordpress_hook' => 'class_method' */
            'init' => 'load_textdomain',
            'admin_enqueue_scripts' => 'enqueue_scripts',
        ];
        foreach ($actions as $hook => $callback) {
            add_action($hook, [$this, $callback]);
        }
    }

    public function enqueue_scripts($hook_suffix) {
		// if($hook_suffix !== 'media_page_svg-color-changer') return;

        // use the constant WP___PLUGIN_SHORTHAND___VERSION as the version argument
        // for cache-busting of CSS and JS files

        // wp_enqueue_script('__PLUGIN_SHORTHAND___js', plugins_url('/js/__PLUGIN_SHORTHAND__.js', WP___PLUGIN_SHORTHAND___MAINFILE), array(), WP___PLUGIN_SHORTHAND___VERSION, true);
        // wp_enqueue_style('__PLUGIN_SHORTHAND___css', plugins_url('/css/__PLUGIN_SHORTHAND__.css', WP___PLUGIN_SHORTHAND___MAINFILE), array(), WP___PLUGIN_SHORTHAND___VERSION);

        // wp_localize_script('__PLUGIN_SHORTHAND___js', '__PLUGIN_SHORTHAND___vars', array(
        //     'nonce' => wp_create_nonce('__PLUGIN_SHORTHAND___nonce'),
        //     'ajax_url' => admin_url('admin-ajax.php'),
        // ));
    }

    public function load_textdomain() {
        load_plugin_textdomain( '__PLUGIN_SLUG__', false, dirname( plugin_basename( WP_SVGCC_MAINFILE ) ) . '/languages' );
    }

}
