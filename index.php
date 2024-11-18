<?php
/*
 * Plugin Name: 
 * Plugin URI: https://shop.48design.com/
 * Description: 
 * Version: 1.0.0
 * Author: 48DESIGN GmbH
 * Author URI: https://www.vierachtdesign.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: 
 * Domain Path: /languages
 * Tested up to: 6.7
 * Requires at least: 3.9.0
 * Requires PHP: 5.6.0
 */

defined('ABSPATH') or die('Direct script access disallowed.');
defined('WP___PLUGIN_SHORTHAND___VERSION') or define('WP___PLUGIN_SHORTHAND___VERSION', '1.0.0');

$vad_laum_file = __DIR__ . '/includes/vad-updater/wp-licensing-and-update-module.php';
if ( is_file( $vad_laum_file ) ) {
	require_once( $vad_laum_file );
	$VAD_WP_LAUM->productOptions = array(
		'PID' => '',
		'multiple' => false,
	);
}

$activation_check = function () {
    $current_slug = plugin_basename(__DIR__);
	$is_premium = substr($current_slug, -8) === '-premium';

	if($is_premium) {
		$free_slug = str_replace('-premium', '', $current_slug);
		$free_plugin_file = $free_slug . '/' . $free_slug . '.php';

		if (is_plugin_active($free_plugin_file)) {
		  deactivate_plugins($free_plugin_file);
		  add_action('admin_notices', function() {
			echo '<div class="notice notice-warning is-dismissible"><p>The free version of the plugin was deactivated as the premium version is now active.</p></div>';
		  });
		}
	} else {
		$premium_slug = $current_slug . '-premium';
		$premium_plugin_file = $premium_slug . '/' . $premium_slug . '.php';
		$free_plugin_file = $current_slug . '/' . $current_slug . '.php';

		if (is_plugin_active($premium_plugin_file)) {
		  deactivate_plugins($free_plugin_file);
		  wp_die( __( 'Cannot activate free version of the plugin while the premium version is active.', '__PLUGIN_SLUG__' ) );
		}
	}
};
register_activation_hook(__FILE__, $activation_check);

if(!class_exists('__PLUGIN_CLASSNAME__')) {
	define('WP___PLUGIN_SHORTHAND___MAINFILE', __FILE__);
	include_once(plugin_dir_path(__FILE__) . 'class-__PLUGIN_SLUG__.php');

	function __PLUGIN_SHORTHAND___initialize() {
		new __PLUGIN_CLASSNAME__();
	}

	add_action('plugins_loaded', '__PLUGIN_SHORTHAND___initialize');
}
