<?php
/**
 * Datos personales de los pedidos archivados: exportar y borrar a pedido (Herramientas → Exportar /
 * Borrar datos personales), igual que WooCommerce hace con los pedidos de la tienda.
 *
 * - Exportar: los pedidos archivados del email (o de su cuenta) salen en el mismo archivo que arma WordPress.
 * - Borrar: respeta el ajuste de WooCommerce "Al gestionar una solicitud de borrado de cuenta, eliminar los
 *   datos personales de los pedidos" (woocommerce_erasure_request_removes_order_data). Si está apagado, los
 *   pedidos archivados se conservan y se avisa, como hace Woo con los de la tienda.
 *
 * Anonimizar solo toca el ARCHIVO. Mientras el pedido está archivado, la marca "anonimizado" va en una tabla
 * propia ({prefix}orderarc_anon_marks) y no como meta: una fila nueva en la meta del archivo tomaría un id que
 * puede estar usado en la tabla viva y el pedido ya no se podría restaurar. Al restaurarlo recibe en la tabla
 * viva la meta `_anonymized` = yes y la nota "Personal data removed." (las mismas que usa WooCommerce).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- lee y actualiza filas de las tablas propias del archivo.
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- todo identificador va con %i y todo valor con %s/%d; en el SQL solo se interpolan listas de marcadores ($*_placeholders, un marcador por valor).
 *
 * @package OrderArc
 */

defined( 'ABSPATH' ) || exit;

class OrderArc_Privacy {

	const PER_PAGE = 10;

