/**
 * @file
 * Sets up the summary for Anu LMS pages condition on block forms.
 */

(function ($, Drupal) {
  'use strict';
  function checkboxesSummary(context) {
    // Determine if the condition has been enabled (the box is checked).
    var conditionChecked = $(context).find('[data-drupal-selector="edit-visibility-anu-lms-pages-show"]:checked').length;
    // Determine if the negate condition has been enabled (the box is checked).
    var negateChecked = $(context).find('[data-drupal-selector="edit-visibility-anu-lms-pages-negate"]:checked').length;

    if (conditionChecked) {
      if (negateChecked) {
        // Both boxes have been checked.
        return Drupal.t("Hide on Anu LMS pages");
      }

      // The condition has been enabled.
      return Drupal.t("Shown on Anu LMS pages");
    }

    // The condition has not been enabled and is not negated.
    return Drupal.t('Not restricted');
  }

  /**
   * Provide the summary information for the block settings vertical tabs.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior for the block settings summaries.
   */
  Drupal.behaviors.blockSettingsSummaryAnuLmsPages = {
    attach: function () {
      // Only do something if the function drupalSetSummary is defined.
      if (jQuery.fn.drupalSetSummary !== undefined) {
        // Set the summary on the vertical tab.
        $('[data-drupal-selector="edit-visibility-anu-lms-pages"]').drupalSetSummary(checkboxesSummary);
      }
    }
  };

}(jQuery, Drupal));
