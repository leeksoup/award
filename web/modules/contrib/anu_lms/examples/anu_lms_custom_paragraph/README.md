# Example of extending Anu LMS with a custom paragraph

This module demonstrates how to add a new custom paragraph to Anu LMS.

Most files in `./js` folder were copied from `anu_lms/js` as is and are needed just to build the application (which you
need to do if you plan to customize Anu LMS frontend in a custom module). The most important files of this example
module you might be interested in having a closer look are:

- `webpack.config.js`
- `anu_lms_override_component.module`
- `src/utilities/paragraphMappings.js`
- `src/utilities/transform.paragraphs.js`

Pay your attention that this example module does not come with the built version of the Anu LMS js application, so if
you want to actually enable it in Drupal then you need to build the application first:

```
cd examples/anu_lms_custom_paragraph/js
npm i
npm run build
```

When the application is built, enable "Anu LMS Example: Custom paragraph" module on the Drupal "Extensions"
page as a usual module. The module adds a new paragraph type called "Block: Custom paragraph example". Add a new lesson
with this paragraph type, and you'll see it rendered.
