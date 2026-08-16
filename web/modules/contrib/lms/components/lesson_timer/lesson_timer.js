/**
 * @file
 * Lesson Timer functionality.
 */

(function ($, Drupal) {

  'use strict';

  /**
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.lms_lesson_timer = {
    attach: function (context, settings) {
      once('lms-timer-init', '[data-lms-close-time]', context).forEach(Drupal.lmsLessonCountdown);
    }
  };

  /**
   * Callback used in {@link Drupal.behaviors.lms_lesson_timer}.
   *
   * @param {object} element
   */
  Drupal.lmsLessonCountdown = function (element) {
    let close_time = element.dataset.lmsCloseTime;

    let x = setInterval(function () {
      // Calculate countdown distance.
      let distance = close_time - Math.floor(new Date().getTime() / 1000);

      // If the count down is finished, write some text
      if (distance < 0) {
        clearInterval(x);
        $(element).find('.time').first().text(Drupal.t('finished'));
        $(element).parent('form').find('[data-drupal-selector="edit-submit"]').click();
        return;
      }

      // Time calculations for days, hours, minutes and seconds.
      let periods = [
        Math.floor(distance / (60 * 60 * 24)),
        Math.floor(distance % (60 * 60 * 24) / (60 * 60)),
        Math.floor(distance % (60 * 60) / 60),
        Math.floor(distance % 60),
      ];

      let timer_fragments = [];
      for (let i = 0; i < 4; i++) {
        if (
          periods[i] === 0 &&
          (i === 0 || periods[i - 1] === 0)
        ) {
          continue;
        }

        if (i === 0) {
          timer_fragments.push(Drupal.formatPlural(periods[i], '1 day', '@count days'));
        }
        else if (i === 1) {
          timer_fragments.push(Drupal.formatPlural(periods[i], '1 hour', '@count hours'));
        }
        else if (i === 2) {
          timer_fragments.push(Drupal.formatPlural(periods[i], '1 minute', '@count minutes'));
        }
        else if (i === 3) {
          timer_fragments.push(Drupal.formatPlural(periods[i], '1 second', '@count seconds'));
        }
      }

      $(element).find('.time').first().text(timer_fragments.join(', '));
    }, 1000);

  }

})(jQuery, Drupal, drupalSettings);