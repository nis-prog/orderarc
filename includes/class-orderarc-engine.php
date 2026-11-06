<?php
/**
 * Motor de archivado: mueve filas entre las tablas vivas y sus espejos {prefix}orderarc_*,
 * verifica (conteo + checksum de todas las columnas) antes de borrar el origen, todo en una transacción.
 * No usa la API de pedidos de WooCommerce: no dispara mails, stock ni hooks de borrado.
 *
 * Reglas de seguridad (antes de mover nada, en preflight()):
 * - todas las tablas que intervienen deben ser InnoDB (si no, ROLLBACK no deshace);
 * - ninguna puede tener triggers ni claves foráneas (borrados en cascada que no se copiaron);
 * - el archivo tiene las mismas columnas que la tabla viva (se agregan las que falten);
 * - en la transacción se controla que la conexión no cambió (una reconexión pasaría a autocommit).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- el plugin trabaja a nivel de filas sobre tablas propias y de Woo; no hay API ni caché aplicable.
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- todo identificador va con %i y todo valor con %s/%d/%f; en el SQL solo se interpolan listas de marcadores ($*_placeholders, un marcador por valor).
 *
 * @package OrderArc
 */

defined( 'ABSPATH' ) || exit;

class OrderArc_Engine {

	const PREFIX      = 'orderarc_';
	const PLACEHOLDER = 'shop_order_placehold';

	/** Tablas de WooCommerce que se quedan vivas a propósito (informes, descargas). */
	public static function keep_live() {
		return array(
			'wc_order_stats'                               => 'reports',
			'wc_order_product_lookup'                      => 'reports',
			'wc_order_coupon_lookup'                       => 'reports',
			'wc_order_tax_lookup'                          => 'reports',
			'wc_customer_lookup'                           => 'reports',
			'woocommerce_downloadable_product_permissions' => 'downloads',
			'wc_download_log'                              => 'downloads',
		);
	}

	/* ---------------------------------------------------------------- Utilidades */

	/** Nombre de tabla o columna seguro (solo letras, números y guion bajo). */
	public static function valid_ident( $s ) {
		return is_string( $s ) && '' !== $s && (bool) preg_match( '/^[A-Za-z0-9_]+$/', $s );
	}

	public static function live( $short ) {
		global $wpdb;
		return $wpdb->prefix . $short;
	}

	/* ------------------------------------------------ Archivo en otra base de datos del mismo servidor
	 * Si la opción orderarc_archive_db nombra otra base (la configura la versión Pro), las tablas del
	 * archivo viven ahí. Para no tocar cada consulta, arch() devuelve el nombre con un marcador
	 * (XS . tabla) y el filtro `query` de wpdb cambia `XS tabla` por `base`.`tabla` justo antes de
	 * mandar el SQL. Las búsquedas en information_schema separan base y tabla con split().
	 * El gratis siempre lee esta opción: si alguna vez se usó la Pro, el archivo sigue visible.
	 */
	const XS = "\x01orderarc\x01"; // con caracteres de control: no aparece en datos reales.

	/** Base de datos del archivo, o '' si es la misma del sitio. */
	public static function arch_schema() {
		static $schema = null;
		if ( null === $schema ) {
			$schema = ''; // primero vacío: get_option() pasa por el filtro `query` (sin esto, recursión).
			$s      = (string) get_option( 'orderarc_archive_db', '' );
			$schema = ( self::valid_ident( $s ) && DB_NAME !== $s ) ? $s : '';
		}
		return $schema;
	}

	/** Identidad de esta instalación (base + prefijo): un staging clonado tiene otra base y no coincide. */
	public static function site_identity() {
		global $wpdb;
		return md5( DB_NAME . '|' . $wpdb->prefix );
	}

