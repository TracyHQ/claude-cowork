<?php
/**
 * wp-ja-kinetic/kinetic-contact-form — the source's com_contact single-contact body
 * (html/com_contact/contact/default.php + default_address.php + default_form.php), rendered with
 * the element tree the running source prints (measured live 2026-09-23, `/index.php/company/contact`).
 * Posts to this same page (`get_permalink()`), handled by `wp_ja_kinetic_contact_submit()`
 * (inc/extra.php, on `template_redirect`), which sends the message with `wp_mail()` and hands the
 * result back through `wp_ja_kinetic_auth_state()`.
 *
 * Region: the block prints the source's own `div#t4-main-body.t4-section.t4-main-body` wrapper
 * around `#system-message-container` + `.contact`, because on the source the component output IS
 * that T4 section — the page masthead (module 231) and CTA (module 232) sit outside it, in the
 * page-top / page-bottom sections. The page content keeps masthead, this block and CTA as three
 * siblings, so the masthead/CTA stay outside the section the same way. The section's own padding
 * is the page-227.css port in assets/css/wp-ja-kinetic-sections.css (`body.item-227
 * .t4-section.t4-main-body`).
 *
 * Carried as the source prints it, hidden by the same page-227.css rules:
 *  - `.kinetic-contact__name` (the contact record's name), the empty `dl.contact-address` and the
 *    empty `.kinetic-contact__custom` wrapper — the record has no address or custom fields; the
 *    empty `dl` still carries `.kinetic-addr`'s 6px top margin, which collapses through the info
 *    card exactly as on the source.
 *  - the Subject field: hidden in the Kinetic design, submitted with its fixed default value, not
 *    required (the source strips `required` from the input but keeps the label's `required` class
 *    and star).
 *  - the privacy-consent fieldset with its Bootstrap modal shell: the running source wires it to a
 *    Joomla privacy article this port has no record for, so the handler never requires it to send;
 *    the client validator still marks it exactly as the source's `validate.js` does.
 *  - the method e-mail links inside `<joomla-hidden-mail>`, the element the source wraps every
 *    cloaked address in (its script only swaps the same link back in).
 *  - five hidden inputs, the source's own count: the handler flag, `return` (empty, as on the
 *    source), `id` (record id:alias — this page's id and slug here), and the nonce pair.
 *
 * Field ids/classes are the source's (`jform_*`, the duplicated `form-control` class included);
 * field names stay this theme's own, read by the handler.
 *
 * @package wp-ja-kinetic
 */

defined( 'ABSPATH' ) || exit;

$wp_ja_kinetic_state   = wp_ja_kinetic_auth_state();
$wp_ja_kinetic_errors  = array_values( array_filter( (array) ( $wp_ja_kinetic_state['contact_error'] ?? array() ), 'strlen' ) );
$wp_ja_kinetic_success = (bool) ( $wp_ja_kinetic_state['contact_success'] ?? false );
$wp_ja_kinetic_name    = (string) ( $wp_ja_kinetic_state['contact_name'] ?? '' );
$wp_ja_kinetic_email   = (string) ( $wp_ja_kinetic_state['contact_email'] ?? '' );
$wp_ja_kinetic_msg     = (string) ( $wp_ja_kinetic_state['contact_message'] ?? '' );

/** Method icon SVGs, verbatim from the running source (`lucide` outline set, 20x20, currentColor). */
$wp_ja_kinetic_methods = array(
	array(
		'icon'  => '<circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="4"/>',
		'title' => 'Support',
		'desc'  => 'Technical help for active accounts.',
		'email' => 'support@kinetic.dev',
	),
	array(
		'icon'  => '<rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
		'title' => 'Sales',
		'desc'  => 'Pricing, demos and migrations.',
		'email' => 'sales@kinetic.dev',
	),
	array(
		'icon'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'title' => 'Security',
		'desc'  => 'Report a vulnerability responsibly.',
		'email' => 'security@kinetic.dev',
	),
);
?>
<div id="t4-main-body" class="t4-section  t4-main-body">
<?php if ( ! empty( $wp_ja_kinetic_errors ) ) : ?>
	<?php
	/*
	 * Server-side error notice, the markup the source's message layout prints for a failed
	 * submit (measured live: all fields empty, client validation bypassed) — one `.alert-message`
	 * per message, heading text the untranslated type ("danger"), a `<noscript>` fallback.
	 */
	?>
	<div id="system-message-container" aria-live="polite"><noscript><div class="alert alert-danger"><?php echo esc_html( implode( '', $wp_ja_kinetic_errors ) ); ?></div></noscript><joomla-alert type="danger" close-text="Close" dismiss="true" role="alert"><button type="button" class="joomla-alert--close" aria-label="Close"><span aria-hidden="true">&times;</span></button><div class="alert-heading"><span class="danger"></span><span class="visually-hidden">danger</span></div><div class="alert-wrapper"><?php foreach ( $wp_ja_kinetic_errors as $wp_ja_kinetic_error ) : ?><div class="alert-message"><?php echo esc_html( $wp_ja_kinetic_error ); ?></div><?php endforeach; ?></div></joomla-alert></div>
