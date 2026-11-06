<?php
/**
 * Desinstalación de OrderArc.
 *
 * Regla: nunca se borra un pedido. Si quedan pedidos archivados, las tablas del archivo y las
 * opciones que las describen se conservan (reinstalando el plugin vuelven a verse y se pueden restaurar).
 * Solo cuando el archivo está vacío se borra todo lo del plugin. En multisitio, sitio por sitio.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- limpieza de tablas y opciones propias.
 *
 * @package OrderArc
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Si la versión Pro sigue instalada y activa, usa estos mismos datos: no se toca nada.
if ( defined( 'ORDERARC_PRO' ) || in_array( 'orderarc-premium/orderarc.php', (array) get_option( 'active_plugins', array() ), true ) ) {
	return;
}

/** Limpia el sitio actual. */
function orderarc_uninstall_site() {
	global $wpdb;
	$prefix = $wpdb->prefix . 'orderarc_';
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
	$rows   = 0;
	foreach ( $tables as $t ) {
		if ( ! in_array( $t, array( $prefix . 'keep', $prefix . 'owner', $prefix . 'anon_marks' ), true ) ) {
			$rows += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
		}
	}
	// Archivo en otra base de datos (versión Pro): si tiene pedidos, también se conserva todo.
	$schema = (string) get_option( 'orderarc_archive_db', '' );
	if ( preg_match( '/^[A-Za-z0-9_]+$/', $schema ) && DB_NAME !== $schema ) {
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $schema, $prefix . 'owner' ) )
			? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT site FROM %i.%i LIMIT 1', $schema, $prefix . 'owner' ) )
			: '';
		if ( md5( DB_NAME . '|' . $wpdb->prefix ) !== $owner ) {
			// Otra base que no se ve, o que pertenece a otra copia del sitio: no se toca y se conserva todo lo de acá.
			$rows = max( 1, $rows );
			$other = array();
		} else {
			$other = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s', $schema, $wpdb->esc_like( $prefix ) . '%' ) );
		}
		foreach ( $other as $t ) {
			if ( ! in_array( $t, array( $prefix . 'keep', $prefix . 'owner', $prefix . 'anon_marks' ), true ) ) {
				$rows += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i.%i', $schema, $t ) );
			}
		}
		if ( $rows > 0 ) {
			$tables = array(); // nada que borrar acá: abajo se conservan opciones y tablas.
		} else {
			foreach ( $other as $t ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i.%i', $schema, $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- tablas propias y vacías.
			}
		}
	}

	// Tareas programadas y estado del trabajo: siempre.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'orderarc_run_batch', array(), 'orderarc' );
		as_unschedule_all_actions( 'orderarc_daily', array(), 'orderarc' );
		as_unschedule_all_actions( 'orderarc_pro_daily', array(), 'orderarc' );
	}
	wp_clear_scheduled_hook( 'orderarc_run_batch' );
	foreach ( array( 'orderarc_lock', 'orderarc_due', 'orderarc_job', 'orderarc_cursor_archive', 'orderarc_cursor_restore', 'orderarc_search', 'orderarc_health' ) as $o ) {
		delete_option( $o );
	}
	foreach ( array( 'orderarc_archived_count', 'orderarc_arch_sources', 'orderarc_preview' ) as $t ) {
		delete_transient( $t );
	}

	if ( $rows > 0 ) {
		return; // quedan pedidos archivados: se conservan las tablas y lo necesario para leerlas y restaurarlas.
	}
	foreach ( $tables as $t ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- tablas propias y vacías.
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'orderarc_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_orderarc_' ) . '%', $wpdb->esc_like( '_transient_timeout_orderarc_' ) . '%' ) );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $orderarc_site ) {
		switch_to_blog( $orderarc_site );
		orderarc_uninstall_site();
		restore_current_blog();
	}
} else {
	orderarc_uninstall_site();
}
