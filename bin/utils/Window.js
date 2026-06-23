/**
 * Shared helpers for the tag / tag group create & edit windows.
 *
 * Provides keyboard submit shortcuts, the discreet shortcut hint and
 * inline field validation, so the tag window and the tag group window
 * behave consistently.
 *
 * @module package/quiqqer/tags/bin/utils/Window
 * @author www.pcsg.de
 */
define('package/quiqqer/tags/bin/utils/Window', [

    'Locale',

    'css!package/quiqqer/tags/bin/utils/Window.css'

], function (QUILocale) {
    "use strict";

    const lg = 'quiqqer/tags';

    const isMac  = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
    const modKey = isMac ? 'Cmd' : 'Strg';

    return {

        /**
         * Bind submit shortcuts to a window.
         *
         * Enter in a single-line input submits the window. In the
         * textarea Enter stays a newline, only Cmd/Ctrl+Enter submits.
         *
         * @param {Object} Win - QUIConfirm instance
         * @param {Array} inputs - single-line input elements
         * @param {HTMLElement} [Textarea] - optional description textarea
         */
        bindSubmitShortcuts: function (Win, inputs, Textarea) {
            const submitOnEnter = function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    Win.submit();
                }
            };

            inputs.forEach(function (Input) {
                if (Input) {
                    Input.addEventListener('keydown', submitOnEnter);
                }
            });

            if (Textarea) {
                Textarea.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                        event.preventDefault();
                        Win.submit();
                    }
                });
            }
        },

        /**
         * Inject the discreet keyboard shortcut hint at the bottom of the
         * window content.
         *
         * @param {HTMLElement} Content - window content node
         * @return {HTMLElement} the injected hint container
         */
        injectShortcutHint: function (Content) {
            const Hints = document.createElement('div');

            Hints.className = 'quiqqer-tags-windowHints';
            Hints.setAttribute('data-name', 'shortcuts');
            Hints.setAttribute('aria-label', QUILocale.get(lg, 'panel.add.window.shortcuts.label'));

            [
                {
                    keys : ['Enter'],
                    label: QUILocale.get(lg, 'panel.add.window.shortcuts.save')
                },
                {
                    keys : [modKey, 'Enter'],
                    label: QUILocale.get(lg, 'panel.add.window.shortcuts.save.description')
                }
            ].forEach(function (entry) {
                const Hint = document.createElement('div');
                Hint.className = 'quiqqer-tags-windowHint';

                const Keys = document.createElement('span');
                Keys.className = 'quiqqer-tags-windowHintKeys';
                Keys.setAttribute('aria-hidden', 'true');

                entry.keys.forEach(function (key, index) {
                    const Kbd = document.createElement('kbd');
                    Kbd.textContent = key;
                    Keys.appendChild(Kbd);

                    if (index < entry.keys.length - 1) {
                        const Sep = document.createElement('span');
                        Sep.className = 'quiqqer-tags-windowHintSep';
                        Sep.textContent = '+';
                        Keys.appendChild(Sep);
                    }
                });

                const Text = document.createElement('span');
                Text.className = 'quiqqer-tags-windowHintText';
                Text.textContent = entry.label;

                Hint.appendChild(Keys);
                Hint.appendChild(Text);
                Hints.appendChild(Hint);
            });

            Content.appendChild(Hints);

            return Hints;
        },

        /**
         * Show an inline field error and focus the field.
         *
         * @param {HTMLElement} ErrorNode - the error message node
         * @param {HTMLElement} Input - the related input
         */
        showFieldError: function (ErrorNode, Input) {
            if (ErrorNode) {
                ErrorNode.hidden = false;
            }

            if (Input) {
                Input.classList.add('quiqqer-tags-windowInput--error');
                Input.setAttribute('aria-invalid', 'true');
                Input.focus();
            }
        },

        /**
         * Hide an inline field error.
         *
         * @param {HTMLElement} ErrorNode - the error message node
         * @param {HTMLElement} Input - the related input
         */
        hideFieldError: function (ErrorNode, Input) {
            if (ErrorNode) {
                ErrorNode.hidden = true;
            }

            if (Input) {
                Input.classList.remove('quiqqer-tags-windowInput--error');
                Input.removeAttribute('aria-invalid');
            }
        }
    };
});
