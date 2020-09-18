<?php

namespace Forum_One\DB2WP_Import_CLI;

class Command_Loader {

	/**
	 * The plugin's instance.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    Plugin $plugin This plugin's instance.
	 */
	private $plugin;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 *
	 * @param Plugin $plugin This plugin's instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}


	/**
	 * Initialize commands.
	 */
	public function init() {
		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'db2wp-import', __NAMESPACE__ . '\Command\DB2WP_Import_Command' );
		}
	}

}
