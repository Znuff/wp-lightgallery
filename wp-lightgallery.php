<?php
/**
 * Plugin Name: Lightgallery Imagebox 
 * Plugin URI: https://linge-ma.ro/wp-lightgallery
 * Description: lightGallery for Wordpress, 
 * Version: r3
 * Author: Znuff
 * Author URI: https://linge-ma.ro
 * License: GPLv3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPLG_PLUGIN_FILE', __FILE__ );
define( 'WPLG_PLUGIN_DIR', plugin_dir_path( WPLG_PLUGIN_FILE ) );
define( 'WPLG_PLUGIN_URL', plugin_dir_url( WPLG_PLUGIN_FILE ) );

/**
 * Simple content checks so we can conditionally load assets.
 */
function wplg_content_has_image_links( $content ) {
	// Matches <a ...><img ...> with optional whitespace/newlines.
	return (bool) preg_match( '#<a\b[^>]*>\s*<img\b#is', (string) $content );
}

function wplg_content_has_500px_links( $content ) {
	// Matches the specific host/path requirement.
	return (bool) preg_match( '#https?://drscdn\.500px\.org/photo/#i', (string) $content );
}

/**
 * Decide if this post needs lightGallery + which "modes".
 *
 * @return array{need_assets:bool, enable_post_gallery:bool, enable_stirile_gallery:bool}
 */
function wplg_get_requirements_for_current_post() {
	if ( ! is_singular( 'post' ) ) {
		return array(
			'need_assets'          => false,
			'enable_post_gallery'  => false,
			'enable_stirile_gallery' => false,
		);
	}

	global $post;
	if ( ! $post instanceof WP_Post ) {
		return array(
			'need_assets'          => false,
			'enable_post_gallery'  => false,
			'enable_stirile_gallery' => false,
		);
	}

	$content = (string) $post->post_content;

	$enable_post_gallery = wplg_content_has_image_links( $content );

	$is_stirile = has_category( 'stirile-zilei', $post );
	$enable_stirile_gallery = $is_stirile && wplg_content_has_500px_links( $content );

	$need_assets = $enable_post_gallery || $enable_stirile_gallery;

	return array(
		'need_assets'            => $need_assets,
		'enable_post_gallery'    => $enable_post_gallery,
		'enable_stirile_gallery' => $enable_stirile_gallery,
	);
}

/**
 * Return asset versions (modification timestamp) for cache-busting.
 *
 * @return array
 */
function wplg_get_asset_versions() {
	$css_file      = WPLG_PLUGIN_DIR . 'css/lightgallery-bundle.min.css';
	$core_js_file  = WPLG_PLUGIN_DIR . 'lightgallery.min.js';

	$versions = array(
		'css' => file_exists( $css_file )     ? filemtime( $css_file )     : '1',
		'js'  => file_exists( $core_js_file ) ? filemtime( $core_js_file ) : '1',
	);

	return $versions;
}

/**
 * Enqueue frontend assets only when needed.
 */
function wplg_enqueue_assets() {
	$req = wplg_get_requirements_for_current_post();
	if ( empty( $req['need_assets'] ) ) {
		return;
	}

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

	// Plugins: load only what we actually use.
	// - zoom/hash/rotate used by both galleries (nice UX)
	// - pager only used by the default image-link gallery
	$plugin_deps = array( 'wp-lightgallery' );

	$need_zoom      = ! empty( $req['enable_post_gallery'] ) || ! empty( $req['enable_stirile_gallery'] );
	$need_hash      = ! empty( $req['enable_post_gallery'] ) || ! empty( $req['enable_stirile_gallery'] );
	$need_rotate    = ! empty( $req['enable_post_gallery'] ) || ! empty( $req['enable_stirile_gallery'] );
	$need_pager     = ! empty( $req['enable_post_gallery'] ); // explicitly NOT for stirile-zilei gallery
	$need_thumbnail = ! empty( $req['enable_stirile_gallery'] ); // thumbnails only for stirile

	if ( $need_zoom ) {
		wp_enqueue_script(
			'wp-lightgallery-zoom',
			WPLG_PLUGIN_URL . 'plugins/zoom/lg-zoom.min.js',
			array( 'wp-lightgallery' ),
			$versions['js'],
			true
		);
		$plugin_deps[] = 'wp-lightgallery-zoom';
	}

	if ( $need_hash ) {
		wp_enqueue_script(
			'wp-lightgallery-hash',
			WPLG_PLUGIN_URL . 'plugins/hash/lg-hash.min.js',
			array( 'wp-lightgallery' ),
			$versions['js'],
			true
		);
		$plugin_deps[] = 'wp-lightgallery-hash';
	}

	if ( $need_rotate ) {
		wp_enqueue_script(
			'wp-lightgallery-rotate',
			WPLG_PLUGIN_URL . 'plugins/rotate/lg-rotate.min.js',
			array( 'wp-lightgallery' ),
			$versions['js'],
			true
		);
		$plugin_deps[] = 'wp-lightgallery-rotate';
	}

	if ( $need_pager ) {
		wp_enqueue_script(
			'wp-lightgallery-pager',
			WPLG_PLUGIN_URL . 'plugins/pager/lg-pager.min.js',
			array( 'wp-lightgallery' ),
			$versions['js'],
			true
		);
		$plugin_deps[] = 'wp-lightgallery-pager';
	}

	if ( $need_thumbnail ) {
		wp_enqueue_script(
			'wp-lightgallery-thumbnail',
			WPLG_PLUGIN_URL . 'plugins/thumbnail/lg-thumbnail.min.js',
			array( 'wp-lightgallery' ),
			$versions['js'],
			true
		);
		$plugin_deps[] = 'wp-lightgallery-thumbnail';
	}

	// Init script handle so init runs AFTER all selected plugins.
	wp_register_script( 'wp-lightgallery-init', '', $plugin_deps, $versions['js'], true );
	wp_enqueue_script( 'wp-lightgallery-init' );

	// Cosmetic inline CSS (only when we loaded the css).
	wp_add_inline_style( 'wp-lightgallery', ".lg-backdrop{background-color:rgba(30,30,30,.9);} .lg-sub-html {padding: 0}" );

	// Config for JS.
	$config = array(
		'enablePostGallery'      => ! empty( $req['enable_post_gallery'] ),
		'enableStirileZilei'     => ! empty( $req['enable_stirile_gallery'] ),
	);
	wp_add_inline_script(
		'wp-lightgallery-init',
		'window.WPLG = ' . wp_json_encode( $config ) . ';',
		'before'
	);

	// Actual initialization.
	wp_add_inline_script( 'wp-lightgallery-init', wplg_get_inline_script() );
}
add_action( 'wp_enqueue_scripts', 'wplg_enqueue_assets' );

