const path = require('path');
// You need to make sure the path to anu lms is correct here.
const anuConfig = require('../../../js/webpack.config.js');
// If your custom module is inside of custom/module_name folder, then your
// path to anu_lms will likely be this:
//const anuConfig = require('../../../contrib/anu_lms/js/webpack.config');

module.exports = {
  // Inherit most of the config from Anu LMS.
  ...anuConfig,
  // Resolve some components differently.
  resolve: {
    modules: [path.resolve(__dirname, 'node_modules')],
    alias: {
      // Overwritten components where we add our custom paragraph type.
      '@anu/utilities/paragraphMappings': path.resolve(__dirname, './src/utilities/paragraphMappings'),
      '@anu/utilities/transform.paragraphs': path.resolve(__dirname, './src/utilities/transform.paragraphs'),
      // Default ANU LMS components.
      // Again, make sure the path to the js app of the Anu LMS module is correct.
      '@anu': path.resolve(__dirname, '../../../js/src'),
      //'@anu': path.resolve(__dirname, '../../../contrib/anu_lms/js/src'),
    },
  },
};
