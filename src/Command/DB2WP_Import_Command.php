<?php

namespace Forum_One\DB2WP_Import_CLI\Command;

use Forum_One\DB2WP_Import_CLI\Controller\DB2WP_Import_Controller;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A customizable command for performing data migrations from another DB into WordPress.
 */
class DB2WP_Import_Command extends \WP_CLI_Command {

	public $processed_posts = array();

	private $do_redirect = false;

	private $redirection_group_id = 0;

	/**
	 * WPDB instance.
	 *
	 * @var wpdb
	 */
	protected $wpdb;

	/**
	 * The WP upload directory.
	 *
	 * @var array
	 */
	protected $wp_upload_dir;

	/**
	 * This home_url() value without protocol.
	 *
	 * @var string
	 */
	protected $home_url_domain;

	/**
	 * Imports contents from a given database connection.
	 *
	 * @since  1.0.0
	 * @author Elvis Morales
	 */
	public function __invoke( $args, $assoc_args ) {
		// Get WPDB.
		global $wpdb;
		$this->wpdb = $wpdb;

		// Set the path to the WP uploads directory.
		$this->wp_upload_dir = wp_upload_dir();

		// Set home_url() value removing the protocol.
		$this->home_url_domain = $this->remove_http( get_home_url() );

		$defaults   = array(
			'post_type' => null,
		);
		$assoc_args = wp_parse_args( $assoc_args, $defaults );

		// Check if we want to do Redirects.
		if ( true === \WP_CLI\Utils\get_flag_value( $assoc_args, 'do-redirect' ) ) {
			// Check if redirection plugin is active and classes available.
			if ( class_exists( '\Red_Item' ) && class_exists( '\Red_Group' ) ) {
				$this->do_redirect = true;
				$this->redirection_group_id = $this->get_redirection_group_id();
			} else {
				\WP_CLI::error_multi_line(
					array(
						'Redirection plugin is not active.',
					)
				);
				\WP_CLI::error( '[--do-redirect] flag requires Redirection: https://wordpress.org/plugins/redirection/.' );
			}
		}

		// Add additional actions and filters.
		$this->add_db2wp_filters();

		\WP_CLI::log( 'Starting the import process...' );

		// Start the import.
		$this->import_from_db( $assoc_args );
	}

	/**
	 * Tries to find the Redirection group ID accosiated with DB2WP plugin.
	 * If not found attempts to create it automatically.
	 */
	private function get_redirection_group_id() {
		$db2wp_group_nameslug = 'db2wpmigration';
		// Get redirection groups.
		$redirection_groups = \Red_Group::get_all();

		// Find redirection slug in array by name.
		$plugin_redirection_group = array_filter(
			$redirection_groups,
			function ( $value ) use ( $db2wp_group_nameslug ) {
				return ( $value['name'] == $db2wp_group_nameslug );
			}
		);

		// If group not found, attempt to create it.
		if ( ! empty( $plugin_redirection_group ) ) {
			$plugin_redirection_group = reset( $plugin_redirection_group );
			return $plugin_redirection_group['id'];
		} else {
			$red_group = \Red_Group::create( $db2wp_group_nameslug, 1 );
			if ( $red_group ) {
				return $red_group->id;
			}
		}

		return 0;
	}

	/**
	 * Connects and query external DB with Doctrine.
	 *
	 * @param array $args
	 */
	private function import_from_db( $args ) {
		$db2wp_import = new DB2WP_Import_Controller( $args['post_type'] );
		$db2wp_import->processed_posts = $this->processed_posts;

		// Get a connection.
		$conn = $this->get_dbal_connection();

		// Set query builder.
		$builder = $conn->createQueryBuilder();
		$builder = apply_filters( 'db2wp_query_builder', $builder, $args['post_type'], $conn );

		// Get results.
		$extdb_query_results = $builder->execute()->fetchAll();

		// Process results with importer controller.
		$db2wp_import->import( $extdb_query_results );
		$this->processed_posts += $db2wp_import->processed_posts;

		return true;
	}

