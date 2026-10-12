/**
 * CVG AI Chatbot — the front-end widget (snippet edition).
 *
 * WPCode: add this as a "JavaScript Snippet", location "Site Wide Footer".
 * Not using WPCode? Paste it into your theme's footer.php inside a <script> tag.
 *
 * Same logic as the plugin's assets/js/cvg-chatbot.js, with two additions the
 * snippet build needs:
 *
 *  - IT WAITS. In a plugin, WordPress guarantees the order of the markup, the
 *    config vars and this script. Pasted into three separate WPCode snippets,
 *    nothing guarantees that, so instead of giving up when window.cvgChatbot or
 *    the markup is not there yet, this polls briefly for both (see boot(), at
 *    the bottom). That is why you can paste the three files in any order.
 *  - IT FILLS IN THE CHROME. The plugin renders the title, the online badge and
 *    the input limit in PHP. 02-html-block.html is static, so applyChrome()
 *    writes them from the /config endpoint instead — which is what keeps the
 *    markup file free of anything you would otherwise have to edit by hand.
 *
 * Deliberate differences from the tutorial script this all replaces:
 *
 *  - STRICT MODE, IIFE, no globals. The original leaked `data` and `response` as
 *    implicit globals, which breaks under strict mode or any bundler.
 *  - Model output is NEVER assigned to innerHTML. The original did, which makes
 *    whatever the model returns executable in the visitor's browser. Here text is
 *    rendered through document.createTextNode, and a tiny markdown subset is built
 *    with real DOM nodes.
 *  - No conversation history is sent. The server owns it; we send one message and
 *    an opaque session id.
 *  - A stale nonce is RECOVERED rather than shown as an error. Page caches serve
 *    the same nonce to everyone for hours, so on a 403 with code `cvg_stale_nonce`
 *    the widget fetches a fresh one and retries exactly once.
 *  - response.ok is never treated as "it worked". The lab showed Plain permalinks
 *    returning HTTP 200 carrying an HTML page; the original's `if (response.ok)`
 *    passed, and `response.json()` then threw on the `<` of `<!DOCTYPE`. Here the
 *    body is parsed defensively and a non-JSON reply is reported as such.
 *
 * License: GPL-2.0-or-later
 *
 * Created by Dimu Harshana - https://ceeveeglobal.com/
 * Full guide: https://ceeveeglobal.com/add-ai-chatbot-to-wordpress/
 * License: GPL-2.0-or-later
 */
