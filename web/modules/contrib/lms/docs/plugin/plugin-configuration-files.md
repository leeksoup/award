# Plugin Configuration Files

Activity-Answer plugins often need custom fields to store data. These fields are defined through YAML configuration files that are automatically installed when an activity type is created using your plugin.

Your plugin's configuration files define the fields that instructors will populate to create activities. You have complete flexibility in how many and what type of fields to add.

## Configuration Directory Structure

Configuration files for your plugin should be placed in a specific directory structure within your module:

    my_module/
    ├── src/
    ∣ └── Plugin/
    ∣   └── ActivityAnswer/
    ∣     └── MyCustomPlugin.php
    └── config/
      └── activity_answer/
        └── my_custom_plugin/
          ├── core.entity_form_display.lms_activity._bundle_placeholder_.default.yml
          ├── core.entity_view_display.lms_activity._bundle_placeholder_.default.yml
          ├── field.field.lms_activity._bundle_placeholder_.field_name.yml
          └── field.storage.lms_activity.field_name.yml

When the configuration is installed, the `_bundle_placeholder_` string in these files is automatically replaced with the actual activity type machine name. 

The reason the plugin system was designed with placeholders is that each plugin can be reused in multiple Activity Types — when creating a new Activity Type and selecting this plugin, the imported config will have all occurrences of the placeholder replaced with the bundle ID.

The `_bundle_placeholder_` is a token that the LMS module replaces with the machine name of the new Activity Type when your plugin's configuration is installed. This powerful feature allows a single plugin to be used as a template for multiple, distinct Activity Types on a site, each with its own set of fields.

## Required Configuration Files

The following configuration files will be a part of any plugin that needs fields:

1.  **Form display configuration**: Controls how fields appear in the activity creation form (`core.entity_form_display.lms_activity._bundle_placeholder_.default.yml`)  
     
2.  **View display configuration**: Controls how fields appear when viewing an activity (`core.entity_view_display.lms_activity._bundle_placeholder_.default.yml`)  
     
3.  **Field storage definitions**: Define each field’s basic properties  
     
4.  **Field instance definitions**: Attach each field to the activity bundle

## Creating Configuration Files: The Practical Approach

Rather than writing these YAML files from scratch, you can use Drupal’s Configuration Manager to export properly structured configuration. Here’s a detailed step-by-step approach:

1.  **Set up a development environment:**
    -   Install Drupal with the LMS module  
          
    -   Enable the Configuration Manager module at `/admin/modules`: Admin > Extend > select Core / Configuration Manager > Install  
          
2.  **Create a new Activity Type:**
    -   Go to Admin → LMS → Activity Types → Add Activity Type  
          
    -   Give it a meaningful name (e.g., “Plugin Prototype”)  
          
    -   Select “No answer” as the Activity-Answer plugin.  This plugin is built into the core LMS module and is ideal as a blank slate because it has no pre-configured fields.  Or if there is an existing plugin that already contains most of the fields you want, you can use that as a base instead  
          
    -   Save your new activity type  
          
3.  **Add your required fields:**
    -   Go to Admin → LMS → Activity Types  
          
    -   Find your new activity type and in the Operations dropdown, click “Manage fields”  
          
    -   Add all the fields your plugin will need (e.g., question field, answer options)  
          
    -   Configure field settings appropriately  
          
4.  **Configure form and view displays:**
    -   Go to “Manage form display” and arrange fields as needed  
          
    -   Go to “Manage display” and configure how fields should be displayed  
          
5.  **Export the configuration:**
    -   Go to Admin → Configuration → Development → Configuration synchronization → Export  
          
    -   You can either choose "Full archive" export (or use `drush cex`), then from the downloaded archive, selectively extract just the relevant config files for your plugin.  Or choose “Single item” export, and:  
          
        -   From the “Configuration type” dropdown, select the following items one by one:
            -   Field: Select all fields you created (e.g., `field.field.lms_activity.your_activity_type.field_name`)  
                  
            -   Field Storage: Select storage for all your fields (e.g., `field.storage.lms_activity.field_name`)  
                  
            -   Entity Form Display: Select the form display for your activity type (e.g., `core.entity_form_display.lms_activity.your_activity_type.default`)  
                  
            -   Entity View Display: Select the view display for your activity type (e.g., `core.entity_view_display.lms_activity.your_activity_type.default`)  
                  
        -   Copy the exported YAML for each item  
              
