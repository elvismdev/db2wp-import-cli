<?php
/**
 * DB2WP Import CLI
 *
 * @package PluginPackage
 *
 * @wordpress-plugin
 * Plugin Name:       DB2WP Import CLI
 * Plugin URI:        http://forumone.github.io/db2wp-import-cli
 * Description:       Provides a customizable command line interface for performing data migrations from another DB into WordPress.
 * Version:           1.0
 * Author:            Forum One
 * Author URI:        http://forumone.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       db2wp-import-cli
 * Domain Path:       /languages
 */

 // If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

/**
 * Require utility functions.
 */
require_once dirname( __FILE__ ) . '/utils/db2wp_functions.php';
require_once dirname( __FILE__ ) . '/utils/db2wp_gutenberg_functions.php';

/**
 * Function to define plugin constants like the external DB parameters.
 *
 * @param string $constant_name The constant name.
 * @param string $value The constant value.
 */
function db2wp_define_constants( $constant_name, $value ) {
		$constant_name = 'DB2WP_' . $constant_name;
	if ( ! defined( $constant_name ) ) {
		define( $constant_name, $value );
	}
}
db2wp_define_constants( 'EXTDB_DBNAME', '' );
db2wp_define_constants( 'EXTDB_USER', '' );
db2wp_define_constants( 'EXTDB_PASSWORD', '' );
db2wp_define_constants( 'EXTDB_HOST', '' );
db2wp_define_constants( 'EXTDB_DRIVER', '' );

/**
 * Begins execution of the plugin.
 *
 * @since    1.0
 */
\add_action(
	'plugins_loaded',
	function () {
		$plugin = new \Forum_One\DB2WP_Import_CLI\Plugin();
		$plugin->run();
	}
);
