<?php
/**
 * Plugin Name: OrderArc – Order Archive for WooCommerce
 * Plugin URI: https://getorderarc.com
 * Description: Archive old WooCommerce orders without losing a single row: exact, verified and reversible. Keeps order search fast on large stores.
 * Version: 0.1.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Nicolás Alejandro Salinas
 * License: GPLv2 or later
 * Text Domain: orderarc
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 11.2
 *
 * @package OrderArc
 */

defined( 'ABSPATH' ) || exit;

// Si OrderArc Pro ya está cargado (trae este mismo código), no se carga dos veces.
if ( defined( 'ORDERARC_PRO' ) ) {
	return;
}

define( 'ORDERARC_VERSION', '0.1.2' );
define( 'ORDERARC_DIR', __DIR__ );
define( 'ORDERARC_FILE', __FILE__ );

require_once ORDERARC_DIR . '/includes/class-orderarc-engine.php';
require_once ORDERARC_DIR . '/includes/class-orderarc-runner.php';
require_once ORDERARC_DIR . '/includes/class-orderarc-archived.php';
require_once ORDERARC_DIR . '/includes/class-orderarc-account.php';
require_once ORDERARC_DIR . '/includes/class-orderarc-privacy.php';

OrderArc_Runner::init();
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			OrderArc_Privacy::init();
		}
	}
);
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) && ! is_admin() ) {
			OrderArc_Account::init();
		}
	}
);

if ( is_admin() ) {
	require_once ORDERARC_DIR . '/includes/class-orderarc-admin.php';
	add_action(
		'plugins_loaded',
		function () {
			if ( class_exists( 'WooCommerce' ) ) {
				OrderArc_Admin::init();
			}
		}
	);
}

register_activation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			OrderArc_Engine::ensure_tables();
		}
		OrderArc_Runner::schedule_daily();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( OrderArc_Runner::HOOK, array(), OrderArc_Runner::GROUP );
			as_unschedule_all_actions( OrderArc_Runner::DAILY_HOOK, array(), OrderArc_Runner::GROUP );
		}
		delete_option( OrderArc_Runner::LOCK );
		// Un trabajo a medias queda en pausa (al reactivar se reanuda con "Reanudar", sin quedar trabado).
		$job = OrderArc_Runner::job();
		if ( in_array( $job['status'] ?? '', array( 'running', 'waiting' ), true ) ) {
			$job['status'] = 'paused';
			update_option( OrderArc_Runner::JOB, $job, false );
		}
	}
);

// Enlace "Pro version" en la fila del plugin (no aparece si la versión Pro está instalada en su lugar).
add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file && ! defined( 'ORDERARC_PRO' ) ) {
			$links[] = '<a href="https://getorderarc.com/#pro" target="_blank" rel="noopener">' . esc_html__( 'Pro version', 'orderarc' ) . '</a>';
		}
		return $links;
	},
	10,
	2
);

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
