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
<div class="p-3" style="max-width: 720px;">
    <?php if ($this->token === '') : ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_NO_TOKEN'); ?>
        </div>
    <?php else : ?>
        <p class="mb-3"><?php echo Text::_('COM_CLAUDECOWORK_CONNECT_INTRO'); ?></p>

        <div class="mb-4">
            <label for="cowork-token" class="form-label fw-bold">
                <?php echo Text::_('COM_CLAUDECOWORK_CONFIG_TOKEN_LABEL'); ?>
            </label>
            <div class="input-group">
                <input
                    type="text"
                    id="cowork-token"
                    class="form-control font-monospace"
                    value="<?php echo htmlspecialchars($this->token, ENT_QUOTES, 'UTF-8'); ?>"
                    readonly
                    onclick="this.select()"
                >
                <button type="button" class="btn btn-primary" id="cowork-copy-token">
                    <span class="icon-copy" aria-hidden="true"></span>
                    <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_COPY'); ?>
                </button>
            </div>
            <small class="text-muted"><?php echo Text::_('COM_CLAUDECOWORK_CONNECT_TOKEN_HINT'); ?></small>
        </div>

        <div class="mb-2">
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
        </div>
    <?php endif; ?>
</div>

<?php if ($this->token !== '') : ?>
<script>
    // No framework needed for one button; the copy affordance is the whole point of this screen.
    document.getElementById('cowork-copy-token').addEventListener('click', function () {
        var field = document.getElementById('cowork-token');
        field.select();
        navigator.clipboard.writeText(field.value).then(function () {
            var button = document.getElementById('cowork-copy-token');
            var original = button.innerHTML;
            button.innerHTML = <?php echo json_encode(Text::_('COM_CLAUDECOWORK_CONNECT_COPIED')); ?>;
            setTimeout(function () { button.innerHTML = original; }, 1500);
        });
    });
</script>
<?php endif; ?>
