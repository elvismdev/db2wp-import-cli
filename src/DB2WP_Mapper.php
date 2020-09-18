<?php
/**
 * WordPress data mapper class controller to organize import data to WP with the help of filters allowing to debug and manipulate the data.
 *
 * @package WordPress
 */

namespace Forum_One\DB2WP_Import_CLI;

/**
 * DB2WP data mapper class.
 */
class DB2WP_Mapper {

	/**
	 * Maps data.
	 *
	 * @param array  $data
	 * @param string $post_type
	 */
	public function map( $data, $post_type ) {
		$authors = apply_filters( 'db2wp_mapper_map_authors', array(), $data, $post_type );
		$posts = apply_filters( 'db2wp_mapper_map_posts', array(), $data, $post_type );
		$categories = apply_filters( 'db2wp_mapper_map_categories', array(), $data, $post_type );
		$tags = apply_filters( 'db2wp_mapper_map_tags', array(), $data, $post_type );
		$terms = apply_filters( 'db2wp_mapper_map_terms', array(), $data, $post_type );

		return apply_filters(
			'db2wp_mapper_map_array',
			array(
				'authors' => $authors,
				'posts' => $posts,
				'categories' => $categories,
				'tags' => $tags,
				'terms' => $terms,
				// 'base_url' => $base_url,
				// 'base_blog_url' => $base_blog_url,
				// 'version' => $wxr_version,
			),
			$data,
			$post_type
		);
	}

}
