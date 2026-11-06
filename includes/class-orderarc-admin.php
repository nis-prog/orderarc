<?php
/**
 * Pantalla del panel: WooCommerce → OrderArc (Overview, Archive, Archived orders),
 * enlace "Archived (N)" en la lista de pedidos y redirección de pedidos archivados.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- conteos y una búsqueda de prueba sobre tablas de Woo; sin API equivalente.
 *
 * @package OrderArc
 */

defined( 'ABSPATH' ) || exit;

class OrderArc_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'orderarc';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'save', 'preview', 'start', 'pause', 'resume', 'restore_all', 'restore' ) as $a ) {
			add_action( 'admin_post_orderarc_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
		add_action( 'wp_ajax_orderarc_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_orderarc_measure', array( __CLASS__, 'ajax_measure' ) );
		add_filter( 'views_woocommerce_page_wc-orders', array( __CLASS__, 'views' ) );
		add_filter( 'views_edit-shop_order', array( __CLASS__, 'views' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'OrderArc', 'OrderArc', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'orderarc-admin', plugins_url( 'assets/admin.css', ORDERARC_FILE ), array(), ORDERARC_VERSION );
		wp_enqueue_script( 'orderarc-admin', plugins_url( 'assets/admin.js', ORDERARC_FILE ), array(), ORDERARC_VERSION, true );
		wp_localize_script(
			'orderarc-admin',
			'orderarc',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'orderarc_ajax' ),
				/* translators: %s: seconds */
				'took'  => __( 'Searching orders took %s s', 'orderarc' ),
				/* translators: %s: seconds */
				'nofin' => __( 'Search did not finish after %s s', 'orderarc' ),
			)
		);
	}

	/* ---------- Enlace en la lista de pedidos y redirección ---------- */

	public static function views( $views ) {
		if ( ! current_user_can( self::CAP ) ) {
			return $views;
		}
		$n = OrderArc_Engine::archived_count();
		if ( $n > 0 ) {
			$views['orderarc'] = sprintf(
				'<a href="%s">%s <span class="count">(%s)</span></a>',
				esc_url( self::url( array( 'tab' => 'archived' ) ) ),
				esc_html__( 'Archived', 'orderarc' ),
				esc_html( number_format_i18n( $n ) )
			);
		}
		return $views;
	}

	/** Un pedido archivado abierto desde un enlace viejo va a su vista de archivado. */
	public static function maybe_redirect() {
		global $pagenow;
		if ( wp_doing_ajax() || ! current_user_can( self::CAP ) ) {
			return;
		}
		$id = 0;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo lectura de la URL para redirigir.
		$page   = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		if ( 'admin.php' === $pagenow && 'wc-orders' === $page && 'edit' === $action ) {
			$id = absint( $_GET['id'] ?? 0 );
			if ( $id && function_exists( 'wc_get_order' ) && wc_get_order( $id ) ) {
				return;
			}
		} elseif ( 'post.php' === $pagenow && 'edit' === $action ) {
			$id   = absint( $_GET['post'] ?? 0 );
			$type = $id ? get_post_type( $id ) : false;
			if ( $type && OrderArc_Engine::PLACEHOLDER !== $type ) {
				return;
			}
		}
		// phpcs:enable
		if ( $id && OrderArc_Archived::exists( $id ) ) {
			wp_safe_redirect( self::url( array( 'tab' => 'archived', 'order' => $id ) ) );
			exit;
		}
	}

	/* ---------- Acciones (formularios) ---------- */

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'orderarc' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $tab, $msg = '', $extra = array() ) {
		if ( $msg ) {
			set_transient( 'orderarc_msg_' . get_current_user_id(), $msg, 120 );
		}
		wp_safe_redirect( self::url( array_merge( array( 'tab' => $tab ), $extra ) ) );
		exit;
	}

	private static function ack_from_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificado en guard().
		if ( ! empty( $_POST['orderarc_backup'] ) && ! get_option( 'orderarc_backup_ack' ) ) {
			update_option( 'orderarc_backup_ack', array( 'user' => get_current_user_id(), 'time' => time() ), false );
		}
		return (bool) get_option( 'orderarc_backup_ack' );
	}

	public static function post_save() {
		self::guard( 'orderarc_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verificado en guard().
		$s        = OrderArc_Runner::settings();
		$allowed  = array_keys( self::status_choices() );
		// La regla básica solo se cambia si vino en el formulario (con la versión Pro esos campos no se muestran).
		if ( isset( $_POST['older_than_days'] ) ) {
			$statuses = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['statuses'] ?? array() ) ), $allowed ) );
			$s['statuses']        = $statuses ? $statuses : OrderArc_Runner::defaults()['statuses'];
			$s['older_than_days'] = max( 30, min( 36500, absint( $_POST['older_than_days'] ) ) );
		}
		$s['window_start']    = min( 23, absint( $_POST['window_start'] ?? 1 ) );
		$s['window_end']      = min( 23, absint( $_POST['window_end'] ?? 6 ) );
		$s['catch_up']        = 'wait' === sanitize_key( wp_unslash( $_POST['catch_up'] ?? '' ) ) ? 'wait' : 'gentle';
		$s['auto']            = ! empty( $_POST['auto'] ) && self::ack_from_post();
		// phpcs:enable
		$s = (array) apply_filters( 'orderarc_save_settings', $s );
		update_option( 'orderarc_settings', $s, false );
		do_action( 'orderarc_settings_saved', $s );
		OrderArc_Engine::flush_counts();
		OrderArc_Runner::schedule_daily();
		self::back( 'archive', __( 'Settings saved.', 'orderarc' ) );
	}

	public static function post_preview() {
		self::guard( 'orderarc_preview' );
		$s    = OrderArc_Runner::settings();
		$prev = OrderArc_Engine::preview( $s );
		// Se guarda con la huella de las reglas: si cambian (ajustes, licencia Pro), la vista previa vieja no se muestra.
		$prev['rules_key'] = md5( wp_json_encode( OrderArc_Engine::rules( $s ) ) );
		set_transient( 'orderarc_preview_' . get_current_user_id(), $prev, 600 );
		self::back( 'archive' );
	}

	public static function post_start() {
		self::guard( 'orderarc_start' );
		if ( ! self::ack_from_post() ) {
			self::back( 'archive' );
		}
		OrderArc_Runner::start( 'archive', true );
		self::back( 'archive' );
	}

	public static function post_pause() {
		self::guard( 'orderarc_pause' );
		OrderArc_Runner::pause();
		self::back( 'archive' );
	}

	public static function post_resume() {
		self::guard( 'orderarc_resume' );
		OrderArc_Runner::resume();
		self::back( 'archive' );
	}

	public static function post_restore_all() {
		self::guard( 'orderarc_restore_all' );
		$s = OrderArc_Runner::settings();
		if ( $s['auto'] ) { // si no, la noche siguiente vuelve a archivar lo restaurado.
			$s['auto'] = false;
			update_option( 'orderarc_settings', $s, false );
		}
		OrderArc_Runner::start( 'restore', true );
		self::back( 'archive' );
	}

	/** Restaurar uno o varios pedidos desde la pestaña de archivados. */
	public static function post_restore() {
		self::guard( 'orderarc_restore' );
		$ids  = array_slice( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ?? array() ) ) ), 0, 200 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificado en guard().
		$msgs = array();
		$st   = OrderArc_Runner::job()['status'] ?? '';
		if ( in_array( $st, array( 'running', 'waiting' ), true ) || ! OrderArc_Runner::lock() ) {
			self::back( 'archived', __( 'An archive or restore job is running. Pause it first.', 'orderarc' ) );
		}
		foreach ( $ids as $id ) {
			if ( ! OrderArc_Archived::exists( $id ) ) {
				continue; // solo pedidos archivados (no reembolsos sueltos ni IDs inventados).
			}
			$r = OrderArc_Engine::move( array( $id ), 'restore', true );
			if ( $r['ok'] && $r['orders'] ) {
				/* translators: %s: order number */
				$msgs[] = sprintf( __( 'Order #%s restored.', 'orderarc' ), $id );
			} elseif ( ! $r['ok'] ) {
				/* translators: 1: first order number, 2: last order number, 3: reason */
				$msgs[] = sprintf( __( 'Orders %1$s–%2$s not moved: %3$s. Nothing was changed for these orders.', 'orderarc' ), $id, $id, $r['error'] );
			}
		}
		OrderArc_Runner::unlock();
		self::back( 'archived', implode( "\n", $msgs ) );
	}

	/* ---------- AJAX ---------- */

	public static function ajax_status() {
		check_ajax_referer( 'orderarc_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$job = OrderArc_Runner::job();
		wp_send_json_success( array( 'status' => $job['status'] ?? '', 'html' => self::status_html( $job ) ) );
	}

	/** Mide una búsqueda real de WooCommerce (la misma que hace el buscador de pedidos). */
	public static function ajax_measure() {
		check_ajax_referer( 'orderarc_ajax' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		global $wpdb;
		$hpos  = OrderArc_Engine::storage()['hpos'];
		$total = self::live_orders();
		$email = $hpos
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- un email de muestra para la búsqueda de prueba; no hay API.
			? $wpdb->get_var( $wpdb->prepare( "SELECT billing_email FROM %i WHERE type='shop_order' AND billing_email<>'' ORDER BY id LIMIT 1 OFFSET %d", OrderArc_Engine::live( 'wc_orders' ), (int) ( $total / 2 ) ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT m.meta_value FROM %i m JOIN %i o ON o.ID=m.post_id AND o.post_type='shop_order' WHERE m.meta_key='_billing_email' AND m.meta_value<>'' ORDER BY m.post_id LIMIT 1 OFFSET %d", OrderArc_Engine::live( 'postmeta' ), OrderArc_Engine::live( 'posts' ), (int) ( $total / 2 ) ) );
		if ( ! $email ) {
			$email = 'orderarc-test@example.com';
		}
		$t = microtime( true );
		if ( $hpos ) {
			wc_get_orders( array( 's' => $email, 'limit' => 20, 'return' => 'ids', 'type' => 'shop_order' ) );
		} else {
			wc_order_search( $email );
		}
		$ms = (int) round( ( microtime( true ) - $t ) * 1000 );
		update_option( 'orderarc_search', array( 'ms' => $ms, 'time' => time(), 'orders' => $total ), false );
		wp_send_json_success( array( 'ms' => $ms ) );
	}

	/* ---------- Datos ---------- */

	public static function live_orders() {
		global $wpdb;
		return OrderArc_Engine::storage()['hpos']
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- conteo de pedidos para el resumen; no hay API sin cargar los pedidos.
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type='shop_order'", OrderArc_Engine::live( 'wc_orders' ) ) )
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type='shop_order'", OrderArc_Engine::live( 'posts' ) ) );
	}

	/** Estados que se pueden archivar: todos menos los que siguen en curso. */
	public static function status_choices() {
		$all = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		foreach ( array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-checkout-draft' ) as $k ) {
			unset( $all[ $k ] );
		}
		return $all;
	}

	private static function storage_label( $st = null ) {
		$st = $st ? $st : OrderArc_Engine::storage();
		return ( $st['hpos'] ? 'HPOS' : 'Legacy' ) . ( $st['sync'] ? ' + sync' : '' );
	}

	private static function fmt_date( $gmt ) {
		if ( ! $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ), strtotime( $gmt . ' UTC' ) ); // en la zona horaria del sitio.
	}

	private static function status_label( $status ) {
		$all = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$key = 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
		return $all[ $key ] ?? $status;
	}

	private static function money( $amount, $currency ) {
		return function_exists( 'wc_price' ) ? wc_price( (float) $amount, array( 'currency' => $currency ) ) : esc_html( $amount );
	}

	/* ---------- Pantalla ---------- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab  = sanitize_key( $_GET['tab'] ?? 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navegación.
		$tabs = array(
			'overview' => __( 'Overview', 'orderarc' ),
			'archive'  => __( 'Archive', 'orderarc' ),
			'archived' => __( 'Archived orders', 'orderarc' ),
		);
		// Pestañas extra (versión Pro): clave => título; se dibujan con la acción orderarc_tab_{clave}.
		$tabs = (array) apply_filters( 'orderarc_admin_tabs', $tabs );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
		echo '<div class="wrap orderarc"><h1>OrderArc</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( array( 'tab' => $k ) ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		$msg = get_transient( 'orderarc_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'orderarc_msg_' . get_current_user_id() );
			echo '<div class="notice notice-info is-dismissible"><p>' . nl2br( esc_html( $msg ) ) . '</p></div>';
		}
		self::storage_notice();
		if ( '' !== OrderArc_Engine::archive_blocked() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( OrderArc_Engine::archive_blocked() ) . '</p></div>';
		}
		do_action( 'orderarc_notices' );
		if ( ! OrderArc_Engine::db_supported() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'OrderArc needs a MySQL or MariaDB database. This site uses a different database, so archiving is turned off.', 'orderarc' ) . '</p></div>';
			if ( 'archive' === $tab ) {
				echo '</div>';
				return;
			}
		}
		if ( method_exists( __CLASS__, 'tab_' . $tab ) ) {
			call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		} else {
			do_action( 'orderarc_tab_' . $tab );
		}
		echo '</div>';
	}

	/** Se archivó con un modo y la tienda ahora usa otro: solo el hecho. */
	private static function storage_notice() {
		$was = get_option( 'orderarc_archived_storage' );
		$now = OrderArc_Engine::storage()['hpos'] ? 'hpos' : 'legacy';
		if ( $was && $was !== $now && OrderArc_Engine::archived_count() > 0 ) {
			$label = array( 'hpos' => 'HPOS', 'legacy' => 'Legacy' );
			/* translators: 1: storage when orders were archived, 2: current storage */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Orders were archived while the store used %1$s. The store now uses %2$s.', 'orderarc' ), $label[ $was ], $label[ $now ] ) ) . '</p></div>';
		}
	}

	private static function tab_overview() {
		global $wpdb;
		$s       = OrderArc_Runner::settings();
		$pre     = OrderArc_Engine::preview( $s );
		$names   = array_intersect_key( self::status_choices(), array_flip( $s['statuses'] ) );
		$search  = get_option( 'orderarc_search' );
		$h       = OrderArc_Runner::health();
		$checked = (int) get_option( 'orderarc_third_party_checked' );
		if ( $checked < time() - DAY_IN_SECONDS ) {
			OrderArc_Engine::refresh_third_party();
		}
		echo '<table class="widefat striped orderarc-facts"><tbody>';
		self::fact( __( 'Order storage', 'orderarc' ), esc_html( self::storage_label() ) );
		self::fact( __( 'Orders in the store', 'orderarc' ), esc_html( number_format_i18n( self::live_orders() ) ) );
		self::fact(
			__( 'Ready to archive', 'orderarc' ),
			esc_html( number_format_i18n( $pre['orders'] ) ) . ' <span class="description">(' . esc_html(
				/* translators: 1: list of statuses, 2: number of days */
				apply_filters( 'orderarc_rules_label', sprintf( __( '%1$s · older than %2$s days', 'orderarc' ), implode( ', ', $names ), number_format_i18n( $s['older_than_days'] ) ), $s )
			) . ')</span>'
		);
		self::fact( __( 'Skipped (orders with subscriptions or other child orders)', 'orderarc' ), esc_html( number_format_i18n( $pre['skipped'] ) ) );
		self::fact( __( 'Archived', 'orderarc' ), esc_html( number_format_i18n( OrderArc_Engine::archived_count() ) ) );
		$res = '';
		if ( $search ) {
			/* translators: %s: seconds */
			$res = esc_html( sprintf( __( 'Searching orders took %s s', 'orderarc' ), number_format_i18n( $search['ms'] / 1000, 1 ) ) ) . ' <span class="description">(' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $search['time'] ) ) . ')</span>';
		}
		self::fact( '<button type="button" class="button" id="orderarc-measure">' . esc_html__( 'Measure search speed', 'orderarc' ) . '</button>', '<span id="orderarc-measure-result">' . $res . '</span>', true );
		$tp = OrderArc_Engine::third_party();
		if ( $tp ) {
			$list = array();
			foreach ( $tp as $t => $col ) {
				if ( OrderArc_Engine::table_exists( OrderArc_Engine::live( $t ) ) ) {
					$list[] = '<code>' . esc_html( OrderArc_Engine::live( $t ) ) . '</code> (' . esc_html( $col ) . ')';
				}
			}
			if ( $list ) {
				self::fact( __( 'Tables from other plugins linked to orders', 'orderarc' ), implode( '<br>', $list ) );
			}
		}
		$parts = array();
		if ( $h['last_tick'] ) {
			/* translators: %s: time span, e.g. "3 mins" */
			$parts[] = sprintf( __( 'last run %s ago', 'orderarc' ), human_time_diff( $h['last_tick'] ) );
		}
		/* translators: %s: number */
		$parts[] = sprintf( __( 'nights missed: %s', 'orderarc' ), number_format_i18n( $h['missed_windows'] ) );
		/* translators: %s: yes or no */
		$parts[] = sprintf( __( 'WP-Cron disabled: %s', 'orderarc' ), $h['wp_cron_disabled'] ? __( 'yes', 'orderarc' ) : __( 'no', 'orderarc' ) );
		self::fact( __( 'Background tasks', 'orderarc' ), esc_html( implode( ' · ', $parts ) ) );
		do_action( 'orderarc_overview_rows' );
		echo '</tbody></table>';
	}

	private static function fact( $label, $value_html, $label_is_html = false ) {
		echo '<tr><th scope="row">' . ( $label_is_html ? wp_kses_post( $label ) : esc_html( $label ) ) . '</th><td>' . wp_kses_post( $value_html ) . '</td></tr>';
	}

	private static function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="orderarc-inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	private static function backup_box() {
		if ( get_option( 'orderarc_backup_ack' ) ) {
			return;
		}
		echo '<label class="orderarc-backup"><input type="checkbox" name="orderarc_backup" value="1" required> ' . esc_html__( 'I have a recent backup of the database', 'orderarc' ) . '</label> ';
	}

	private static function tab_archive() {
		$s       = OrderArc_Runner::settings();
		$job     = OrderArc_Runner::job();
		$status  = $job['status'] ?? '';
		$active  = in_array( $status, array( 'running', 'waiting' ), true );
		$preview = get_transient( 'orderarc_preview_' . get_current_user_id() );
		if ( is_array( $preview ) && ( $preview['rules_key'] ?? '' ) !== md5( wp_json_encode( OrderArc_Engine::rules( $s ) ) ) ) {
			$preview = false;
		}

		echo '<div class="orderarc-job" data-status="' . esc_attr( $status ) . '"><div id="orderarc-status">' . wp_kses_post( self::status_html( $job ) ) . '</div><p>';
		if ( $active ) {
			self::form_open( 'orderarc_pause' );
			submit_button( __( 'Pause', 'orderarc' ), 'secondary', 'submit', false );
			echo '</form>';
		} elseif ( 'paused' === $status ) {
			self::form_open( 'orderarc_resume' );
			submit_button( __( 'Resume', 'orderarc' ), 'primary', 'submit', false );
			echo '</form>';
		} else {
			self::form_open( 'orderarc_preview' );
			submit_button( __( 'Preview', 'orderarc' ), 'secondary', 'submit', false );
			echo '</form>';
			self::form_open( 'orderarc_start' );
			self::backup_box();
			submit_button( __( 'Archive now', 'orderarc' ), 'primary', 'submit', false );
			echo '</form>';
		}
		if ( ! $active && OrderArc_Engine::archived_count() > 0 ) {
			self::form_open( 'orderarc_restore_all' );
			submit_button( __( 'Restore all', 'orderarc' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</p>';
		if ( $preview && ! $active ) {
			echo '<p class="orderarc-preview">' . esc_html(
				sprintf(
					/* translators: 1: orders, 2: refunds, 3: oldest date, 4: newest date */
					__( '%1$s orders and %2$s refunds would be archived (from %3$s to %4$s)', 'orderarc' ),
					number_format_i18n( $preview['orders'] ),
					number_format_i18n( $preview['refunds'] ),
					self::fmt_date( $preview['from'] ),
					self::fmt_date( $preview['to'] )
				)
			) . '</p>';
		}
		echo '</div>';

		// Ajustes.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="orderarc_save">';
		wp_nonce_field( 'orderarc_save' );
		echo '<table class="form-table" role="presentation"><tbody>';
		// La versión Pro puede reemplazar la regla única (estados + antigüedad) por varias reglas.
		if ( apply_filters( 'orderarc_show_basic_rule', true ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Statuses to archive', 'orderarc' ) . '</th><td>';
			foreach ( self::status_choices() as $k => $label ) {
				printf( '<label class="orderarc-check"><input type="checkbox" name="statuses[]" value="%s"%s> %s</label>', esc_attr( $k ), checked( in_array( $k, $s['statuses'], true ), true, false ), esc_html( $label ) );
			}
			echo '</td></tr>';
			$days = '<input type="number" name="older_than_days" min="30" max="36500" class="small-text" value="' . esc_attr( $s['older_than_days'] ) . '">';
			/* translators: %s: number input */
			echo '<tr><th scope="row">' . esc_html__( 'Older than', 'orderarc' ) . '</th><td>' . wp_kses( sprintf( esc_html__( '%s days', 'orderarc' ), $days ), self::form_tags() ) . '</td></tr>';
		}
		do_action( 'orderarc_settings_rows', $s );
		/* translators: 1: start hour select, 2: end hour select */
		echo '<tr><th scope="row">' . esc_html__( 'Run between', 'orderarc' ) . '</th><td>' . wp_kses( sprintf( esc_html__( '%1$s and %2$s', 'orderarc' ), self::hour_select( 'window_start', $s['window_start'] ), self::hour_select( 'window_end', $s['window_end'] ) ), self::form_tags() ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Archive automatically every day', 'orderarc' ) . '</th><td><label class="orderarc-check"><input type="checkbox" name="auto" id="orderarc-auto" value="1"' . checked( $s['auto'], true, false ) . '> ' . esc_html__( 'Enabled', 'orderarc' ) . '</label> ';
		self::backup_box_optional();
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'If a night is missed', 'orderarc' ) . '</th><td>';
		printf( '<label class="orderarc-check"><input type="radio" name="catch_up" value="gentle"%s> %s</label>', checked( $s['catch_up'], 'gentle', false ), esc_html__( 'Continue slowly', 'orderarc' ) );
		printf( '<label class="orderarc-check"><input type="radio" name="catch_up" value="wait"%s> %s</label>', checked( $s['catch_up'], 'wait', false ), esc_html__( 'Wait for the next night', 'orderarc' ) );
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save changes', 'orderarc' ) );
		echo '</form>';
		do_action( 'orderarc_archive_tab_after', $s );
		if ( ! defined( 'ORDERARC_PRO' ) ) {
			echo '<p class="description orderarc-pro-line">' . esc_html__( 'OrderArc Pro adds archive rules, WP-CLI, a monthly report, an archive in another database, and retention and GDPR tools.', 'orderarc' ) . ' <a href="https://getorderarc.com/#pro" target="_blank" rel="noopener">' . esc_html__( 'Learn more', 'orderarc' ) . '</a></p>';
		}
	}

	/** En los ajustes la casilla de backup solo hace falta si se activa el archivado automático. */
	private static function backup_box_optional() {
		if ( ! get_option( 'orderarc_backup_ack' ) ) {
			echo '<label class="orderarc-backup" id="orderarc-auto-backup"><input type="checkbox" name="orderarc_backup" value="1"> ' . esc_html__( 'I have a recent backup of the database', 'orderarc' ) . '</label>';
		}
	}

	/** Etiquetas de formulario permitidas al imprimir los campos de ajustes. */
	private static function form_tags() {
		return array(
			'input'  => array( 'type' => true, 'name' => true, 'id' => true, 'min' => true, 'max' => true, 'class' => true, 'value' => true ),
			'select' => array( 'name' => true ),
			'option' => array( 'value' => true, 'selected' => true ),
		);
	}

	private static function hour_select( $name, $val ) {
		$h = '<select name="' . esc_attr( $name ) . '">';
		for ( $i = 0; $i < 24; $i++ ) {
			$h .= sprintf( '<option value="%d"%s>%02d:00</option>', $i, selected( (int) $val, $i, false ), $i );
		}
		return $h . '</select>';
	}

	/** Línea de estado del trabajo (también la usa el refresco por AJAX). */
	public static function status_html( $job ) {
		$st = $job['status'] ?? '';
		if ( ! $st ) {
			return '';
		}
		$count = (int) ( $job['orders'] ?? 0 );
		$n     = number_format_i18n( $count );
		$errs  = (array) ( $job['errors'] ?? array() );
		$dir   = 'restore' === ( $job['direction'] ?? '' );
		switch ( $st ) {
			case 'running':
				$line = $dir
					/* translators: 1: number of orders, 2: number of errors */
					? sprintf( _n( 'Running — %1$s order restored · %2$s errors', 'Running — %1$s orders restored · %2$s errors', $count, 'orderarc' ), $n, number_format_i18n( count( $errs ) ) )
					/* translators: 1: number of orders, 2: number of errors */
					: sprintf( _n( 'Running — %1$s order archived · %2$s errors', 'Running — %1$s orders archived · %2$s errors', $count, 'orderarc' ), $n, number_format_i18n( count( $errs ) ) );
				break;
			case 'waiting':
				/* translators: %s: time, e.g. 01:00 */
				$line = sprintf( __( 'Waiting for the time window (opens at %s)', 'orderarc' ), sprintf( '%02d:00', (int) OrderArc_Runner::settings()['window_start'] ) );
				break;
			case 'paused':
				$line = __( 'Paused', 'orderarc' );
				break;
			case 'done':
				$mins = max( 1, (int) round( ( ( $job['finished'] ?? time() ) - ( $job['started'] ?? time() ) ) / 60 ) );
				$line = $dir
					/* translators: 1: number of orders, 2: minutes */
					? sprintf( _n( 'Done: %1$s order restored in %2$s min', 'Done: %1$s orders restored in %2$s min', $count, 'orderarc' ), $n, number_format_i18n( $mins ) )
					/* translators: 1: number of orders, 2: minutes */
					: sprintf( _n( 'Done: %1$s order in %2$s min', 'Done: %1$s orders in %2$s min', $count, 'orderarc' ), $n, number_format_i18n( $mins ) );
				break;
			default:
				$line = '';
		}
		$html = '<p class="orderarc-line orderarc-' . esc_attr( $st ) . '"><strong>' . esc_html( $line ) . '</strong></p>';
		if ( $errs ) {
			$html .= '<ul class="orderarc-errors">';
			foreach ( array_slice( array_reverse( $errs ), 0, 10 ) as $e ) {
				$html .= '<li>' . esc_html( $e ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}

	private static function tab_archived() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- búsqueda y navegación (GET).
		$order = absint( $_GET['order'] ?? 0 );
		if ( $order ) {
			self::order_view( $order );
			return;
		}
		$term  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$per  = 20;
		$res  = OrderArc_Archived::search( $term, $paged, $per );
		$rows = OrderArc_Archived::summaries( $res['ids'] );

		echo '<form method="get" class="orderarc-search"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="archived">';
		echo '<input type="search" name="s" value="' . esc_attr( $term ) . '" placeholder="' . esc_attr__( 'Search archived orders (number, email, name, phone)', 'orderarc' ) . '" class="orderarc-q"> ';
		submit_button( __( 'Search', 'orderarc' ), 'secondary', '', false );
		echo '</form>';

		self::form_open( 'orderarc_restore' );
		echo '<table class="wp-list-table widefat fixed striped orderarc-list"><thead><tr><td class="check-column"><input type="checkbox" id="orderarc-all"></td>';
		foreach ( array( __( 'Order', 'orderarc' ), __( 'Date', 'orderarc' ), __( 'Status', 'orderarc' ), __( 'Customer', 'orderarc' ), __( 'Email', 'orderarc' ), __( 'Total', 'orderarc' ), '' ) as $c ) {
			echo '<th scope="col">' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No archived orders found.', 'orderarc' ) . '</td></tr>';
		}
		foreach ( $rows as $id => $o ) {
			$view = self::url( array( 'tab' => 'archived', 'order' => $id ) );
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( $id ) . '"></th>';
			echo '<td><a href="' . esc_url( $view ) . '"><strong>#' . esc_html( $id ) . '</strong></a></td>';
			echo '<td>' . esc_html( self::fmt_date( $o['date'] ) ) . '</td><td>' . esc_html( self::status_label( $o['status'] ) ) . '</td>';
			echo '<td>' . esc_html( $o['customer'] ) . '</td><td>' . esc_html( $o['email'] ) . '</td><td>' . wp_kses_post( self::money( $o['total'], $o['currency'] ) ) . '</td>';
			echo '<td class="orderarc-actions"><a class="button button-small" href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'orderarc' ) . '</a> ';
			echo '<button type="submit" class="button button-small" name="ids[]" value="' . esc_attr( $id ) . '" formnovalidate data-single="1">' . esc_html__( 'Restore', 'orderarc' ) . '</button></td></tr>';
		}
		echo '</tbody></table><div class="tablenav bottom"><div class="alignleft actions">';
		submit_button( __( 'Restore selected', 'orderarc' ), 'secondary', 'restore_selected', false );
		echo '</div>';
		$pages = (int) ceil( $res['total'] / $per );
		/* translators: %s: number of items */
		echo '<div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( _n( '%s item', '%s items', $res['total'], 'orderarc' ), number_format_i18n( $res['total'] ) ) ) . '</span>';
		if ( $pages > 1 ) {
			echo ' ' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
		}
		echo '</div></div></form>';
	}

	private static function order_view( $id ) {
		$o = OrderArc_Archived::get( $id );
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'archived' ) ) ) . '">&larr; ' . esc_html__( 'Archived orders', 'orderarc' ) . '</a></p>';
		if ( ! $o ) {
			echo '<p>' . esc_html__( 'No archived orders found.', 'orderarc' ) . '</p>';
			return;
		}
		$cur = $o['currency'];
		echo '<div class="orderarc-order"><h2>#' . esc_html( $id ) . ' <span class="orderarc-badge">' . esc_html__( 'Archived order (read-only)', 'orderarc' ) . '</span></h2>';
		echo '<p>' . esc_html( self::fmt_date( $o['date'] ) ) . ' · ' . esc_html( self::status_label( $o['status'] ) ) . ( $o['payment'] ? ' · ' . esc_html( $o['payment'] ) : '' ) . '</p>';
		echo '<div class="orderarc-cols"><div><h3>' . esc_html__( 'Billing', 'orderarc' ) . '</h3><p>' . wp_kses_post( $o['billing'] ) . '</p><p>' . esc_html( $o['email'] ) . ( $o['phone'] ? '<br>' . esc_html( $o['phone'] ) : '' ) . '</p></div>';
		echo '<div><h3>' . esc_html__( 'Shipping', 'orderarc' ) . '</h3><p>' . wp_kses_post( $o['shipping'] ) . '</p></div></div>';
		if ( $o['note'] ) {
			echo '<p><em>' . esc_html( $o['note'] ) . '</em></p>';
		}
		echo '<table class="widefat striped orderarc-items"><thead><tr><th>' . esc_html__( 'Item', 'orderarc' ) . '</th><th>' . esc_html__( 'Qty', 'orderarc' ) . '</th><th>' . esc_html__( 'Total', 'orderarc' ) . '</th></tr></thead><tbody>';
		foreach ( $o['items'] as $it ) {
			if ( ! in_array( $it['type'], array( 'line_item', 'fee' ), true ) ) {
				continue; // envío y cupones van en las filas de totales.
			}
			echo '<tr><td>' . esc_html( $it['name'] ) . '</td><td>' . esc_html( 'line_item' === $it['type'] ? $it['qty'] : '' ) . '</td><td>' . wp_kses_post( self::money( (float) $it['shown'], $cur ) ) . '</td></tr>';
		}
		$ship_names = OrderArc_Archived::shipping_names( $o );
		echo '</tbody><tfoot>';
		$tot = array(
			__( 'Discount', 'orderarc' ) => (float) $o['discount'] ? -1 * (float) $o['discount'] : null,
			__( 'Shipping', 'orderarc' ) => (float) $o['ship'] ? (float) $o['ship'] : null,
			__( 'Tax', 'orderarc' )      => (float) $o['tax'] ? (float) $o['tax'] : null,
			__( 'Total', 'orderarc' )    => (float) $o['total'],
		);
		foreach ( $tot as $label => $v ) {
			if ( null !== $v ) {
				$extra = __( 'Shipping', 'orderarc' ) === $label && $ship_names ? ' · ' . $ship_names : '';
				echo '<tr><th colspan="2">' . esc_html( $label . $extra ) . '</th><td>' . wp_kses_post( self::money( $v, $cur ) ) . '</td></tr>';
			}
		}
		foreach ( $o['refunds'] as $r ) {
			echo '<tr><th colspan="2">' . esc_html__( 'Refunded', 'orderarc' ) . ' · ' . esc_html( self::fmt_date( $r['date'] ) ) . ( $r['reason'] ? ' · ' . esc_html( $r['reason'] ) : '' ) . '</th><td>' . wp_kses_post( self::money( -1 * abs( (float) $r['amount'] ), $cur ) ) . '</td></tr>';
		}
		echo '</tfoot></table>';
		if ( $o['notes'] ) {
			echo '<h3>' . esc_html__( 'Notes', 'orderarc' ) . '</h3><ul class="orderarc-notes">';
			foreach ( $o['notes'] as $n ) {
				echo '<li class="' . ( $n['customer'] ? 'customer' : '' ) . '"><div>' . wp_kses_post( wpautop( $n['content'] ) ) . '</div><small>' . esc_html( self::fmt_date( $n['date'] ) ) . ' ' . esc_html( $n['author'] ) . '</small></li>';
			}
			echo '</ul>';
		}
		self::form_open( 'orderarc_restore' );
		echo '<input type="hidden" name="ids[]" value="' . esc_attr( $id ) . '">';
		submit_button( __( 'Restore this order', 'orderarc' ), 'primary', 'submit', false );
		echo '</form></div>';
	}
}