	/**
	 * Get Doctrine DBAL connection.
	 *
	 * @see https://www.doctrine-project.org/projects/doctrine-dbal/en/2.10/reference/configuration.html#configuration
	 * @return \Doctrine\DBAL\DriverManager
	 */
	private function get_dbal_connection() {
		// Get a connection.
		$connection_params = array(
			'dbname' => DB2WP_EXTDB_DBNAME,
			'user' => DB2WP_EXTDB_USER,
			'password' => DB2WP_EXTDB_PASSWORD,
			'host' => DB2WP_EXTDB_HOST,
			'driver' => DB2WP_EXTDB_DRIVER,
		);
		$connection_params = apply_filters( 'db2wp_connection_params', $connection_params );

		return \Doctrine\DBAL\DriverManager::getConnection( $connection_params );
	}

	/**
	 * Defines useful verbosity filters and actions for the DB2WP importer.
	 */
	private function add_db2wp_filters() {

		add_filter(
			'db2wp_import_posts',
			function( $posts ) {
				global $wpcli_import_counts;
				$wpcli_import_counts['current_post'] = 0;
				$wpcli_import_counts['total_posts']  = count( $posts );
				return $posts;
			},
			10
		);

		add_filter(
			'db2wp_import_post_data_raw',
			function( $post ) {
				global $wpcli_import_counts;

				$wpcli_import_counts['current_post']++;
				\WP_CLI::log( '' );
				\WP_CLI::log( '' );
				\WP_CLI::line( sprintf( 'Processing record extdb_id #%d ("%s") (post_type: %s)', $post['extdb_id'], $post['post_title'], $post['post_type'] ) );
				\WP_CLI::log( sprintf( '-- %s of %s (in last extdb fetch query)', number_format( $wpcli_import_counts['current_post'] ), number_format( $wpcli_import_counts['total_posts'] ) ) );
				\WP_CLI::log( '-- ' . date( 'r' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

				return $post;
			}
		);

		add_action(
			'db2wp_import_insert_post',
			function( $post_id, $original_post_id, $post, $postdata ) {
				global $wpcli_import_counts;
				if ( is_wp_error( $post_id ) ) {
					\WP_CLI::warning( 'Error importing post: ' . $post_id->get_error_code() );
				} else {
					\WP_CLI::success( "Imported post as post_id #{$post_id}" );

					// DO redirects if we want them.
					if ( true === $this->do_redirect ) {
						// Handle redirection.
						$this->handle_do_redirect( $post_id, $postdata );
					}

					// If we have content.
					if ( isset( $postdata['post_content'] ) && '' != $postdata['post_content'] ) {
						// Import images from body copy to WP media and update body copy URL references.
						$post_content = $this->sideload_img_tags( $post_id, $postdata['post_content'] );

						// Import linked document files from the body copy to WP media and update body copy URL references.
						$post_content = $this->sideload_a_tag_files( $post_id, $post_content );

						// Update post content.
						$this->wpdb->update( $this->wpdb->posts, array( 'post_content' => $post_content ), array( 'ID' => $post_id ) );
					}
				}

				if ( 0 === ( $wpcli_import_counts['current_post'] % 500 ) ) {
					\WP_CLI\Utils\wp_clear_object_cache();
					\WP_CLI::log( '-- Cleared object cache.' );
				}

			},
			10,
			4
		);

		add_action(
			'db2wp_import_insert_term',
			function( $t, $term_name, $post_id, $post ) {
				\WP_CLI::success( "Created term \"{$term_name}\"" );
			},
			10,
			4
		);

		add_action(
			'db2wp_import_set_post_terms',
			function( $tt_ids, $term_ids, $taxonomy, $post_id, $post ) {
				\WP_CLI::success( 'Added terms (' . implode( ',', $term_ids ) . ") for taxonomy \"{$taxonomy}\"" );
			},
			10,
			5
		);

	}

	/**
	 * Imports to the WP media library the files linked a tags found in the content.
	 *
	 * @param int          $pid The parent post ID to attach the file.
	 * @param string       $content The content to update with local WP URLs to media files.
	 * @param string|array $ext The extensions of files to download.
	 */
	private function sideload_a_tag_files( $pid, $content ) {
		$files_uploads = apply_filters( 'db2wp_sideload_files_uploads_dir', $this->wp_upload_dir );

		// Define default file extensions to download.
		$file_ext_allowed = array(
			'doc',
			'docx',
			'odt',
			'pdf',
			'xls',
			'xlsx',
			'ods',
			'ppt',
			'pptx',
			'txt',
		);
		$file_ext_allowed = apply_filters( 'db2wp_sideload_file_ext_allowed ', $file_ext_allowed );

		\WP_CLI::line( __( 'Download file links in post_content to WordPress media library', 'db2wp-import-cli' ) );

		// Get all URL's from <a> tags.
		$crawler = new Crawler( $content );
		$tag_file_links = $crawler
		->filter( 'a' )
		->reduce(
			function ( Crawler $node, $i ) use ( $file_ext_allowed ) {
				// Get the value from the href attribute.
				$href = $node->attr( 'href' );
				// Check if the href value points to the specified file extensions and return the node. Otherwise remove the node.
				$file_ext = strtolower( $this->get_file_extension( $href ) );
				if ( ! in_array( $file_ext, $file_ext_allowed ) ) {
					return false;
				}

				return true;
			}
		)
		->each(
			function ( Crawler $node, $i ) {
				return $node->attr( 'href' );
			}
		);

		$target_dir = $files_uploads['path'];
		$target_url = $files_uploads['url'];
		foreach ( $tag_file_links as $file_url ) {

			if ( empty( $file_url ) ) {
				continue;
			}

			// If file URL match with home_url() value then this is an already downloaded WP file. Skip to next.
			if ( $this->is_wp_domain_match( $file_url ) ) {
				continue;
			}

			$original_file_url = $file_url;
			$file_url = apply_filters( 'db2wp_post_content_file_a_tag_url', $file_url );

			// If no file full URL, continue.
			if ( empty( $file_url ) || ! preg_match( '%^(http|ftp)s?://%i', $file_url ) ) {
				continue;
			}

			$attid = false;

			// Trying to find existing file in media library .
			$file_name = $this->sanitize_filename( urldecode( $this->get_basename( $file_url ) ) );
			\WP_CLI::log(
				sprintf(
					__( '-- Searching for existing file `%1$s` by `_wp_attached_file` `%2$s`...', 'db2wp-import-cli' ),
					$file_url,
					$file_name
				)
			);
			$attch = $this->get_media_from_gallery( $file_name, $target_dir, 'files' );

			// Exisitng file founded .
			if ( $attch ) {
				$attid = $attch->ID;
				\WP_CLI::log(
					sprintf(
						__( '-- Existing file was found for post_content `%s`...', 'db2wp-import-cli' ),
						rawurldecode( $file_url )
					)
				);
			} else {

				\WP_CLI::log(
					sprintf(
						__( '-- File `%s` was not found in WordPress Media Library (attachments)...', 'db2wp-import-cli' ),
						rawurldecode( $file_url )
					)
				);

				// Download remote file and save it in WP media library.
				$url = html_entity_decode( trim( $file_url ), ENT_QUOTES );

				if ( empty( $url ) ) {
					continue;
				}

				\WP_CLI::log(
					sprintf(
						__( '-- Downloading file from `%s`', 'db2wp-import-cli' ),
						$url
					)
				);

				$file_basename = basename( $url );

				$tmp = download_url( $url );

				$file_array = array(
					'name' => $file_basename,
					'tmp_name' => $tmp,
				);

				if ( is_wp_error( $tmp ) ) {
					@unlink( $file_array['tmp_name'] );
					continue;
				}

				// Attempt to find the mime type if the file.
				$filetype = wp_check_filetype_and_ext( $tmp, $file_basename );
				if ( isset( $filetype['type'] ) && ! empty( $filetype['type'] ) ) {
					$file_array['type'] = $filetype['type'];
				}

				$attid = media_handle_sideload( $file_array, $pid );

				// Check response .
				if ( is_wp_error( $attid ) ) {
					\WP_CLI::warning(
						sprintf(
							__( 'File %1$s can not be downloaded, response %2$s', 'db2wp-import-cli' ),
							$url,
							maybe_serialize( $attid )
						)
					);
					@unlink( $file_array['tmp_name'] );
					continue;
				} else {
					$type = 'files';
					if ( 'images' == $type ) {
						\WP_CLI::log(
							sprintf(
								__( '-- Image `%s` has been successfully downloaded', 'db2wp-import-cli' ),
								$url
							)
						);
					} elseif ( 'files' == $type ) {
						\WP_CLI::log(
							sprintf(
								__( '-- File `%s` has been successfully downloaded', 'db2wp-import-cli' ),
								$url
							)
						);
					}

					\WP_CLI::success(
						sprintf(
							__( 'Created an attachment for file `%s`', 'db2wp-import-cli' ),
							$url
						)
					);
				}
			}

			// attachment founded or successfully created .
			if ( $attid ) {
				$attachment_url = wp_get_attachment_url( $attid );
				if ( $attachment_url ) {
					$content = str_replace( $original_file_url, $attachment_url, $content );
				}
			}
		}
		// Return updated post content.
		return $content;
	}

	/**
	 * Imports to the WP media library the images in img tags found in the content.
	 *
	 * @param string $content
	 * @param int    $pid
	 */
	private function sideload_img_tags( $pid, $content ) {
		$images_uploads = apply_filters( 'db2wp_sideload_images_uploads_dir', $this->wp_upload_dir );

		\WP_CLI::line( __( 'Download post_content images to WordPress media library', 'db2wp-import-cli' ) );

		// search for images in galleries.
		$galleries = array();
		if ( preg_match_all( '%\[gallery[^\]]*ids="([^\]^"]*)"[^\]]*\]%is', $content, $matches, PREG_PATTERN_ORDER ) ) {
			$galleries = array_unique( array_filter( $matches[1] ) );
		}
		$gallery_images = array();
		if ( ! empty( $galleries ) ) {
			foreach ( $galleries as $key => $gallery ) {
				$imgs = array_unique( array_filter( explode( ',', $gallery ) ) );
				if ( ! empty( $imgs ) ) {
					foreach ( $imgs as $img ) {
						if ( ! is_numeric( $img ) ) {
							$gallery_images[] = json_decode( base64_decode( $img ), true );
						}
					}
				}
			}
		}
		// search for images in <img> tags.
		$tag_images = array();
		if ( preg_match_all( '%<img\s[^>]*src=(?(?=")"([^"]*)"|(?(?=\')\'([^\']*)\'|([^\s>]*)))%is', $content, $matches, PREG_PATTERN_ORDER ) ) {
			$tag_images = array_unique( array_merge( array_filter( $matches[1] ), array_filter( $matches[2] ), array_filter( $matches[3] ) ) );
		}

		if ( preg_match_all( '%<img\s[^>]*srcset=(?(?=")"([^"]*)"|(?(?=\')\'([^\']*)\'|([^\s>]*)))%is', $content, $matches, PREG_PATTERN_ORDER ) ) {
			$srcset_images = array_unique( array_merge( array_filter( $matches[1] ), array_filter( $matches[2] ), array_filter( $matches[3] ) ) );
			if ( ! empty( $srcset_images ) ) {
				foreach ( $srcset_images as $srcset_image ) {
					$srcset = explode( ',', $srcset_image );
					$srcset = array_filter( $srcset );
					foreach ( $srcset as $srcset_img ) {
						$srcset_image_parts = explode( ' ', $srcset_img );
						foreach ( $srcset_image_parts as $srcset_image_part ) {
							if ( ! empty( filter_var( $srcset_image_part, FILTER_VALIDATE_URL ) ) ) {
								$tag_images[] = trim( $srcset_image_part );
							}
						}
					}
				}
			}
		}

		$images_sources = array(
			'gallery' => $gallery_images,
			'tag' => $tag_images,
		);

		$target_dir = $images_uploads['path'];
		$target_url = $images_uploads['url'];
		foreach ( $images_sources as $source_type => $images ) {

			if ( empty( $images ) ) {
				continue;
			}

			foreach ( $images as $image ) {

				if ( $source_type == 'gallery' ) {
					$image_data = $image;
					$image = $image_data['url'];
					$image_title = $image_data['title'];
					$image_caption = $image_data['caption'];
					$image_alt = $image_data['alt'];
					$image_description = $image_data['description'];
				}

				// If image URL match with home_url() value then this is an already downloaded WP file. Skip to next.
				if ( $this->is_wp_domain_match( $image ) ) {
					continue;
				}

				$original_image_url = $image;
				$image = apply_filters( 'db2wp_post_content_image_tag_url', $image );

				// If no image, continue.
				if ( empty( $image ) || ! preg_match( '%^(http|ftp)s?://%i', $image ) ) {
					continue;
				}

				$attid = false;

				// trying to find existing image in media library.
				$image_name  = $this->sanitize_filename( urldecode( $this->get_basename( $image ) ) );
				\WP_CLI::log(
					sprintf(
						__( '-- Searching for existing image `%1$s` by `_wp_attached_file` `%2$s`...', 'db2wp-import-cli' ),
						$image,
						$image_name
					)
				);
				$attch = $this->get_media_from_gallery( $image_name, $target_dir, 'images' );

				// exisitng image founded.
				if ( $attch ) {
					$attid = $attch->ID;
					\WP_CLI::log(
						sprintf(
							__( '-- Existing image was found for post_content `%s`...', 'db2wp-import-cli' ),
							rawurldecode( $image )
						)
					);
				} else {

					\WP_CLI::log(
						sprintf(
							__( '-- Image `%s` was not found in WordPress Media Library (attachments)...', 'db2wp-import-cli' ),
							rawurldecode( $image )
						)
					);

					// Download remote image and save it in images table.
					$url = html_entity_decode( trim( $image ), ENT_QUOTES );

					if ( empty( $url ) ) {
						continue;
					}

					\WP_CLI::log(
						sprintf(
							__( '-- Downloading image from `%s`', 'db2wp-import-cli' ),
							$url
						)
					);
					$attid = media_sideload_image( $url, $pid, null, 'id' );

					// Check response.
					if ( is_wp_error( $attid ) ) {
						\WP_CLI::warning(
							sprintf(
								__( 'File %1$s can not be downloaded, response %2$s', 'db2wp-import-cli' ),
								$url,
								maybe_serialize( $attid )
							)
						);
					} else {
						$type = 'images';
						if ( 'images' == $type ) {
							\WP_CLI::log(
								sprintf(
									__( '-- Image `%s` has been successfully downloaded', 'db2wp-import-cli' ),
									$url
								)
							);
						} elseif ( 'files' == $type ) {
							\WP_CLI::log(
								sprintf(
									__( '-- File `%s` has been successfully downloaded', 'db2wp-import-cli' ),
									$url
								)
							);
						}

						\WP_CLI::success(
							sprintf(
								__( 'Created an attachment for image `%s`', 'db2wp-import-cli' ),
								$url
							)
						);
					}
				}

				// attachment founded or successfully created.
				if ( $attid ) {

					if ( $source_type == 'gallery' ) {
						$content = str_replace( base64_encode( json_encode( $image_data ) ), $attid, $content );
					}
					$attachment_url = wp_get_attachment_url( $attid );
					if ( $attachment_url ) {
						$content = str_replace( $original_image_url, $attachment_url, $content );
					}

					if ( $source_type == 'gallery' ) {
						$update_attachment_meta = array();
						$update_attachment_meta['post_title'] = trim( $image_title );
						$update_attachment_meta['post_excerpt'] = trim( $image_caption );
						$update_attachment_meta['post_content'] = trim( $image_description );
						update_post_meta( $attid, '_wp_attachment_image_alt', trim( $image_alt ) );
						$this->wpdb->update( $this->wpdb->posts, $update_attachment_meta, array( 'ID' => $attid ) );
					}
				}
			}
		}

		// Return updated body content.
		return $content;
	}

	/**
	 * Removes HTTP protocol parts from a URL.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function remove_http( $url ) {
		$disallowed = array( 'http://', 'https://' );
		foreach ( $disallowed as $d ) {
			if ( strpos( $url, $d ) === 0 ) {
				return str_replace( $d, '', $url );
			}
		}
		return $url;
	}

	/**
	 * Checks if given URL this WP domain URL.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function is_wp_domain_match( $url ) {
		$whitelisted_domains = array( $this->home_url_domain );
		$domain = parse_url( $url, PHP_URL_HOST );
		$port = parse_url( $url, PHP_URL_PORT );

		// If there is a port append it to the domain.
		if ( $port ) {
			$domain .= ':' . $port;
		}

		// Check if we match the domain exactly.
		if ( in_array( $domain, $whitelisted_domains ) ) {
			return apply_filters( 'db2wp_is_wp_domain_match', true, $domain );
		}

		$valid = false;

		foreach ( $whitelisted_domains as $whitelisted_domain ) {
			$whitelisted_domain = '.' . $whitelisted_domain;
			if ( strpos( $domain, $whitelisted_domain ) === ( strlen( $domain ) - strlen( $whitelisted_domain ) ) ) {
				$valid = true;
				break;
			}
		}
		return apply_filters( 'db2wp_is_wp_domain_match', $valid, $domain );
	}

	/**
	 * Returns the extension of a file.
	 *
	 * @param string $str
	 * @return string
	 */
	private function get_file_extension( $str ) {
		$i = strrpos( $str, '.' );
		if ( ! $i ) {
			return '';
		}
		$l = strlen( $str ) - $i;
		$ext = substr( $str, $i + 1, $l );
		return ( strlen( $ext ) <= 4 ) ? $ext : '';
	}

	/**
	 * Searches for an existing image in the WP media library.
	 *
	 * @param string $image_name
	 * @param string $target_dir
	 */
	private function get_media_from_gallery( $image_name, $target_dir = false, $bundle_type = 'images' ) {

		$search_image_by_wp_attached_file = apply_filters( 'db2wp_search_image_by_wp_attached_file', true );
		if ( ! $search_image_by_wp_attached_file ) {
			return false;
		}

		// Get WPDB.
		$wpdb = $this->wpdb;

		$original_image_name = $image_name;

		if ( ! $target_dir ) {
			$target_dir = $this->wp_upload_dir['path'];
		}

		// Prepare scaled image file name.
		$scaled_name = $image_name;
		$ext = $this->get_file_extension( $image_name );
		if ( $ext ) {
			$scaled_name = str_replace( '.' . $ext, '-scaled.' . $ext, $image_name );
		}

		$attch = '';

		// search attachment by attached file.
		$attachment_metas = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->postmeta . " WHERE meta_key = %s AND (meta_value = %s OR meta_value = %s OR meta_value LIKE %s ESCAPE '$' OR meta_value LIKE %s ESCAPE '$');", '_wp_attached_file', $image_name, $scaled_name, '%/' . str_replace( '_', '$_', $image_name ), '%/' . str_replace( '_', '$_', $scaled_name ) ) );

		if ( ! empty( $attachment_metas ) ) {
			foreach ( $attachment_metas as $attachment_meta ) {
				$attch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->posts . ' WHERE ID = %d;', $attachment_meta->post_id ) );
				if ( ! empty( $attch ) ) {
					\WP_CLI::log(
						sprintf(
							__( '-- Found existing media with ID `%1$s` by meta key _wp_attached_file equals to `%2$s`...', 'db2wp-import-cli' ),
							$attch->ID,
							trim( $image_name )
						)
					);
					break;
				}
			}
		}

		if ( empty( $attch ) ) {
			$attachment_metas = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->postmeta . " WHERE meta_key = %s AND (meta_value = %s OR meta_value = %s OR meta_value LIKE %s ESCAPE '$' OR meta_value LIKE %s ESCAPE '$');", '_wp_attached_file', sanitize_file_name( $image_name ), sanitize_file_name( $scaled_name ), '%/' . str_replace( '_', '$_', sanitize_file_name( $image_name ) ), '%/' . str_replace( '_', '$_', sanitize_file_name( $scaled_name ) ) ) );

			if ( ! empty( $attachment_metas ) ) {
				foreach ( $attachment_metas as $attachment_meta ) {
					$attch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->posts . ' WHERE ID = %d;', $attachment_meta->post_id ) );
					if ( ! empty( $attch ) ) {
						\WP_CLI::log(
							sprintf(
								__( '-- Found existing media with ID `%1$s` by meta key _wp_attached_file equals to `%2$s`...', 'db2wp-import-cli' ),
								$attch->ID,
								sanitize_file_name( $image_name )
							)
						);
						break;
					}
				}
			}
		}

		if ( empty( $attch ) ) {
			// Search attachment by file name with extension.
			$attch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->posts . ' WHERE (post_title = %s OR post_name = %s) AND post_type = %s', $image_name, sanitize_title( $image_name ), 'attachment' ) . " AND post_mime_type LIKE 'image%';" );
			if ( ! empty( $attch ) ) {
				\WP_CLI::log(
					sprintf(
						__( '-- Found existing media with ID `%1$s` by post_title or post_name equals to `%2$s`...', 'db2wp-import-cli' ),
						$attch->ID,
						$image_name
					)
				);
			}
		}

		// Search attachment by file headers.
		if ( empty( $attch ) && @file_exists( $target_dir . DIRECTORY_SEPARATOR . $original_image_name ) ) {
			if ( 'images' == $bundle_type && ( $img_meta = wp_read_image_metadata( $target_dir . DIRECTORY_SEPARATOR . $original_image_name ) ) ) {
				if ( trim( $img_meta['title'] ) && ! is_numeric( sanitize_title( $img_meta['title'] ) ) ) {
					$img_title = $img_meta['title'];
					$attch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->posts . ' WHERE post_title = %s AND post_type = %s AND post_mime_type LIKE %s;', $img_title, 'attachment', 'image%' ) );
					if ( ! empty( $attch ) ) {
						\WP_CLI::log(
							sprintf(
								__( '-- Found existing media with ID `%1$s` by post_title equals to `%2$s`...', 'db2wp-import-cli' ),
								$attch->ID,
								$image_name
							)
						);
					}
				}
			}
			if ( empty( $attch ) ) {
				@unlink( $target_dir . DIRECTORY_SEPARATOR . $original_image_name );
			}
		}

		return apply_filters( 'db2wp_get_media_from_gallery', $attch, $original_image_name, $target_dir );
	}

	/**
	 * Gets the base name of a file.
	 *
	 * @param string $file
	 * @return string
	 */
	private function get_basename( $file ) {
		$a = explode( '/', $file );
		return end( $a );
	}

	/**
	 * Sanitazes a file name.
	 *
	 * @param string $filename
	 * @return string
	 */
	private function sanitize_filename( $filename ) {
		$filename = preg_replace( '/\?.*/', '', $filename );
		$filename_parts = explode( '.', $filename );
		if ( ! empty( $filename_parts ) && count( $filename_parts ) > 1 ) {
			$ext = end( $filename_parts );
			// Replace all weird characters.
			$sanitized = substr( $filename, 0, -( strlen( $ext ) + 1 ) );
			$sanitized = str_replace( '.', 'willbedots', $sanitized );
			$sanitized = str_replace( '_', 'willbetrimmed', $sanitized );
			$sanitized = sanitize_file_name( $sanitized );
			$sanitized = str_replace( 'willbetrimmed', '_', $sanitized );
			$sanitized = str_replace( 'willbedots', '.', $sanitized );
			// Replace dots inside filename
			// $sanitized = str_replace('.','-', $sanitized);.
			return $sanitized . '.' . $ext;
		}
		return $filename;
	}

	/**
	 * Set a redirection if any.
	 *
	 * @param int   $post_id The ID of the post just created.
	 * @param array $postdata The user-developer mapped data.
	 */
	private function handle_do_redirect( $post_id, $postdata ) {
		if ( isset( $postdata['do_redirect_source'] ) ) {
			// Prepare the source.
			$_source = wp_parse_url( $postdata['do_redirect_source'] );
			$source = '';
			if ( isset( $_source['path'] ) && ! empty( $_source['path'] ) ) {
				$source = $_source['path'];
				if ( isset( $_source['query'] ) && ! empty( $_source['query'] ) ) {
					$source .= '?' . $_source['query'];
				}
			}

			// Prepare the target.
			$permalink = get_permalink( $post_id );
			$target = wp_parse_url( $permalink, PHP_URL_PATH );

			// Create a redirect.
			if ( $source && $target ) {
				$this->create_redirect( $source, $target );
			}
		}
	}

	/**
	 * Creates a redirect in Redirection plugin.
	 *
	 * @see /wp-admin/tools.php?page=redirection.php
	 * @param string $source The page OLD PATH.
	 * @param string $target The page NEW WP PATH.
	 */
	private function create_redirect( $source, $target ) {
		$redirect_exists = false;

		// Check if a redirect for this source URL already exists.
		$redirects = \Red_Item::get_for_url( $source );
		// Redirects will be ordered by position. Run through the list until one fires.
		foreach ( (array) $redirects as $item ) {
			if ( $item->get_url() == $source ) {
				$redirect_exists = true;
				break;
			}
		}

		// If redirect for this source path doesn't exists yet, then create it.
		if ( ! $redirect_exists && ! empty( $this->redirection_group_id ) ) {
			// Create the redirection.
			$redirect = \Red_Item::create(
				array(
					'url' => $source,
					'action_data' => array(
						'url' => $target,
					),
					'regex'  => 0,
					'group_id'  => $this->redirection_group_id,
					'match_type'  => 'url',
					'action_type' => 'url',
					'action_code' => 301,
				)
			);

			if ( is_wp_error( $redirect ) ) {
				\WP_CLI::warning( '-- Error creating redirect: ' . $redirect->get_error_message() );
				// return $this->add_error_details( $redirect, __LINE__ );
			} else {
				\WP_CLI::success( 'Created redirect ("' . $source . ' => ' . $target . '")' );
			}
		} else {
			\WP_CLI::log(
				sprintf(
					__( '-- Redirect for source path "%1$s" already exists.', 'db2wp-import-cli' ),
					$source
				)
			);
		}

	}

}
