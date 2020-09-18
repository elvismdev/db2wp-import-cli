<?php

if ( ! function_exists( 'db2wp_remove_script_style_tags' ) ) {
	/**
	 * Removes <script> and <style> tags from body copy.
	 *
	 * @param string $str HTML body content.
	 * @return string
	 */
	function db2wp_remove_script_style_tags( $str ) {
		return preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $str );
	}
}

if ( ! function_exists( 'db2wp_remove_style_attr' ) ) {
	/**
	 * Removes inline styles.
	 *
	 * @param string $str HTML body content.
	 * @return string
	 */
	function db2wp_remove_style_attr( $str ) {
		return preg_replace( '/ style=("|\')(.*?)("|\')/', '', $str );
	}
}

if ( ! function_exists( 'db2wp_media_sideload_from_url' ) ) {
	/**
	 * Downloads a file to the WP media library and returns it's ID.
	 *
	 * @param string $url The URL of the file to download.
	 * @param string $meta Metadata to save with the attchment.
	 * @return int|WP_Error
	 */
	function db2wp_media_sideload_from_url( $url, $meta = array() ) {
		$file_basename = basename( $url );

		$tmp = download_url( $url );

		$file_array = array(
			'name' => $file_basename,
			'tmp_name' => $tmp,
		);

		if ( is_wp_error( $tmp ) ) {
			@unlink( $file_array['tmp_name'] );
			return $tmp;
		}

		// Attempt to find the mime type if the file.
		$filetype = wp_check_filetype_and_ext( $tmp, $file_basename );
		if ( isset( $filetype['type'] ) && ! empty( $filetype['type'] ) ) {
			$file_array['type'] = $filetype['type'];
		}

		$attid = media_handle_sideload( $file_array );

		// Save attachment metadata if not error found.
		if ( ! is_wp_error( $attid ) ) {
			foreach ( $meta as $key => $value ) {
				update_post_meta( $attid, $key, $value );
			}
		}

		return $attid;
	}
}

if ( ! function_exists( 'db2wp_get_attachment_id_by_filename' ) ) {
	/**
	 * Finds a file in the WP media library and returns it's ID.
	 *
	 * @param string $url The URL of the file to download.
	 * @return int|WP_Error
	 */
	function db2wp_get_attachment_id_by_filename( $file ) {
		// Get WPDB.
		global $wpdb;
		// Create empty attachment variable.
		$attch = '';

		// Define a common image file extensions list.
		$images_ext_list = array(
			'jpg',
			'jpeg',
			'png',
			'gif',
			'ico',
			'bmp',
			'tif',
			'tiff',
		);

		// Define a common file extensions list.
		$docs_ext_list = array(
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

		// Grab the file name and extension.
		$info = pathinfo( $file );
		$filename = $info['filename'];
		$extension = $info['extension'];

		// CHeck if file is an image by it's extension.
		if ( in_array( $extension, $images_ext_list ) ) {
			// Search Image attachment by file name and type.
			$attch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->posts . ' WHERE (post_title = %s OR post_name = %s) AND post_type = %s', $filename, sanitize_title( $filename ), 'attachment' ) . " AND post_mime_type LIKE 'image%';" );
			if ( ! empty( $attch ) ) {
				return $attch->ID;
			}
		}

		// CHeck if file is a document by it's extension.
		if ( in_array( $extension, $docs_ext_list ) ) {
			// Search document attachment by file name and type.
			$attch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->posts . ' WHERE (post_title = %s OR post_name = %s) AND post_type = %s', $filename, sanitize_title( $filename ), 'attachment' ) . " AND post_mime_type LIKE 'application%';" );
			if ( ! empty( $attch ) ) {
				return $attch->ID;
			}
		}

		return $attch;
	}
}
