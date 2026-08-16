/**
 * @file
 * Vanilla JS Drag and Drop implementation with Mobile Touch support.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.lmsDragAndDrop = {
    attach: function (context) {
      once('lms-drag-drop', '.lms-drag-drop-container', context).forEach((container) => {
        const pool = container.querySelector('.draggable-pool');
        const dropZones = container.querySelectorAll('.drop-zone');
        const draggableItems = container.querySelectorAll('.draggable-item');
        const hiddenInput = container.querySelector('[data-lms-selector="drag-drop-answer"]');

        let draggedItem = null;
        let placeholder = null;
        let touchOffsetX = 0;
        let touchOffsetY = 0;
        let currentHoverTarget = null;
        let draggableId = 0;

        /**
         * Updates the hidden input with the current state of answers.
         */
        const updateAnswerState = () => {
          const answer = {};
          dropZones.forEach((zone) => {
            const item = zone.querySelector('.draggable-item');
            if (item) {
              answer[zone.dataset.dropZoneId] = parseInt(item.dataset.draggableId);
            }
          });
          hiddenInput.value = JSON.stringify(answer);
        };

        /**
         * Logic to handle the "Drop".
         *
         * @param {HTMLElement} target - The element dropped onto (zone or pool).
         * @param {HTMLElement} item - The item being dropped.
         */
        const handleDropLogic = (target, item) => {
          const origin = item.parentNode;

          // If dropped on the pool.
          if (target === pool || target.closest('.draggable-pool')) {
            pool.appendChild(item);
            resetItemStyle(item);
            return;
          }

          // If dropped on a drop-zone.
          const zone = target.closest('.drop-zone');
          if (zone) {
            const existingItem = zone.querySelector('.draggable-item');

            // Swap logic.
            if (existingItem && existingItem !== item) {
              if (origin && origin.classList.contains('drop-zone')) {
                origin.appendChild(existingItem);
              }
              else {
                pool.appendChild(existingItem);
              }
            }

            zone.appendChild(item);
            resetItemStyle(item);
          }
          else {
            // Invalid target, return to origin or pool.
            if (origin) {
              origin.appendChild(item);
            }
            else {
              pool.appendChild(item);
            }
            resetItemStyle(item);
          }
        };

        /**
         * Helper to clear inline styles added during drag.
         */
        const resetItemStyle = (item) => {
          item.style.position = '';
          item.style.left = '';
          item.style.top = '';
          item.style.width = '';
          item.classList.remove('is-dragging');
          item.classList.remove('is-placeholder');
        };

        /**
         * Clear drag-over class from the previous hover target.
         */
        const clearCurrentHover = () => {
          if (currentHoverTarget) {
            currentHoverTarget.classList.remove('drag-over');
            currentHoverTarget = null;
          }
        };

        /**
         * Restore state from hidden input on load.
         */
        const restoreState = () => {
          if (!hiddenInput.value) {
            return;
          }
          try {
            const savedState = JSON.parse(hiddenInput.value);
            Object.keys(savedState).forEach((zoneId) => {
              const draggableId = savedState[zoneId];
              const zone = container.querySelector('.drop-zone[data-drop-zone-id="' + zoneId + '"]');
              const item = container.querySelector('.draggable-item[data-draggable-id="' + draggableId + '"]');

              if (zone && item) {
                zone.appendChild(item);
              }
            });
          }
          catch (e) {
            // Fail silently on invalid JSON.
          }
        };

        /**
         * Apply visual feedback (correct/wrong) based on server evaluation.
         */
        const applyFeedback = () => {
          const activityId = container.dataset.activityId;
          if (
            activityId &&
            drupalSettings.lms &&
            drupalSettings.lms.dragAndDrop &&
            drupalSettings.lms.dragAndDrop[activityId] &&
            drupalSettings.lms.dragAndDrop[activityId].feedbackResults
          ) {
            const results = drupalSettings.lms.dragAndDrop[activityId].feedbackResults;
            Object.keys(results).forEach((zoneId) => {
              const zone = container.querySelector('.drop-zone[data-drop-zone-id="' + zoneId + '"]');
              if (zone) {
                if (results[zoneId] === 'correct') {
                  zone.classList.add('correct-answer');
                }
                else if (results[zoneId] === 'wrong') {
                  zone.classList.add('wrong-answer');
                }
              }
            });
          }
        };

        // --- Touch Event Handlers (Mobile) ---

        const onTouchStart = (e) => {
          const target = e.target.closest('.draggable-item');
          if (!target) {
            return;
          }

          draggedItem = target;
          const rect = draggedItem.getBoundingClientRect();
          const touch = e.touches[0];

          touchOffsetX = touch.clientX - rect.left;
          touchOffsetY = touch.clientY - rect.top;

          // Create placeholder to hold space visually in the origin.
          placeholder = draggedItem.cloneNode(true);
          placeholder.classList.add('is-placeholder');
          placeholder.removeAttribute('draggable');
          draggedItem.parentNode.insertBefore(placeholder, draggedItem);

          // Style dragged item to float.
          draggedItem.classList.add('is-dragging');
          draggedItem.style.width = rect.width + 'px';
          draggedItem.style.position = 'fixed';
          draggedItem.style.left = (touch.clientX - touchOffsetX) + 'px';
          draggedItem.style.top = (touch.clientY - touchOffsetY) + 'px';
        };

        const onTouchMove = (e) => {
          if (!draggedItem) {
            return;
          }
          if (e.cancelable) {
            e.preventDefault();
          }

          const touch = e.touches[0];
          draggedItem.style.left = (touch.clientX - touchOffsetX) + 'px';
          draggedItem.style.top = (touch.clientY - touchOffsetY) + 'px';

          // Determine element under the touch point.
          draggedItem.hidden = true;
          const elemBelow = document.elementFromPoint(touch.clientX, touch.clientY);
          draggedItem.hidden = false;

          if (!elemBelow) {
            clearCurrentHover();
            return;
          }

          const droppableBelow = elemBelow.closest('.drop-zone') || elemBelow.closest('.draggable-pool');

          // Only update DOM classes when the hover target changes.
          if (droppableBelow !== currentHoverTarget) {
            clearCurrentHover();
            if (droppableBelow) {
              droppableBelow.classList.add('drag-over');
              currentHoverTarget = droppableBelow;
            }
          }
        };

        const onTouchEnd = (e) => {
          if (!draggedItem) {
            return;
          }

          const touch = e.changedTouches[0];
          draggedItem.hidden = true;
          const elemBelow = document.elementFromPoint(touch.clientX, touch.clientY);
          draggedItem.hidden = false;

          if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.removeChild(placeholder);
          }

          clearCurrentHover();

          if (elemBelow) {
            handleDropLogic(elemBelow, draggedItem);
          }
          else {
            pool.appendChild(draggedItem);
            resetItemStyle(draggedItem);
          }

          draggedItem = null;
          placeholder = null;
          updateAnswerState();
        };

        // --- Mouse Event Handlers (Desktop HTML5 DnD) ---

        draggableItems.forEach((item) => {
          item.setAttribute('draggable', 'true');

          item.addEventListener('dragstart', (e) => {
            draggedItem = item;
            e.dataTransfer.effectAllowed = 'move';
            draggableId = item.dataset.draggableId;
            // Delay adding class so the ghost image is taken from the original.
            setTimeout(() => {
              item.classList.add('is-placeholder');
            }, 0);
          });

          item.addEventListener('dragend', () => {
            if (draggedItem) {
              draggedItem.classList.remove('is-placeholder');
              draggedItem = null;
            }
            clearCurrentHover();
          });
        });

        // Add listeners to potential drop targets (zones and pool).
        const targets = [...dropZones, pool];
        targets.forEach((target) => {
          target.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (currentHoverTarget !== target) {
              clearCurrentHover();
              target.classList.add('drag-over');
              currentHoverTarget = target;
            }
          });

          target.addEventListener('dragleave', (e) => {
            // Only remove if leaving the target entirely, not entering a child.
            if (!target.contains(e.relatedTarget)) {
              if (currentHoverTarget === target) {
                clearCurrentHover();
              }
            }
          });

          target.addEventListener('drop', (e) => {
            e.preventDefault();
            clearCurrentHover();
            const item = container.querySelector('.draggable-item[data-draggable-id="' + draggableId + '"]');
            if (item) {
              handleDropLogic(target, item);
              updateAnswerState();
            }
          });
        });

        // Attach Touch listeners to container (delegation).
        container.addEventListener('touchstart', onTouchStart, { passive: true });
        container.addEventListener('touchmove', onTouchMove, { passive: false });
        container.addEventListener('touchend', onTouchEnd);

        // Run restore and feedback applications.
        restoreState();
        applyFeedback();
      });
    }
  };
})(Drupal, drupalSettings, once);
