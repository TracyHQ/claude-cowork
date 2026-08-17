<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var ClaudeCoworkViewCowork $this */

// Deliberately framework-neutral markup with inline styles. Joomla 3's admin template ships
// Bootstrap 2, where the card / input-group classes the Joomla 4 screen uses render as nothing;
// plain elements sized here look the same on any backend template.
?>
<div style="max-width: 680px; margin: 1em auto;">
    <?php if ($this->token === '') : ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_NO_TOKEN'); ?>
        </div>
    <?php else : ?>
        <p><?php echo Text::_('COM_CLAUDECOWORK_CONNECT_INTRO'); ?></p>

        <div style="margin-bottom: 1.5em;">
            <label for="cowork-token" style="font-weight: bold; display: block; margin-bottom: 0.3em;">
                <?php echo Text::_('COM_CLAUDECOWORK_CONFIG_TOKEN_LABEL'); ?>
            </label>
            <div style="display: flex; gap: 0.5em;">
                <input
                    type="text"
                    id="cowork-token"
                    value="<?php echo htmlspecialchars($this->token, ENT_QUOTES, 'UTF-8'); ?>"
                    readonly
                    onclick="this.select()"
                    style="flex: 1; font-family: monospace; padding: 0.5em; font-size: 1.05em;"
                >
                <button type="button" class="btn btn-primary" id="cowork-copy-token" style="white-space: nowrap;">
                    <span class="cowork-copy-label"><?php echo Text::_('COM_CLAUDECOWORK_CONNECT_COPY'); ?></span>
                </button>
            </div>
            <small style="color: #777; display: block; margin-top: 0.4em;">
                <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_TOKEN_HINT'); ?>
            </small>
        </div>

        <div>
            <label for="cowork-endpoint" style="font-weight: bold; display: block; margin-bottom: 0.3em;">
                <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_ENDPOINT_LABEL'); ?>
            </label>
            <input
                type="text"
                id="cowork-endpoint"
                value="<?php echo htmlspecialchars($this->endpoint, ENT_QUOTES, 'UTF-8'); ?>"
                readonly
                onclick="this.select()"
                style="width: 100%; font-family: monospace; padding: 0.5em; box-sizing: border-box;"
            >
            <small style="color: #777; display: block; margin-top: 0.4em;">
                <?php echo Text::_('COM_CLAUDECOWORK_CONNECT_ENDPOINT_HINT'); ?>
            </small>
        </div>

        <script>
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
</div>
