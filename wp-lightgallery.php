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
	// Matches both the old direct CDN links and the newer 500px page links.
	return (bool) preg_match( '#https?://(?:drscdn\.500px\.org/photo/|(?:www\.)?500px\.com/photo/)#i', (string) $content );
}

/**
 * Is a string a valid 500px base62 photo id?
 *
 * @param string $id
 * @return bool
 */
function wplg_500px_valid_id( $id ) {
	return (bool) preg_match( '/^[A-Za-z0-9_-]{6,16}$/', (string) $id );
}

/**
 * Parse a 500px photo URL into a reference.
 *
 * Supports the current `/photo/<base62>` URLs (GraphQL node id) and the legacy
 * `/photo/<numeric>/<slug>` URLs (legacy id).
 *
 * @param string $url
 * @return array{type:string,value:string}|null
 */
function wplg_500px_parse_ref( $url ) {
	$url = (string) $url;

	// Legacy: /photo/1122538020/at-night-by-videophotoart-com
	if ( preg_match( '~https?://(?:www\.)?500px\.com/photo/([0-9]{4,20})/~i', $url, $m ) ) {
		return array(
			'type'  => 'legacy',
			'value' => $m[1],
		);
	}

	// Current: /photo/d4hHJN8xaUd
	if ( preg_match( '~https?://(?:www\.)?500px\.com/photo/([A-Za-z0-9_-]{6,16})(?:[/?#]|$)~i', $url, $m ) ) {
		return array(
			'type'  => 'node',
			'value' => $m[1],
		);
	}

	return null;
}

/**
 * Build a stable key for a photo reference.
 *
 * @param array $ref
 * @return string
 */
function wplg_500px_ref_key( $ref ) {
	return $ref['type'] . '_' . $ref['value'];
}

/**
 * Normalise a list of photo references, dropping duplicates/invalid entries.
 *
 * @param array $refs
 * @return array<string,array{type:string,value:string}> Keyed by ref key.
 */
function wplg_500px_normalize_refs( $refs ) {
	$out = array();

	foreach ( (array) $refs as $ref ) {
		if ( ! is_array( $ref ) || empty( $ref['type'] ) || ! isset( $ref['value'] ) ) {
			continue;
		}

		$type  = (string) $ref['type'];
		$value = (string) $ref['value'];

		if ( 'node' === $type && wplg_500px_valid_id( $value ) ) {
			$out[ $type . '_' . $value ] = array(
				'type'  => $type,
				'value' => $value,
			);
		} elseif ( 'legacy' === $type && preg_match( '/^[0-9]{4,20}$/', $value ) ) {
			$out[ $type . '_' . $value ] = array(
				'type'  => $type,
				'value' => $value,
			);
		}
	}

	return $out;
}

/**
 * Parse the `expiry` unix timestamp from a signed 500px CDN URL.
 *
 * @param string $url
 * @return int Expiry timestamp, or 0 when unknown.
 */
function wplg_500px_parse_expiry( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['query'] ) ) {
		return 0;
	}

	$query = array();
	wp_parse_str( $parts['query'], $query );

	return isset( $query['expiry'] ) ? (int) $query['expiry'] : 0;
}

/**
 * Pick the largest direct URL and a thumbnail from an API `urls` set.
 *
 * @param array $urls
 * @return array{full:string, thumb:string}
 */
function wplg_500px_pick_urls( $urls ) {
	$urls = is_array( $urls ) ? $urls : array();

	$full = '';
	foreach ( array( 'size_4k', 'size_2048', 'size_1024', 'size_600' ) as $key ) {
		if ( ! empty( $urls[ $key ] ) ) {
			$full = $urls[ $key ];
			break;
		}
	}

	$thumb = ! empty( $urls['size_600'] ) ? $urls['size_600'] : $full;

	return array(
		'full'  => $full,
		'thumb' => $thumb,
	);
}

/**
 * Query the 500px GraphQL API for one or more photo refs in a single request.
 *
 * @param array $refs List of array{type:string,value:string}.
 * @return array<string,array{full:string,thumb:string,expiry:int}> Keyed by ref key.
 */
function wplg_500px_api_query( $refs ) {
	$refs = wplg_500px_normalize_refs( $refs );
	if ( empty( $refs ) ) {
		return array();
	}

	$selections = array();
	$i          = 0;
	foreach ( $refs as $ref ) {
		$fields = 'id urls { size_600 size_1024 size_2048 size_4k }';
		if ( 'legacy' === $ref['type'] ) {
			$selections[] = 'p' . $i . ': getPhotoByLegacyId(legacyId: ' . wp_json_encode( $ref['value'] ) . ') { ' . $fields . ' }';
		} else {
			$selections[] = 'p' . $i . ': getPhotoById(id: ' . wp_json_encode( $ref['value'] ) . ') { ' . $fields . ' }';
		}
		$i++;
	}

	$query = 'query WPLGGetPhotos { ' . implode( ' ', $selections ) . ' }';

	$response = wp_remote_post(
		'https://api-neo.500px.com/graphql',
		array(
			'timeout' => 8,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-500px-device-id' => wp_generate_uuid4(),
				'x-500px-platform'  => 'Web',
				'referer'           => 'https://500px.com/',
			),
			'body'    => wp_json_encode( array( 'query' => $query ) ),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
		return array();
	}

	$out = array();
	$i   = 0;
	foreach ( array_keys( $refs ) as $key ) {
		$alias = 'p' . $i;
		$i++;

		if ( empty( $data['data'][ $alias ]['id'] ) || empty( $data['data'][ $alias ]['urls'] ) ) {
			continue;
		}

		$picked = wplg_500px_pick_urls( $data['data'][ $alias ]['urls'] );
		if ( '' === $picked['full'] ) {
			continue;
		}

		$out[ $key ] = array(
			'full'   => $picked['full'],
			'thumb'  => $picked['thumb'],
			'expiry' => wplg_500px_parse_expiry( $picked['full'] ),
		);
	}

	return $out;
}

