<?php
/**
 * Ejecución en segundo plano: trabajos de archivado/restauración con Action Scheduler,
 * candado (nunca dos a la vez), ventana horaria, y tamaño de tanda que se adapta
 * al tiempo que tarda cada tanda (si el servidor se pone lento, va más despacio).
 *
 * @package OrderArc
 */

defined( 'ABSPATH' ) || exit;

class OrderArc_Runner {

	const HOOK       = 'orderarc_run_batch';
	const DAILY_HOOK = 'orderarc_daily';
	const GROUP      = 'orderarc';
	const JOB        = 'orderarc_job';
	const LOCK       = 'orderarc_lock';

	/** Segundos de trabajo por ejecución (por debajo de los límites típicos de PHP de 30 s). */
	const SLICE_SECONDS = 20;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'daily' ) );
	}

	public static function defaults() {
		return array(
			'auto'            => false,          // archivar solo todos los días
			'statuses'        => array( 'wc-completed', 'wc-cancelled', 'wc-refunded' ),
			'older_than_days' => 365,
			'window_start'    => 1,              // hora local de inicio (0-23)
			'window_end'      => 6,              // hora local de fin (0-23); igual a inicio = todo el día
			'batch'           => 50,             // pedidos por tanda al empezar
			'target_ms'       => 800,            // tiempo objetivo por tanda
			'catch_up'        => 'gentle',       // si se perdió la ventana entera: 'gentle' (sigue despacio) | 'wait'
		);
	}

	public static function settings() {
		$s = get_option( 'orderarc_settings', array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function job() {
		$j = get_option( self::JOB, array() );
		return is_array( $j ) ? $j : array();
	}

	private static function save_job( $job ) {
		update_option( self::JOB, $job, false );
	}

	/** ¿La hora local actual está dentro de la ventana? */
	public static function in_window( $s = null ) {
		$s     = $s ? $s : self::settings();
		$start = (int) $s['window_start'];
		$end   = (int) $s['window_end'];
		if ( $start === $end ) {
			return true;
		}
		$h = (int) wp_date( 'G' );
		return $start < $end ? ( $h >= $start && $h < $end ) : ( $h >= $start || $h < $end );
	}

	/** Duración de la ventana en segundos. */
	public static function window_seconds( $s = null ) {
		$s = $s ? $s : self::settings();
		$h = ( (int) $s['window_end'] - (int) $s['window_start'] + 24 ) % 24;
		return ( $h ? $h : 24 ) * HOUR_IN_SECONDS;
	}

	/**
	 * Salud del cron, para la pantalla de diagnóstico: si WP-Cron está apagado, cuánto viene
	 * llegando tarde cada ejecución y cuántas ventanas se perdieron enteras.
	 */
	public static function health() {
		$h   = get_option( 'orderarc_health', array() );
		$h   = is_array( $h ) ? $h : array();
		$due = (int) get_option( 'orderarc_due' );
		return array_merge(
			array( 'last_tick' => 0, 'max_late' => 0, 'missed_windows' => 0, 'ticks' => 0 ),
			$h,
			array(
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'overdue'          => $due && time() > $due ? time() - $due : 0,
			)
		);
	}

	private static function note_tick() {
		$h      = self::health();
		$due    = (int) get_option( 'orderarc_due' );
		$late   = $due ? max( 0, time() - $due ) : 0;
		$keep   = array_intersect_key( $h, array_flip( array( 'last_tick', 'max_late', 'missed_windows', 'ticks' ) ) );
		$keep['last_tick'] = time();
		$keep['ticks']++;
		$keep['max_late']  = max( (int) $keep['max_late'], $late );
		update_option( 'orderarc_health', $keep, false );
		return $late;
	}

	/** Segundos que faltan para que abra la ventana. */
	public static function seconds_to_window( $s = null ) {
		$s   = $s ? $s : self::settings();
		$now = current_datetime();
		$at  = $now->setTime( (int) $s['window_start'], 0, 0 );
		if ( $at <= $now ) {
			$at = $at->modify( '+1 day' );
		}
		return max( 60, $at->getTimestamp() - $now->getTimestamp() );
	}

	/**
	 * Empieza un trabajo. $direction 'archive' | 'restore'. $ignore_window: lo pidió el dueño a mano.
	 */
	public static function start( $direction = 'archive', $ignore_window = false ) {
		$job = self::job();
		$st  = $job['status'] ?? '';
		// Un trabajo esperando la ventana + pedido a mano en la misma dirección: arranca ya, sin perder lo hecho.
		if ( 'waiting' === $st && $ignore_window && ( $job['direction'] ?? '' ) === $direction ) {
			$job['status']        = 'running';
			$job['ignore_window'] = true;
			$job['updated']       = time();
			self::save_job( $job );
			self::unschedule();
			self::schedule( 0 );
			return true;
		}
		if ( in_array( $st, array( 'running', 'waiting' ), true ) ) {
			return new WP_Error( 'orderarc_busy', 'busy' );
		}
		$s = self::settings();
		if ( 'archive' === $direction ) {
			OrderArc_Engine::refresh_third_party();
		}
		delete_option( 'orderarc_cursor_' . $direction );
		self::save_job( array(
			'status'        => 'running',
			'direction'     => 'restore' === $direction ? 'restore' : 'archive',
			'ignore_window' => (bool) $ignore_window,
			'started'       => time(),
			'updated'       => time(),
			'orders'        => 0,
			'rows'          => 0,
			'batches'       => 0,
			'errors'        => array(),
			'batch'         => (int) $s['batch'],
			'last_ms'       => 0,
			'slowdowns'     => 0,
			// Huella de las reglas con las que arrancó: si cambian (ajustes, licencia Pro), no se sigue con otras.
			'rules_key'     => self::rules_key( $s ),
		) );
		self::schedule( 0 );
		return true;
	}

	public static function rules_key( $s ) {
		return md5( wp_json_encode( OrderArc_Engine::rules( $s ) ) );
	}

	public static function pause() {
		$job = self::job();
		if ( $job ) {
			$job['status'] = 'paused';
			self::save_job( $job );
		}
		self::unschedule();
	}

	public static function resume() {
		$job = self::job();
		if ( $job && 'paused' === ( $job['status'] ?? '' ) ) {
			$job['status'] = 'running';
			self::save_job( $job );
			self::schedule( 0 );
		}
	}

	/**
	 * $from_tick: dentro de una ejecución, Action Scheduler cuenta la acción en curso como
	 * "programada", así que ahí no se puede usar as_has_scheduled_action para evitar duplicados.
	 */
	private static function schedule( $delay, $from_tick = false ) {
		update_option( 'orderarc_due', time() + (int) $delay, false );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$pending = $from_tick ? as_get_scheduled_actions( array( 'hook' => self::HOOK, 'group' => self::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) : array();
			if ( $from_tick ? ! $pending : ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_single_action( time() + (int) $delay, self::HOOK, array(), self::GROUP );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::HOOK );
		}
	}

	private static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( 'orderarc_due' );
	}

	/** Nombre del candado de MySQL (uno por sitio). */
	private static function lock_name() {
		global $wpdb;
		return 'orderarc_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 16 );
	}

	/**
	 * Candado atómico de MySQL: nunca corren dos ejecuciones a la vez. Si la ejecución muere,
	 * MySQL lo libera solo al cerrarse la conexión.
	 */
	public static function lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- candado de MySQL; no hay API.
	}

	public static function unlock() {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- candado de MySQL; no hay API.
	}

	/** Estado guardado ahora mismo (sin la caché de esta ejecución): para respetar una pausa hecha mientras corría. */
	private static function stored_status() {
		wp_cache_delete( self::JOB, 'options' );
		$j = get_option( self::JOB, array() );
		return is_array( $j ) ? ( $j['status'] ?? '' ) : '';
	}

	/** Una ejecución: trabaja ~20 s, ajusta el ritmo y se reprograma. */
	public static function tick() {
		$job = self::job();
		if ( empty( $job['status'] ) || 'running' !== $job['status'] && 'waiting' !== $job['status'] ) {
			return;
		}
		$s    = self::settings();
		$late = self::note_tick();
		if ( empty( $job['ignore_window'] ) && empty( $job['catch_up'] ) && ! self::in_window( $s ) ) {
			// Llegamos tarde por más que la ventana entera: nadie disparó el cron de noche (sin visitas, o cron apagado).
			if ( 'waiting' === $job['status'] && $late >= self::window_seconds( $s ) ) {
				$h = get_option( 'orderarc_health', array() );
				$h['missed_windows'] = (int) ( $h['missed_windows'] ?? 0 ) + 1;
				update_option( 'orderarc_health', $h, false );
				if ( 'gentle' === $s['catch_up'] ) {
					$job['catch_up'] = true;
				}
			}
			if ( empty( $job['catch_up'] ) ) {
				$job['status']  = 'waiting';
				$job['updated'] = time();
				self::save_job( $job );
				self::schedule( self::seconds_to_window( $s ), true );
				return;
			}
		}
		// Modo suave (fuera de la ventana por haberla perdido): tandas chicas, objetivo a la mitad, pausa entre ejecuciones.
		$gentle = ! empty( $job['catch_up'] ) && empty( $job['ignore_window'] ) && ! self::in_window( $s );
		$target = $gentle ? (int) ( $s['target_ms'] / 2 ) : (int) $s['target_ms'];
		if ( $gentle ) {
			$job['batch'] = min( 25, (int) $job['batch'] );
		}
		// Archivar con reglas distintas de las que eligió el usuario al arrancar: nunca. Se pausa con el motivo.
		if ( 'archive' === $job['direction'] && isset( $job['rules_key'] ) && self::rules_key( $s ) !== $job['rules_key'] ) {
			$job['status']   = 'paused';
			$job['errors'][] = __( 'The archive rules changed after this run started, so it was paused. Start it again to use the current rules.', 'orderarc' );
			$job['updated']  = time();
			self::save_job( $job );
			self::unschedule();
			return;
		}
		if ( ! self::lock() ) {
			self::schedule( 30, true );
			return;
		}
		try {
			$job['status'] = 'running';
			$r             = OrderArc_Engine::run( array(
				'direction'       => $job['direction'],
				'statuses'        => $s['statuses'],
				'older_than_days' => $s['older_than_days'],
				'batch'           => (int) $job['batch'],
				'seconds'         => $gentle ? 10 : self::SLICE_SECONDS,
			) );
			$job['orders']  += $r['orders'];
			$job['rows']    += $r['rows'];
			$job['batches'] += $r['batches'];
			$job['errors']   = array_slice( array_merge( $job['errors'], $r['errors'] ), -50 );
			$job['updated']  = time();

			// Ritmo: mediana del tiempo por tanda contra el objetivo.
			$ms = $r['ms_per_batch'];
			sort( $ms );
			$median         = $ms ? $ms[ (int) ( count( $ms ) / 2 ) ] : 0;
			$job['last_ms'] = $median;
			$delay          = $gentle ? 10 : 0; // 0: la cola de Action Scheduler encadena la siguiente ronda sin esperar otra visita
			if ( $median > 2 * $target ) {
				$job['batch'] = max( 5, (int) floor( $job['batch'] / 2 ) );
				$job['slowdowns']++;
				$delay = 60; // el servidor está cargado: respirar un minuto
			} elseif ( $median > $target ) {
				$job['batch'] = max( 5, (int) floor( $job['batch'] * 0.75 ) );
				$delay        = 15;
			} elseif ( $median && $median < $target / 2 ) {
				$job['batch'] = min( $gentle ? 25 : 200, (int) ceil( $job['batch'] * 1.25 ) );
			}

			if ( ! empty( $r['done'] ) ) {
				$job['status']   = 'done';
				$job['finished'] = time();
				delete_option( 'orderarc_due' );
			}
			// Los chequeos previos no dejaron trabajar (tabla no InnoDB, triggers…): se pausa con el motivo.
			if ( ! empty( $r['fatal'] ) ) {
				$job['status']   = 'paused';
				$job['errors'][] = $r['fatal'];
				$job['errors']   = array_slice( $job['errors'], -50 );
			}
			// Si el dueño pausó mientras esta ejecución trabajaba, se respeta la pausa.
			if ( 'paused' === self::stored_status() && 'done' !== $job['status'] ) {
				$job['status'] = 'paused';
			}
			self::save_job( $job );
		} finally {
			self::unlock();
		}
		if ( 'running' === $job['status'] ) {
			self::schedule( $delay, true );
		}
		do_action( 'orderarc_job_progress', $job );
	}

	/** Todos los días: si el archivado automático está activo, arranca un trabajo nuevo (respeta la ventana). */
	public static function daily() {
		$s = self::settings();
		// La versión Pro puede frenar el automático (p. ej. reglas propias con la licencia vencida).
		if ( empty( $s['auto'] ) || ! apply_filters( 'orderarc_auto_allowed', true, $s ) ) {
			return;
		}
		$job = self::job();
		if ( ! empty( $job['status'] ) && in_array( $job['status'], array( 'running', 'waiting', 'paused' ), true ) ) {
			return;
		}
		self::start( 'archive', false );
	}

	/** Programa (o reprograma) el disparador diario a la hora de inicio de la ventana. */
	public static function schedule_daily() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		as_unschedule_all_actions( self::DAILY_HOOK, array(), self::GROUP );
		as_schedule_recurring_action( time() + self::seconds_to_window(), DAY_IN_SECONDS, self::DAILY_HOOK, array(), self::GROUP );
	}
}
