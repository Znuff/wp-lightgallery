<?php
/**
 * Plugin Name: wp-lightgallery
 * Plugin URI: https://linge-ma.ro/wp-lightgallery
 * Description: lightGallery for Wordpress
 * Version: r2
 * Author: Znuff
 * Author URI: https://linge-ma.ro
 * License: GPLv3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
opcache_reset();
define( 'WPLG_PLUGIN_FILE', __FILE__ );
define( 'WPLG_PLUGIN_DIR', plugin_dir_path( WPLG_PLUGIN_FILE ) );
define( 'WPLG_PLUGIN_URL', plugin_dir_url( WPLG_PLUGIN_FILE ) );

/**
 * Return asset versions (modification timestamp) for cache-busting.
 *
 * @return array
 */
function wplg_get_asset_versions() {
	$css_file = WPLG_PLUGIN_DIR . 'css/lightgallery-bundle.min';
	$js_file  = WPLG_PLUGIN_DIR . 'lightgallery.min.js';

	$versions = array(
		'css' => file_exists( $css_file ) ? filemtime( $css_file ) : '1',
		'js'  => file_exists( $js_file )  ? filemtime( $js_file )  : '1',
	);

	return $versions;
}

/**
 * Enqueue frontend assets.
 */
function wplg_enqueue_assets() {
	$versions = wplg_get_asset_versions();

	wp_enqueue_style(
		'wp-lightgallery',
		WPLG_PLUGIN_URL . 'css/lightgallery-bundle.min.css',
		array(),
		$versions['css']
	);

	wp_enqueue_script(
		'wp-lightgallery',
		WPLG_PLUGIN_URL . 'lightgallery.min.js',
		array(),
		$versions['js'],
		true
  );

  wp_enqueue_script(
    'wp-lightgallery-thumbnail',
    WPLG_PLUGIN_URL . 'plugins/thumbnail/lg-thumbnail.min.js',
    array(),
    $versions['js'],
    true
  );

  wp_enqueue_script(
    'wp-lightgallery-zoom',
    WPLG_PLUGIN_URL . 'plugins/zoom/lg-zoom.min.js',
    array(),
    $versions['js'],
    true
  );


	wp_add_inline_script( 'wp-lightgallery', wplg_get_inline_script() );
}
add_action( 'wp_enqueue_scripts', 'wplg_enqueue_assets' );

/**
 * Initialize lightGallery on post content images.
 *
 * @return string JavaScript initialization code.
 */
function wplg_get_inline_script() {
	return <<<JS
document.addEventListener('DOMContentLoaded', function() {
	const articles = document.querySelectorAll('article');
	articles.forEach(function(article) {
		const links = article.querySelectorAll('a[data-lightgallery]');
		if (links.length > 0) {
			lightGallery(article, {
				selector: 'a[data-lightgallery]',
				thumbnail: true,
				galleryId: 0,
				animateThumb: false,
				zoomFromOrigin: false,
				allowMediaOverlap: true,
				toggleThumb: true,

			});
		}
	});
});
JS;
}

/**
 * Add data-lightgallery attribute to image links in post content.
 *
 * @param string $content The post content.
 * @return string Modified content.
 */
function wplg_add_data_attribute( $content ) {
	if ( ! is_main_query() || ! is_singular( 'post' ) ) {
		return $content;
	}

	$dom = new DOMDocument();
	$dom->encoding = 'UTF-8';

	// Suppress warnings and load HTML.
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $content );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );

	// Find all <a> tags that contain <img> elements.
	$links = $xpath->query( '//a[img]' );

	if ( $links && $links->length > 0 ) {
		foreach ( $links as $link ) {
			// Skip if data-lightgallery already exists.
			if ( ! $link->hasAttribute( 'data-lightgallery' ) ) {
				$link->setAttribute( 'data-lightgallery', 'post-gallery' );
			}
		}
	}

	// Extract body content and return.
	$body = $dom->getElementsByTagName( 'body' )->item( 0 );
	$modified_html = '';

	if ( $body ) {
		foreach ( $body->childNodes as $node ) {
			$modified_html .= $dom->saveHTML( $node );
		}
	}

	return $modified_html;
}
add_filter( 'the_content', 'wplg_add_data_attribute' );