	/** Campos de dirección con datos personales. País y provincia aparte (ver anonymize()). */
	const ADDRESS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'email', 'phone' );

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'orderarc_restored', array( __CLASS__, 'on_restored' ), 10, 1 );
	}

	public static function register_exporter( $exporters ) {
		$exporters['orderarc-archived-orders'] = array(
			'exporter_friendly_name' => __( 'OrderArc Archived Orders', 'orderarc' ),
			'callback'               => array( __CLASS__, 'exporter' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['orderarc-archived-orders'] = array(
			'eraser_friendly_name' => __( 'OrderArc Archived Orders', 'orderarc' ),
			'callback'             => array( __CLASS__, 'eraser' ),
		);
		return $erasers;
	}

	private static function anon( $type ) {
		return function_exists( 'wp_privacy_anonymize_data' ) ? wp_privacy_anonymize_data( $type ) : ( 'email' === $type ? 'deleted@site.invalid' : '' );
	}

	/** Se puede leer y cambiar el archivo (existe y no es el de otra copia del sitio). */
	private static function usable() {
		return OrderArc_Engine::archive_ready() && '' === OrderArc_Engine::archive_blocked();
	}

	/* ---------------------------------------------------------------- Marcas */

	/** Tabla de marcas (vive con el archivo: si el archivo está en otra base, se mueve con él). */
	public static function marks() {
		return OrderArc_Engine::arch( 'anon_marks' );
	}

	public static function ensure_marks() {
		global $wpdb;
		$t = self::marks();
		if ( ! OrderArc_Engine::table_exists( $t ) ) {
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, done_gmt DATETIME NOT NULL) ENGINE=InnoDB', $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- tabla propia.
		}
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
	}

	/**
	 * Pedidos que vuelven a la tienda: si estaban anonimizados, reciben en la tabla viva la meta `_anonymized`
	 * (con un id nuevo de esa tabla) y la nota de WooCommerce, y salen de la tabla de marcas.
	 */
	public static function on_restored( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		$t   = self::marks();
		if ( ! $ids || ! OrderArc_Engine::table_exists( $t ) ) {
			return;
		}
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$done            = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM %i WHERE order_id IN ($id_placeholders)", array_merge( array( $t ), $ids ) ) ) );
		if ( ! $done ) {
			return;
		}
		$done_placeholders = implode( ',', array_fill( 0, count( $done ), '%d' ) );
		$lo = OrderArc_Engine::live( 'wc_orders' );
		$lm = OrderArc_Engine::live( 'wc_orders_meta' );
		if ( OrderArc_Engine::table_exists( $lo ) && OrderArc_Engine::table_exists( $lm ) ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (order_id, meta_key, meta_value) SELECT o.id, '_anonymized', 'yes' FROM %i o WHERE o.id IN ($done_placeholders) AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.order_id=o.id AND m.meta_key='_anonymized')", array_merge( array( $lm, $lo ), $done, array( $lm ) ) ) );
		}
		$lp  = OrderArc_Engine::live( 'posts' );
		$lpm = OrderArc_Engine::live( 'postmeta' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (post_id, meta_key, meta_value) SELECT p.ID, '_anonymized', 'yes' FROM %i p WHERE p.ID IN ($done_placeholders) AND p.post_type IN ('shop_order','shop_order_refund') AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id=p.ID AND m.meta_key='_anonymized')", array_merge( array( $lpm, $lp ), $done, array( $lpm ) ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE order_id IN ($done_placeholders)", array_merge( array( $t ), $done ) ) );
		OrderArc_Engine::flush_caches( $done );
		foreach ( $done as $id ) {
			$o = wc_get_order( $id );
			if ( $o && 'shop_order_refund' !== $o->get_type() ) {
				$o->add_order_note( __( 'Personal data removed.', 'woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- texto de WooCommerce, ya traducido.
			}
		}
	}

	/* ---------------------------------------------------------------- Anonimizar */

	/**
	 * Anonimiza pedidos y reembolsos del archivo, en una sola transacción. Devuelve cuántos quedaron marcados
	 * (0 si algo falló: entonces no cambia nada).
	 *
	 * $keep_tax_data = true (retención de la versión Pro): conserva país, provincia y el ID de transacción del
	 * pago, que pide la contabilidad. false (solicitud de borrado): los borra también, como WooCommerce.
	 * Siempre conserva importes, impuestos, fechas y productos. Borra las notas internas del pedido.
	 */
	public static function anonymize( $ids, $keep_tax_data ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( ! $ids || ! self::ensure_marks() ) {
			return 0;
		}
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$text            = self::anon( 'text' );
		$email           = self::anon( 'email' );
		$wpdb->query( 'START TRANSACTION' );
		$ok = true;
		// HPOS.
		$hp = OrderArc_Engine::arch( 'wc_orders' );
		if ( OrderArc_Engine::table_exists( $hp ) ) {
			$ha = OrderArc_Engine::arch( 'wc_order_addresses' );
			$hm = OrderArc_Engine::arch( 'wc_orders_meta' );
			if ( $keep_tax_data ) {
				$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET billing_email=IF(billing_email IS NULL OR billing_email='', billing_email, %s), ip_address=IF(ip_address IS NULL OR ip_address='', ip_address, '0.0.0.0'), user_agent='', customer_note='', customer_id=0 WHERE id IN ($id_placeholders)", array_merge( array( $hp, $email ), $ids ) ) );
			} else {
				$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET billing_email=IF(billing_email IS NULL OR billing_email='', billing_email, %s), ip_address=IF(ip_address IS NULL OR ip_address='', ip_address, '0.0.0.0'), user_agent='', customer_note='', customer_id=0, transaction_id='' WHERE id IN ($id_placeholders)", array_merge( array( $hp, $email ), $ids ) ) );
			}
			if ( OrderArc_Engine::table_exists( $ha ) ) {
						$vals = array( $ha, $text, $text, $text, $text, $text, $text, $text, $email, $text );
				if ( $keep_tax_data ) {
					$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET first_name=IF(first_name IS NULL OR first_name='', first_name, %s), last_name=IF(last_name IS NULL OR last_name='', last_name, %s), company=IF(company IS NULL OR company='', company, %s), address_1=IF(address_1 IS NULL OR address_1='', address_1, %s), address_2=IF(address_2 IS NULL OR address_2='', address_2, %s), city=IF(city IS NULL OR city='', city, %s), postcode=IF(postcode IS NULL OR postcode='', postcode, %s), email=IF(email IS NULL OR email='', email, %s), phone=IF(phone IS NULL OR phone='', phone, %s) WHERE order_id IN ($id_placeholders)", array_merge( $vals, $ids ) ) );
				} else {
					$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET first_name=IF(first_name IS NULL OR first_name='', first_name, %s), last_name=IF(last_name IS NULL OR last_name='', last_name, %s), company=IF(company IS NULL OR company='', company, %s), address_1=IF(address_1 IS NULL OR address_1='', address_1, %s), address_2=IF(address_2 IS NULL OR address_2='', address_2, %s), city=IF(city IS NULL OR city='', city, %s), postcode=IF(postcode IS NULL OR postcode='', postcode, %s), email=IF(email IS NULL OR email='', email, %s), phone=IF(phone IS NULL OR phone='', phone, %s), state=IF(state IS NULL OR state='', state, ''), country=IF(country IS NULL OR country='', country, '') WHERE order_id IN ($id_placeholders)", array_merge( $vals, $ids ) ) );
				}
			}
			if ( OrderArc_Engine::table_exists( $hm ) ) {
				$mk = array( '_billing_address_index', '_shipping_address_index' );
				if ( ! $keep_tax_data ) {
					$mk = array_merge( $mk, array( 'Payer first name', 'Payer last name', 'Payer PayPal address', 'Transaction ID' ) );
				}
				$key_placeholders = implode( ',', array_fill( 0, count( $mk ), '%s' ) );
				$ok               = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET meta_value=%s WHERE meta_key IN ($key_placeholders) AND meta_value<>'' AND order_id IN ($id_placeholders)", array_merge( array( $hm, $text ), $mk, $ids ) ) );
			}
		}
		// wp_posts / wp_postmeta (legacy, o la copia sincronizada).
		$pp = OrderArc_Engine::arch( 'posts' );
		$pm = OrderArc_Engine::arch( 'postmeta' );
		if ( OrderArc_Engine::table_exists( $pp ) && OrderArc_Engine::table_exists( $pm ) ) {
			$keys = array( '_customer_ip_address', '_customer_user_agent', '_customer_user', '_billing_address_index', '_shipping_address_index' );
			if ( ! $keep_tax_data ) {
				$keys = array_merge( $keys, array( '_transaction_id', 'Payer first name', 'Payer last name', 'Payer PayPal address', 'Transaction ID' ) );
			}
			$addr = $keep_tax_data ? self::ADDRESS : array_merge( self::ADDRESS, array( 'state', 'country' ) );
			foreach ( array( 'billing', 'shipping' ) as $t ) {
				foreach ( $addr as $c ) {
					$keys[] = '_' . $t . '_' . $c;
				}
			}
			$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$ok               = $ok && false !== $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET meta_value=CASE WHEN meta_value IS NULL OR meta_value='' THEN meta_value WHEN meta_key LIKE %s THEN %s WHEN meta_key='_customer_ip_address' THEN '0.0.0.0' WHEN meta_key IN ('_customer_user_agent','_transaction_id') THEN '' WHEN meta_key='_customer_user' THEN '0' WHEN meta_key LIKE %s OR meta_key LIKE %s THEN '' ELSE %s END WHERE meta_key IN ($key_placeholders) AND post_id IN ($id_placeholders)",
					array_merge( array( $pm, '%\\_email', $email, '%\\_state', '%\\_country', $text ), $keys, $ids )
				)
			);
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET post_excerpt='' WHERE post_type IN ('shop_order','shop_order_refund') AND ID IN ($id_placeholders)", array_merge( array( $pp ), $ids ) ) );
		}
		// Notas internas del pedido (y su meta): se borran, como en el borrado de datos de WooCommerce.
		$ac  = OrderArc_Engine::arch( 'comments' );
		$acm = OrderArc_Engine::arch( 'commentmeta' );
		if ( OrderArc_Engine::table_exists( $ac ) ) {
			if ( OrderArc_Engine::table_exists( $acm ) ) {
				$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "DELETE m FROM %i m JOIN %i c ON c.comment_ID=m.comment_id WHERE c.comment_type='order_note' AND c.comment_post_ID IN ($id_placeholders)", array_merge( array( $acm, $ac ), $ids ) ) );
			}
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE comment_type='order_note' AND comment_post_ID IN ($id_placeholders)", array_merge( array( $ac ), $ids ) ) );
		}
		// Marca de cada pedido (INSERT IGNORE por si una pasada anterior quedó a medias).
		$row_placeholders = implode( ',', array_fill( 0, count( $ids ), '(%d, UTC_TIMESTAMP())' ) );
		$ok               = $ok && false !== $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (order_id, done_gmt) VALUES $row_placeholders", array_merge( array( self::marks() ), $ids ) ) );
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		$wpdb->query( 'COMMIT' );
		delete_transient( 'orderarc_arch_sources' );
		return count( $ids );
	}

	/* ---------------------------------------------------------------- Buscar por persona */

	/**
	 * Pedidos archivados (no reembolsos) de un email o de la cuenta con ese email, del más nuevo al más viejo.
	 * $exclude_marked: sin los que ya están anonimizados (para el borrado, que los va sacando de la lista).
	 */
	private static function orders_for( $email, $offset, $limit, $exclude_marked = false ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$uid  = $user ? (int) $user->ID : -1;
		if ( $exclude_marked && ! self::ensure_marks() ) {
			return array();
		}
		$mk  = self::marks();
		$ids = array();
		$hp  = OrderArc_Engine::arch( 'wc_orders' );
		$ha  = OrderArc_Engine::arch( 'wc_order_addresses' );
		if ( OrderArc_Engine::table_exists( $hp ) && OrderArc_Engine::table_exists( $ha ) ) {
			if ( $exclude_marked ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT o.id FROM %i o WHERE o.type='shop_order' AND ( o.billing_email=%s OR o.customer_id=%d OR EXISTS (SELECT 1 FROM %i a WHERE a.order_id=o.id AND a.email=%s) ) AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=o.id)", $hp, $email, $uid, $ha, $email, $mk ) );
			} else {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT o.id FROM %i o WHERE o.type='shop_order' AND ( o.billing_email=%s OR o.customer_id=%d OR EXISTS (SELECT 1 FROM %i a WHERE a.order_id=o.id AND a.email=%s) )", $hp, $email, $uid, $ha, $email ) );
			}
		}
		$pp = OrderArc_Engine::arch( 'posts' );
		$pm = OrderArc_Engine::arch( 'postmeta' );
		if ( OrderArc_Engine::table_exists( $pp ) && OrderArc_Engine::table_exists( $pm ) ) {
			if ( $exclude_marked ) {
				$more = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p WHERE p.post_type='shop_order' AND EXISTS (SELECT 1 FROM %i m WHERE m.post_id=p.ID AND ( (m.meta_key='_billing_email' AND m.meta_value=%s) OR (m.meta_key='_customer_user' AND m.meta_value=%s) )) AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=p.ID)", $pp, $pm, $email, (string) $uid, $mk ) );
			} else {
				$more = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p WHERE p.post_type='shop_order' AND EXISTS (SELECT 1 FROM %i m WHERE m.post_id=p.ID AND ( (m.meta_key='_billing_email' AND m.meta_value=%s) OR (m.meta_key='_customer_user' AND m.meta_value=%s) ))", $pp, $pm, $email, (string) $uid ) );
			}
			$ids = array_merge( $ids, $more );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		rsort( $ids );
		return array_slice( $ids, $offset, $limit );
	}

	/** Reembolsos archivados de esos pedidos (también se anonimizan). */
	private static function refunds_of( $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$out             = array();
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$hp              = OrderArc_Engine::arch( 'wc_orders' );
		if ( OrderArc_Engine::table_exists( $hp ) ) {
			$out = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type='shop_order_refund' AND parent_order_id IN ($id_placeholders)", array_merge( array( $hp ), $ids ) ) );
		}
		$pp = OrderArc_Engine::arch( 'posts' );
		if ( OrderArc_Engine::table_exists( $pp ) ) {
			$out = array_merge( $out, $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type='shop_order_refund' AND post_parent IN ($id_placeholders)", array_merge( array( $pp ), $ids ) ) ) );
		}
		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}

	/* ---------------------------------------------------------------- Exportar */

	public static function exporter( $email, $page = 1 ) {
		$page = max( 1, (int) $page );
		$out  = array();
		if ( ! self::usable() || ! is_email( $email ) ) {
			return array( 'data' => $out, 'done' => true );
		}
		$ids = self::orders_for( $email, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		foreach ( $ids as $id ) {
			$o = OrderArc_Archived::get( $id, true );
			if ( ! $o ) {
				continue;
			}
			$items = array();
			foreach ( (array) ( $o['items'] ?? array() ) as $it ) {
				if ( 'line_item' === $it['type'] ) {
					$items[] = $it['name'] . ' × ' . ( '' !== (string) $it['qty'] ? (float) $it['qty'] : 1 );
				}
			}
			$data = array(
				array( 'name' => __( 'Order Number', 'orderarc' ), 'value' => $id ),
				array( 'name' => __( 'Order Date', 'orderarc' ), 'value' => $o['date'] ),
				array( 'name' => __( 'Billing Address', 'orderarc' ), 'value' => wp_strip_all_tags( str_replace( '<br/>', ', ', (string) $o['billing'] ) ) ),
				array( 'name' => __( 'Shipping Address', 'orderarc' ), 'value' => wp_strip_all_tags( str_replace( '<br/>', ', ', (string) $o['shipping'] ) ) ),
				array( 'name' => __( 'Phone Number', 'orderarc' ), 'value' => $o['phone'] ),
				array( 'name' => __( 'Email Address', 'orderarc' ), 'value' => $o['email'] ),
				array( 'name' => __( 'Items Purchased', 'orderarc' ), 'value' => implode( ', ', $items ) ),
			);
			$net = self::network_data( $id );
			if ( '' !== $net['ip'] ) {
				$data[] = array( 'name' => __( 'IP Address', 'orderarc' ), 'value' => $net['ip'] );
			}
			if ( '' !== $net['ua'] ) {
				$data[] = array( 'name' => __( 'Browser User Agent', 'orderarc' ), 'value' => $net['ua'] );
			}
			$out[] = array(
				'group_id'          => 'orderarc_archived_orders',
				'group_label'       => __( 'Archived orders', 'orderarc' ),
				'group_description' => __( 'Orders moved to the OrderArc archive.', 'orderarc' ),
				'item_id'           => 'orderarc-order-' . $id,
				'data'              => array_values(
					array_filter(
						$data,
						function ( $d ) {
							return '' !== (string) $d['value'];
						}
					)
				),
			);
		}
		return array( 'data' => $out, 'done' => count( $ids ) < self::PER_PAGE );
	}

	/** IP y navegador guardados en el pedido archivado. */
	private static function network_data( $id ) {
		global $wpdb;
		$hp = OrderArc_Engine::arch( 'wc_orders' );
		if ( OrderArc_Engine::table_exists( $hp ) ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT ip_address ip, user_agent ua FROM %i WHERE id=%d', $hp, $id ), ARRAY_A );
			if ( $r ) {
				return array( 'ip' => (string) $r['ip'], 'ua' => (string) $r['ua'] );
			}
		}
		$pm = OrderArc_Engine::arch( 'postmeta' );
		if ( OrderArc_Engine::table_exists( $pm ) ) {
			$ip = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_customer_ip_address' LIMIT 1", $pm, $id ) );
			$ua = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_customer_user_agent' LIMIT 1", $pm, $id ) );
			return array( 'ip' => $ip, 'ua' => $ua );
		}
		return array( 'ip' => '', 'ua' => '' );
	}

	/* ---------------------------------------------------------------- Borrar */

	public static function eraser( $email, $page = 1 ) {
		$page = max( 1, (int) $page );
		$res  = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		if ( ! self::usable() || ! is_email( $email ) ) {
			return $res;
		}
		$erase = function_exists( 'wc_string_to_bool' ) && wc_string_to_bool( get_option( 'woocommerce_erasure_request_removes_order_data', 'no' ) );
		if ( ! $erase ) {
			// Se conservan: se informan de a 10 (la lista no cambia entre páginas).
			$ids = self::orders_for( $email, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
			foreach ( $ids as $id ) {
				/* translators: %s: order number */
				$res['messages'][]     = sprintf( __( 'Personal data within archived order %s has been retained.', 'orderarc' ), $id );
				$res['items_retained'] = true;
			}
			$res['done'] = count( $ids ) < self::PER_PAGE;
			return $res;
		}
		if ( ! OrderArc_Runner::lock() ) {
			$res['messages'][]     = __( 'Archived orders were not changed because an archive or restore job is running. Run the erasure again in a few minutes.', 'orderarc' );
			$res['items_retained'] = true;
			return $res;
		}
		// Los anonimizados salen de la lista, así que siempre se toma la primera página de los que faltan.
		$ids = self::orders_for( $email, 0, self::PER_PAGE, true );
		if ( $ids && self::anonymize( array_merge( $ids, self::refunds_of( $ids ) ), false ) ) {
			OrderArc_Engine::flush_caches( $ids );
			foreach ( $ids as $id ) {
				/* translators: %s: order number */
				$res['messages'][]    = sprintf( __( 'Removed personal data from archived order %s.', 'orderarc' ), $id );
				$res['items_removed'] = true;
			}
			$res['done'] = count( $ids ) < self::PER_PAGE;
		} elseif ( $ids ) {
			$res['messages'][]     = __( 'The database did not accept the change, so archived orders were left as they were.', 'orderarc' );
			$res['items_retained'] = true;
		}
		OrderArc_Runner::unlock();
		return $res;
	}
}
