/**
 * CVG AI Chatbot — the PHP half, as a WPCode snippet.
 *
 * ---------------------------------------------------------------------------
 * READ THIS BEFORE YOU PASTE. The failure is SILENT: nothing in debug.log, no
 * error on screen, the chat box simply never answers.
 *
 *     SET THE LOCATION TO "Run Everywhere". Anything that runs only on the
 *     front end (e.g. "Frontend Only" / frontend_cl) fires on WordPress's `wp`
 *     hook, which only runs on a real page view. REST requests are not page
 *     views, so the routes below never register and every chat request 404s
 *     with 0 bytes written to the log. Measured: `frontend_cl` and
 *     `admin_only` → route absent; `everywhere` and `frontend_only` → 200.
 *
 * CORRECTION, 2026-10-12. An earlier version of this header also warned that a
 * leading <?php tag would stop the snippet activating. That was measured FALSE
 * and is withdrawn. WPCode strips the tag when you save: 3,903 bytes submitted,
 * 3,898 stored, and the snippet activates normally. This file still opens with
 * a comment rather than a tag, and either way works. The original failure came
 * from writing the snippet row straight into the database with WP-CLI, which
 * skips the save routine that does the stripping. See
 * https://ceeveeglobal.com/add-ai-chatbot-to-wordpress/
 * ---------------------------------------------------------------------------
 *
 * This is the plugin flattened into one paste-in file, kept behaviour-identical
 * so the two versions cannot drift:
 *
 *   cvg-ai-chatbot.php                   -> CONFIG + cvg_snip_cfg() + cvg_snip_log()
 *   includes/class-cvg-chatbot-rest.php  -> cvg_snip_routes() + the three callbacks
 *   includes/providers/*.php             -> cvg_snip_call_provider()
 *   includes/class-cvg-chatbot-widget.php-> cvg_snip_boot_vars() (replaces
 *                                           wp_localize_script, since the markup
 *                                           and script are pasted separately)
 *
 * The one real difference: there is no settings screen. You edit the CONFIG
 * block below instead. Everything that matters for security is identical —
 * both routes declare a permission_callback, the chat route is nonce-gated and
 * rate-limited, the conversation lives on the server, max_tokens is always
 * capped, and the provider's error text goes to the log and never to the
 * visitor.
 *
 * Your API key does NOT go in this file. Put it in wp-config.php:
 *
 *     define( 'CVG_CHATBOT_API_KEY', 'sk-your-real-key' );
 *
 * Package: CVG AI Chatbot (snippet edition)
 *
 * Created by Dimu Harshana - https://ceeveeglobal.com/
 * Full guide: https://ceeveeglobal.com/add-ai-chatbot-to-wordpress/
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/* ===========================================================================
 * CONFIG — this block is the part you edit.
 * =========================================================================== */

if ( ! function_exists( 'cvg_snip_cfg' ) ) {
	/**
	 * Every setting, in one place.
	 *
	 * @param string|null $key Optional single key.
	 * @return mixed
	 */
	function cvg_snip_cfg( $key = null ) {
		$cfg = array(

			// Turn the whole widget on and off.
			'enabled'         => true,

			// 'openai' | 'anthropic' | 'compatible'
			// 'compatible' covers Ollama, Groq, OpenRouter, LM Studio, vLLM —
			// anything that speaks the OpenAI chat-completions shape.
			'provider'        => 'compatible',

			// Only used by 'compatible'. Ignored for openai/anthropic.
			'base_url'        => 'http://localhost:11434/v1',

			'model'           => 'qwen2.5:3b-instruct',

			// Read on the SERVER, every request. A visitor cannot replace it.
			'system_prompt'   => 'You are a helpful assistant for this website. Answer clearly and briefly. If a question is outside what you know about this site, say so plainly instead of guessing.',

			// Shown in the panel before anyone types.
			'title'           => 'Ask a question',
			'startup_message' => 'Hi! Ask me anything about this site.',
			'bot_status'      => true,   // false renders the header as "Offline"
			'quick_replies'   => array( 'What do you offer?', 'How do I get started?' ),

			// Cost and abuse controls. Every one of these is a real limit, not a
			// suggestion — see the blog post for what each one stops.
			'max_tokens'      => 500,    // hard cap on each reply
			'temperature'     => 0.7,    // 0 = predictable, 2 = wild
			'timeout'         => 30,     // seconds before we give up on the provider
			'history_turns'   => 8,      // exchanges kept as context
			'max_input_chars' => 1000,   // longest message a visitor may send
			'rate_limit'      => 20,     // requests per window, per visitor. 0 = off
			'rate_window'     => 3600,   // the window, in seconds

			// Brand. Two values, used as CSS custom properties.
			'accent'          => '#4F46E5',
			'accent_text'     => '#FDFFF4',
		);

		return ( null === $key ) ? $cfg : ( isset( $cfg[ $key ] ) ? $cfg[ $key ] : null );
	}
}