( function () {
	'use strict';

	function init( cfg, root ) {

		var panel   = root.querySelector( '.cvg-chatbot__panel' );
		var launch  = root.querySelector( '.cvg-chatbot__launcher' );
		var closeBt = root.querySelector( '.cvg-chatbot__close' );
		var log     = root.querySelector( '.cvg-chatbot__log' );
		var quick   = root.querySelector( '.cvg-chatbot__quick' );
		var form    = root.querySelector( '.cvg-chatbot__form' );
		var input   = root.querySelector( '.cvg-chatbot__input' );
		var sendBt  = root.querySelector( '.cvg-chatbot__send' );

		if ( ! panel || ! launch || ! log || ! form || ! input || ! sendBt ) {
			// The markup is present but incomplete — almost always a partial
			// paste of 02-html-block.html. Say so once, rather than throwing on
			// every click.
			if ( window.console && window.console.warn ) {
				window.console.warn( 'cvg-ai-chatbot: #cvg-chatbot is missing its inner markup. Re-paste 02-html-block.html in full.' );
			}
			return;
		}

		var i18n    = cfg.i18n || {};
		var nonce   = cfg.nonce;
		var session = '';
		var busy    = false;
		var started = false;

		/* ---------------------------------------------------------------- render */

		/**
		 * Render a very small markdown subset into a container, using DOM nodes only.
		 *
		 * Supports **bold**, `code`, fenced blocks, links, and line breaks — enough for
		 * a model's normal prose. Everything else stays literal text. There is no path
		 * here by which model output becomes markup, which is the entire point: the
		 * original kit's innerHTML assignment made every reply a potential injection.
		 */
		function renderText( container, text ) {
			var lines = String( text ).split( /\n/ );
			var inFence = false;
			var fence = null;

			lines.forEach( function ( line ) {
				if ( /^```/.test( line.trim() ) ) {
					if ( inFence ) {
						inFence = false;
						fence = null;
					} else {
						inFence = true;
						fence = document.createElement( 'pre' );
						var codeEl = document.createElement( 'code' );
						fence.appendChild( codeEl );
						container.appendChild( fence );
					}
					return;
				}

				if ( inFence && fence ) {
					var code = fence.querySelector( 'code' );
					code.appendChild( document.createTextNode( line + '\n' ) );
					return;
				}

				var p = document.createElement( 'p' );
				inlineInto( p, line );
				container.appendChild( p );
			} );
		}

		/** Inline spans: **bold**, `code`, and bare URLs. Text nodes for everything else. */
		function inlineInto( parent, line ) {
			// One pass, splitting on the three patterns we support. Anything unmatched
			// is appended as a text node, so it cannot become markup.
			var pattern = /(\*\*[^*]+\*\*|`[^`]+`|https?:\/\/[^\s<>"')]+)/g;
			var last = 0;
			var m;

			while ( ( m = pattern.exec( line ) ) !== null ) {
				if ( m.index > last ) {
					parent.appendChild( document.createTextNode( line.slice( last, m.index ) ) );
				}
				var tok = m[ 0 ];
				if ( tok.slice( 0, 2 ) === '**' ) {
					var strong = document.createElement( 'strong' );
					strong.appendChild( document.createTextNode( tok.slice( 2, -2 ) ) );
					parent.appendChild( strong );
				} else if ( tok.charAt( 0 ) === '`' ) {
					var code = document.createElement( 'code' );
					code.appendChild( document.createTextNode( tok.slice( 1, -1 ) ) );
					parent.appendChild( code );
				} else {
					var a = document.createElement( 'a' );
					a.href = tok;                       // assigned, not interpolated
					a.rel = 'nofollow noopener';
					a.target = '_blank';
					a.appendChild( document.createTextNode( tok ) );
					parent.appendChild( a );
				}
				last = m.index + tok.length;
			}
			if ( last < line.length ) {
				parent.appendChild( document.createTextNode( line.slice( last ) ) );
			}
		}

		function addMessage( who, text ) {
			var row = document.createElement( 'div' );
			row.className = 'cvg-msg cvg-msg--' + who;
			var bubble = document.createElement( 'div' );
			bubble.className = 'cvg-msg__bubble';
			if ( 'bot' === who ) {
				renderText( bubble, text );
			} else {
				// A visitor's own message is always literal text.
				var p = document.createElement( 'p' );
				p.appendChild( document.createTextNode( text ) );
				bubble.appendChild( p );
			}
			row.appendChild( bubble );
			log.appendChild( row );
			log.scrollTop = log.scrollHeight;
			return row;
		}

		function addTyping() {
			var row = document.createElement( 'div' );
			row.className = 'cvg-msg cvg-msg--bot cvg-msg--typing';
			var bubble = document.createElement( 'div' );
			bubble.className = 'cvg-msg__bubble';
			for ( var i = 0; i < 3; i++ ) {
				var dot = document.createElement( 'span' );
				dot.className = 'cvg-typing__dot';
				bubble.appendChild( dot );
			}
			var sr = document.createElement( 'span' );
			sr.className = 'cvg-sr';
			sr.appendChild( document.createTextNode( i18n.thinking || 'Thinking…' ) );
			bubble.appendChild( sr );
			row.appendChild( bubble );
			log.appendChild( row );
			log.scrollTop = log.scrollHeight;
			return row;
		}

		/* ---------------------------------------------------------------- network */

		/** Parse a response body without assuming it is JSON. */
		function readJson( res ) {
			return res.text().then( function ( raw ) {
				try {
					return { json: JSON.parse( raw ), raw: raw };
				} catch ( e ) {
					// HTTP 200 with an HTML body is a real, observed case (Plain
					// permalinks). Surface it as a parse failure instead of throwing
					// an unhandled SyntaxError.
					return { json: null, raw: raw };
				}
			} );
		}

		function refreshNonce() {
			return fetch( cfg.nonceUrl, { credentials: 'same-origin' } )
				.then( readJson )
				.then( function ( out ) {
					if ( out.json && out.json.nonce ) {
						nonce = out.json.nonce;
						return true;
					}
					return false;
				} )
				.catch( function () {
					return false;
				} );
		}

		function postMessage( text, isRetry ) {
			return fetch( cfg.chatUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					// Our own header, NOT X-WP-Nonce. Core intercepts an invalid
					// X-WP-Nonce before the route's permission_callback runs, which
					// makes a stale cached nonce indistinguishable from a block.
					'X-CVG-Nonce': nonce
				},
				body: JSON.stringify( { message: text, session: session } )
			} )
				.then( readJson )
				.then( function ( out ) {
					var body = out.json;

					// A stale nonce is expected on a cached page. Recover once.
					// Both codes are handled: ours, and core's, in case a future
					// WordPress claims the custom header too.
					if ( ! isRetry && body && ( body.code === 'cvg_stale_nonce' || body.code === 'rest_cookie_invalid_nonce' ) ) {
						return refreshNonce().then( function ( ok ) {
							if ( ok ) {
								return postMessage( text, true );
							}
							return { error: ( body && body.message ) || i18n.generic };
						} );
					}

					if ( ! body ) {
						return { error: i18n.generic };
					}
					// WP_Error responses carry `code`/`message`; ours carry ok/answer.
					if ( body.code && body.message ) {
						return { error: body.message };
					}
					if ( body.ok && body.answer ) {
						if ( body.session ) {
							session = body.session;
						}
						return { answer: body.answer };
					}
					return { error: body.message || i18n.generic };
				} )
				.catch( function () {
					return { error: i18n.generic };
				} );
		}

		/* ---------------------------------------------------------------- actions */

		function setBusy( state ) {
			busy = state;
			sendBt.disabled = state;
			input.disabled = state;
		}

		function send( text ) {
			text = String( text || '' ).trim();
			if ( ! text || busy ) {
				return;
			}
			if ( quick ) {
				quick.innerHTML = '';
			}
			addMessage( 'user', text );
			input.value = '';
			autoGrow();
			setBusy( true );
			var typing = addTyping();

			postMessage( text, false ).then( function ( out ) {
				if ( typing && typing.parentNode ) {
					typing.parentNode.removeChild( typing );
				}
				if ( out.answer ) {
					addMessage( 'bot', out.answer );
				} else {
					var row = addMessage( 'bot', out.error || i18n.generic );
					row.classList.add( 'cvg-msg--error' );
				}
				setBusy( false );
				input.focus();
			} );
		}

		function autoGrow() {
			input.style.height = 'auto';
			input.style.height = Math.min( 120, input.scrollHeight ) + 'px';
		}

		/**
		 * Write the parts the plugin renders in PHP.
		 *
		 * 02-html-block.html ships static defaults so the widget is never blank
		 * if /config is unreachable; this overwrites them with whatever the
		 * CONFIG block in 01-paste-into-wpcode.php actually says.
		 */
		function applyChrome( c ) {
			var titleEl  = root.querySelector( '.cvg-chatbot__title' );
			var statusEl = root.querySelector( '.cvg-chatbot__status' );
			var statusTx = root.querySelector( '.cvg-chatbot__status-text' );

			if ( titleEl && c.title ) {
				titleEl.textContent = c.title;
				panel.setAttribute( 'aria-label', c.title );
			}
			if ( statusEl ) {
				statusEl.setAttribute( 'data-online', c.online ? 'true' : 'false' );
			}
			if ( statusTx ) {
				statusTx.textContent = c.online
					? ( i18n.online || 'Online' )
					: ( i18n.offline || 'Offline' );
			}
			if ( c.maxInputChars ) {
				input.setAttribute( 'maxlength', String( c.maxInputChars ) );
			}
			if ( i18n.placeholder ) {
				input.setAttribute( 'placeholder', i18n.placeholder );
			}
		}

		function loadConfig() {
			return fetch( cfg.configUrl, { credentials: 'same-origin' } )
				.then( readJson )
				.then( function ( out ) {
					var c = out.json && out.json.config ? out.json.config : null;
					if ( ! c ) {
						return;
					}
					applyChrome( c );
					if ( c.startupMessage ) {
						addMessage( 'bot', c.startupMessage );
					}
					if ( quick && c.quickReplies && c.quickReplies.length ) {
						c.quickReplies.forEach( function ( label ) {
							var b = document.createElement( 'button' );
							b.type = 'button';
							b.className = 'cvg-quick__btn';
							b.appendChild( document.createTextNode( label ) );
							b.addEventListener( 'click', function () {
								send( label );
							} );
							quick.appendChild( b );
						} );
					}
				} )
				.catch( function () {} );
		}

		function toggle( open ) {
			root.setAttribute( 'data-open', open ? 'true' : 'false' );
			launch.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				panel.removeAttribute( 'hidden' );
				if ( ! started ) {
					started = true;
					loadConfig();
				}
				input.focus();
			} else {
				panel.setAttribute( 'hidden', '' );
				launch.focus();
			}
		}

		/* ---------------------------------------------------------------- wire up */

		launch.addEventListener( 'click', function () {
			toggle( root.getAttribute( 'data-open' ) !== 'true' );
		} );
		closeBt.addEventListener( 'click', function () {
			toggle( false );
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			send( input.value );
		} );
		input.addEventListener( 'input', autoGrow );
		input.addEventListener( 'keydown', function ( e ) {
			// Enter sends, Shift+Enter makes a new line.
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				send( input.value );
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && root.getAttribute( 'data-open' ) === 'true' ) {
				toggle( false );
			}
		} );
	}

	/* ------------------------------------------------------------------- boot */

	/**
	 * Start as soon as BOTH the config vars and the markup exist.
	 *
	 * A plugin controls its own load order. Three separate WPCode snippets do
	 * not, so this gives up only after five seconds of trying rather than on the
	 * first look — which is what makes the paste order of the three files
	 * irrelevant. One attempt every 100ms, then a single console message naming
	 * whichever piece never arrived, so a half-finished install is a readable
	 * problem instead of a dead button.
	 */
	var tries = 0;
	var LIMIT = 50;

	function attempt() {
		var cfg  = window.cvgChatbot;
		var root = document.getElementById( 'cvg-chatbot' );

		if ( cfg && cfg.chatUrl && root ) {
			init( cfg, root );
			return;
		}

		if ( ++tries < LIMIT ) {
			window.setTimeout( attempt, 100 );
			return;
		}

		if ( window.console && window.console.warn ) {
			var missing = [];
			if ( ! window.cvgChatbot ) {
				missing.push( 'window.cvgChatbot (01-paste-into-wpcode.php is not running — check the snippet is active and its location is "Run Everywhere")' );
			}
			if ( ! document.getElementById( 'cvg-chatbot' ) ) {
				missing.push( '#cvg-chatbot (02-html-block.html is not on the page)' );
			}
			window.console.warn( 'cvg-ai-chatbot: gave up waiting for ' + missing.join( ' and ' ) );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', attempt );
	} else {
		attempt();
	}
}() );
