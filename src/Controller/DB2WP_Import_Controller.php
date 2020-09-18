<?php
/**
 * WordPress Importer class controller for managing the import process of an external database query results.
 *
 * @package WordPress
 */

namespace Forum_One\DB2WP_Import_CLI\Controller;

use Forum_One\DB2WP_Import_CLI\DB2WP_Mapper;

/**
 * DB2WP importer class.
 */
class DB2WP_Import_Controller {

	var $posts = array();

	var $processed_posts = array();

	var $featured_images   = array();

	/**
	 * The post_type in WordPress to do the import operation on.
	 *
	 * @access   protected
	 * @var      string    $post_type
	 */
	protected $post_type;

	/**
	 * Initialize the import controller for a given post type.
	 *
	 * @param string $post_type
	 */
	public function __construct( $post_type ) {
		$this->post_type = $post_type;
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param array $extdb_rows
	 */
	public function import( $extdb_rows ) {
		$this->import_start( $extdb_rows );

		wp_suspend_cache_invalidation( true );
		$this->process_posts();
		wp_suspend_cache_invalidation( false );

		$this->remap_featured_images();

		$this->import_end();
	}

	/**
	 * Update _thumbnail_id meta to new, imported attachment IDs
	 */
	public function remap_featured_images() {
		// cycle through posts that have a featured image.
		foreach ( $this->featured_images as $post_id => $value ) {
			if ( isset( $this->processed_posts[ $value ] ) ) {
				$new_id = $this->processed_posts[ $value ];
				// only update if there's a difference.
				if ( $new_id != $value ) {
					update_post_meta( $post_id, '_thumbnail_id', $new_id );
				}
			}
		}
	}

	/**
	 * Prepares us for the task of processing queried data
	 *
	 * @param array $extdb_rows
	 */
	public function import_start( $extdb_rows ) {
		$import_data = $this->map_import_data_to_wp( $extdb_rows );
		$this->posts = $import_data['posts'];

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	public function import_end() {
		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
					delete_option( "{$tax}_children" );
					_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		\WP_CLI::success( __( 'All done.', 'db2wp-import-cli' ) );

		do_action( 'db2wp_import_end' );
	}

	/**
	 * Create new posts based on import information
	 */
	public function process_posts() {
		$this->posts = apply_filters( 'db2wp_import_posts', $this->posts );

		// Define $postdata array defaults.
		$postdata_defaults = array(
			'import_id'         => null,
			'post_author'       => null,
			'post_date'         => null,
			'post_date_gmt'     => null,
			'post_content'      => null,
			'post_excerpt'      => null,
			'post_title'        => null,
			'post_status'       => null,
			'post_name'         => null,
			'comment_status'    => null,
			'ping_status'       => null,
			'guid'              => null,
			'post_parent'       => null,
			'menu_order'        => null,
			'post_type'         => null,
			'post_password'     => null,
		);

		// Loop and process each result row.
		foreach ( $this->posts as $post ) {
			$post = apply_filters( 'db2wp_import_post_data_raw', $post );

			if ( ! post_type_exists( $post['post_type'] ) ) {
				\WP_CLI::warning(
					sprintf(
						__( 'Failed to import "%1$s": Invalid post type %2$s', 'db2wp-import-cli' ),
						esc_html( $post['post_title'] ),
						esc_html( $post['post_type'] )
					)
				);
				continue;
			}

			if ( isset( $this->processed_posts[ $post['extdb_id'] ] ) && ! empty( $post['extdb_id'] ) ) {
					continue;
			}

			// Get the post_type object in WP.
			$post_type_object = get_post_type_object( $post['post_type'] );

			// Check if post exists.
			$post_exists = post_exists( $post['post_title'] );

			/**
			  * Filter ID of the existing post corresponding to post currently importing.
				*
				* Return 0 to force the post to be imported. Filter the ID to be something else
				* to override which existing post is mapped to the imported post.
				*
				* @see post_exists()
				* @param int   $post_exists  Post ID, or 0 if post did not exist.
				* @param array $post         The post array to be inserted.
				*/
			$post_exists = apply_filters( 'db2wp_import_existing_post', $post_exists, $post );

			if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
				\WP_CLI::line(
					sprintf(
						__( '-- %1$s "%2$s" already exists.', 'db2wp-import-cli' ),
						$post_type_object->labels->singular_name,
						esc_html( $post['post_title'] )
					)
				);
				// $comment_post_ID = $post_exists;
				// $post_id = $post_exists;
				$this->processed_posts[ intval( $post['extdb_id'] ) ] = intval( $post_exists );
			} else {

				// Merge post data with defaults.
				$postdata = wp_parse_args( $post, $postdata_defaults );

				$original_record_id = $post['extdb_id'];
				$postdata = apply_filters( 'db2wp_import_post_data_processed', $postdata, $post );

				$postdata = wp_slash( $postdata );

				// Write to WP.
				$post_id = wp_insert_post( $postdata, true );
				// $comment_post_id = $post_id;
				do_action( 'db2wp_import_insert_post', $post_id, $original_record_id, $postdata, $post );

				if ( is_wp_error( $post_id ) ) {
					\WP_CLI::warning(
						sprintf(
							__( 'Failed to import %1$s "%2$s"', 'db2wp-import-cli' ),
							$post_type_object->labels->singular_name,
							esc_html( $post['post_title'] )
						)
					);

					if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
						\WP_CLI::log( $post_id->get_error_message() );
					}
					continue;
				}

				// map pre-import ID to local ID.
				$this->processed_posts[ intval( $post['extdb_id'] ) ] = (int) $post_id;

				if ( ! isset( $post['terms'] ) ) {
					$post['terms'] = array();
				}

				$post['terms'] = apply_filters( 'db2wp_import_post_terms', $post['terms'], $post_id, $post );

				// add categories, tags and other terms.
				if ( ! empty( $post['terms'] ) ) {
					$terms_to_set = array();
					foreach ( $post['terms'] as $taxonomy => $term_names ) {
						// Remove duplicates if any from the array with term names.
						if ( apply_filters( 'db2wp_array_unique_terms', false ) ) {
							$term_names = array_unique( $term_names );
						}
						// Loop all term names.
						foreach ( $term_names as $term_name ) {
							$term_slug = sanitize_title( $term_name );
							$term_exists = term_exists( $term_slug, $taxonomy );
							$term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
							if ( ! $term_id ) {
								$t = wp_insert_term( $term_name, $taxonomy, array( 'slug' => $term_slug ) );
								if ( ! is_wp_error( $t ) ) {
										$term_id = $t['term_id'];
										do_action( 'db2wp_import_insert_term', $t, $term_name, $post_id, $post );
								} else {
									if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
										\WP_CLI::warning(
											sprintf(
												__( 'Failed to import %1$s %2$s: %3$s', 'db2wp-import-cli' ),
												esc_html( $taxonomy ),
												esc_html( $term_name ),
												$t->get_error_message()
											)
										);
									} else {
										\WP_CLI::warning(
											sprintf(
												__( 'Failed to import %1$s %2$s', 'db2wp-import-cli' ),
												esc_html( $taxonomy ),
												esc_html( $term_name )
											)
										);
									}
									do_action( 'db2wp_import_insert_term_failed', $t, $term_name, $post_id, $post );
									continue;
								}
							}
							$terms_to_set[ $taxonomy ][] = intval( $term_id );
						}
					}

					foreach ( $terms_to_set as $tax => $ids ) {
						$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
						do_action( 'db2wp_import_set_post_terms', $tt_ids, $ids, $tax, $post_id, $post );
					}
					unset( $post['terms'], $terms_to_set );
				}

				// Process post meta.
				if ( ! isset( $post['postmeta'] ) ) {
					$post['postmeta'] = array();
				}

				$post['postmeta'] = apply_filters( 'db2wp_import_post_meta', $post['postmeta'], $post_id, $post );

				// add/update post meta.
				if ( ! empty( $post['postmeta'] ) ) {
					foreach ( $post['postmeta'] as $meta ) {
						$key   = apply_filters( 'db2wp_import_post_meta_key', $meta['key'], $post_id, $post );
						// export gets meta straight from the DB so could have a serialized string.
						$value = apply_filters( 'db2wp_import_post_meta_value', maybe_unserialize( $meta['value'] ), $post_id, $post );

						if ( $key ) {

							add_post_meta( $post_id, wp_slash( $key ), wp_slash_strings_only( $value ), true );

							do_action( 'db2wp_import_post_meta', $post_id, $key, $value );

							// if the post has a featured image, take note of this in case of remap.
							if ( '_thumbnail_id' == $key ) {
								$this->featured_images[ $post_id ] = (int) $value;
							}
						}
					}
				}
			}
		}

		unset( $this->posts );
	}

	/**
	 * Maps import data to WordPress format.
	 *
	 * @param array $data Information in random or unknow formats.
	 * @return array Information organized after passing through the mapper.
	 */
	public function map_import_data_to_wp( $data ) {
			$mapper = new DB2WP_Mapper();
			return $mapper->map( $data, $this->post_type );
	}

}