/* ===========================================================================
 * Nothing below here needs editing.
 * =========================================================================== */

if ( ! defined( 'CVG_SNIP_NS' ) ) {
	define( 'CVG_SNIP_NS', 'cvg-chatbot/v1' );
}

if ( ! function_exists( 'cvg_snip_api_key' ) ) {
	/**
	 * The API key, from wp-config.php only.
	 *
	 * Deliberately not a setting in this file. A key pasted into a snippet is
	 * visible to every admin and editor, and it travels in database exports and
	 * backups. A constant in wp-config.php does neither.
	 *
	 * @return string
	 */
	function cvg_snip_api_key() {
		if ( defined( 'CVG_CHATBOT_API_KEY' ) && is_string( CVG_CHATBOT_API_KEY ) ) {
			return CVG_CHATBOT_API_KEY;
		}
		return '';
	}
}

if ( ! function_exists( 'cvg_snip_log' ) ) {
	/**
	 * Log an owner-facing diagnostic. Never logs the key.
	 *
	 * Separate on purpose from whatever the visitor is told: the detail that
	 * actually identifies the problem belongs in the log, and a neutral
	 * sentence belongs on screen.
	 *
	 * @param string $context Short label.
	 * @param string $detail  What happened.
	 */
	function cvg_snip_log( $context, $detail ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$key    = cvg_snip_api_key();
		$detail = ( '' !== $key ) ? str_replace( $key, '[redacted]', (string) $detail ) : (string) $detail;
		error_log( sprintf( '[cvg-ai-chatbot] %s: %s', $context, $detail ) );
	}
}

/* ------------------------------------------------------------------ provider */

