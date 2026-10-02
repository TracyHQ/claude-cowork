<?php
/**
 * Account pages: a signed-out visitor who opens a profile page is sent to the Login page, as the source
 * does (Joomla answers 303 to the login menu item).
 *
 * @package wp-ja-morgan
 */

add_action(
	'template_redirect',
	static function () {
		if ( is_user_logged_in() ) {
			return;
		}
		$request = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
		// The three source routes with no WordPress equivalent (Submit an Article, Template Settings, Site Settings) also answer a
		// guest with the login redirect, as Joomla does; a signed-in visitor falls through to the seeded redirect to the home page.
		$is_guest_route = in_array( $request, array( 'submit-an-article', 'template-settings', 'site-settings' ), true );
		$is_profile     = in_array( $request, array( 'your-profile', 'j-pages/profile', 'j-pages/edit-profile' ), true )
			|| ( is_page() && 'page-account' === get_page_template_slug( get_queried_object_id() ) );
		if ( ! $is_profile && ! $is_guest_route ) {
			return;
		}
		$login = get_page_by_path( 'j-pages/login' );
		if ( $login ) {
			wp_safe_redirect( get_permalink( $login ), 303 );
			exit;
		}
	},
	1 // Before redirect_canonical (10) and the seeded redirects (10): the source answers 303 at `/your-profile` itself, not 301 to a slash first.
);

/**
 * The four forms the source answers with Joomla tasks are answered here by admin-post handlers:
 * registration, username reminder and the contact form. Each posts back with a nonce, does the work
 * with WordPress core, and returns to the page with ?jm_notice=<code> which the pattern prints.
 */
function wp_ja_morgan_form_back( $page, $code ) {
	$url = add_query_arg( 'jm_notice', $code, home_url( '/' . trim( $page, '/' ) . '/' ) );
	wp_safe_redirect( $url, 303 );
	exit;
}

function wp_ja_morgan_form_notice( $messages ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display-only status code.
	$code = isset( $_GET['jm_notice'] ) ? sanitize_key( wp_unslash( $_GET['jm_notice'] ) ) : '';
	if ( '' === $code || ! isset( $messages[ $code ] ) ) {
		return '';
	}
	$class = 0 === strpos( $code, 'ok' ) ? 'alert-success' : 'alert-danger';
	return '<div class="alert ' . esc_attr( $class ) . '" role="status">' . esc_html( $messages[ $code ] ) . '</div>';
}

function wp_ja_morgan_handle_register() {
	if ( ! isset( $_POST['jm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jm_nonce'] ) ), 'jm_register' ) ) {
		wp_ja_morgan_form_back( 'j-pages/registration', 'error-token' );
	}
	if ( ! get_option( 'users_can_register' ) ) {
		wp_ja_morgan_form_back( 'j-pages/registration', 'error-closed' );
	}
	$f = isset( $_POST['jform'] ) && is_array( $_POST['jform'] ) ? wp_unslash( $_POST['jform'] ) : array();
	$name  = sanitize_text_field( isset( $f['name'] ) ? $f['name'] : '' );
	$login = sanitize_user( isset( $f['username'] ) ? $f['username'] : '', true );
	$mail  = sanitize_email( isset( $f['email1'] ) ? $f['email1'] : '' );
	$pass1 = isset( $f['password1'] ) ? (string) $f['password1'] : '';
	$pass2 = isset( $f['password2'] ) ? (string) $f['password2'] : '';
	$tos   = isset( $f['profile']['tos'] ) ? (string) $f['profile']['tos'] : '0';
	if ( '' === $name || '' === $login || ! is_email( $mail ) || strlen( $pass1 ) < 4 ) {
		wp_ja_morgan_form_back( 'j-pages/registration', 'error-fields' );
	}
	if ( $pass1 !== $pass2 ) {
		wp_ja_morgan_form_back( 'j-pages/registration', 'error-password' );
	}
	if ( '1' !== $tos ) {
		wp_ja_morgan_form_back( 'j-pages/registration', 'error-tos' );
	}
	if ( username_exists( $login ) || email_exists( $mail ) ) {
		wp_ja_morgan_form_back( 'j-pages/registration', 'error-exists' );
	}
	$id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => $pass1,
			'user_email'   => $mail,
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => get_option( 'default_role', 'subscriber' ),
		)
	);
	if ( is_wp_error( $id ) ) {
		wp_ja_morgan_form_back( 'j-pages/registration', 'error-exists' );
	}
	if ( isset( $f['profile'] ) && is_array( $f['profile'] ) ) {
		foreach ( array( 'address1', 'address2', 'city', 'region', 'country', 'postal_code', 'phone', 'website', 'favoritebook', 'aboutme', 'dob' ) as $key ) {
			if ( isset( $f['profile'][ $key ] ) ) {
				update_user_meta( $id, 'jm_profile_' . $key, sanitize_textarea_field( $f['profile'][ $key ] ) );
			}
		}
	}
	wp_ja_morgan_form_back( 'j-pages/registration', 'ok' );
}
add_action( 'admin_post_nopriv_jm_register', 'wp_ja_morgan_handle_register' );
add_action( 'admin_post_jm_register', 'wp_ja_morgan_handle_register' );

