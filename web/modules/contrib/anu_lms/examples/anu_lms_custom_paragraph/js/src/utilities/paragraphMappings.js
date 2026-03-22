// You need to make sure that path to Anu LMS is correct.
// In your case it will most likely be different.
import paragraphMappings from '../../../../../js/src/utilities/paragraphMappings';

// Import your custom paragraph here.
import CustomParagraph from '../components/CustomParagraph';

export default {
  ...paragraphMappings,
  // Map paragraph bundle name to its component.
  lesson_custom_paragraph_example: CustomParagraph
};