if ( ! function_exists( 'cvg_snip_call_provider' ) ) {
	/**
	 * Send a conversation to the configured provider and return the reply.
	 *
	 * Flattened from the plugin's three adapter classes. The OpenAI and
	 * compatible paths are the SAME request/response code with a different base
	 * URL — which is what makes testing against a local Ollama a test of the
	 * real shipping path rather than a lookalike.
	 *
	 * @param string $system The system prompt.
	 * @param array  $turns  Ordered array( 'role' => 'user'|'assistant', 'content' => string ).
	 * @return string|WP_Error The reply text, or a WP_Error whose message is for the LOG.
	 */
	function cvg_snip_call_provider( $system, array $turns ) {
		$cfg      = cvg_snip_cfg();
		$key      = cvg_snip_api_key();
		$provider = $cfg['provider'];
		$timeout  = max( 5, (int) $cfg['timeout'] );
		$max_tok  = max( 1, (int) $cfg['max_tokens'] );
		$temp     = max( 0, min( 2, (float) $cfg['temperature'] ) );

		// A local endpoint legitimately needs no key, so "no key" is only a
		// configuration error for the providers that actually require one.
		if ( 'compatible' !== $provider && '' === $key ) {
			return new WP_Error( 'cvg_no_key', 'No API key configured. Add CVG_CHATBOT_API_KEY to wp-config.php.' );
		}

		if ( 'anthropic' === $provider ) {
			// Anthropic takes the system prompt as its own top-level field and
			// does not accept a 'system' role inside messages.
			$messages = array();
			foreach ( $turns as $t ) {
				$messages[] = array(
					'role'    => ( 'assistant' === $t['role'] ) ? 'assistant' : 'user',
					'content' => (string) $t['content'],
				);
			}
			$url     = 'https://api.anthropic.com/v1/messages';
			$headers = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			);
			$body = array(
				'model'      => (string) $cfg['model'],
				'system'     => (string) $system,
				'messages'   => $messages,
				'max_tokens' => $max_tok,
			);
		} else {
			// OpenAI and every OpenAI-compatible endpoint.
			$messages = array( array( 'role' => 'system', 'content' => (string) $system ) );
			foreach ( $turns as $t ) {
				$messages[] = array(
					'role'    => ( 'assistant' === $t['role'] ) ? 'assistant' : 'user',
					'content' => (string) $t['content'],
				);
			}

			if ( 'openai' === $provider ) {
				$url = 'https://api.openai.com/v1/chat/completions';
			} else {
				$base = rtrim( trim( (string) $cfg['base_url'] ), '/' );
				if ( '' === $base ) {
					$base = 'http://localhost:11434/v1';
				}
				// Accept ".../v1", a bare host, or a full completions URL —
				// different providers document all three.
				if ( preg_match( '#/chat/completions$#', $base ) ) {
					$url = $base;
				} else {
					if ( ! preg_match( '#/v\d+$#', $base ) ) {
						$base .= '/v1';
					}
					$url = $base . '/chat/completions';
				}
			}

			$headers = array( 'Content-Type' => 'application/json' );
			if ( '' !== $key ) {
				// Sent only when there is something to send: some local servers
				// reject an empty bearer token outright.
				$headers['Authorization'] = 'Bearer ' . $key;
			}

			$body = array(
				'model'       => (string) $cfg['model'],
				'messages'    => $messages,
				'max_tokens'  => $max_tok,
				'temperature' => $temp,
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		// Always a WP_Error, never a bare string. The tutorial code returned a
		// string on transport failure and an array on success, so its own API had
		// two different shapes depending on how it broke.
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cvg_transport', 'Transport error: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'cvg_bad_json', sprintf( 'HTTP %d with a non-JSON body: %s', $code, substr( $raw, 0, 300 ) ) );
		}

		if ( $code < 200 || $code > 299 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : substr( $raw, 0, 300 );
			return new WP_Error( 'cvg_http_' . $code, sprintf( 'HTTP %d from provider: %s', $code, $msg ) );
		}

		if ( 'anthropic' === $provider ) {
			$text = isset( $data['content'][0]['text'] ) ? $data['content'][0]['text'] : '';
		} else {
			$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		}

		if ( '' === trim( (string) $text ) ) {
			// A 200 carrying nothing. Checking the status code alone would call
			// this a success and show the visitor an empty bubble.
			return new WP_Error( 'cvg_empty', sprintf( 'HTTP %d but the reply had no content', $code ) );
		}

		return (string) $text;
	}
}

/* ---------------------------------------------------------------- rate limit */

if ( ! function_exists( 'cvg_snip_client_key' ) ) {
	/**
	 * A coarse, privacy-respecting client key.
	 *
	 * Hashed with a site salt: enough to count requests, not a stored IP
	 * address — so the rate limiter does not quietly become a visitor log.
	 *
	 * @return string
	 */
	function cvg_snip_client_key() {
		$ip = '';
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
				$ip  = trim( explode( ',', $raw )[0] );
				break;
			}
		}
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}
}

