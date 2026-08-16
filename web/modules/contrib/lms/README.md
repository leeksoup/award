# Drupal LMS

This module provides functionality to build a learning management system
on your site. It is also the core of the Drupal LMS ecosystem providing base
functionalities and API for other modules.


## Table of contents
- Requirements
- Installation
- Activity - Answer plugin development guidelines


## Requirements

1. Drupal core >= 10.3
1. Group module >= 3.0
1. Subgroup (Graph) module >= 3.0.0-alpha6
1. Group Membership Request module >= 3.2.2


## Installation

For less advanced users it's recommended to use the quick start repository:
https://github.com/graber-1/drupal_lms_ddev, just follow the instructions.

For a clean setup, install as any other module, it's still recommended to
check the quick start repository for configuration examples. Clean install
requires the following to be functional:
* Creation of activity types.
* Configuration of global and group roles and permissions.
* Creation of views to display courses, results etc.
* Configuration of blocks (Course navigation) depending on the selected theme.


## Activity - Answer plugin development guidelines

Every activity has a presentation part that is handled by Drupal Core entity
field and display API but also a question - answer part that is handled by a
question - answer plugin. Such plugins control the following:
1. The way a question form element is displayed for a student when answering
   the question.
1. The logic of calculating score for the given answer and evaluation method.
1. Installation of config required for the plugin (fields that store
   additional data for example).
1. Displaying the given answer on teacher evaluation form if the question
   is manually evaluated.

Recommended plugin development steps:
1. Create a activity type, choose "No answer" as an activity-answer plugin.
1. Manage fields of your new activity type, add whatever may be needed for
   defining your question form element (don't worry about answer storage,
   that's already handled by LMS core).
1. Export config, create a plugin config folder in your project root
   (config/activity_answer/[your_plugin_id]), copy the required config there
1. Replace all occurrences of your newly created activity type ID with the
   `_bundle_placeholder_` string (also in file names).
   The reason for this is that the plugin is supposed to work with any activity
   type, when creating a new activity type and selecting this plugin, the
   imported config will have all occurrences of the placeholder replaced with
   the bundle ID.
1. Create a plugin class (see
   `Drupal\lms\Plugin\ActivityAnswer\ActivityAnswerInterface` and existing
   core plugins in `modules/lms_answer_plugins` for reference).
1. Change Activity - Answer plugin on your new activity type for testing.
