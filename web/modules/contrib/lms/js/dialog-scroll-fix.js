/**
 * @file
 * Prevents unwanted scroll when closing a modal dialog.
 *
 * ROOT CAUSE
 * ==========
 * When a modal form is saved, the AJAX response first replaces the widget DOM
 * (replaceCommand) and then closes the dialog (closeDialog — always last).
 * The button that opened the dialog no longer exists in the DOM by the time
 * jQuery UI tries to restore focus to it, so focus falls to document.body.
 * dialog.ajax.js then focuses the first focusable element in the container,
 * causing an unwanted scroll.
 *
 * FIX
 * ===
 * 1. On mousedown (capture phase, before beforeSend disables the input), save
 *    the triggering input's data-drupal-selector as pendingOpenerSelector.
 * 2. On dialog:beforecreate, store that selector on the dialog element and
 *    focus the input so jQuery UI records it as this.opener.
 * 3. On dialog:afterclose, look up the button by data-drupal-selector in the
 *    current DOM — which already contains the freshly-rendered widget — and
 *    focus it with preventScroll: true. Since the dialog is always closed last,
 *    no timing workarounds are needed.
 *
 * @todo Track https://www.drupal.org/project/drupal/issues/3579458.
 */

(function (Drupal, once) {

  'use strict';

  // data-drupal-selector of the input that triggered the most recent AJAX
  // request. Saved on mousedown, before beforeSend disables the input.
  let pendingOpenerSelector = null;

  /**
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.lmsDialogScrollFix = {
    attach: function (context, settings) {
      once('lms-dialog-scroll-fix', 'html', context).forEach(function () {

        // Capturing listener fires before jQuery/Drupal handlers and before
        // beforeSend disables the input.
        document.addEventListener('mousedown', function (e) {
          if (e.target.matches('input[type="submit"]')) {
            pendingOpenerSelector = e.target.getAttribute('data-drupal-selector');
          }
        }, true);

        // dialog:beforecreate fires inside commandExecutionQueue after
        // ajax.js line 1074 has already re-enabled the input.
        // Store the opener selector on the dialog element for use on close,
        // and focus the input so jQuery UI records it as this.opener.
        document.addEventListener('dialog:beforecreate', function (e) {
          if (!pendingOpenerSelector) {
            return;
          }
          e.target.dataset.lmsOpenerSelector = pendingOpenerSelector;
          const opener = document.querySelector('[data-drupal-selector="' + pendingOpenerSelector + '"]');
          if (opener) {
            opener.focus({ preventScroll: true });
          }
          pendingOpenerSelector = null;
        });

        // dialog:afterclose fires after the widget replacement AJAX command
        // has already run (closeDialog is always the last command). Look up
        // the button by selector in the refreshed DOM and refocus it,
        // overriding whatever jQuery UI or dialog.ajax.js did scroll to the
        // element this time as the default scrolling behavior is quite random.
        document.addEventListener('dialog:afterclose', function (e) {
          const openerSelector = e.target.dataset.lmsOpenerSelector;
          if (!openerSelector) {
            return;
          }
          const opener = document.querySelector('[data-drupal-selector="' + openerSelector + '"]');
          if (opener) {
            opener.focus({ preventScroll: false });
          }
        });

      });
    }
  };

})(Drupal, once);