	/**
	 * Si el archivo está en otra base, esa base tiene que pertenecer a esta instalación (tabla
	 * {prefix}orderarc_owner con la identidad). Si no, no se lee ni se cambia nada: un staging clonado
	 * en el mismo servidor copia la opción y, sin esto, trabajaría sobre el archivo de producción.
	 * Devuelve '' si se puede usar o el motivo.
	 */
	public static function archive_blocked() {
		static $reason = null;
		$sch = self::arch_schema();
		if ( '' === $sch ) {
			return '';
		}
		if ( null === $reason ) {
			global $wpdb;
			$reason = '';
			$owner  = $wpdb->prefix . self::PREFIX . 'owner';
			$seen   = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=%s', $sch ) );
			$id     = $seen && $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $sch, $owner ) )
				? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT site FROM %i.%i LIMIT 1', $sch, $owner ) )
				: '';
			if ( ! $seen ) {
				/* translators: %s: database name */
				$reason = sprintf( __( 'The archive database %s cannot be reached from this site.', 'orderarc' ), $sch );
			} elseif ( self::site_identity() !== $id ) {
				/* translators: %s: database name */
				$reason = sprintf( __( 'The archive database %s belongs to another copy of this site. OrderArc does not read or change it from here.', 'orderarc' ), $sch );
			}
		}
		return $reason;
	}

	/** array( base o '' para la del sitio, nombre de tabla sin marcador ). */
	public static function split( $name ) {
		if ( 0 === strpos( $name, self::XS ) ) {
			return array( self::arch_schema(), substr( $name, strlen( self::XS ) ) );
		}
		return array( '', $name );
	}

	/** Nombre para mostrar en mensajes (base.tabla). */
	public static function display( $name ) {
		list( $sch, $t ) = self::split( $name );
		return $sch ? $sch . '.' . $t : $t;
	}

	/** Filtro `query`: `XS tabla` → `base`.`tabla`. */
	public static function rewrite( $sql ) {
		if ( false === strpos( $sql, self::XS ) ) {
			return $sql;
		}
		$sch = self::arch_schema();
		return '' === $sch ? $sql : str_replace( '`' . self::XS, '`' . $sch . '`.`', $sql );
	}

	public static function arch( $short ) {
		global $wpdb;
		$name = $wpdb->prefix . self::PREFIX . $short;
		if ( strlen( $name ) > 64 ) { // límite de MySQL: tablas de otros plugins con nombres largos.
			$name = substr( $name, 0, 55 ) . '_' . substr( md5( $short ), 0, 8 );
		}
		return self::arch_schema() ? self::XS . $name : $name;
	}

	public static function keep_table() {
		global $wpdb;
		$name = $wpdb->prefix . self::PREFIX . 'keep';
		return self::arch_schema() ? self::XS . $name : $name;
	}

	public static function table_exists( $name, $refresh = false ) {
		static $cache = array();
		if ( $refresh || ! isset( $cache[ $name ] ) ) {
			global $wpdb;
			list( $sch, $t ) = self::split( $name );
			$cache[ $name ] = $sch
				? (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $sch, $t ) )
				: (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
		}
		return $cache[ $name ];
	}

	/** ¿Existen las tablas del archivo? (las lecturas no crean tablas: si no hay archivo, no hay nada que mostrar). */
	public static function archive_ready() {
		if ( '' !== self::archive_blocked() ) {
			return false;
		}
		return self::table_exists( self::arch( 'posts' ) ) && self::table_exists( self::keep_table() );
	}

	/**
	 * Dónde guarda Woo los pedidos en este sitio.
	 * hpos: la tabla wc_orders manda. sync: Woo mantiene además la copia en wp_posts. tables: existen las tablas HPOS.
	 */
	public static function storage() {
		static $st = null;
		if ( null === $st ) {
			$tables = self::table_exists( self::live( 'wc_orders' ) );
			$st     = array(
				'tables' => $tables,
				'hpos'   => $tables && 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ),
				'sync'   => $tables && 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ),
			);
		}
		return $st;
	}

	/** Tipos de pedido que cuentan como hijos que impiden archivar (suscripciones, etc.): todos menos reembolso. */
	private static function blocking_child_types() {
		$t = function_exists( 'wc_get_order_types' ) ? wc_get_order_types( 'cot-migration' ) : array( 'shop_order', 'shop_order_refund' );
		$t = array_values( array_diff( $t, array( 'shop_order_refund', self::PLACEHOLDER ) ) );
		return $t ? $t : array( 'shop_subscription' );
	}

	/** Columnas copiables (sin las generadas) de una tabla. */
	private static function columns( $table, $refresh = false ) {
		static $cache = array();
		if ( $refresh || ! isset( $cache[ $table ] ) ) {
			global $wpdb;
			$cols = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ) as $c ) {
				if ( false === stripos( (string) $c['Extra'], 'GENERATED' ) ) {
					$cols[] = $c['Field'];
				}
			}
			$cache[ $table ] = $cols;
		}
		return $cache[ $table ];
	}

	/** Clave primaria de una sola columna, o '' si no tiene (o es compuesta). */
	private static function primary_key( $table ) {
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			global $wpdb;
			$k               = $wpdb->get_col( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name='PRIMARY'", $table ), 4 );
			$cache[ $table ] = 1 === count( $k ) ? (string) $k[0] : '';
		}
		return $cache[ $table ];
	}

	/* ---------------------------------------------------------------- Relaciones */

	/**
	 * Relaciones: tabla (sin prefijo) => [columna clave, conjunto]. Conjuntos: order | post | item | comment.
	 * Orden = orden de inserción (padres primero). El borrado va al revés.
	 */
	public static function relations() {
		$rel = array();
		if ( self::storage()['tables'] ) {
			$rel['wc_orders']                 = array( 'id', 'order' );
			$rel['wc_order_addresses']        = array( 'order_id', 'order' );
			$rel['wc_order_operational_data'] = array( 'order_id', 'order' );
			$rel['wc_orders_meta']            = array( 'order_id', 'order' );
		}
		// wp_posts / postmeta: solo las filas que son el pedido de verdad (legacy o copia sincronizada). En su lugar
		// queda un registro mínimo (shop_order_placehold) que reserva el número; Woo no lo cuenta como pedido.
		$rel += array(
			'posts'                      => array( 'ID', 'post' ),
			'postmeta'                   => array( 'post_id', 'post' ),
			'woocommerce_order_items'    => array( 'order_id', 'order' ),
			'woocommerce_order_itemmeta' => array( 'order_item_id', 'item' ),
			'comments'                   => array( 'comment_ID', 'comment' ),
			'commentmeta'                => array( 'comment_id', 'comment' ),
		);
		foreach ( self::third_party() as $t => $col ) {
			if ( self::table_exists( self::live( $t ) ) ) { // si el otro plugin se desinstaló, su tabla ya no está.
				$rel[ $t ] = array( $col, 'order' );
			}
		}
		return $rel;
	}

	private static function relations_core() {
		return array( 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta', 'posts', 'postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'comments', 'commentmeta' );
	}

	/** Tablas de otros plugins detectadas (tabla sin prefijo => columna). Solo identificadores válidos. */
	public static function third_party() {
		$t   = get_option( 'orderarc_third_party', array() );
		$out = array();
		foreach ( is_array( $t ) ? $t : array() as $table => $col ) {
			if ( self::valid_ident( $table ) && self::valid_ident( $col ) ) {
				$out[ $table ] = $col;
			}
		}
		return $out;
	}

	/**
	 * Detecta tablas de otros plugins con una columna que apunte a pedidos.
	 * Conservador a propósito: lo que no se detecta queda vivo (no se pierde nada, solo no se archiva).
	 * Se descartan: tablas de otros sitios de un multisitio u otras instalaciones en la misma base, tablas de
	 * WordPress/WooCommerce, columnas sin índice, tablas con triggers o claves foráneas.
	 */
	public static function detect_third_party() {
		global $wpdb;
		$p     = $wpdb->prefix;
		$found = array();
		$cols  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.TABLE_NAME t, c.COLUMN_NAME c FROM information_schema.COLUMNS c
				 JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA=c.TABLE_SCHEMA AND tb.TABLE_NAME=c.TABLE_NAME AND tb.TABLE_TYPE='BASE TABLE'
				 WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME LIKE %s
				 AND c.COLUMN_NAME IN ('order_id','wc_order_id','woocommerce_order_id','post_id')",
				$wpdb->esc_like( $p ) . '%'
			),
			ARRAY_A
		);
		if ( ! $cols ) {
			return $found;
		}
		// Prefijos de otros sitios/instalaciones que empiezan como el nuestro (wp_2_, wp_tienda2_…): cada uno tiene su tabla options.
		$others = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p ) . '%' . $wpdb->esc_like( 'options' ) ) ) as $opt ) {
			$pre = substr( $opt, 0, -strlen( 'options' ) );
			if ( $pre !== $p && 0 === strpos( $pre, $p ) ) {
				$others[] = $pre;
			}
		}
		$sample = self::sample_orders();
		if ( ! $sample ) {
			return $found;
		}
		$known = array_merge( self::relations_core(), array_keys( self::keep_live() ) );
		foreach ( $cols as $r ) {
			$short = substr( $r['t'], strlen( $p ) );
			if ( ! self::valid_ident( $short ) || ! self::valid_ident( $r['c'] ) || in_array( $short, $known, true ) ) {
				continue;
			}
			if ( 0 === strpos( $short, self::PREFIX ) || 0 === strpos( $short, 'actionscheduler' ) || preg_match( '/^\d+_/', $short )
				|| 0 === strpos( $short, 'wc_' ) || 0 === strpos( $short, 'woocommerce_' ) ) {
				continue;
			}
			foreach ( $others as $o ) {
				if ( 0 === strpos( $r['t'], $o ) ) {
					continue 2;
				}
			}
			// Sin clave primaria de una sola columna no se puede borrar "solo lo que ya está copiado": queda fuera.
			if ( ! self::indexed( $r['t'], $r['c'] ) || '' === self::primary_key( $r['t'] ) || self::has_triggers_or_fk( array( $r['t'] ) ) ) {
				continue;
			}
			if ( ! self::points_to_orders( $r['t'], $r['c'] ) ) {
				continue;
			}
			$sample_placeholders = implode( ',', array_fill( 0, count( $sample ), '%d' ) );
			$n                   = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sample_placeholders: un %d por valor.
					"SELECT COUNT(*) FROM %i WHERE %i IN ($sample_placeholders)",
					array_merge( array( $r['t'], $r['c'] ), $sample )
				)
			);
			if ( $n > 0 ) {
				$found[ $short ] = array( 'col' => $r['c'], 'matches' => $n );
			}
		}
		return $found;
	}

	/**
	 * Prueba inversa: los valores de la columna tienen que ser números de pedido de WooCommerce. Otro plugin de
	 * tienda numera sus propios pedidos 1, 2, 3… en una columna order_id: esos números coinciden con pedidos de
	 * Woo por casualidad, pero no son pedidos de Woo. Se toman hasta 200 valores distintos (los más altos) y
	 * se exige que al menos el 95 % sea un pedido o reembolso de WooCommerce, vivo o ya archivado.
	 */
	private static function points_to_orders( $table, $col ) {
		global $wpdb;
		$vals = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT %i FROM %i WHERE %i > 0 ORDER BY %i DESC LIMIT 200', $col, $table, $col, $col ) ) );
		if ( ! $vals ) {
			return false;
		}
		$value_placeholders = implode( ',', array_fill( 0, count( $vals ), '%d' ) );
		$known              = array();
		$hp    = array( self::live( 'wc_orders' ), self::arch( 'wc_orders' ) );
		$pp    = array( self::live( 'posts' ), self::arch( 'posts' ) );
		foreach ( $hp as $t ) {
			if ( self::table_exists( $t ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
				$known = array_merge( $known, $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type IN ('shop_order','shop_order_refund') AND id IN ($value_placeholders)", array_merge( array( $t ), $vals ) ) ) );
			}
		}
		foreach ( $pp as $t ) {
			if ( self::table_exists( $t ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
				$known = array_merge( $known, $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type IN ('shop_order','shop_order_refund') AND ID IN ($value_placeholders)", array_merge( array( $t ), $vals ) ) ) );
			}
		}
		$hits = count( array_intersect( $vals, array_unique( array_map( 'intval', $known ) ) ) );
		return $hits >= 0.95 * count( $vals );
	}

	/** Hasta 200 pedidos seguidos a partir de un punto al azar (por clave primaria, sin ORDER BY RAND()). */
	private static function sample_orders() {
		global $wpdb;
		if ( self::storage()['hpos'] ) {
			$t   = self::live( 'wc_orders' );
			$mm  = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(id) a, MAX(id) b FROM %i WHERE type='shop_order'", $t ), ARRAY_A );
			$from = $mm && $mm['b'] ? wp_rand( (int) $mm['a'], (int) $mm['b'] ) : 0;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type='shop_order' AND id >= %d ORDER BY id LIMIT 200", $t, $from ) );
			if ( count( $ids ) < 50 ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE type='shop_order' ORDER BY id DESC LIMIT 200", $t ) );
			}
		} else {
			$t   = self::live( 'posts' );
			$mm  = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(ID) a, MAX(ID) b FROM %i WHERE post_type='shop_order'", $t ), ARRAY_A );
			$from = $mm && $mm['b'] ? wp_rand( (int) $mm['a'], (int) $mm['b'] ) : 0;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type='shop_order' AND ID >= %d ORDER BY ID LIMIT 200", $t, $from ) );
			if ( count( $ids ) < 50 ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type='shop_order' ORDER BY ID DESC LIMIT 200", $t ) );
			}
		}
		return array_map( 'intval', $ids );
	}

	/** ¿La columna es la primera de algún índice? (si no, cada tanda recorrería la tabla entera). */
	private static function indexed( $table, $col ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s AND SEQ_IN_INDEX=1 LIMIT 1',
				$table,
				$col
			)
		);
	}

	/** Primera tabla de la lista que tenga triggers o claves foráneas (entrantes o salientes), o ''. */
	private static function has_triggers_or_fk( $tables ) {
		// Tablas del archivo en otra base: se revisan aparte, con su propio esquema.
		$other = array();
		$here  = array();
		foreach ( $tables as $tb ) {
			list( $sch, $t ) = self::split( $tb );
			if ( $sch ) {
				$other[] = $t;
			} else {
				$here[] = $t;
			}
		}
		$found = self::triggers_or_fk_in( $here, '' );
		if ( '' === $found && $other ) {
			$found = self::triggers_or_fk_in( $other, self::arch_schema() );
			$found = $found ? self::arch_schema() . '.' . $found : '';
		}
		return $found;
	}

	private static function triggers_or_fk_in( $tables, $schema ) {
		global $wpdb;
		if ( ! $tables ) {
			return '';
		}
		$table_placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		if ( '' !== $schema ) {
			$t = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
				$wpdb->prepare( "SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s AND EVENT_OBJECT_TABLE IN ($table_placeholders) LIMIT 1", array_merge( array( $schema ), $tables ) )
			);
			if ( ! $t ) {
				$t = $wpdb->get_var(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
					$wpdb->prepare( "SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=%s AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN ($table_placeholders) OR REFERENCED_TABLE_NAME IN ($table_placeholders)) LIMIT 1", array_merge( array( $schema ), $tables, $tables ) )
				);
			}
			return $t ? (string) $t : '';
		}
		$t  = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
			$wpdb->prepare( "SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ($table_placeholders) LIMIT 1", $tables )
		);
		if ( $t ) {
			return (string) $t;
		}
		$t = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
				"SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN ($table_placeholders) OR REFERENCED_TABLE_NAME IN ($table_placeholders)) LIMIT 1",
				array_merge( $tables, $tables )
			)
		);
		return $t ? (string) $t : '';
	}

	/** Detecta y guarda las tablas de otros plugins. Nunca olvida una que ya tiene filas archivadas. */
	public static function refresh_third_party() {
		$map = array();
		foreach ( self::detect_third_party() as $t => $d ) {
			$map[ $t ] = $d['col'];
		}
		foreach ( self::third_party() as $t => $col ) {
			if ( ! isset( $map[ $t ] ) && self::table_exists( self::arch( $t ) ) ) {
				$map[ $t ] = $col;
			}
		}
		update_option( 'orderarc_third_party', $map, false );
		update_option( 'orderarc_third_party_checked', time(), false );
		return $map;
	}

	/* ---------------------------------------------------------------- Estructura y chequeos previos */

	/** Crea las tablas espejo (misma estructura e índices, siempre InnoDB). Solo desde activación y preflight(). */
	public static function ensure_tables() {
		global $wpdb;
		$keep = self::keep_table();
		if ( ! self::table_exists( $keep ) ) {
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, kept_at INT UNSIGNED NOT NULL) ENGINE=InnoDB ', $keep ) . $wpdb->get_charset_collate() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- tabla propia; collate de WP.
			self::table_exists( $keep, true );
		}
		foreach ( self::relations() as $short => $_ ) {
			$a = self::arch( $short );
			if ( ! self::table_exists( $a ) ) {
				$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $a, self::live( $short ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- tabla espejo propia.
				if ( 'InnoDB' !== self::engine( $a ) ) {
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $a ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- tabla propia recién creada y vacía.
				}
				self::table_exists( $a, true );
			}
		}
	}

	private static function engine( $table ) {
		global $wpdb;
		list( $sch, $t ) = self::split( $table );
		return $sch
			? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $sch, $t ) )
			: (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $t ) );
	}

	/**
	 * Chequeos antes de mover. Devuelve '' si se puede trabajar o el motivo (traducible) si no.
	 * Crea tablas que falten, agrega al archivo columnas nuevas de la tabla viva y protege los contadores AUTO_INCREMENT.
	 */
	/**
	 * La base del sitio es MySQL o MariaDB (conexión mysqli de wpdb). Con otra base, por ejemplo la de
	 * SQLite que usa WordPress Playground, no hay transacciones, candados ni information_schema.
	 */
	public static function db_supported() {
		global $wpdb;
		return isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli;
	}

	public static function preflight() {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		if ( ! self::db_supported() ) {
			$done = __( 'OrderArc needs a MySQL or MariaDB database. This site uses a different database, so archiving is turned off.', 'orderarc' );
			return $done;
		}
		if ( '' !== self::archive_blocked() ) {
			$done = self::archive_blocked();
			return $done;
		}
		global $wpdb;
		self::ensure_tables();
		$tables = array( self::keep_table() );
		foreach ( self::relations() as $short => $_ ) {
			$tables[] = self::live( $short );
			$tables[] = self::arch( $short );
		}
		foreach ( $tables as $t ) {
			if ( 'InnoDB' !== self::engine( $t ) ) {
				/* translators: %s: database table */
				return ( $done = sprintf( __( '%s does not use InnoDB', 'orderarc' ), self::display( $t ) ) );
			}
		}
		$t = self::has_triggers_or_fk( $tables );
		if ( $t ) {
			/* translators: %s: database table */
			return ( $done = sprintf( __( '%s has triggers or foreign keys', 'orderarc' ), $t ) );
		}
		foreach ( self::relations() as $short => $def ) {
			$live = self::live( $short );
			$arch = self::arch( $short );
			// Columnas nuevas de la tabla viva (por una actualización de Woo o del otro plugin): se agregan al archivo.
			$missing = array_diff( self::columns( $live ), self::columns( $arch ) );
			if ( $missing ) {
				foreach ( $missing as $c ) {
					$d = $wpdb->get_row( $wpdb->prepare( 'SELECT COLUMN_TYPE ct, IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', $live, $c ), ARRAY_A );
					if ( ! $d || ! preg_match( '/^[a-z]+(\([0-9, ]+\))?( unsigned)?$/i', $d['ct'] ) && ! preg_match( '/^(enum|set)\(/i', $d['ct'] ) ) {
						/* translators: %s: database table */
						return ( $done = sprintf( __( 'table structure differs in %s', 'orderarc' ), $short ) );
					}
					// Siempre NULL en el archivo: las filas ya archivadas no tienen ese dato.
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i ', $arch, $c ) . $d['ct'] . ' NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- tipo leído de information_schema y validado arriba.
				}
				self::columns( $arch, true ); // la estructura cambió: releerla para las tandas de esta misma ejecución.
			}
			// Si el archivo tiene claves más altas que la tabla viva, MySQL sin contador persistente podría reutilizarlas.
			$pk = self::primary_key( $live );
			if ( $pk && self::primary_key( $arch ) === $pk ) {
				$am = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(%i) FROM %i', $pk, $arch ) );
				$lm = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(%i) FROM %i', $pk, $live ) );
				if ( $am > $lm && $am > 0 ) {
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = %d', $live, $am + 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- solo sube el contador.
				}
			}
		}
		return ( $done = '' );
	}

	/* ---------------------------------------------------------------- Pedidos que se pueden archivar */

	/**
	 * Reglas de archivado. El gratis tiene una sola (estados + antigüedad, de los ajustes); el filtro
	 * `orderarc_rules` permite reemplazarla por varias. Un pedido entra si cumple CUALQUIERA de las reglas.
	 * Cada regla: statuses (wc-*), days, y opcionales payment_methods, countries (ISO de facturación),
	 * min_total, max_total.
	 */
	public static function rules( $args ) {
		$rules = array(
			array(
				'statuses' => $args['statuses'] ?? array( 'wc-completed', 'wc-cancelled', 'wc-refunded' ),
				'days'     => $args['older_than_days'] ?? 365,
			),
		);
		$rules = apply_filters( 'orderarc_rules', $rules, $args );
		$out   = array();
		foreach ( (array) $rules as $r ) {
			$st = array_values( array_filter( array_map( 'sanitize_key', (array) ( $r['statuses'] ?? array() ) ) ) );
			if ( ! $st ) {
				continue;
			}
			$out[] = array(
				'statuses'        => $st,
				'days'            => max( 1, min( 36500, (int) ( $r['days'] ?? 365 ) ) ),
				'payment_methods' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $r['payment_methods'] ?? array() ) ) ) ),
				'countries'       => array_values( array_filter( array_map( 'strtoupper', array_map( 'sanitize_key', (array) ( $r['countries'] ?? array() ) ) ) ) ),
				'min_total'       => isset( $r['min_total'] ) && '' !== $r['min_total'] ? (float) $r['min_total'] : null,
				'max_total'       => isset( $r['max_total'] ) && '' !== $r['max_total'] ? (float) $r['max_total'] : null,
			);
		}
		return $out;
	}

	/**
	 * Valores de una regla, en el orden de los marcadores de las condiciones fijas que usan candidates() y
	 * preview(). Cada filtro opcional va con un interruptor (%d = 0 lo apaga) y, si está apagado, con un valor
	 * de relleno, así el SQL es siempre el mismo y solo cambia la cantidad de marcadores de cada lista.
	 * Devuelve array( estados, métodos de pago, países, valores ).
	 */
	private static function rule_values( $r, $hpos ) {
		$st     = $r['statuses'];
		$pm     = $r['payment_methods'] ? $r['payment_methods'] : array( '' );
		$co     = $r['countries'] ? $r['countries'] : array( '' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $r['days'] );
		$keys   = array( '_subscription_renewal', '_subscription_resubscribe', '_subscription_switch' );
		$min    = null !== $r['min_total'] ? (float) $r['min_total'] : 0.0;
		$max    = null !== $r['max_total'] ? (float) $r['max_total'] : 0.0;
		$use_pm = $r['payment_methods'] ? 1 : 0;
		$use_mn = null !== $r['min_total'] ? 1 : 0;
		$use_mx = null !== $r['max_total'] ? 1 : 0;
		$use_co = $r['countries'] ? 1 : 0;
		if ( $hpos ) {
			$v = array_merge(
				$st,
				array( $cutoff, $use_pm ),
				$pm,
				array( $use_mn, $min, $use_mx, $max, $use_co, self::live( 'wc_order_addresses' ) ),
				$co,
				array( self::live( 'wc_orders_meta' ) ),
				$keys
			);
		} else {
			$m = self::live( 'postmeta' );
			$v = array_merge(
				$st,
				array( $cutoff, $use_pm, $m ),
				$pm,
				array( $use_mn, $m, $min, $use_mx, $m, $max, $use_co, $m ),
				$co,
				array( $m ),
				$keys
			);
		}
		return array( $st, $pm, $co, $v );
	}

	/**
	 * Tabla de pedidos restaurados a mano (no se vuelven a archivar) e interruptor. Si la tabla todavía no
	 * existe, nadie fue restaurado a mano: se usa una tabla que siempre existe con el interruptor apagado.
	 */
	private static function keep_check() {
		return self::table_exists( self::keep_table() ) ? array( self::keep_table(), 1 ) : array( self::live( 'woocommerce_order_items' ), 0 );
	}

	/** IDs de pedidos padre de UNA regla, desde el cursor, en orden de ID. */
	private static function rule_candidates( $r, $hpos, $after, $limit ) {
		global $wpdb;
		list( $st, $pm, $co, $v ) = self::rule_values( $r, $hpos );
		list( $kt, $kf )          = self::keep_check();
		$status_placeholders  = implode( ',', array_fill( 0, count( $st ), '%s' ) );
		$payment_placeholders = implode( ',', array_fill( 0, count( $pm ), '%s' ) );
		$country_placeholders = implode( ',', array_fill( 0, count( $co ), '%s' ) );
		if ( $hpos ) {
			$o = self::live( 'wc_orders' );
			return $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
					"SELECT o.id FROM %i o WHERE o.type='shop_order' AND o.parent_order_id=0 AND o.status IN ($status_placeholders) AND o.date_created_gmt < %s AND ( %d = 0 OR o.payment_method IN ($payment_placeholders) ) AND ( %d = 0 OR o.total_amount >= %f ) AND ( %d = 0 OR o.total_amount <= %f ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ra WHERE ra.order_id=o.id AND ra.address_type='billing' AND ra.country IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.order_id=o.id AND sm.meta_key IN (%s,%s,%s)) AND o.id > %d AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.parent_order_id=o.id AND c.type<>'shop_order_refund') AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=o.id AND %d = 1) ORDER BY o.id LIMIT %d",
					array_merge( array( $o ), $v, array( $after, $o, $kt, $kf, $limit ) )
				)
			);
		}
		$p                  = self::live( 'posts' );
		$kids               = self::blocking_child_types();
		$child_placeholders = implode( ',', array_fill( 0, count( $kids ), '%s' ) );
		return $wpdb->get_col(
			$wpdb->prepare(
				// Recorre por clave primaria desde el cursor: sin FORCE INDEX MySQL elige el índice tipo/estado y ordena todo.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
				"SELECT o.ID FROM %i o FORCE INDEX (PRIMARY) WHERE o.post_type='shop_order' AND o.post_parent=0 AND o.post_status IN ($status_placeholders) AND o.post_date_gmt < %s AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rm WHERE rm.post_id=o.ID AND rm.meta_key='_payment_method' AND rm.meta_value IN ($payment_placeholders)) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rt WHERE rt.post_id=o.ID AND rt.meta_key='_order_total' AND CAST(rt.meta_value AS DECIMAL(26,8)) >= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ru WHERE ru.post_id=o.ID AND ru.meta_key='_order_total' AND CAST(ru.meta_value AS DECIMAL(26,8)) <= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc WHERE rc.post_id=o.ID AND rc.meta_key='_billing_country' AND rc.meta_value IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.post_id=o.ID AND sm.meta_key IN (%s,%s,%s)) AND o.post_date_gmt > '1970-01-02' AND o.ID > %d AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.post_parent=o.ID AND c.post_type IN ($child_placeholders)) AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=o.ID AND %d = 1) ORDER BY o.ID LIMIT %d",
				array_merge( array( $p ), $v, array( $after, $p ), $kids, array( $kt, $kf, $limit ) )
			)
		);
	}

	/** Pedidos candidatos (solo padres): unión de las reglas, desde el cursor, en orden de ID. */
	public static function candidates( $args ) {
		$rules = self::rules( $args );
		if ( ! $rules ) {
			return array();
		}
		$after = (int) ( $args['after_id'] ?? 0 );
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$hpos  = self::storage()['hpos'];
		$ids   = array();
		foreach ( $rules as $r ) {
			// Cada regla da sus $limit primeros desde el cursor: los $limit primeros de la unión están entre ellos.
			$ids = array_merge( $ids, array_map( 'intval', self::rule_candidates( $r, $hpos, $after, $limit ) ) );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return array_slice( $ids, 0, $limit );
	}

	/** Arma los conjuntos de IDs (pedido + reembolsos, ítems, notas) leyendo de las tablas $side ('live'|'arch'). */
	public static function collect( $parent_ids, $side ) {
		global $wpdb;
		$t   = function ( $s ) use ( $side ) {
			return 'arch' === $side ? self::arch( $s ) : self::live( $s );
		};
		$pid = array_values( array_map( 'intval', $parent_ids ) );
		if ( ! $pid ) {
			return array( 'order' => array(), 'post' => array(), 'item' => array(), 'comment' => array() );
		}
		$parent_placeholders = implode( ',', array_fill( 0, count( $pid ), '%d' ) );
		$ord                 = array();
		// Unión de los dos almacenes: con sincronización el pedido está en ambos.
		if ( self::storage()['tables'] && self::table_exists( $t( 'wc_orders' ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
			$ord = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE id IN ($parent_placeholders) OR (parent_order_id IN ($parent_placeholders) AND type='shop_order_refund')", array_merge( array( $t( 'wc_orders' ) ), $pid, $pid ) ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE (ID IN ($parent_placeholders) OR (post_parent IN ($parent_placeholders) AND post_type='shop_order_refund')) AND post_type IN ('shop_order','shop_order_refund')", array_merge( array( $t( 'posts' ) ), $pid, $pid ) ) );
		$ord   = array_values( array_unique( array_map( 'intval', array_merge( $ord, $posts ) ) ) );
		sort( $ord );
		$sets = array( 'order' => $ord, 'post' => array_map( 'intval', $posts ), 'item' => array(), 'comment' => array() );
		if ( $ord ) {
			$order_placeholders = implode( ',', array_fill( 0, count( $ord ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
			$sets['item']    = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM %i WHERE order_id IN ($order_placeholders)", array_merge( array( $t( 'woocommerce_order_items' ) ), $ord ) ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
			$sets['comment'] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM %i WHERE comment_post_ID IN ($order_placeholders)", array_merge( array( $t( 'comments' ) ), $ord ) ) ) );
		}
		return $sets;
	}

	/* ---------------------------------------------------------------- Mover */

	/** Filas de $table con $col IN ($ids) (opcionalmente sin los registros mínimos de wp_posts). */
	private static function count_rows( $table, $col, $ids, $skip_placeholder = false ) {
		global $wpdb;
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		if ( $skip_placeholder ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_placeholders: un %d por valor.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE %i IN ($id_placeholders) AND post_type <> %s", array_merge( array( $table, $col ), $ids, array( self::PLACEHOLDER ) ) ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_placeholders: un %d por valor.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE %i IN ($id_placeholders)", array_merge( array( $table, $col ), $ids ) ) );
	}

	/** Conteo + checksum de todas las columnas $cols de las filas de $table con $col IN ($ids). */
	public static function fingerprint( $table, $col, $ids, $cols ) {
		global $wpdb;
		if ( ! $ids ) {
			return '0:0';
		}
		$column_placeholders = implode( ',', array_fill( 0, count( $cols ), "COALESCE(%i,'\\\\N')" ) );
		$id_placeholders     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column_placeholders: un %i por columna; $id_placeholders: un %d por valor.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT_WS('|',$column_placeholders))),0) s FROM %i WHERE %i IN ($id_placeholders)", array_merge( $cols, array( $table, $col ), $ids ) ), ARRAY_A );
		return $row ? $row['n'] . ':' . $row['s'] : 'ERR';
	}

	private static function fail( $msg, $rollback ) {
		global $wpdb;
		if ( $rollback ) {
			$wpdb->query( 'ROLLBACK' );
		}
		return array( 'ok' => false, 'orders' => 0, 'rows' => 0, 'error' => $msg );
	}

	/**
	 * Mueve un grupo de pedidos padre. $direction: 'archive' (vivo → archivo) | 'restore' (archivo → vivo).
	 * $keep: al restaurar, marcar los pedidos para que el archivado automático no los vuelva a llevar.
	 * Todo o nada. Devuelve ['ok'=>bool, 'orders'=>n, 'rows'=>n, 'error'=>string].
	 */
	public static function move( $parent_ids, $direction, $keep = false ) {
		global $wpdb;
		$pre = self::preflight();
		if ( '' !== $pre ) {
			return self::fail( $pre, false );
		}
		$archive   = 'archive' === $direction;
		$from_side = $archive ? 'live' : 'arch';
		$sets      = self::collect( $parent_ids, $from_side );
		if ( ! $sets['order'] ) {
			return array( 'ok' => true, 'orders' => 0, 'rows' => 0, 'error' => '' );
		}
		$check = function ( $short ) {
			/* translators: %s: database table */
			return sprintf( __( 'check failed in %s', 'orderarc' ), $short );
		};

		// Restaurar con otro almacenamiento que el que había al archivar dejaría el pedido invisible para Woo.
		if ( ! $archive ) {
			$st = self::storage();
			if ( $st['hpos'] ) {
				$have = self::table_exists( self::arch( 'wc_orders' ) ) ? self::count_rows( self::arch( 'wc_orders' ), 'id', $sets['order'] ) : 0;
				if ( $have !== count( $sets['order'] ) ) {
					return self::fail( __( 'archived with a different order storage', 'orderarc' ), false );
				}
			} elseif ( count( $sets['post'] ) !== count( $sets['order'] ) ) {
				return self::fail( __( 'archived with a different order storage', 'orderarc' ), false );
			}
		}

		$plan = array();
		foreach ( self::relations() as $short => $def ) {
			list( $col, $set ) = $def;
			$ids = $sets[ $set ];
			if ( ! $ids ) {
				continue;
			}
			$src = $archive ? self::live( $short ) : self::arch( $short );
			$dst = $archive ? self::arch( $short ) : self::live( $short );
			if ( ! self::count_rows( $src, $col, $ids ) ) {
				continue; // nada que mover en esta tabla para estos pedidos.
			}
			// Columnas que el origen tiene y el destino no: se perderían. No se mueve.
			if ( array_diff( self::columns( $src ), self::columns( $dst ) ) ) {
				/* translators: %s: database table */
				return self::fail( sprintf( __( 'table structure differs in %s', 'orderarc' ), $short ), false );
			}
			// El destino no puede tener ya esas filas (choque de IDs).
			if ( self::count_rows( $dst, $col, $ids, 'posts' === $short ) > 0 ) {
				/* translators: %s: database table */
				return self::fail( sprintf( __( 'conflict in %s', 'orderarc' ), $short ), false );
			}
			$plan[] = array( $short, $src, $dst, $col, $ids, self::columns( $src ), self::primary_key( $src ) );
		}

		// REPEATABLE READ: el INSERT…SELECT bloquea las filas de origen hasta el COMMIT, así nadie las cambia
		// entre la copia y el borrado (con READ COMMITTED las leería sin bloquear). Vale solo para esta transacción.
		$wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$wpdb->query( 'START TRANSACTION' );
		$cid  = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$same = function () use ( $wpdb, $cid ) {
			// Si wpdb se reconectó, la transacción ya no existe y lo que sigue sería autocommit.
			return $cid > 0 && (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) === $cid;
		};
		$rows  = 0;
		$posts = $sets['post'];
		$post_placeholders = implode( ',', array_fill( 0, count( $posts ), '%d' ) );

		// Restaurar: primero se sacan los registros mínimos que reservaban el número.
		if ( ! $archive && $posts ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
			$d = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE ID IN ($post_placeholders) AND post_type=%s", array_merge( array( self::live( 'posts' ) ), $posts, array( self::PLACEHOLDER ) ) ) );
			if ( false === $d || (int) $d > count( $posts ) || ! $same() ) {
				return self::fail( $check( 'posts' ), true );
			}
		}
		foreach ( $plan as $s ) {
			list( $short, $src, $dst, $col, $ids, $cols ) = $s;
			$before = self::fingerprint( $src, $col, $ids, $cols );
			$column_placeholders = implode( ',', array_fill( 0, count( $cols ), '%i' ) );
			$id_placeholders     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$n                   = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column_placeholders: un %i por columna; $id_placeholders: un %d por valor.
					"INSERT INTO %i ($column_placeholders) SELECT $column_placeholders FROM %i WHERE %i IN ($id_placeholders)",
					array_merge( array( $dst ), $cols, $cols, array( $src, $col ), $ids )
				)
			);
			$after  = self::fingerprint( $dst, $col, $ids, $cols );
			if ( false === $n || $before !== $after || 'ERR' === $before || ! $same() ) {
				return self::fail( $check( $short ), true );
			}
			$rows += (int) $n;
		}
		foreach ( array_reverse( $plan ) as $s ) {
			list( $short, $src, $dst, $col, $ids, $cols, $pk ) = $s;
			$expected        = self::count_rows( $dst, $col, $ids );
			$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			if ( ! $same() ) {
				return self::fail( $check( $short ), true );
			}
			if ( $pk && in_array( $pk, self::columns( $dst ), true ) ) {
				// Solo se borra lo que está en el destino (misma clave primaria).
				$d = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_placeholders: un %d por valor.
						"DELETE s FROM %i s JOIN %i d ON d.%i = s.%i WHERE s.%i IN ($id_placeholders)",
						array_merge( array( $src, $dst, $pk, $pk, $col ), $ids )
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_placeholders: un %d por valor.
				$d = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE %i IN ($id_placeholders)", array_merge( array( $src, $col ), $ids ) ) );
			}
			if ( false === $d || (int) $d !== $expected || ! $same() ) {
				return self::fail( $check( $short ), true );
			}
		}
		// Archivar: en wp_posts queda un registro mínimo por pedido, para que nadie reutilice el número.
		if ( $archive && $posts ) {
			$n = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
					"INSERT INTO %i (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) SELECT ID,0,post_date,post_date_gmt,'','','','draft','closed','closed','','','','',post_modified,post_modified_gmt,'',post_parent,'',0,%s,'',0 FROM %i WHERE ID IN ($post_placeholders)",
					array_merge( array( self::live( 'posts' ), self::PLACEHOLDER, self::arch( 'posts' ) ), $posts )
				)
			);
			if ( (int) $n !== count( $posts ) || ! $same() ) {
				return self::fail( $check( 'posts' ), true );
			}
		}
		// Restaurado a mano: dentro de la misma transacción, para que ningún trabajo lo vuelva a archivar en el medio.
		if ( ! $archive && $keep ) {
			$pid = array_values( array_map( 'intval', $parent_ids ) );
			$row_placeholders = implode( ',', array_fill( 0, count( $pid ), '(%d,%d)' ) );
			$args = array( self::keep_table() );
			foreach ( $pid as $i ) {
				$args[] = $i;
				$args[] = time();
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared -- lista de (%d,%d) armada arriba.
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (order_id, kept_at) VALUES $row_placeholders", $args ) ) || ! $same() ) {
				return self::fail( $check( 'keep' ), true );
			}
		}
		$wpdb->query( 'COMMIT' );

		self::flush_caches( $sets['order'] );
		if ( $archive ) {
			update_option( 'orderarc_archived_storage', self::storage()['hpos'] ? 'hpos' : 'legacy', false );
		}
		self::flush_counts();
		if ( $archive ) {
			do_action( 'orderarc_archived', $sets['order'], array_values( array_intersect( array_map( 'intval', $parent_ids ), $sets['order'] ) ) );
		} else {
			do_action( 'orderarc_restored', $sets['order'], array_values( array_intersect( array_map( 'intval', $parent_ids ), $sets['order'] ) ) );
		}
		// Se cuentan pedidos, no reembolsos: el número coincide con la vista previa y con "Archived".
		$moved = count( array_intersect( array_map( 'intval', $parent_ids ), $sets['order'] ) );
		return array( 'ok' => true, 'orders' => $moved, 'rows' => $rows, 'error' => '' );
	}

	/** Borra los conteos cacheados de la pantalla. */
	public static function flush_counts() {
		delete_transient( 'orderarc_archived_count' );
		delete_transient( 'orderarc_arch_sources' );
		delete_transient( 'orderarc_preview' );
	}

	public static function flush_caches( $order_ids ) {
		foreach ( $order_ids as $id ) {
			wp_cache_delete( $id, 'orders' );
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
			if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
				try {
					wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				}
			}
		}
		if ( function_exists( '_count_posts_cache_key' ) ) {
			foreach ( array( 'shop_order', 'shop_order_refund' ) as $type ) {
				wp_cache_delete( _count_posts_cache_key( $type ), 'counts' );
				wp_cache_delete( _count_posts_cache_key( $type, 'readable' ), 'counts' );
			}
		}
		if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
			wc_delete_shop_order_transients();
		}
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCountCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCountCache::class )->flush();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
		}
	}

	/* ---------------------------------------------------------------- Conteos para la pantalla */

	/**
	 * Simulación: cuántos pedidos y reembolsos se archivarían, el rango de fechas y cuántos quedan fuera por tener hijos.
	 * Cacheado 10 minutos (se borra al archivar, restaurar o guardar los ajustes).
	 */
	public static function preview( $args ) {
		global $wpdb;
		$rules  = self::rules( $args );
		$key    = md5( wp_json_encode( array( $rules, self::storage(), gmdate( 'Y-m-d' ) ) ) );
		$cached = get_transient( 'orderarc_preview' );
		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key ) {
			return $cached['data'];
		}
		$out = array( 'orders' => 0, 'refunds' => 0, 'skipped' => 0, 'from' => '', 'to' => '' );
		if ( $rules ) {
			$hpos = self::storage()['hpos'];
			$out  = 1 === count( $rules ) ? self::preview_rule( reset( $rules ), $hpos ) : self::preview_rules( $args, $rules, $hpos );
		}
		set_transient( 'orderarc_preview', array( 'key' => $key, 'data' => $out ), 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** Simulación de una sola regla: tres conteos en la base. */
	private static function preview_rule( $r, $hpos ) {
		global $wpdb;
		list( $st, $pm, $co, $v ) = self::rule_values( $r, $hpos );
		list( $kt, $kf )          = self::keep_check();
		$status_placeholders  = implode( ',', array_fill( 0, count( $st ), '%s' ) );
		$payment_placeholders = implode( ',', array_fill( 0, count( $pm ), '%s' ) );
		$country_placeholders = implode( ',', array_fill( 0, count( $co ), '%s' ) );
		if ( $hpos ) {
			$o = self::live( 'wc_orders' );
			$main = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, MIN(o.date_created_gmt) a, MAX(o.date_created_gmt) b FROM %i o WHERE o.type='shop_order' AND o.parent_order_id=0 AND o.status IN ($status_placeholders) AND o.date_created_gmt < %s AND ( %d = 0 OR o.payment_method IN ($payment_placeholders) ) AND ( %d = 0 OR o.total_amount >= %f ) AND ( %d = 0 OR o.total_amount <= %f ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ra WHERE ra.order_id=o.id AND ra.address_type='billing' AND ra.country IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.order_id=o.id AND sm.meta_key IN (%s,%s,%s)) AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.parent_order_id=o.id AND c.type<>'shop_order_refund') AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=o.id AND %d = 1)", array_merge( array( $o ), $v, array( $o, $kt, $kf ) ) ), ARRAY_A );
			$skip = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o WHERE o.type='shop_order' AND o.parent_order_id=0 AND o.status IN ($status_placeholders) AND o.date_created_gmt < %s AND ( %d = 0 OR o.payment_method IN ($payment_placeholders) ) AND ( %d = 0 OR o.total_amount >= %f ) AND ( %d = 0 OR o.total_amount <= %f ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ra WHERE ra.order_id=o.id AND ra.address_type='billing' AND ra.country IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.order_id=o.id AND sm.meta_key IN (%s,%s,%s)) AND EXISTS (SELECT 1 FROM %i c WHERE c.parent_order_id=o.id AND c.type<>'shop_order_refund')", array_merge( array( $o ), $v, array( $o ) ) ) );
			$ref  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i r JOIN %i o ON o.id=r.parent_order_id WHERE r.type='shop_order_refund' AND o.type='shop_order' AND o.parent_order_id=0 AND o.status IN ($status_placeholders) AND o.date_created_gmt < %s AND ( %d = 0 OR o.payment_method IN ($payment_placeholders) ) AND ( %d = 0 OR o.total_amount >= %f ) AND ( %d = 0 OR o.total_amount <= %f ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ra WHERE ra.order_id=o.id AND ra.address_type='billing' AND ra.country IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.order_id=o.id AND sm.meta_key IN (%s,%s,%s)) AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.parent_order_id=o.id AND c.type<>'shop_order_refund') AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=o.id AND %d = 1)", array_merge( array( $o, $o ), $v, array( $o, $kt, $kf ) ) ) );
		} else {
			$p                  = self::live( 'posts' );
			$kids               = self::blocking_child_types();
			$child_placeholders = implode( ',', array_fill( 0, count( $kids ), '%s' ) );
			$main = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, MIN(o.post_date_gmt) a, MAX(o.post_date_gmt) b FROM %i o WHERE o.post_type='shop_order' AND o.post_parent=0 AND o.post_status IN ($status_placeholders) AND o.post_date_gmt < %s AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rm WHERE rm.post_id=o.ID AND rm.meta_key='_payment_method' AND rm.meta_value IN ($payment_placeholders)) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rt WHERE rt.post_id=o.ID AND rt.meta_key='_order_total' AND CAST(rt.meta_value AS DECIMAL(26,8)) >= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ru WHERE ru.post_id=o.ID AND ru.meta_key='_order_total' AND CAST(ru.meta_value AS DECIMAL(26,8)) <= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc WHERE rc.post_id=o.ID AND rc.meta_key='_billing_country' AND rc.meta_value IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.post_id=o.ID AND sm.meta_key IN (%s,%s,%s)) AND o.post_date_gmt > '1970-01-02' AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.post_parent=o.ID AND c.post_type IN ($child_placeholders)) AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=o.ID AND %d = 1)", array_merge( array( $p ), $v, array( $p ), $kids, array( $kt, $kf ) ) ), ARRAY_A );
			$skip = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o WHERE o.post_type='shop_order' AND o.post_parent=0 AND o.post_status IN ($status_placeholders) AND o.post_date_gmt < %s AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rm WHERE rm.post_id=o.ID AND rm.meta_key='_payment_method' AND rm.meta_value IN ($payment_placeholders)) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rt WHERE rt.post_id=o.ID AND rt.meta_key='_order_total' AND CAST(rt.meta_value AS DECIMAL(26,8)) >= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ru WHERE ru.post_id=o.ID AND ru.meta_key='_order_total' AND CAST(ru.meta_value AS DECIMAL(26,8)) <= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc WHERE rc.post_id=o.ID AND rc.meta_key='_billing_country' AND rc.meta_value IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.post_id=o.ID AND sm.meta_key IN (%s,%s,%s)) AND EXISTS (SELECT 1 FROM %i c WHERE c.post_parent=o.ID AND c.post_type IN ($child_placeholders))", array_merge( array( $p ), $v, array( $p ), $kids ) ) );
			$ref  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i r JOIN %i o ON o.ID=r.post_parent WHERE r.post_type='shop_order_refund' AND o.post_type='shop_order' AND o.post_parent=0 AND o.post_status IN ($status_placeholders) AND o.post_date_gmt < %s AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rm WHERE rm.post_id=o.ID AND rm.meta_key='_payment_method' AND rm.meta_value IN ($payment_placeholders)) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rt WHERE rt.post_id=o.ID AND rt.meta_key='_order_total' AND CAST(rt.meta_value AS DECIMAL(26,8)) >= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ru WHERE ru.post_id=o.ID AND ru.meta_key='_order_total' AND CAST(ru.meta_value AS DECIMAL(26,8)) <= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc WHERE rc.post_id=o.ID AND rc.meta_key='_billing_country' AND rc.meta_value IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.post_id=o.ID AND sm.meta_key IN (%s,%s,%s)) AND o.post_date_gmt > '1970-01-02' AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.post_parent=o.ID AND c.post_type IN ($child_placeholders)) AND NOT EXISTS (SELECT 1 FROM %i k WHERE k.order_id=o.ID AND %d = 1)", array_merge( array( $p, $p ), $v, array( $p ), $kids, array( $kt, $kf ) ) ) );
		}
		return array(
			'orders'  => (int) ( $main['n'] ?? 0 ),
			'refunds' => $ref,
			'skipped' => $skip,
			'from'    => $main['a'] ?? '',
			'to'      => $main['b'] ?? '',
		);
	}

	/**
	 * Simulación con varias reglas (versión Pro): las reglas se pueden pisar, así que se cuenta la unión
	 * recorriendo los candidatos por tandas, y las fechas y reembolsos se calculan tanda por tanda.
	 */
	private static function preview_rules( $args, $rules, $hpos ) {
		global $wpdb;
		$out   = array( 'orders' => 0, 'refunds' => 0, 'skipped' => 0, 'from' => '', 'to' => '' );
		$after = 0;
		do {
			$ids = self::candidates( array_merge( $args, array( 'after_id' => $after, 'limit' => 500 ) ) );
			if ( ! $ids ) {
				break;
			}
			$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			if ( $hpos ) {
				$o = self::live( 'wc_orders' );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_placeholders: un %d por valor.
				$d = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(date_created_gmt) a, MAX(date_created_gmt) b FROM %i WHERE id IN ($id_placeholders)", array_merge( array( $o ), $ids ) ), ARRAY_A );
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type='shop_order_refund' AND parent_order_id IN ($id_placeholders)", array_merge( array( $o ), $ids ) ) );
			} else {
				$p = self::live( 'posts' );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_placeholders: un %d por valor.
				$d = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(post_date_gmt) a, MAX(post_date_gmt) b FROM %i WHERE ID IN ($id_placeholders)", array_merge( array( $p ), $ids ) ), ARRAY_A );
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type='shop_order_refund' AND post_parent IN ($id_placeholders)", array_merge( array( $p ), $ids ) ) );
			}
			$out['orders']  += count( $ids );
			$out['refunds'] += $n;
			if ( $d && $d['a'] && ( '' === $out['from'] || $d['a'] < $out['from'] ) ) {
				$out['from'] = $d['a'];
			}
			if ( $d && $d['b'] && $d['b'] > $out['to'] ) {
				$out['to'] = $d['b'];
			}
			$after = (int) end( $ids );
		} while ( count( $ids ) >= 500 );
		$skipped = array();
		foreach ( $rules as $r ) {
			$skipped = array_merge( $skipped, self::rule_skipped( $r, $hpos ) );
		}
		$out['skipped'] = count( array_unique( $skipped ) );
		return $out;
	}

	/** IDs de pedidos de UNA regla que quedan fuera por tener hijos (suscripciones, etc.). */
	private static function rule_skipped( $r, $hpos ) {
		global $wpdb;
		list( $st, $pm, $co, $v ) = self::rule_values( $r, $hpos );
		$status_placeholders  = implode( ',', array_fill( 0, count( $st ), '%s' ) );
		$payment_placeholders = implode( ',', array_fill( 0, count( $pm ), '%s' ) );
		$country_placeholders = implode( ',', array_fill( 0, count( $co ), '%s' ) );
		if ( $hpos ) {
			$o = self::live( 'wc_orders' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT o.id FROM %i o WHERE o.type='shop_order' AND o.parent_order_id=0 AND o.status IN ($status_placeholders) AND o.date_created_gmt < %s AND ( %d = 0 OR o.payment_method IN ($payment_placeholders) ) AND ( %d = 0 OR o.total_amount >= %f ) AND ( %d = 0 OR o.total_amount <= %f ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ra WHERE ra.order_id=o.id AND ra.address_type='billing' AND ra.country IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.order_id=o.id AND sm.meta_key IN (%s,%s,%s)) AND EXISTS (SELECT 1 FROM %i c WHERE c.parent_order_id=o.id AND c.type<>'shop_order_refund')", array_merge( array( $o ), $v, array( $o ) ) ) ) );
		}
		$p                  = self::live( 'posts' );
		$kids               = self::blocking_child_types();
		$child_placeholders = implode( ',', array_fill( 0, count( $kids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo listas de marcadores (*_placeholders: un marcador por valor).
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT o.ID FROM %i o WHERE o.post_type='shop_order' AND o.post_parent=0 AND o.post_status IN ($status_placeholders) AND o.post_date_gmt < %s AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rm WHERE rm.post_id=o.ID AND rm.meta_key='_payment_method' AND rm.meta_value IN ($payment_placeholders)) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rt WHERE rt.post_id=o.ID AND rt.meta_key='_order_total' AND CAST(rt.meta_value AS DECIMAL(26,8)) >= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ru WHERE ru.post_id=o.ID AND ru.meta_key='_order_total' AND CAST(ru.meta_value AS DECIMAL(26,8)) <= %f) ) AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc WHERE rc.post_id=o.ID AND rc.meta_key='_billing_country' AND rc.meta_value IN ($country_placeholders)) ) AND NOT EXISTS (SELECT 1 FROM %i sm WHERE sm.post_id=o.ID AND sm.meta_key IN (%s,%s,%s)) AND EXISTS (SELECT 1 FROM %i c WHERE c.post_parent=o.ID AND c.post_type IN ($child_placeholders))", array_merge( array( $p ), $v, array( $p ), $kids ) ) ) );
	}

	/** Pedidos padre archivados (cacheado unos minutos). */
	public static function archived_count() {
		$n = get_transient( 'orderarc_archived_count' );
		if ( false === $n ) {
			global $wpdb;
			$n = 0;
			if ( self::archive_ready() ) {
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type='shop_order'", self::arch( 'posts' ) ) );
				if ( self::storage()['tables'] && self::table_exists( self::arch( 'wc_orders' ) ) ) {
					$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o WHERE o.type='shop_order' AND NOT EXISTS (SELECT 1 FROM %i x WHERE x.ID=o.id)", self::arch( 'wc_orders' ), self::arch( 'posts' ) ) );
				}
			}
			set_transient( 'orderarc_archived_count', $n, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $n;
	}

	/** Pedidos padre en el archivo (de cualquiera de los dos almacenes), en orden de ID. */
	public static function archived_parents( $after, $limit ) {
		global $wpdb;
		if ( self::storage()['tables'] && self::table_exists( self::arch( 'wc_orders' ) ) ) {
			return array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM (SELECT ID id FROM %i WHERE post_type='shop_order' AND ID > %d UNION SELECT id FROM %i WHERE type='shop_order' AND id > %d) u ORDER BY id LIMIT %d",
						self::arch( 'posts' ),
						$after,
						self::arch( 'wc_orders' ),
						$after,
						$limit
					)
				)
			);
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type='shop_order' AND ID > %d ORDER BY ID LIMIT %d", self::arch( 'posts' ), $after, $limit ) ) );
	}

	/**
	 * Corre tandas hasta agotar el tiempo. $args: direction, statuses, older_than_days, batch, seconds.
	 * Guarda el cursor en una opción para reanudar. Si los chequeos previos fallan devuelve 'fatal' sin mover nada.
	 */
	public static function run( $args ) {
		$direction = 'restore' === ( $args['direction'] ?? '' ) ? 'restore' : 'archive';
		$batch     = max( 1, min( 200, (int) ( $args['batch'] ?? 25 ) ) );
		$deadline  = microtime( true ) + max( 2, (int) ( $args['seconds'] ?? 20 ) );
		$stats     = array( 'orders' => 0, 'rows' => 0, 'batches' => 0, 'errors' => array(), 'ms_per_batch' => array() );
		$pre       = self::preflight();
		if ( '' === $pre ) {
			// La versión Pro puede impedir una dirección (p. ej. archivar en otra base con la licencia vencida).
			$pre = (string) apply_filters( 'orderarc_block_direction', '', $direction );
		}
		if ( '' !== $pre ) {
			$stats['fatal'] = $pre;
			return $stats;
		}
		$cur_key = 'orderarc_cursor_' . $direction;
		$cursor  = (int) get_option( $cur_key, 0 );
		while ( microtime( true ) < $deadline ) {
			if ( 'archive' === $direction ) {
				$ids = self::candidates( array_merge( $args, array( 'after_id' => $cursor, 'limit' => $batch ) ) );
			} else {
				$ids = self::archived_parents( $cursor, $batch );
			}
			if ( ! $ids ) {
				delete_option( $cur_key );
				$stats['done'] = true;
				break;
			}
			$t = microtime( true );
			$r = self::move( $ids, $direction );
			$stats['ms_per_batch'][] = (int) round( ( microtime( true ) - $t ) * 1000 );
			$stats['batches']++;
			if ( $r['ok'] ) {
				$stats['orders'] += $r['orders'];
				$stats['rows']   += $r['rows'];
			} else {
				/* translators: 1: first order number, 2: last order number, 3: reason */
				$stats['errors'][] = sprintf( __( 'Orders %1$s–%2$s not moved: %3$s. Nothing was changed for these orders.', 'orderarc' ), min( $ids ), max( $ids ), $r['error'] );
				do_action( 'orderarc_move_failed', $ids, $direction, $r['error'] );
			}
			$cursor = max( $ids );
			update_option( $cur_key, $cursor, false );
		}
		return $stats;
	}
}

add_filter( 'query', array( 'OrderArc_Engine', 'rewrite' ) );
