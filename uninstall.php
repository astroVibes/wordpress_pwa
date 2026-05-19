<?php
/**
 * Uninstall script per PWA Core.
 *
 * Eseguito automaticamente da WordPress quando l'utente elimina il plugin
 * dalla schermata Plugin → Elimina.
 *
 * @package PWACore
 */

declare( strict_types=1 );

// Sicurezza: il file deve essere eseguito SOLO da WordPress durante l'uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* ============================================================
 * RIMOZIONE OPTIONS
 * ============================================================ */
$option_keys = [
	'pwa_core_settings',
	'pwa_core_cache_timestamp',
	'pwa_core_rewrite_version',
];

foreach ( $option_keys as $key ) {
	delete_option( $key );
	// Per installazioni multisite: rimuove anche le site-options.
	if ( is_multisite() ) {
		delete_site_option( $key );
	}
}

/* ============================================================
 * RIMOZIONE PAGINA OFFLINE (opzionale ma pulito)
 * ============================================================ */
// Recupera l'ID dalle options PRIMA di averle cancellate... in realtà sopra abbiamo già fatto.
// Quindi usiamo la postmeta come fallback.
$offline_pages = get_posts(
	[
		'post_type'      => 'page',
		'post_status'    => [ 'publish', 'draft', 'trash', 'private' ],
		'meta_key'       => '_pwa_core_offline_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'posts_per_page' => 10,
		'fields'         => 'ids',
		'suppress_filters' => true,
	]
);

if ( is_array( $offline_pages ) ) {
	foreach ( $offline_pages as $page_id ) {
		wp_delete_post( (int) $page_id, true );
	}
}

/* ============================================================
 * MULTISITE: ripeti per ogni sito della rete
 * ============================================================ */
if ( is_multisite() ) {
	$site_ids = get_sites( [ 'fields' => 'ids' ] );
	if ( is_array( $site_ids ) ) {
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			foreach ( $option_keys as $key ) {
				delete_option( $key );
			}
			$offline_pages_site = get_posts(
				[
					'post_type'        => 'page',
					'post_status'      => [ 'publish', 'draft', 'trash', 'private' ],
					'meta_key'         => '_pwa_core_offline_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page'   => 10,
					'fields'           => 'ids',
					'suppress_filters' => true,
				]
			);
			if ( is_array( $offline_pages_site ) ) {
				foreach ( $offline_pages_site as $page_id ) {
					wp_delete_post( (int) $page_id, true );
				}
			}
			restore_current_blog();
		}
	}
}

/* ============================================================
 * FLUSH REWRITE RULES per pulizia
 * ============================================================ */
// Le rewrite rules del plugin vengono rigenerate al prossimo page load.
// Non c'è bisogno di flush manuale qui (sarebbe inutile dopo la cancellazione delle classi).