/**
 * Resolve 500px photo refs to signed direct URLs, using a transient cache.
 *
 * Entries are refreshed shortly before their signed URL expires.
 *
 * @param array $refs List of array{type:string,value:string}.
 * @return array<string,array{full:string,thumb:string,expiry:int}> Keyed by ref key.
 */
function wplg_500px_resolve_refs( $refs ) {
	$refs = wplg_500px_normalize_refs( $refs );
	if ( empty( $refs ) ) {
		return array();
	}

	$resolved = array();
	$missing  = array();

	foreach ( $refs as $key => $ref ) {
		$cached = get_transient( 'wplg_500px_' . $key );
		if ( is_array( $cached ) && ! empty( $cached['full'] ) ) {
			$expiry = isset( $cached['expiry'] ) ? (int) $cached['expiry'] : 0;
			if ( 0 === $expiry || $expiry - time() > 300 ) {
				$resolved[ $key ] = $cached;
				continue;
			}
		}

		if ( get_transient( 'wplg_500px_err_' . $key ) ) {
			continue;
		}

		$missing[ $key ] = $ref;
	}

	if ( empty( $missing ) ) {
		return $resolved;
	}

	$fetched = wplg_500px_api_query( $missing );

	foreach ( $missing as $key => $ref ) {
		if ( empty( $fetched[ $key ]['full'] ) ) {
			set_transient( 'wplg_500px_err_' . $key, 1, 10 * MINUTE_IN_SECONDS );
			continue;
		}

		$entry  = $fetched[ $key ];
		$expiry = isset( $entry['expiry'] ) ? (int) $entry['expiry'] : 0;
		$ttl    = $expiry > 0 ? max( 60, $expiry - time() - 600 ) : HOUR_IN_SECONDS;

		set_transient( 'wplg_500px_' . $key, $entry, $ttl );
		$resolved[ $key ] = $entry;
	}

	return $resolved;
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
 * - stirile-zilei: <a href="https://500px.com/photo/..."> (resolved to a direct
 *   image) or <a href="https://drscdn.500px.org/photo/..."> (already direct),
 *   only in that category
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

	// B) stirile-zilei: links to 500px (page or direct CDN) => stirile-zilei gallery
	if ( $is_stirile ) {
		$st_links = $xpath->query( '//a[contains(@href,"drscdn.500px.org/photo") or contains(@href,"500px.com/photo/")]' );

		if ( $st_links && $st_links->length > 0 ) {
			// Resolve all 500px page links for this post in one API round-trip.
			$refs = array();
			foreach ( $st_links as $link ) {
				$ref = wplg_500px_parse_ref( $link->getAttribute( 'href' ) );
				if ( $ref ) {
					$refs[ wplg_500px_ref_key( $ref ) ] = $ref;
				}
			}
			$resolved = wplg_500px_resolve_refs( $refs );

			foreach ( $st_links as $link ) {
				$href = $link->getAttribute( 'href' );
				$ref  = wplg_500px_parse_ref( $href );

				if ( $ref ) {
					// 500px page URL -> direct signed image URL.
					$key = wplg_500px_ref_key( $ref );

					if ( empty( $resolved[ $key ]['full'] ) ) {
						// Unresolved: leave it as a plain external link.
						continue;
					}

					$href = $resolved[ $key ]['full'];
					$link->setAttribute( 'href', $href );

					$thumb = ! empty( $resolved[ $key ]['thumb'] ) ? $resolved[ $key ]['thumb'] : $href;
					$link->setAttribute( 'data-exthumbimage', $thumb );
				} else {
					// Older format: already a direct drscdn image.
					if ( ! empty( $href ) ) {
						$link->setAttribute( 'data-exthumbimage', esc_url( $href ) );
					}
				}

				$link->setAttribute( 'data-lightgallery', 'stirile-zilei' );

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

/**
 * Pre-warm the 500px direct-URL cache when a stirile-zilei post is published.
 *
 * @param int $post_id
 */
function wplg_500px_prime_post( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return;
	}

	if ( ! has_category( 'stirile-zilei', $post ) ) {
		return;
	}

	if ( ! wplg_content_has_500px_links( $post->post_content ) ) {
		return;
	}

	if ( preg_match_all( '~https?://(?:www\.)?500px\.com/photo/[^"\'\s<>]+~i', $post->post_content, $matches ) ) {
		$refs = array();
		foreach ( $matches[0] as $url ) {
			$ref = wplg_500px_parse_ref( $url );
			if ( $ref ) {
				$refs[] = $ref;
			}
		}

		wplg_500px_resolve_refs( $refs );
	}
}
add_action( 'save_post', 'wplg_500px_prime_post', 20 );

