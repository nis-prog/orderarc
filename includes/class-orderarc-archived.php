<?php
/**
 * Lectura de pedidos archivados (búsqueda, listado y vista), sirva el archivo de HPOS o de legacy.
 * Solo lee las tablas {prefix}orderarc_*; nunca escribe.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- lectura de tablas propias del archivo; no hay API de Woo para ellas.
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- las consultas se arman solo con marcadores (%i, %s, %d) y se pasan por prepare().
 *
 * @package OrderArc
 */

defined( 'ABSPATH' ) || exit;

class OrderArc_Archived {

	private static function a( $short ) {
		return OrderArc_Engine::arch( $short );
	}

	private static function hpos_arch() {
		return OrderArc_Engine::storage()['tables'] && OrderArc_Engine::table_exists( OrderArc_Engine::arch( 'wc_orders' ) );
	}

	/** ¿Este pedido (padre) está archivado? */
	public static function exists( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 || ! OrderArc_Engine::archive_ready() ) {
			return false;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE ID=%d AND post_type='shop_order'", self::a( 'posts' ), $id ) ) ) {
			return true;
		}
		return self::hpos_arch() && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE id=%d AND type='shop_order'", self::a( 'wc_orders' ), $id ) );
	}

	/**
	 * Qué copias tiene el archivo: HPOS (wc_orders) y/o pedidos que solo están en wp_posts (archivados en legacy).
	 * Con sincronización el mismo pedido está en las dos: se busca en una sola.
	 */
	private static function sources() {
		if ( ! OrderArc_Engine::archive_ready() ) {
			return array( 'hpos' => false, 'legacy' => false );
		}
		$src = get_transient( 'orderarc_arch_sources' );
		if ( ! is_array( $src ) ) {
			global $wpdb;
			$hpos = self::hpos_arch() && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE type='shop_order' LIMIT 1", self::a( 'wc_orders' ) ) );
			$leg  = $hpos
				? (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i p WHERE p.post_type='shop_order' AND NOT EXISTS (SELECT 1 FROM %i o WHERE o.id=p.ID) LIMIT 1", self::a( 'posts' ), self::a( 'wc_orders' ) ) )
				: (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE post_type='shop_order' LIMIT 1", self::a( 'posts' ) ) );
			$src  = array( 'hpos' => $hpos, 'legacy' => $leg );
			set_transient( 'orderarc_arch_sources', $src, 10 * MINUTE_IN_SECONDS );
		}
		return $src;
	}

	/** IDs únicos del más nuevo al más viejo, con salto y tope. */
	private static function desc( $ids, $offset, $limit ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		rsort( $ids );
		return array_slice( $ids, $offset, $limit );
	}

	/**
	 * Interruptor "solo pedidos que no están también en wc_orders" para las consultas sobre wp_posts del archivo:
	 * con copia HPOS en el archivo se excluyen (se buscan por wc_orders); sin ella, la condición se apaga.
	 */
	private static function only_posts() {
		return self::sources()['hpos'] ? array( 1, self::a( 'wc_orders' ) ) : array( 0, self::a( 'posts' ) );
	}

	/**
	 * Busca pedidos archivados. Sin término: todos, del más nuevo al más viejo.
	 * Número de pedido → por clave; con @ → email por índice; otro texto → nombre, empresa, teléfono, dirección.
	 * Devuelve ['ids' => int[], 'total' => int].
	 */
	public static function search( $term, $page = 1, $per = 20 ) {
		global $wpdb;
		$src    = self::sources();
		$term   = trim( (string) $term );
		$offset = max( 0, ( $page - 1 ) * $per );
		$found  = array();
		list( $of, $ot ) = self::only_posts();

		if ( '' === $term ) {
			$top = $offset + $per;
			if ( $src['legacy'] ) {
				$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p WHERE p.post_type='shop_order' AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i x WHERE x.id=p.ID) ) ORDER BY p.ID DESC LIMIT %d", self::a( 'posts' ), $of, $ot, $top ) ) );
			}
			if ( $src['hpos'] ) {
				$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type='shop_order' ORDER BY id DESC LIMIT %d", self::a( 'wc_orders' ), $top ) ) );
			}
			return array( 'ids' => self::desc( $found, $offset, $per ), 'total' => OrderArc_Engine::archived_count() );
		}

		$num = ltrim( $term, '#' );
		if ( ctype_digit( $num ) && strlen( $num ) <= 12 && self::exists( (int) $num ) ) {
			return array( 'ids' => array( (int) $num ), 'total' => 1 );
		}
		// Una sola pasada por consulta: todos los IDs que coinciden (con tope alto) y se pagina en PHP.
		$cap = 100000;
		if ( false !== strpos( $term, '@' ) ) {
			$pre = $wpdb->esc_like( $term ) . '%';
			if ( $src['hpos'] ) {
				$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type='shop_order' AND billing_email LIKE %s ORDER BY id DESC LIMIT %d", self::a( 'wc_orders' ), $pre, $cap ) ) );
				$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT a.order_id FROM %i a JOIN %i o ON o.id=a.order_id AND o.type='shop_order' WHERE a.email LIKE %s ORDER BY a.order_id DESC LIMIT %d", self::a( 'wc_order_addresses' ), self::a( 'wc_orders' ), $pre, $cap ) ) );
			}
			if ( $src['legacy'] ) {
				$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM %i m JOIN %i p ON p.ID=m.post_id AND p.post_type='shop_order' AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i x WHERE x.id=p.ID) ) WHERE m.meta_key='_billing_email' AND m.meta_value LIKE %s ORDER BY m.post_id DESC LIMIT %d", self::a( 'postmeta' ), self::a( 'posts' ), $of, $ot, $pre, $cap ) ) );
			}
		} else {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			if ( $src['hpos'] ) {
				$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT a.order_id FROM %i a JOIN %i o ON o.id=a.order_id AND o.type='shop_order' WHERE CONCAT_WS(' ',a.first_name,a.last_name,a.company,a.phone,a.address_1,a.city,a.postcode) LIKE %s ORDER BY a.order_id DESC LIMIT %d", self::a( 'wc_order_addresses' ), self::a( 'wc_orders' ), $like, $cap ) ) );
			}
			if ( $src['legacy'] ) {
				$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM %i m JOIN %i p ON p.ID=m.post_id AND p.post_type='shop_order' AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i x WHERE x.id=p.ID) ) WHERE m.meta_key IN ('_billing_address_index','_shipping_address_index') AND m.meta_value LIKE %s ORDER BY m.post_id DESC LIMIT %d", self::a( 'postmeta' ), self::a( 'posts' ), $of, $ot, $like, $cap ) ) );
			}
		}
		$all = self::desc( $found, 0, $cap );
		return array( 'ids' => array_slice( $all, $offset, $per ), 'total' => count( $all ) );
	}

	/**
	 * Pedidos archivados de un cliente (solo por su cuenta, nunca por email). Devuelve ['ids','total'].
	 */
	public static function for_customer( $user_id, $page = 1, $per = 20 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array( 'ids' => array(), 'total' => 0 );
		}
		$src   = self::sources();
		$found = array();
		$cap   = 5000;
		if ( $src['hpos'] ) {
			$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type='shop_order' AND customer_id=%d ORDER BY id DESC LIMIT %d", self::a( 'wc_orders' ), $user_id, $cap ) ) );
		}
		if ( $src['legacy'] ) {
			list( $of, $ot ) = self::only_posts();
			$found           = array_merge( $found, $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM %i m JOIN %i p ON p.ID=m.post_id AND p.post_type='shop_order' AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i x WHERE x.id=p.ID) ) WHERE m.meta_key='_customer_user' AND m.meta_value=%s ORDER BY m.post_id DESC LIMIT %d", self::a( 'postmeta' ), self::a( 'posts' ), $of, $ot, (string) $user_id, $cap ) ) );
		}
		$all = self::desc( $found, 0, $cap );
		return array( 'ids' => array_slice( $all, max( 0, ( $page - 1 ) * $per ), $per ), 'total' => count( $all ) );
	}

	/** Datos para la tabla: número, fecha, estado, cliente, email, total. */
	public static function summaries( $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$o = self::get( $id, false );
			if ( $o ) {
				$out[ $id ] = $o;
			}
		}
		return $out;
	}

	/** Un pedido archivado completo (o solo cabecera si $full es false). */
	public static function get( $id, $full = true ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 || ! OrderArc_Engine::archive_ready() ) {
			return null;
		}
		$row = self::hpos_arch() ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::a( 'wc_orders' ), $id ), ARRAY_A ) : null;
		if ( $row && 'shop_order' !== $row['type'] ) {
			return null; // un reembolso no es un pedido para mostrar.
		}
		if ( $row && ( OrderArc_Engine::storage()['hpos'] || ! self::legacy_row( $id ) ) ) {
			$o = self::from_hpos( $row, $full );
		} else {
			$o = self::from_legacy( $id, $full );
		}
		if ( ! $o ) {
			return null;
		}
		if ( $full ) {
			$o['items'] = self::items( $id );
			$o['notes'] = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT c.comment_ID id, c.comment_date_gmt date, c.comment_content content, c.comment_author author, MAX(IF(m.meta_key=%s,m.meta_value,NULL)) customer
					 FROM %i c LEFT JOIN %i m ON m.comment_id=c.comment_ID
					 WHERE c.comment_post_ID=%d GROUP BY c.comment_ID ORDER BY c.comment_date_gmt DESC, c.comment_ID DESC',
					'is_customer_note',
					self::a( 'comments' ),
					self::a( 'commentmeta' ),
					$id
				),
				ARRAY_A
			);
		}
		return $o;
	}

	private static function legacy_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE ID=%d AND post_type IN ('shop_order','shop_order_refund')", self::a( 'posts' ), $id ), ARRAY_A );
	}

	private static function from_hpos( $row, $full ) {
		global $wpdb;
		$addr = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', self::a( 'wc_order_addresses' ), $row['id'] ), ARRAY_A );
		$by   = array();
		foreach ( $addr as $a ) {
			$by[ $a['address_type'] ] = $a;
		}
		$b = $by['billing'] ?? array();
		$o = array(
			'id'       => (int) $row['id'],
			'date'     => $row['date_created_gmt'],
			'status'   => $row['status'],
			'currency' => $row['currency'],
			'total'    => $row['total_amount'],
			'email'    => $row['billing_email'] ? $row['billing_email'] : ( $b['email'] ?? '' ),
			'customer' => trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) ),
			'user'     => (int) $row['customer_id'],
		);
		if ( $full ) {
			$op            = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', self::a( 'wc_order_operational_data' ), $row['id'] ), ARRAY_A );
			$o['billing']  = self::addr( $b );
			$o['shipping'] = self::addr( $by['shipping'] ?? array() );
			$o['phone']    = $b['phone'] ?? '';
			$o['payment']  = $row['payment_method_title'];
			$o['note']     = $row['customer_note'];
			$o['tax']      = $row['tax_amount'];
			$o['ship']     = $op['shipping_total_amount'] ?? '';
			$o['discount'] = $op['discount_total_amount'] ?? '';
			$o['refunds']  = $wpdb->get_results(
				$wpdb->prepare( "SELECT r.id, r.date_created_gmt date, r.total_amount amount, (SELECT meta_value FROM %i WHERE order_id=r.id AND meta_key='_refund_reason' LIMIT 1) reason FROM %i r WHERE r.parent_order_id=%d AND r.type='shop_order_refund' ORDER BY r.id", self::a( 'wc_orders_meta' ), self::a( 'wc_orders' ), $row['id'] ),
				ARRAY_A
			);
		}
		return $o;
	}

	private static function meta( $id ) {
		global $wpdb;
		$m = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key k, meta_value v FROM %i WHERE post_id=%d', self::a( 'postmeta' ), $id ), ARRAY_A ) as $r ) {
			if ( ! isset( $m[ $r['k'] ] ) ) {
				$m[ $r['k'] ] = $r['v'];
			}
		}
		return $m;
	}

	private static function from_legacy( $id, $full ) {
		global $wpdb;
		$p = self::legacy_row( $id );
		if ( ! $p || 'shop_order' !== $p['post_type'] ) {
			return null;
		}
		$m = self::meta( $id );
		$o = array(
			'id'       => (int) $id,
			'date'     => $p['post_date_gmt'],
			'status'   => $p['post_status'],
			'currency' => $m['_order_currency'] ?? '',
			'total'    => $m['_order_total'] ?? '',
			'email'    => $m['_billing_email'] ?? '',
			'customer' => trim( ( $m['_billing_first_name'] ?? '' ) . ' ' . ( $m['_billing_last_name'] ?? '' ) ),
			'user'     => (int) ( $m['_customer_user'] ?? 0 ),
		);
		if ( $full ) {
			$pick = function ( $type ) use ( $m ) {
				$a = array();
				foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $f ) {
					$a[ $f ] = $m[ "_{$type}_{$f}" ] ?? '';
				}
				return $a;
			};
			$o['billing']  = self::addr( $pick( 'billing' ) );
			$o['shipping'] = self::addr( $pick( 'shipping' ) );
			$o['phone']    = $m['_billing_phone'] ?? '';
			$o['payment']  = $m['_payment_method_title'] ?? '';
			$o['note']     = $p['post_excerpt'];
			$o['tax']      = (float) ( $m['_order_tax'] ?? 0 ) + (float) ( $m['_order_shipping_tax'] ?? 0 );
			$o['ship']     = $m['_order_shipping'] ?? '';
			$o['discount'] = $m['_cart_discount'] ?? '';
			$o['refunds']  = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID id, post_date_gmt date FROM %i WHERE post_parent=%d AND post_type='shop_order_refund' ORDER BY ID", self::a( 'posts' ), $id ), ARRAY_A ) as $r ) {
				$rm             = self::meta( $r['id'] );
				$r['amount']    = $rm['_refund_amount'] ?? ( $rm['_order_total'] ?? '' );
				$r['reason']    = $rm['_refund_reason'] ?? '';
				$o['refunds'][] = $r;
			}
		}
		return $o;
	}

	private static function addr( $a ) {
		if ( ! array_filter( (array) $a ) ) {
			return '';
		}
		if ( function_exists( 'WC' ) && WC()->countries ) {
			return WC()->countries->get_formatted_address(
				array(
					'first_name' => $a['first_name'] ?? '',
					'last_name'  => $a['last_name'] ?? '',
					'company'    => $a['company'] ?? '',
					'address_1'  => $a['address_1'] ?? '',
					'address_2'  => $a['address_2'] ?? '',
					'city'       => $a['city'] ?? '',
					'state'      => $a['state'] ?? '',
					'postcode'   => $a['postcode'] ?? '',
					'country'    => $a['country'] ?? '',
				)
			);
		}
		return esc_html( implode( ', ', array_filter( (array) $a ) ) );
	}

	/** Líneas del pedido: productos, envío, cargos y cupones, con cantidad y total. */
	private static function items( $id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.order_item_id id, i.order_item_name name, i.order_item_type type,
				 MAX(IF(m.meta_key='_qty',m.meta_value,NULL)) qty,
				 MAX(IF(m.meta_key IN ('_line_total','cost','discount_amount'),m.meta_value,NULL)) total,
				 MAX(IF(m.meta_key='_line_subtotal',m.meta_value,NULL)) subtotal,
				 MAX(IF(m.meta_key IN ('_line_tax','total_tax','discount_amount_tax'),m.meta_value,NULL)) tax
				 FROM %i i LEFT JOIN %i m ON m.order_item_id=i.order_item_id
				 WHERE i.order_id=%d GROUP BY i.order_item_id ORDER BY FIELD(i.order_item_type,%s,%s,%s,%s), i.order_item_id",
				self::a( 'woocommerce_order_items' ),
				self::a( 'woocommerce_order_itemmeta' ),
				$id,
				'line_item',
				'fee',
				'shipping',
				'coupon'
			),
			ARRAY_A
		);
		$rows = $rows ? $rows : array();
		foreach ( $rows as $k => $r ) {
			// Como Woo: el producto a su precio sin descontar; el descuento va en su propia fila.
			$rows[ $k ]['shown'] = 'line_item' === $r['type'] && null !== $r['subtotal'] ? $r['subtotal'] : $r['total'];
		}
		return $rows;
	}

	/** Nombres de los métodos de envío del pedido (para la fila de envío). */
	public static function shipping_names( $o ) {
		$n = array();
		foreach ( $o['items'] as $it ) {
			if ( 'shipping' === $it['type'] && '' !== $it['name'] ) {
				$n[] = $it['name'];
			}
		}
		return implode( ', ', $n );
	}
}
