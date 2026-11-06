/* OrderArc admin: refresco del estado del trabajo, medición de búsqueda y botones de restaurar. */
( function () {
	'use strict';
	var cfg = window.orderarc || {};

	function post( action ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } );
	}

	// Estado del trabajo: se refresca solo mientras corre o espera. Estar en esta página también
	// empuja la cola de tareas (Action Scheduler la dispara desde el panel).
	var job = document.querySelector( '.orderarc-job' );
	if ( job && /^(running|waiting)$/.test( job.getAttribute( 'data-status' ) ) ) {
		var timer = setInterval( function () {
			post( 'orderarc_status' ).then( function ( r ) { return r.json(); } ).then( function ( r ) {
				if ( ! r || ! r.success ) { return; }
				document.getElementById( 'orderarc-status' ).innerHTML = r.data.html;
				if ( ! /^(running|waiting)$/.test( r.data.status ) ) {
					clearInterval( timer );
					window.location.reload();
				}
			} ).catch( function () {} );
		}, 5000 );
	}

	var btn = document.getElementById( 'orderarc-measure' );
	if ( btn ) {
		btn.addEventListener( 'click', function () {
			var out = document.getElementById( 'orderarc-measure-result' );
			var t0 = Date.now();
			btn.disabled = true;
			out.innerHTML = '<span class="spinner is-active"></span>';
			var fmt = function ( s ) { return ( Math.round( s * 10 ) / 10 ).toLocaleString( document.documentElement.lang || undefined ); };
			post( 'orderarc_measure' ).then( function ( r ) {
				if ( ! r.ok ) { throw new Error( r.status ); }
				return r.json();
			} ).then( function ( r ) {
				if ( ! r || ! r.success ) { throw new Error( 'x' ); }
				out.textContent = cfg.took.replace( '%s', fmt( r.data.ms / 1000 ) );
			} ).catch( function () {
				out.textContent = cfg.nofin.replace( '%s', fmt( ( Date.now() - t0 ) / 1000 ) );
			} ).then( function () { btn.disabled = false; } );
		} );
	}

	// "Restaurar" de una fila: solo esa fila, aunque haya otras marcadas.
	document.querySelectorAll( '.orderarc-list [data-single]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			document.querySelectorAll( '.orderarc-list input[name="ids[]"]' ).forEach( function ( c ) { c.checked = false; } );
		} );
	} );
	// La casilla de backup del archivado diario solo se muestra al activarlo.
	var auto = document.getElementById( 'orderarc-auto' );
	var autoBackup = document.getElementById( 'orderarc-auto-backup' );
	if ( auto && autoBackup ) {
		var sync = function () { autoBackup.hidden = ! auto.checked; };
		auto.addEventListener( 'change', sync );
		sync();
	}

	var all = document.getElementById( 'orderarc-all' );
	if ( all ) {
		all.addEventListener( 'change', function () {
			document.querySelectorAll( '.orderarc-list tbody input[name="ids[]"]' ).forEach( function ( c ) { c.checked = all.checked; } );
		} );
	}
}() );