function wp_ja_morgan_handle_remind() {
	if ( ! isset( $_POST['jm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jm_nonce'] ) ), 'jm_remind' ) ) {
		wp_ja_morgan_form_back( 'username-reminder-request', 'error-token' );
	}
	$mail = sanitize_email( isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '' );
	if ( ! is_email( $mail ) ) {
		wp_ja_morgan_form_back( 'username-reminder-request', 'error-fields' );
	}
	$user = get_user_by( 'email', $mail );
	if ( $user ) {
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your username at %s', 'wp-ja-morgan' ), wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
		/* translators: 1: username 2: sign-in address */
		$body = sprintf( __( 'Your username is %1$s. You can sign in at %2$s', 'wp-ja-morgan' ), $user->user_login, home_url( '/j-pages/login/' ) );
		wp_mail( $mail, $subject, $body );
	}
	// The same answer whether or not the address is known, so the form cannot be used to list accounts.
	wp_ja_morgan_form_back( 'username-reminder-request', 'ok' );
}
add_action( 'admin_post_nopriv_jm_remind', 'wp_ja_morgan_handle_remind' );
add_action( 'admin_post_jm_remind', 'wp_ja_morgan_handle_remind' );

function wp_ja_morgan_handle_contact() {
	if ( ! isset( $_POST['jm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jm_nonce'] ) ), 'jm_contact' ) ) {
		wp_ja_morgan_form_back( 'contact-us', 'error-token' );
	}
	$f       = isset( $_POST['jform'] ) && is_array( $_POST['jform'] ) ? wp_unslash( $_POST['jform'] ) : array();
	$name    = sanitize_text_field( isset( $f['contact_name'] ) ? $f['contact_name'] : '' );
	$mail    = sanitize_email( isset( $f['contact_email'] ) ? $f['contact_email'] : '' );
	$subject = sanitize_text_field( isset( $f['contact_subject'] ) ? $f['contact_subject'] : '' );
	$message = sanitize_textarea_field( isset( $f['contact_message'] ) ? $f['contact_message'] : '' );
	if ( '' === $name || ! is_email( $mail ) || '' === $subject || '' === $message ) {
		wp_ja_morgan_form_back( 'contact-us', 'error-fields' );
	}
	$headers = array( 'Reply-To: ' . $name . ' <' . $mail . '>' );
	$body    = $message . "\n\n" . $name . ' <' . $mail . '>';
	$sent    = wp_mail( get_option( 'admin_email' ), $subject, $body, $headers );
	if ( ! empty( $f['contact_email_copy'] ) ) {
		wp_mail( $mail, $subject, $body );
	}
	wp_ja_morgan_form_back( 'contact-us', $sent ? 'ok' : 'error-send' );
}
add_action( 'admin_post_nopriv_jm_contact', 'wp_ja_morgan_handle_contact' );
add_action( 'admin_post_jm_contact', 'wp_ja_morgan_handle_contact' );

