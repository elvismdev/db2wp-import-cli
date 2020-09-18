<?php

if ( ! function_exists( 'db2wp_generate_acf_block' ) ) {
	/**
	 * Generates an ACF Gutenberg block.
	 *
	 * @param string $name The ACF name slug of the block.
	 * @param array  $data The data for the block.
	 * @return string
	 */
	function db2wp_generate_acf_block( $name, $data = array() ) {
		// Prefix ACF block name with acf/ if this doesn't exists.
		if ( 'acf/' != substr( $name, 0, 4 ) ) {
			$name = 'acf/' . $name;
		}

		// Create a PHP array to construct the block JSON.
		$block_array = array(
			'id' => uniqid( 'block_' ),
			'name' => $name,
			'data' => $data,
			'align' => '',
			'mode' => 'edit',
		);
		// Convert to JSON formart.
		$block_json = json_encode( $block_array );

		// Create ACF block string.
		$block = '<!-- wp:' . $name . ' ';
		$block .= $block_json;
		$block .= ' /-->';

		// Return the block.
		return $block;
	}
}

if ( ! function_exists( 'db2wp_generate_block_heading' ) ) {
	/**
	 * Generates a heading Gutenberg block.
	 *
	 * @param int    $heading_number The heading number output, a value from 1 - 6 to generate the corresponding H tag (h1, h2, h3...)
	 * @param string $content The content for the block.
	 * @return string
	 */
	function db2wp_generate_block_heading( $heading_number, $content ) {

		// Create the block string.
		$block = '<!-- wp:heading ' . ( 2 != $heading_number ? '{"level":' . $heading_number . '}' : '' ) . ' -->';
		$block .= '<h' . $heading_number . '>' . $content . '</h' . $heading_number . '>';
		$block .= '<!-- /wp:heading -->';

		// Return the block.
		return $block;
	}
}

if ( ! function_exists( 'db2wp_generate_block_list' ) ) {
	/**
	 * Generates a List Gutenberg block.
	 *
	 * @param string $type The type of list to generate. <ul> or <ol>
	 * @param string $content The list content items.
	 * @return string
	 */
	function db2wp_generate_block_list( $type, $content ) {

		// Create the block string.
		$block = '<!-- wp:list ' . ( 'o' == $type ? '{"ordered":' . true . '}' : '' ) . ' -->';
		$block .= '<' . $type . 'l>' . $content . '</' . $type . 'l>';
		$block .= '<!-- /wp:list -->';

		// Return the block.
		return $block;
	}
}

if ( ! function_exists( 'db2wp_generate_block_paragraph' ) ) {
	/**
	 * Generates a Paragraph Gutenberg block.
	 *
	 * @param string $content The content for the block.
	 * @return string
	 */
	function db2wp_generate_block_paragraph( $content ) {

		// Create the block string.
		$block = '<!-- wp:paragraph -->';
		$block .= '<p>' . $content . '</p>';
		$block .= '<!-- /wp:paragraph -->';

		// Return the block.
		return $block;
	}
}

if ( ! function_exists( 'db2wp_generate_block_image' ) ) {
	/**
	 * Generates a image Gutenberg block.
	 *
	 * @param int    $attid The image attachment ID to egnerate the block for.
	 * @param array  $args The arguments to generate an image block.
	 * @param string $caption The text caption for below the image.
	 * @return string
	 */
	function db2wp_generate_block_image( $attid, $args = array(), $caption = '' ) {
		$block = '';

		if ( ! empty( $attid ) ) {
			// Get image attachment URL.
			$image_url = wp_get_attachment_url( $attid );
			// Get image alt attribute.
			$image_alt = trim( strip_tags( get_post_meta( $attid, '_wp_attachment_image_alt', true ) ) );

			// Parse and merge passed arguments against defaults.
			$defaults   = array(
				'align'     => 'left',
				'id'        => (int) $attid, // Add attachment ID to the arguments.
				'sizeSlug'  => 'large',
			);
			$block_args = wp_parse_args( $args, $defaults );

			// Generate the block.
			$block .= '<!-- wp:image ' . json_encode( $block_args ) . ' -->';
			$block .= '<div class="wp-block-image">';
			$block .= '<figure class="align' . $block_args['align'] . ' size-' . $block_args['sizeSlug'] . '">';
			$block .= '<img src="' . $image_url . '" ';
			if ( ! empty( $image_alt ) ) {
				$block .= 'alt="' . $image_alt . '" ';
			}
			$block .= 'class="wp-image-' . $attid . '"/>';
			if ( $caption ) {
				$block .= '<figcaption>' . $caption . '</figcaption>';
			}
			$block .= '</figure>';
			$block .= '</div>';
			$block .= '<!-- /wp:image -->';
		}

		// Return the block.
		return $block;
	}
}