6.  **Save configuration files:**
    -   Create the directory structure in your module: `config/activity_answer/my_plugin_id/`  
          
    -   Save each exported configuration as a separate YAML file with the appropriate name  
          
    -   For example:
        -   `core.entity_form_display.lms_activity.your_activity_type.default.yml`  
              
        -   `core.entity_view_display.lms_activity.your_activity_type.default.yml`  
              
        -   `field.field.lms_activity.your_activity_type.field_name.yml`  
              
        -   `field.storage.lms_activity.field_name.yml`  
              
7.  **Replace activity type name with placeholder:**
    -   Open each file in a text or code editor  
          
    -   Replace all occurrences of your activity type machine name (e.g., `your_activity_type`) with `_bundle_placeholder_`  
          
    -   Also rename the files to use `_bundle_placeholder_` instead of your activity type name.  For example: `field.field.lms_activity._bundle_placeholder_.field_name.yml`  
          
8.  **Verify dependencies:**
    -   Check the `dependencies` section in each file  
          
    -   Ensure that module dependencies are correct  
          

This approach ensures that your configuration follows Drupal’s standards, contains all necessary dependencies, and will properly integrate with the plugin installation process.

The export approach described above is recommended instead of writing configuration files from scratch — it’s both easier and less error-prone.

## Example: Field Storage Definition

    # field.storage.lms_activity.question.yml
    status: true
    dependencies:
      module:
        - lms
        - text
    id: lms_activity.question
    field_name: question
    entity_type: lms_activity
    type: text_long
    settings: {  }
    module: text
    locked: true
    cardinality: 1
    translatable: true
    indexes: {  }
    persist_with_no_fields: false
    custom_storage: false

This file defines a long-text field named `question` for storing the activity’s question text.

## Example: Field Instance Definition

    # field.field.lms_activity._bundle_placeholder_.question.yml
    status: true
    dependencies:
      config:
        - field.storage.lms_activity.question
        - lms.lms_activity_type._bundle_placeholder_
      module:
        - text
    id: lms_activity._bundle_placeholder_.question
    field_name: question
    entity_type: lms_activity
    bundle: _bundle_placeholder_
    label: Question
    description: ''
    required: true
    translatable: false
    default_value: {  }
    default_value_callback: ''
    settings:
      allowed_formats: {  }
    field_type: text_long

This file attaches the `question` field to activities of the specified bundle (which will be replaced with your activity type’s machine name).

## Example: Form Display Configuration

    # core.entity_form_display.lms_activity._bundle_placeholder_.default.yml
    status: true
    dependencies:
      config:
        - field.field.lms_activity._bundle_placeholder_.question
        - lms.lms_activity_type._bundle_placeholder_
      module:
        - text
    id: lms_activity._bundle_placeholder_.default
    targetEntityType: lms_activity
    bundle: _bundle_placeholder_
    mode: default
    content:
      name:
        type: string_textfield
        weight: 0
        region: content
        settings:
          size: 60
          placeholder: ''
        third_party_settings: {  }
      question:
        type: text_textarea
        weight: 1
        region: content
        settings:
          rows: 5
          placeholder: ''
        third_party_settings: {  }
    hidden:
      created: true
      uid: true

This file controls how the fields appear in the activity creation form, including field widget types and display order.

## Example: View Display Configuration

    # core.entity_view_display.lms_activity._bundle_placeholder_.default.yml
    status: true
    dependencies:
      config:
        - field.field.lms_activity._bundle_placeholder_.question
        - lms.lms_activity_type._bundle_placeholder_
      module:
        - text
    id: lms_activity._bundle_placeholder_.default
    targetEntityType: lms_activity
    bundle: _bundle_placeholder_
    mode: default
    content:
      question:
        type: text_default
        label: hidden
        settings: {  }
        third_party_settings: {  }
        weight: 0
        region: content
    hidden:
      name: true
      uid: true

This file controls how the fields appear when viewing an activity, including field formatters and display order.

[**Previous page: Required and Optional Methods**](required-and-optional-methods.md)

[**Next page: Custom Field Types and Widgets**](custom-field-types-and-widgets.md)
