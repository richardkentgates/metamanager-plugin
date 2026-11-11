<?php
/**
 * MM_AI_Page — the Site Assistant (AI phase A4).
 *
 * A wp-admin page under the Metamanager menu (manage_options) holding
 * the conversation, the approval cards, and the audit viewer. It talks
 * only to the metamanager/v1/ai/* REST endpoints — no logic lives here
 * beyond rendering; AI_Chat owns the model.
 */

defined( 'ABSPATH' ) || exit;

class MM_AI_Page {

	public static function add_menu(): void {
		add_submenu_page(
			'metamanager',
			esc_html__( 'Site Assistant', 'metamanager' ),
			esc_html__( 'Site Assistant', 'metamanager' ),
			'manage_options',
			'mm-site-assistant',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Site Assistant', 'metamanager' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Reads run freely. Writes appear as approval cards; destructive actions need typed confirmation. Every execution is audited.', 'metamanager' ); ?>
			</p>

			<div id="mm-ai-log" style="border:1px solid #c3c4c7;background:#fff;padding:12px;min-height:220px;max-height:420px;overflow:auto;margin:12px 0;"></div>

			<div id="mm-ai-cards"></div>

			<div style="display:flex;gap:8px;align-items:flex-start;">
				<textarea id="mm-ai-input" rows="3" style="flex:1;"
					placeholder="<?php echo esc_attr__( 'Ask the assistant…', 'metamanager' ); ?>"></textarea>
				<button type="button" class="button button-primary" id="mm-ai-send">
					<?php echo esc_html__( 'Send', 'metamanager' ); ?>
				</button>
				<button type="button" class="button" id="mm-ai-new">
					<?php echo esc_html__( 'New session', 'metamanager' ); ?>
				</button>
			</div>

			<h2><?php echo esc_html__( 'Audit trail', 'metamanager' ); ?></h2>
			<table class="widefat striped" id="mm-ai-audit">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Time', 'metamanager' ); ?></th>
						<th><?php echo esc_html__( 'Actor', 'metamanager' ); ?></th>
						<th><?php echo esc_html__( 'Ability', 'metamanager' ); ?></th>
						<th><?php echo esc_html__( 'Input', 'metamanager' ); ?></th>
						<th><?php echo esc_html__( 'Outcome', 'metamanager' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<script>
		( function () {
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const api = ( path, method, body ) => fetch( '/wp-json/metamanager/v1' + path, {
				method: method,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: body ? JSON.stringify( body ) : undefined
			} ).then( r => r.json().then( data => ( { ok: r.ok, data: data } ) ) );

			let sessionId = null;

			function logLine( text, cls ) {
				const p = document.createElement( 'div' );
				p.textContent = text;
				if ( cls ) { p.className = cls; }
				document.getElementById( 'mm-ai-log' ).appendChild( p );
				document.getElementById( 'mm-ai-log' ).scrollTop = 1e9;
			}

			function renderAudit( audit ) {
				const tbody = document.querySelector( '#mm-ai-audit tbody' );
				tbody.textContent = '';
				( audit || [] ).forEach( function ( row ) {
					const tr = document.createElement( 'tr' );
					[ row.at, row.actor, row.ability, row.input, row.outcome ].forEach( function ( cell ) {
						const td = document.createElement( 'td' );
						td.textContent = 'object' === typeof cell ? JSON.stringify( cell ) : ( cell === null ? '' : String( cell ) );
						tr.appendChild( td );
					} );
					tbody.appendChild( tr );
				} );
			}

			function renderCard( call, index ) {
				const box = document.createElement( 'div' );
				box.style.cssText = 'border:1px solid #d63638;background:#fcf0f1;padding:10px;margin:8px 0;';
				const title = document.createElement( 'strong' );
				title.textContent = ( 'destructive' === call.tier ? '[DESTRUCTIVE] ' : '[WRITE] ' ) + call.ability;
				box.appendChild( title );
				const inp = document.createElement( 'div' );
				inp.textContent = 'input: ' + JSON.stringify( call.input );
				box.appendChild( inp );
				const edit = document.createElement( 'textarea' );
				edit.rows = 2;
				edit.style.cssText = 'width:100%;';
				edit.value = JSON.stringify( call.input );
				box.appendChild( edit );
				const row = document.createElement( 'div' );
				row.style.cssText = 'display:flex;gap:8px;margin-top:6px;align-items:center;';
				let typed = null;
				if ( 'destructive' === call.tier ) {
					typed = document.createElement( 'input' );
					typed.type = 'text';
					typed.placeholder = 'type CONFIRM to proceed';
					row.appendChild( typed );
				}
				const approve = document.createElement( 'button' );
				approve.className = 'button button-primary';
				approve.textContent = 'Approve';
				const decline = document.createElement( 'button' );
				decline.className = 'button';
				decline.textContent = 'Decline';
				row.appendChild( approve );
				row.appendChild( decline );
				box.appendChild( row );

				function settle( approved ) {
					let input = null;
					try { input = JSON.parse( edit.value ); } catch ( e ) { logLine( 'input is not valid JSON' ); return; }
					const typedOk = ! typed || 'CONFIRM' === typed.value;
					approve.disabled = true;
					decline.disabled = true;
					api( '/ai/approve', 'POST', {
						session_id: sessionId,
						call_index: index,
						approved: approved,
						typed_confirmation: typedOk,
						input: input
					} ).then( function ( res ) {
						if ( ! res.ok ) {
							logLine( 'settle failed: ' + ( res.data && res.data.message ? res.data.message : 'unknown' ) );
							approve.disabled = false;
							decline.disabled = false;
							return;
						}
						logLine( 'settled: ' + call.ability + ' -> ' + res.data.status );
						box.remove();
						refreshSession();
					} );
				}
				approve.addEventListener( 'click', function () { settle( true ); } );
				decline.addEventListener( 'click', function () { settle( false ); } );
				document.getElementById( 'mm-ai-cards' ).appendChild( box );
			}

			function refreshSession() {
				if ( ! sessionId ) { return; }
				api( '/ai/session?session_id=' + sessionId, 'GET' ).then( function ( res ) {
					if ( res.ok ) { renderAudit( res.data.audit ); }
				} );
			}

			function send() {
				const input = document.getElementById( 'mm-ai-input' );
				const message = input.value.trim();
				if ( ! message ) { return; }
				input.value = '';
				logLine( 'you: ' + message );
				document.getElementById( 'mm-ai-cards' ).textContent = '';
				api( '/ai/chat', 'POST', { session_id: sessionId, message: message } ).then( function ( res ) {
					if ( ! res.ok ) {
						logLine( 'error: ' + ( res.data && res.data.message ? res.data.message : 'request failed' ) );
						return;
					}
					sessionId = res.data.session_id;
					logLine( 'assistant: ' + res.data.reply );
					( res.data.calls || [] ).forEach( function ( call, i ) {
						if ( 'pending' === call.status ) {
							renderCard( call, i );
						} else {
							logLine( call.ability + ' -> ' + call.status );
						}
					} );
					refreshSession();
				} );
			}

			document.getElementById( 'mm-ai-send' ).addEventListener( 'click', send );
			document.getElementById( 'mm-ai-new' ).addEventListener( 'click', function () {
				sessionId = null;
				document.getElementById( 'mm-ai-log' ).textContent = '';
				document.getElementById( 'mm-ai-cards' ).textContent = '';
				document.querySelector( '#mm-ai-audit tbody' ).textContent = '';
			} );
		} )();
		</script>
		<?php
	}
}