<?php elseif ( $wp_ja_kinetic_success ) : ?>
	<?php echo wp_ja_kinetic_notice( 'success', 'Thank you for your email.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_ja_kinetic_notice() escapes internally. ?>
<?php else : ?>
	<div id="system-message-container" aria-live="polite"></div>
<?php endif; ?>
<div class="contact kinetic-contact" itemscope itemtype="https://schema.org/Person">
	<div class="kinetic-contact__name">
		<span class="contact-name" itemprop="name">Kinetic Support</span>
	</div>

	<div class="hx-split kinetic-contact__grid">

		<aside class="kinetic-contact__info">
			<div class="hx-card">
				<span class="kinetic-eyebrow">// details</span>

				<dl class="contact-address kinetic-addr" itemprop="address" itemscope itemtype="https://schema.org/PostalAddress"></dl>

				<div class="kinetic-contact__custom"></div>

				<div class="kinetic-contact__misc">
					<span class="kinetic-eyebrow">// more</span>
					<div class="contact-misc">
						<div class="hx-methods">
							<?php foreach ( $wp_ja_kinetic_methods as $wp_ja_kinetic_method ) : ?>
								<div class="hx-method">
									<div class="hx-method__icon">
										<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $wp_ja_kinetic_method['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal paths above, not user input. ?></svg>
									</div>
									<div class="hx-method__body">
										<div class="hx-method__title"><?php echo esc_html( $wp_ja_kinetic_method['title'] ); ?></div>
										<div class="hx-method__desc"><?php echo esc_html( $wp_ja_kinetic_method['desc'] ); ?></div>
										<joomla-hidden-mail is-link="1" is-email="1"><a href="<?php echo esc_url( 'mailto:' . $wp_ja_kinetic_method['email'] ); ?>" class="hx-method__email"><?php echo esc_html( $wp_ja_kinetic_method['email'] ); ?></a></joomla-hidden-mail>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</aside>

		<div class="kinetic-contact__formwrap">
			<div class="hx-card hx-card--lg kinetic-contact__formcard">
				<h2 class="hx-form-title">Send us a message</h2>
				<div class="contact-form kinetic-contactform">
					<form id="contact-form" action="<?php echo esc_url( get_permalink() ); ?>" method="post" class="form-validate">
						<fieldset>
							<div class="kinetic-contactform__row">
								<div class="kinetic-contactform__field contact-name">
									<label id="jform_contact_name-lbl" for="jform_contact_name" class="required control-label">Name<span class="star" aria-hidden="true">&#160;*</span></label>
									<input type="text" name="wp_ja_kinetic_contact_name" id="jform_contact_name" value="<?php echo esc_attr( $wp_ja_kinetic_name ); ?>" class="form-control required form-control" size="30" required autocomplete="off">
								</div>
								<div class="kinetic-contactform__field contact-email">
									<label id="jform_contact_email-lbl" for="jform_contact_email" class="required control-label">Email<span class="star" aria-hidden="true">&#160;*</span></label>
									<input type="email" name="wp_ja_kinetic_contact_email" class="form-control validate-email required form-control" id="jform_contact_email" value="<?php echo esc_attr( $wp_ja_kinetic_email ); ?>" size="30" autocomplete="email" required>
								</div>
							</div>

							<div class="kinetic-contactform__field">
								<label id="jform_contact_emailmsg-lbl" for="jform_contact_emailmsg" class="required control-label">Subject<span class="star" aria-hidden="true">&#160;*</span></label>
								<input type="text" name="wp_ja_kinetic_contact_subject" id="jform_contact_emailmsg" value="Website enquiry" class="form-control form-control" size="60" autocomplete="off">
							</div>

							<div class="kinetic-contactform__field contact-mes">
								<label id="jform_contact_message-lbl" for="jform_contact_message" class="required control-label">Message<span class="star" aria-hidden="true">&#160;*</span></label>
								<textarea name="wp_ja_kinetic_contact_message" id="jform_contact_message" cols="50" rows="10" class="form-control required form-control" required autocomplete="off"><?php echo esc_textarea( $wp_ja_kinetic_msg ); ?></textarea>
							</div>

							<fieldset class="default dynamic-fields kinetic-contactform__field">
								<div class="control-group">
									<div class="control-label">
										<label id="jform_consentbox-lbl" for="jform_consentbox" class="required"><a href="#modal-jform_consentbox" data-bs-toggle="modal">Privacy Note</a><span class="star" aria-hidden="true">&#160;*</span></label>
									</div>
									<div class="controls">
										<div id="modal-jform_consentbox" role="dialog" tabindex="-1" class="joomla-modal modal fade">
											<div class="modal-dialog modal-lg jviewport-width80">
												<div class="modal-content">
													<div class="modal-header">
														<h3 class="modal-title">Privacy Note</h3>
														<button type="button" class="btn-close novalidate" data-bs-dismiss="modal" aria-label="Close"></button>
													</div>
													<div class="modal-body jviewport-height70"></div>
												</div>
											</div>
										</div>
										<fieldset id="jform_consentbox" class="required checkboxes" required>
											<div class="form-check form-check-inline">
												<label for="jform_consentbox0" class="form-check-label"><input type="checkbox" id="jform_consentbox0" name="wp_ja_kinetic_contact_consent" value="0" class="form-check-input"> By submitting this form you agree to the Privacy Policy of this website and the storing of the submitted information.</label>
											</div>
										</fieldset>
									</div>
								</div>
							</fieldset>

							<div class="kinetic-contactform__actions">
								<button class="hx-btn hx-btn--primary validate" type="submit">Send Email</button>
							</div>

							<input type="hidden" name="wp_ja_kinetic_contact" value="1">
							<input type="hidden" name="return" value="">
							<input type="hidden" name="id" value="<?php echo esc_attr( get_the_ID() . ':' . get_post_field( 'post_name' ) ); ?>">
							<?php wp_nonce_field( 'wp_ja_kinetic_contact', 'wp_ja_kinetic_nonce' ); ?>
						</fieldset>
					</form>
				</div>
			</div>
		</div>

	</div>
</div>
</div>