/**
 * The seeded content of the four account pages is a bare sign-in block. The page shows the form the
 * source shows: the pattern that belongs to the page address replaces it when nothing else was written.
 */
add_filter(
	'the_content',
	static function ( $content ) {
		if ( ! is_page() || ! is_main_query() ) {
			return $content;
		}
		$path = trim( (string) get_page_uri( get_queried_object_id() ), '/' );
		$map  = array(
			'j-pages/login'             => 'wp-ja-morgan/account-login',
			'j-pages/registration'      => 'wp-ja-morgan/account-register',
			'password-reset'            => 'wp-ja-morgan/account-reset',
			'username-reminder-request' => 'wp-ja-morgan/account-remind',
			'contact-us'                => 'wp-ja-morgan/contact-form',
		);
		if ( ! isset( $map[ $path ] ) ) {
			return $content;
		}
		if ( false === strpos( $content, 'wp:loginout' ) && '' !== trim( wp_strip_all_tags( $content ) ) && 'contact-us' !== $path ) {
			return $content;
		}
		return do_blocks( '<!-- wp:pattern {"slug":"' . $map[ $path ] . '"} /-->' );
	},
	8
);

/**
 * The masthead description. The source prints the masthead module's default description
 * (mod_jamasthead `default-description`) under the title of every page that has a masthead. The
 * core excerpt block would print the first words of the page body instead, so a page with no
 * excerpt of its own gets that constant text; a hand-written excerpt still wins.
 */
function wp_ja_morgan_masthead_description(): string {
	return __( 'Quisque dolor fringilla semper, libero hendrerit allis, magna augue putate nibh ucibus enim eros acumin arcu', 'wp-ja-morgan' );
}

add_filter(
	'get_the_excerpt',
	static function ( $excerpt, $post ) {
		if ( ! $post || 'page' !== $post->post_type || '' !== trim( (string) $post->post_excerpt ) ) {
			return $excerpt;
		}
		return wp_ja_morgan_masthead_description();
	},
	25,
	2
);

/**
 * The source prints the masthead description as bare text in its `div`; the core excerpt block wraps
 * it in a `p`. Drop that wrapper for the masthead description only, so both sites draw the same element.
 */
add_filter(
	'render_block_core/post-excerpt',
	static function ( string $html, array $block ): string {
		if ( ! str_contains( (string) ( $block['attrs']['className'] ?? '' ), 'ja-masthead-description' ) ) {
			return $html;
		}
		return (string) preg_replace( '#<p class="wp-block-post-excerpt__excerpt">(.*?)</p>#s', '$1', $html, 1 );
	},
	20,
	2
);

/**
 * The search page (templates/search.html) has no post, so its masthead picture is the media
 * library's masthead image, the one every other page's masthead carries as its featured image.
 */
add_filter(
	'render_block_core/cover',
	static function ( string $content, array $block ): string {
		if ( 'search.masthead' !== ( $block['attrs']['metadata']['name'] ?? '' ) ) {
			return $content;
		}
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'name'           => 'bg_masthead',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( ! $found ) {
			return $content;
		}
		$img = wp_get_attachment_image( (int) $found[0], 'full', false, array( 'class' => 'wp-block-cover__image-background', 'alt' => '', 'data-object-fit' => 'cover' ) );
		return (string) preg_replace( '/(<span aria-hidden="true" class="wp-block-cover__background[^>]*><\/span>)/', '${1}' . $img, $content, 1 );
	},
	10,
	2
);

/**
 * Header info row (Call Us / Email Us / Open Hours). The source draws it only in the header of the
 * second home style, so the block stays out of every other page's markup.
 */
add_filter(
	'render_block_core/group',
	static function ( string $content, array $block ): string {
		if ( false === strpos( (string) ( $block['attrs']['className'] ?? '' ), 'jm-head-info' ) ) {
			return $content;
		}
		return is_page() && 'home-style-2' === get_post_field( 'post_name', get_queried_object_id() ) ? $content : '';
	},
	10,
	2
);
