<?php
/**
 * Mi cuenta: el cliente ve sus pedidos archivados debajo de su lista de pedidos ("Older orders")
 * y puede abrir cada uno en solo lectura. Solo los pedidos de su propia cuenta; solo las notas que
 * la tienda le mandó a él (nunca las internas).
 *
 * @package OrderArc
 */

defined( 'ABSPATH' ) || exit;

class OrderArc_Account {

	const VIEW = 'orderarc_view';
	const PAGE = 'orderarc_page';

	public static function init() {
		add_action( 'woocommerce_after_account_orders', array( __CLASS__, 'list_orders' ), 20 );
		add_action( 'woocommerce_account_orders_endpoint', array( __CLASS__, 'maybe_view' ), 1 );
	}

	private static function status_label( $status ) {
		$all = wc_get_order_statuses();
		$key = 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
		return $all[ $key ] ?? $status;
	}

	private static function date( $gmt ) {
		return $gmt ? wp_date( get_option( 'date_format' ), strtotime( $gmt . ' UTC' ) ) : ''; // en la zona horaria del sitio.
	}

	private static function view_url( $id ) {
		return add_query_arg( self::VIEW, (int) $id, wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) );
	}

	public static function list_orders() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paginación.
		$page = max( 1, absint( $_GET[ self::PAGE ] ?? 1 ) );
		$per  = 20;
		$res  = OrderArc_Archived::for_customer( $uid, $page, $per );
		if ( ! $res['total'] ) {
			return;
		}
		echo '<h2 class="orderarc-older">' . esc_html__( 'Older orders', 'orderarc' ) . '</h2>';
		echo '<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table orderarc-older-table"><thead><tr>';
		foreach ( array( __( 'Order', 'orderarc' ), __( 'Date', 'orderarc' ), __( 'Status', 'orderarc' ), __( 'Total', 'orderarc' ), '' ) as $c ) {
			echo '<th scope="col"><span class="nobr">' . esc_html( $c ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( OrderArc_Archived::summaries( $res['ids'] ) as $id => $o ) {
			$url = self::view_url( $id );
			echo '<tr>';
			echo '<th scope="row" data-title="' . esc_attr__( 'Order', 'orderarc' ) . '"><a href="' . esc_url( $url ) . '">#' . esc_html( $id ) . '</a></th>';
			echo '<td data-title="' . esc_attr__( 'Date', 'orderarc' ) . '">' . esc_html( self::date( $o['date'] ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Status', 'orderarc' ) . '">' . esc_html( self::status_label( $o['status'] ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Total', 'orderarc' ) . '">' . wp_kses_post( wc_price( (float) $o['total'], array( 'currency' => $o['currency'] ) ) ) . '</td>';
			echo '<td><a class="woocommerce-button button view" href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'orderarc' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) {
			echo '<nav class="woocommerce-pagination">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( self::PAGE, '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</nav>';
		}
	}

	/** /mi-cuenta/orders/?orderarc_view=ID → el pedido archivado en solo lectura, si es del cliente. */
	public static function maybe_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura, se valida el dueño.
		$id = absint( $_GET[ self::VIEW ] ?? 0 );
		if ( ! $id ) {
			return;
		}
		$o = OrderArc_Archived::get( $id );
		if ( ! $o || ! get_current_user_id() || (int) $o['user'] !== get_current_user_id() ) {
			return; // no es suyo: se ve la lista normal, sin decir nada.
		}
		remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders' );
		$cur = $o['currency'];
		$m   = function ( $v ) use ( $cur ) {
			return wc_price( (float) $v, array( 'currency' => $cur ) );
		};
		echo '<p><a href="' . esc_url( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) ) . '">&larr; ' . esc_html__( 'Older orders', 'orderarc' ) . '</a></p>';
		echo '<h2>#' . esc_html( $id ) . '</h2><p>' . esc_html( self::date( $o['date'] ) ) . ' · ' . esc_html( self::status_label( $o['status'] ) ) . '</p>';
		echo '<table class="woocommerce-table woocommerce-table--order-details shop_table order_details"><thead><tr><th>' . esc_html__( 'Item', 'orderarc' ) . '</th><th>' . esc_html__( 'Total', 'orderarc' ) . '</th></tr></thead><tbody>';
		foreach ( $o['items'] as $it ) {
			if ( ! in_array( $it['type'], array( 'line_item', 'fee' ), true ) ) {
				continue; // envío y cupones van en las filas de totales.
			}
			$qty = 'line_item' === $it['type'] && $it['qty'] ? ' <strong class="product-quantity">&times;&nbsp;' . esc_html( $it['qty'] ) . '</strong>' : '';
			echo '<tr><td>' . esc_html( $it['name'] ) . wp_kses_post( $qty ) . '</td><td>' . wp_kses_post( $m( (float) $it['shown'] ) ) . '</td></tr>';
		}
		$ship_names = OrderArc_Archived::shipping_names( $o );
		echo '</tbody><tfoot>';
		$rows = array(
			array( __( 'Discount', 'orderarc' ), (float) $o['discount'] ? -1 * (float) $o['discount'] : null ),
			array( __( 'Shipping', 'orderarc' ) . ( $ship_names ? ' · ' . $ship_names : '' ), (float) $o['ship'] ? (float) $o['ship'] : null ),
			array( __( 'Tax', 'orderarc' ), (float) $o['tax'] ? (float) $o['tax'] : null ),
			array( __( 'Total', 'orderarc' ), (float) $o['total'] ),
		);
		foreach ( $o['refunds'] as $r ) {
			$rows[] = array( __( 'Refunded', 'orderarc' ), -1 * abs( (float) $r['amount'] ) );
		}
		foreach ( $rows as $r ) {
			if ( null !== $r[1] ) {
				echo '<tr><th scope="row">' . esc_html( $r[0] ) . '</th><td>' . wp_kses_post( $m( $r[1] ) ) . '</td></tr>';
			}
		}
		echo '</tfoot></table>';
		echo '<section class="woocommerce-customer-details"><div class="woocommerce-columns woocommerce-columns--2 col2-set addresses">';
		echo '<div class="woocommerce-column col-1"><h2>' . esc_html__( 'Billing', 'orderarc' ) . '</h2><address>' . wp_kses_post( $o['billing'] ) . '</address></div>';
		if ( $o['shipping'] ) {
			echo '<div class="woocommerce-column col-2"><h2>' . esc_html__( 'Shipping', 'orderarc' ) . '</h2><address>' . wp_kses_post( $o['shipping'] ) . '</address></div>';
		}
		echo '</div></section>';
		$notes = array_filter( $o['notes'], fn( $n ) => ! empty( $n['customer'] ) );
		if ( $notes ) {
			echo '<h2>' . esc_html__( 'Notes', 'orderarc' ) . '</h2><ol class="woocommerce-OrderUpdates commentlist notes">';
			foreach ( $notes as $n ) {
				echo '<li class="woocommerce-OrderUpdate comment note"><p class="woocommerce-OrderUpdate-meta meta">' . esc_html( self::date( $n['date'] ) ) . '</p><div class="woocommerce-OrderUpdate-description description">' . wp_kses_post( wpautop( $n['content'] ) ) . '</div></li>';
			}
			echo '</ol>';
		}
	}
}
