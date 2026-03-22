# Example of adding custom data to the lesson and courses pages

This module demonstrates how to add or modify data prepared for the js app of Anu LMS.

If you want to see this module in action:

- Enable the module on the Drupal's "Extensions" page as usual
- Go to a lesson
- Open browser console
- Execute this js in the console: ```JSON.parse(document.getElementById('anu-application').dataset.application).data```
- You will see ```lesson-example-data: "test"``` added by the module