if ( ! function_exists( 'cvg_snip_rate_limited' ) ) {
	/**
	 * Fixed-window rate limit, stored in a transient.
	 *
	 * Not a precise limiter — a window boundary allows a burst — but it is the
	 * difference between "a stranger can run 10,000 requests on your bill" and
	 * "a stranger can run 20 an hour". The tutorial code has no limit at all.
	 *
	 * @return bool
	 */
	function cvg_snip_rate_limited() {
		$limit = (int) cvg_snip_cfg( 'rate_limit' );
		if ( $limit <= 0 ) {
			return false; // switched off deliberately by the site owner
		}
		$window = max( 60, (int) cvg_snip_cfg( 'rate_window' ) );
		$key    = 'cvg_rl_' . cvg_snip_client_key();
		$count  = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}
}

/* --------------------------------------------------------------------- REST */

if ( ! function_exists( 'cvg_snip_reply' ) ) {
	/**
	 * The one response envelope every path returns.
	 *
	 * @param bool   $ok      Whether the call succeeded.
	 * @param string $message Human message.
	 * @param string $answer  The assistant's reply.
	 * @param int    $code    HTTP status.
	 * @param array  $extra   Extra top-level fields.
	 * @return WP_REST_Response
	 */
	function cvg_snip_reply( $ok, $message, $answer = '', $code = 200, $extra = array() ) {
		$payload  = array_merge(
			array(
				'ok'      => (bool) $ok,
				'message' => (string) $message,
				'answer'  => (string) $answer,
			),
			$extra
		);
		$response = new WP_REST_Response( $payload, $code );
		// A chat reply must never be cached by a proxy or a page-cache plugin.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}
}

if ( ! function_exists( 'cvg_snip_can_chat' ) ) {
	/**
	 * Gate for the chat route: enabled, and a valid nonce.
	 *
	 * The nonce is read from our OWN header, not X-WP-Nonce. Core's
	 * rest_cookie_check_errors() runs on rest_authentication_errors, which fires
	 * BEFORE any permission_callback — so an invalid X-WP-Nonce is rejected by
	 * core with `rest_cookie_invalid_nonce` and this function never runs. A
	 * private header keeps this check the one that decides, which is what lets
	 * the widget tell "your cached nonce went stale, retry" apart from "you are
	 * blocked". It is still a real wp_rest nonce, verified with wp_verify_nonce().
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	function cvg_snip_can_chat( WP_REST_Request $request ) {
		if ( ! cvg_snip_cfg( 'enabled' ) ) {
			return new WP_Error( 'cvg_disabled', 'The chatbot is turned off.', array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-CVG-Nonce' );
		if ( empty( $nonce ) ) {
			$nonce = $request->get_param( '_cvg_nonce' );
		}
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'cvg_stale_nonce',
				'Your session token expired. Refreshing and retrying.',
				array( 'status' => 403 )
			);
		}
		return true;
	}
}

if ( ! function_exists( 'cvg_snip_routes' ) ) {
	/**
	 * Register the three routes.
	 *
	 * EVERY route declares a permission_callback. A missing one is not merely
	 * untidy: WordPress warns about it with an `X-WP-DoingItWrong` response
	 * HEADER that it deliberately keeps out of debug.log during REST requests,
	 * so the warning is real and invisible.
	 */
	function cvg_snip_routes() {
		// Hands out a fresh nonce. Exists because a full-page cache serves the
		// same embedded nonce to every visitor for hours; the widget calls this
		// when a request is rejected as stale, then retries once.
		register_rest_route(
			CVG_SNIP_NS,
			'/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => 'cvg_snip_route_nonce',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			CVG_SNIP_NS,
			'/config',
			array(
				'methods'  => 'GET',
				'callback' => 'cvg_snip_route_config',
				// Public on purpose — the widget needs its labels before anyone
				// has interacted. Declared rather than omitted, and it returns
				// presentational values only: no key, no prompt, no model name.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			CVG_SNIP_NS,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => 'cvg_snip_route_chat',
				'permission_callback' => 'cvg_snip_can_chat',
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'session' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}
	add_action( 'rest_api_init', 'cvg_snip_routes' );
}

if ( ! function_exists( 'cvg_snip_route_nonce' ) ) {
	/**
	 * GET /nonce
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	function cvg_snip_route_nonce( WP_REST_Request $request ) {
		return cvg_snip_reply( true, 'ok', '', 200, array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}
}

if ( ! function_exists( 'cvg_snip_route_config' ) ) {
	/**
	 * GET /config — presentational values only.
	 *
	 * Deliberately absent: the key, the system prompt, the model, the base URL
	 * and the provider. A public endpoint should not describe the site's AI
	 * configuration to anyone who asks.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	function cvg_snip_route_config( WP_REST_Request $request ) {
		$cfg = cvg_snip_cfg();

		$quick = array();
		foreach ( (array) $cfg['quick_replies'] as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$quick[] = $line;
			}
		}

		return cvg_snip_reply(
			true,
			'ok',
			'',
			200,
			array(
				'config' => array(
					'title'          => $cfg['title'],
					'startupMessage' => $cfg['startup_message'],
					'online'         => (bool) $cfg['bot_status'],
					'quickReplies'   => array_slice( $quick, 0, 4 ),
					'maxInputChars'  => (int) $cfg['max_input_chars'],
					'accent'         => $cfg['accent'],
					'accentText'     => $cfg['accent_text'],
				),
			)
		);
	}
}

if ( ! function_exists( 'cvg_snip_history_key' ) ) {
	/**
	 * Transient key for one visitor's conversation.
	 *
	 * @param string $session Opaque session id from the browser.
	 * @return string
	 */
	function cvg_snip_history_key( $session ) {
		return 'cvg_chat_' . substr( hash_hmac( 'sha256', $session . '|' . cvg_snip_client_key(), wp_salt( 'nonce' ) ), 0, 32 );
	}
}

if ( ! function_exists( 'cvg_snip_route_chat' ) ) {
	/**
	 * POST /chat
	 *
	 * THE SERVER OWNS THE CONVERSATION. The browser sends one message and a
	 * session id; the history lives in a transient. So a visitor cannot put
	 * words in the model's mouth, and cannot send an unbounded prompt.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	function cvg_snip_route_chat( WP_REST_Request $request ) {
		nocache_headers();

		if ( cvg_snip_rate_limited() ) {
			$mins = max( 1, (int) round( (int) cvg_snip_cfg( 'rate_window' ) / 60 ) );
			return cvg_snip_reply(
				false,
				sprintf( 'You have reached the message limit. Please try again in about %d minutes.', $mins ),
				'',
				429
			);
		}

		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return cvg_snip_reply( false, 'Please type a message.', '', 400 );
		}

		// Prompt size is what a request costs, so the cap is not left to the client.
		$max_chars = max( 1, (int) cvg_snip_cfg( 'max_input_chars' ) );
		if ( mb_strlen( $message ) > $max_chars ) {
			return cvg_snip_reply(
				false,
				sprintf( 'That message is too long. Please keep it under %d characters.', $max_chars ),
				'',
				400
			);
		}

		$session = (string) $request->get_param( 'session' );
		if ( '' === $session ) {
			// LOWERCASE HEX, and that is not cosmetic. The `session` arg is
			// sanitised with sanitize_key(), which lowercases — so a mixed-case
			// session handed out by wp_generate_password() comes back as a
			// DIFFERENT string, pointing at a different transient. Every turn
			// then writes a history nobody ever reads: the chat answers normally
			// and silently has no memory. bin2hex() is already sanitize_key()-stable.
			$session = bin2hex( random_bytes( 8 ) );
		}

		// History comes from the SERVER. The request body has no say in it.
		$hkey    = cvg_snip_history_key( $session );
		$history = get_transient( $hkey );
		$history = is_array( $history ) ? $history : array();

		// Unbounded history is unbounded cost per request, and it grows every turn.
		$max_turns = max( 1, (int) cvg_snip_cfg( 'history_turns' ) ) * 2;
		if ( count( $history ) > $max_turns ) {
			$history = array_slice( $history, -$max_turns );
		}

		$turns   = $history;
		$turns[] = array( 'role' => 'user', 'content' => $message );

		$answer = cvg_snip_call_provider( (string) cvg_snip_cfg( 'system_prompt' ), $turns );

		if ( is_wp_error( $answer ) ) {
			// The detail goes to the LOG; the visitor gets a neutral sentence.
			// The tutorial code did the opposite of both: it threw the detail
			// away AND echoed the provider's raw error body to an endpoint
			// anyone could call, which leaks the provider and internal hosts.
			cvg_snip_log( 'provider:' . $answer->get_error_code(), $answer->get_error_message() );
			return cvg_snip_reply(
				false,
				'Sorry, the assistant is unavailable right now. Please try again shortly.',
				'',
				503
			);
		}

		$turns[] = array( 'role' => 'assistant', 'content' => $answer );
		set_transient( $hkey, $turns, HOUR_IN_SECONDS * 2 );

		return cvg_snip_reply( true, 'ok', $answer, 200, array( 'session' => $session ) );
	}
}

/* ------------------------------------------------------------- front-end vars */

if ( ! function_exists( 'cvg_snip_boot_vars' ) ) {
	/**
	 * Print what the pasted script needs, as window.cvgChatbot.
	 *
	 * This replaces the plugin's wp_localize_script(), because in the snippet
	 * build the markup and the JavaScript are pasted separately and there is no
	 * registered script handle to attach data to.
	 *
	 * The URLs come from rest_url(), never a hardcoded host. That is what
	 * removes BOTH the dead-host failure (the kit shipped
	 * http://ai-chatbot.local/...) and the mixed-content failure on an HTTPS
	 * site, in one move — rest_url() is always same-origin and always the right
	 * scheme.
	 *
	 * Printed at priority 5 so it lands before a footer-loaded script, though
	 * the script does not depend on that — it waits for these vars.
	 */
	function cvg_snip_boot_vars() {
		if ( ! cvg_snip_cfg( 'enabled' ) || is_admin() || is_feed() || is_robots() ) {
			return;
		}
		$cfg = cvg_snip_cfg();

		$vars = array(
			'chatUrl'   => rest_url( CVG_SNIP_NS . '/chat' ),
			'configUrl' => rest_url( CVG_SNIP_NS . '/config' ),
			'nonceUrl'  => rest_url( CVG_SNIP_NS . '/nonce' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'i18n'      => array(
				'send'        => 'Send',
				'close'       => 'Close chat',
				'open'        => 'Open chat',
				'placeholder' => 'Type your message…',
				'thinking'    => 'Thinking…',
				'offline'     => 'Offline',
				'online'      => 'Online',
				'generic'     => 'Something went wrong. Please try again.',
				'tooLong'     => 'That message is too long.',
			),
		);

		$accent      = sanitize_hex_color( $cfg['accent'] ) ? $cfg['accent'] : '#4F46E5';
		$accent_text = sanitize_hex_color( $cfg['accent_text'] ) ? $cfg['accent_text'] : '#FDFFF4';

		printf(
			'<script id="cvg-chatbot-vars">window.cvgChatbot=%s;</script>' . "\n",
			wp_json_encode( $vars )
		);
		printf(
			'<style id="cvg-chatbot-accent">:root{--cvg-accent:%s;--cvg-accent-text:%s;}</style>' . "\n",
			esc_attr( $accent ),
			esc_attr( $accent_text )
		);
	}
	add_action( 'wp_footer', 'cvg_snip_boot_vars', 5 );
}
