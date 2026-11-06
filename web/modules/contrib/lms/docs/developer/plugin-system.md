# Plugin System

The LMS module uses a plugin-based architecture to support different types of activities and interactions:

## Activity-Answer Plugins

The ActivityAnswer plugin system defines how different activity types:

-   Present questions to students 
-   Structure data to store questions, answers, and other settings
-   Evaluate responses (automatically or manually) and provide scores
-   Display answers for teacher evaluation

Each activity type in the system corresponds to a specific ActivityAnswer plugin, and some plugins are capable of providing more than one activity type.

Refer to the **[Plugin Developer's Guide for Drupal LMS](../plugin/index.md)** for in-depth guidance on building or customizing these plugins.  
 

To see the available core activity-answer plugins, check the  
`modules/lms_answer_plugins/src/Plugin/ActivityAnswer` directory. The one exception is the `NoAnswer` plugin, which ships in the main module at `src/Plugin/ActivityAnswer/NoAnswer.php`.

Or to get all registered plugins programmatically, use the   
`$activity_answer_manager->getDefinitions()` method.

## Modal Subform Plugins

The module uses modal subform plugins to handle the logic for forms that appear in modal dialogs, such as the forms for creating a new lesson or configuring activity parameters from within another form. These are used for:

-   Activity creation and editing in modals 
-   Configuration forms for activities and lessons 
-   Evaluation interfaces

[See the **Modal Subform section in the Forms page** for more details.](form-system.md#the-modal-form-system)

## Field Type Plugins

Custom field types include:

-   `LMSReferenceItem`: Extends entity references with contextual settings. [See the full description on the Entity Structure page.](entity-structure.md#lmsreferenceitem)  
      
-   `StartLinkFieldItemList`: A computed field for course start links. [See its full description here.](entity-structure.md#startlinkfielditemlist)  
     

**[Previous page: Entity Structure](entity-structure.md)**

**[Next page: Form System](form-system.md)**
