# Introduction

The Drupal LMS module provides a flexible framework for building educational platforms on Drupal websites. At the core of this framework is the **Activity-Answer plugin system**, which is built on Drupal's Plugin API and uses modern PHP attributes for discovery. This system enables developers to create custom learning interactions to engage students and assess their understanding.

The plugin-based architecture allows Drupal LMS to be extended with specialized interactions, similar to comparable systems like Canvas, Blackboard, or Moodle, but in a much lighter-weight and more flexible framework.

## Purpose of Activity-Answer Plugins in Drupal LMS

Activity-Answer plugins define how educational content is presented to students and how their responses are evaluated.  Each plugin controls:

-   The content and interactive elements that course creators can add
-   What students see and do with the activity
-   How the activity data is stored
-   How scores are calculated
-   What feedback students get

If you want to extend the functionality of an existing plugin, or develop a new one, you will need to work with most or all of the above functions.  This guide will give you a full understanding of how to do that.

Before going through the process of developing a custom plugin, see if your learning objective could be achieved more easily by combining existing plugins with creative content design.

## Plugin Architecture Overview

The Activity-Answer plugin system is based on Drupal’s Plugin API, and uses PHP attributes for discovery. The core components include:

1.  **Plugin class**: Defines the behavior of the activity and answer, including scoring and feedback  
     
2.  **Configuration files**: Define fields, form displays, and view displays  
     
3.  **Optional field types and widgets**: For specialized data handling and UI requirements  
     

While the LMS module includes other plugin types (Modal Subform plugins, Field plugins, etc.), this guide focuses exclusively on Activity-Answer plugins. For information on other plugin types, refer to the **[Developer’s Guide for Drupal LMS](../developer/index.md)**.

## Core Activity-Answer Plugins

The LMS module includes several core Activity-Answer plugins that you can use as models for your own development:

-   **No Answer**: Built into the module core, for content-only activities  
     
-   **Free Text**: For essay or open-ended questions (manually evaluated)  
     
-   **Free Text with Feedback**: Like Free Text but with automated feedback based on phrase matching  
     
-   **Select**: For multiple-choice questions (radio buttons) and multi-select question (checkboxes)  
     
-   **Select with Feedback**: The interactive version of the Select plugin with automated feedback  
      
-   **True/False**: For binary true/false questions  
     
-   **True/False with Feedback**: True/false with immediate feedback  
     

The “with Feedback” variants provide an interactive learning approach, giving immediate responses to student input and guiding their understanding.  The non-feedback variants silently calculate a score for each question answered, and present a total score at the end of the lesson.

**[Next page: Fundamentals of Activity-Answer Plugins](fundamentals-of-activity-answer-plugins.md)**
