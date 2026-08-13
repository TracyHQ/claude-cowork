<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Tracy\Component\ClaudeCowork\Administrator\View\Cowork\HtmlView $this */
?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-6">
        <?php if ($this->token === '') : ?>
            <div class="card">
                <div class="card-body">
                    <div class="alert alert-warning mb-0">
                        <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_NO_TOKEN'); ?>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <div class="card shadow-sm">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="icon-link" aria-hidden="true"></span>
                    <h2 class="card-title mb-0 h4"><?php echo Text::_('COM_CLAUDECOWORK_CONNECT_HEADING'); ?></h2>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?php echo Text::_('COM_CLAUDECOWORK_CONNECT_INTRO'); ?></p>

                    <div class="mb-4">
                        <label for="cowork-token" class="form-label fw-bold">
                            <?php echo Text::_('COM_CLAUDECOWORK_CONFIG_TOKEN_LABEL'); ?>
                        </label>
                        <div class="input-group input-group-lg">
                            <input
                                type="text"
                                id="cowork-token"
                                class="form-control font-monospace"
                                value="<?php echo htmlspecialchars($this->token, ENT_QUOTES, 'UTF-8'); ?>"
                                readonly
                                onclick="this.select()"
                            >
                            <button type="button" class="btn btn-primary px-4" id="cowork-copy-token">
                                <span class="icon-copy me-1" aria-hidden="true"></span>
                                <span class="cowork-copy-label"><?php echo Text::_('COM_CLAUDECOWORK_CONNECT_COPY'); ?></span>
                            </button>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_TOKEN_HINT'); ?>
                        </small>
                    </div>

                    <div class="mb-0">
                        <label for="cowork-endpoint" class="form-label fw-bold">
                            <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_ENDPOINT_LABEL'); ?>
                        </label>
                        <input
                            type="text"
                            id="cowork-endpoint"
                            class="form-control font-monospace"
                            value="<?php echo htmlspecialchars($this->endpoint, ENT_QUOTES, 'UTF-8'); ?>"
                            readonly
                            onclick="this.select()"
                        >
                        <small class="text-muted d-block mt-2">
                            <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_ENDPOINT_HINT'); ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($this->token !== '') : ?>
<script>
    // No framework needed for one button; the copy affordance is the whole point of this screen.
    document.getElementById('cowork-copy-token').addEventListener('click', function () {
        var field = document.getElementById('cowork-token');
        field.select();
        navigator.clipboard.writeText(field.value).then(function () {
            var label = document.querySelector('#cowork-copy-token .cowork-copy-label');
            var original = label.textContent;
            label.textContent = <?php echo json_encode(Text::_('COM_CLAUDECOWORK_CONNECT_COPIED')); ?>;
            setTimeout(function () { label.textContent = original; }, 1500);
        });
    });
</script>
<?php endif; ?>
