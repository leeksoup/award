# Integration and Deployment

Once you’ve developed an Activity-Answer plugin, you need to integrate it into a module and deploy it. This section covers the essential steps for successfully deploying your plugin.

With Drupal LMS, your custom plugins can leverage Drupal's entire ecosystem of modules for media handling, accessibility, and internationalization.

## Minimum Module Requirements

Unless you're contributing your plugin to the core LMS module, you'll need it to be included in a custom module. At minimum, you'll need:

### 1\. Module Info File (.info.yml)

    # my_lms_plugins.info.yml
    name: My LMS Plugins
    type: module
    description: 'Provides custom Activity-Answer plugins for the Drupal LMS module.'
    core_version_requirement: ^10.3 || ^11
    package: LMS
    dependencies:
      - drupal:text
      - lms:lms # This dependency is required for your plugins to be discovered.

This file defines your module and its dependencies. Note that your module must depend on the LMS module in order for it to discover your plugins.  
 

### 2\. Module File (.module)

    <?php
    
    /**
     * @file
     * My LMS Plugins module.
     */
    
    declare(strict_types=1);
    
    /**
     * Implements hook_theme().
     */
    function my_lms_plugins_theme() {
      return [];
    }

While a .module file is not strictly required if you don’t need any hooks, it’s a good practice to include it. If your plugin includes custom theming, you’ll need to implement `hook_theme()`.  
 

## File Structure

Organize your module with this structure.  The Field directory and its sub-directories are only needed if your plugin defines any custom field types and widgets:

    my_lms_plugins/
     ├── my_lms_plugins.info.yml
     ├── my_lms_plugins.module
     │
     ├── src/
     │ └── Plugin/
     │   │
     │   ├── ActivityAnswer/
     │   │ └── MyCustomPlugin.php
     │   │
     │   └── Field/
     │     │
     │     ├── FieldType/
     │     │ └── MyCustomField.php
     │     │
     │     └── FieldWidget/
     │       └── MyCustomFieldWidget.php
     │
     └── config/
      └── activity_answer/
       └── my_custom_plugin/
        ├── field.field.lms_activity.
        │   _bundle_placeholder_.field_name.yml
        ├── field.storage.lms_activity.field_name.yml
        └── ...

## Testing and Debugging

Before deploying your plugin, thorough testing is essential:

1.  **Manual Testing**:
    
    -   Create an activity type using your plugin
    -   Create activities with various configurations
    -   Test the student experience by taking activities
    -   Test the instructor experience by evaluating answers (if applicable)  
         
2.  **Using Drush for Testing**: The LMS module provides a Drush command, `lms:create-test-content`, which is invaluable for quickly setting up a consistent testing environment:
    
        drush lms:create-test-content --module=my_lms_plugins
    
    This command will automatically discover and import any YAML files (for users, courses, lessons, and activities) found in your module's `tests/data` directory.  It streamlines the testing process by allowing you to define a full set of test content declaratively.  See the **[Testing section of the Developer's Guide](../developer/testing-and-deployment.md#writing-tests-for-custom-functionality)** for more information.  
      
    
3.  **Debugging Techniques**:
    
    -   **Check Drupal's Logs:** For PHP errors, always check the recent log messages at **Administration > Reports > Recent log messages** (`/admin/reports/dblog`).
    -   **Use Xdebug:** For complex issues, using a step-through debugger like Xdebug with your IDE is the most powerful way to inspect code execution.
    -   **Use Devel/Kint:** If you have the Devel module installed, you can use functions like `dpm()` or `kint()` inside your plugin's methods to inspect variables. For example, place `dpm($form_state->getValues());` inside your AJAX callback to see what data is being submitted.
    -   For AJAX issues: Check the browser’s console and network tabs
    -   For configuration issues: Check the configuration synchronization screen  
          

**Remember to Clear Caches!**  
  
Drupal's plugin system uses a cache to discover plugins. After you create a new Activity-Answer plugin class file, you **must** clear Drupal's caches (e.g., by running `drush cr`) for it to appear in the list of available plugins on the Activity Type creation page.

## Installation and Updates

When your module is installed:

1.  The plugin will be discovered based on its attribute
2.  Activity types can be created using your plugin
3.  Configuration for the plugin will be installed when an activity type is created

If you update your plugin:

1.  Plugin code changes will take effect immediately; it's always recommended to clear caches after any update to make sure you're working with fresh data: `ddev drush cr`
2.  Configuration changes require updating existing activity types
3.  Data structure changes will require data migration if there are existing activities built with the plugin

It's always a good idea to write up some documentation for your plugin, while it's still fresh in your mind — including configuration options and examples of use cases.

[**Previous page: AJAX Implementation**](ajax-implementation.md)

[**Next page: Reference: Existing Plugins**](reference-existing-plugins.md)
