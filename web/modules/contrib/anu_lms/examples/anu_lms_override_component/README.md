# Example of overriding a component from Anu LMS library

This module demonstrates how to override a js component in Anu LMS.

Most files in `./js` folder were copied from `anu_lms/js` as is and are needed just to build the application (which you
need to do if you plan to customize Anu LMS frontend in a custom module). The most important files of this example
module you might be interested in having a closer look are:

- `webpack.config.js`
- `anu_lms_override_component.module`

Pay your attention that this example module does not come with the built version of the Anu LMS js application, so if
you want to actually enable it in Drupal then you need to build the application first:

```
cd examples/anu_lms_override_component/js
npm i
npm run build
```

When the application is built, enable "Anu LMS Example: Component override" module on the Drupal "Extensions"
page as a usual module. The module overrides the heading component, so if you create a lesson with the heading block
you'll see the modified version of the component.