/**
 * Initialize lightGallery.
 *
 * @return string JavaScript initialization code.
 */
function wplg_get_inline_script() {
	return <<<JS
document.addEventListener('DOMContentLoaded', function() {
	if (!window.lightGallery || !window.WPLG) return;

	const cfg = window.WPLG;

	// 1) Default post image-link gallery (a[data-lightgallery="post-gallery"])
	if (cfg.enablePostGallery) {
		const articles = document.querySelectorAll('article');
		articles.forEach(function(article) {
			const links = article.querySelectorAll('a[data-lightgallery="post-gallery"]');
			if (!links.length) return;

			lightGallery(article, {
				selector: 'a[data-lightgallery="post-gallery"]',
				plugins: [
					typeof lgZoom !== 'undefined' ? lgZoom : null,
					typeof lgHash !== 'undefined' ? lgHash : null,
					typeof lgRotate !== 'undefined' ? lgRotate : null,
					typeof lgPager !== 'undefined' ? lgPager : null,
				].filter(Boolean),
				galleryId: 0,
				zoomFromOrigin: true,
				pager: true,
				thumbnail: false,
				hash: true,
				hideScrollbar: true,
				showZoomInOutIcons: true,
				allowMediaOverlap: true,
			});
		});
	}

	// 2) stirile-zilei gallery (only drscdn.500px.org/photo links)
	//    - galleryId: 'stirile-zilei'
	//    - NO pager, NO thumbnails
	if (cfg.enableStirileZilei) {
		const root = document.querySelector('.post-content') || document.querySelector('article');
		if (!root) return;

		// guard against double-init
		if (root.dataset.wplgStirileInit === '1') return;

		const links = root.querySelectorAll('a[data-lightgallery="stirile-zilei"]');
		if (!links.length) return;

		root.dataset.wplgStirileInit = '1';

		lightGallery(root, {
			selector: 'a[data-lightgallery="stirile-zilei"]',
			plugins: [
				typeof lgThumbnail !== 'undefined' ? lgThumbnail : null,
				typeof lgZoom !== 'undefined' ? lgZoom : null,
				typeof lgHash !== 'undefined' ? lgHash : null,
				typeof lgRotate !== 'undefined' ? lgRotate : null,
			].filter(Boolean),
			galleryId: 'stirile-zilei',
			pager: false,
			thumbnail: true,
			hash: true,
			hideScrollbar: true,
			showZoomInOutIcons: true,
			allowMediaOverlap: true,
			exThumbImage: 'data-exthumbimage',
		});
	}


});
JS;
}

/**
 * Add data-lightgallery attributes:
 * - post-gallery: <a> that contains <img> (default behavior)
 * - stirile-zilei: <a href="https://drscdn.500px.org/photo/..."> (only in that category)
 *
 * @param string $content The post content.
 * @return string Modified content.
 */
function wplg_add_data_attribute( $content ) {
	if ( ! is_main_query() || ! is_singular( 'post' ) ) {
		return $content;
	}

	global $post;
	$is_stirile = ( $post instanceof WP_Post ) ? has_category( 'stirile-zilei', $post ) : false;

	$dom = new DOMDocument();
	$dom->encoding = 'UTF-8';

	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $content );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );

	// A) Default: <a> that contains <img> => post-gallery
	$img_links = $xpath->query( '//a[img]' );
	if ( $img_links && $img_links->length > 0 ) {
		foreach ( $img_links as $link ) {
			if ( ! $link->hasAttribute( 'data-lightgallery' ) ) {
				$link->setAttribute( 'data-lightgallery', 'post-gallery' );
			}
		}
	}

	// B) stirile-zilei: links to drscdn.500px.org/photo => stirile-zilei gallery
	if ( $is_stirile ) {
		$st_links = $xpath->query( '//a[contains(@href,"drscdn.500px.org/photo")]' );
    if ( $st_links && $st_links->length > 0 ) {
			foreach ( $st_links as $link ) {
				$link->setAttribute( 'data-lightgallery', 'stirile-zilei' );

				// Reuse the original link as thumbnail source
				$href = $link->getAttribute( 'href' );
				if ( ! empty( $href ) ) {
					$link->setAttribute( 'data-exthumbimage', esc_url( $href ) );
				}

				// Use the link text as caption/title (e.g. "Blondă")
				$caption = trim( preg_replace( '/\s+/', ' ', $link->textContent ) );
				if ( $caption !== '' ) {
					// lightGallery reads captions from data-sub-html
					$link->setAttribute(
						'data-sub-html',
						'<div class="lg-sub-html"><h4>' . esc_html( $caption ) . '</h4></div>'
					);
				}
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

