# Anu LMS

Drupal module which adds E-Learning capabilities with knowledge assessment tools.

## Compatibility

Anu LMS supports the following platforms:

### Desktop Platforms:
- Firefox 53+
- Chrome 49+
- Opera 36+
- Edge 80+

### Mobile Platforms:
- iOS (Chrome, Safari) v14.4+
- Android (Chrome, Firefox) 7+

## How to configure PWA?

PWA stands for "Progressive Web Application". After installing [pwa](https://www.drupal.org/project/pwa) module, Anu LMS
will be able to "make course available offline" without access to internet on a user's device.

To enable offline courses just install and configure "pwa" module. Configurations by default work well with Anu LMS. If
you want to customize any PWA configuration - go to `/admin/config/pwa/settings` page.

## Custom labels for courses / modules / lessons

Anu LMS comes with pre-defined learning terminology for courses, lessons and modules. If these labels don't work for
your case then you can override them at `/admin/config/anu_lms/entity_labels`.

## Development

### Installation

If you don't have a local dev environment yet, we recommend [DDEV](https://ddev.com/) as it comes with all necessary
tooling for Anu LMS development.

1. Install a clean Drupal 9 site ([Instructions for DDEV](https://ddev.readthedocs.io/en/latest/users/cli-usage/#drupal-9-quickstart)).
2. Prepare composer for Anu LMS installation:
  ```
  composer require 'drupal/anu_lms:^2.9'
  ```
3. Enable Anu LMS module and the demo content
```
drush pm:enable anu_lms anu_lms_demo_content
drush cex
```
4. Disable Drupal cache to see your code changes immediately. [Instructions](https://www.drupal.org/node/2598914).
5. Install and configure [PWA](https://www.drupal.org/project/pwa) module to enable Anu offline capabilities.

#### Additional steps for Drupal 10

To get it work on Drupal 10, it requires you to add additional patch in your project/composer.json file:
```
"drupal/rest_entity_recursive": {
"#3356067: Issue with supportsNormalization declaration": "https://www.drupal.org/files/issues/2023-06-19/rest_entity_recursive-3356067-1.patch"
}
```

#### Upgrading to 2.10 from previous versions

Currently, there is no automatic update for the CKeditor 5 module. After the update, it needs to manually enable CKeitor 5 and reconfigure text formats to use it instead of CKeditor 4.

### React development

Node.js & NPM are required dependencies to develop Anu LMS js application (in case if you want to customize the UI).

The React app sources are stored in `./js/src` folder of the module. To prepare React app for development, perform the
following steps:

1. `cd` into `anu_lms/js` foloder
2. Run `npm install`
3. Run `npm run watch`
4. Make changes to JS code. After page refresh you should see your changes in your local Drupal site.
5. When the changes are ready, run `npm run format`, `npm run lint-fix` and `npm run lint` and fix any code styling issues. Some IDEs can handle it automatically.
6. Run `npm run build` to build final JS bundle files.

### Examples

Anu LMS comes with examples on how to extend or modify certain parts of Anu LMS. You will find example modules inside
the `examples` folder.

## Demo content

To install some demo content for learning and testing, enable “Anu LMS Demo content” module.

```
drush pm:enable anu_lms_demo_content
```

Demo courses page will be available at /anu-demo after module installation.

All demo content is deleted on module uninstall.
