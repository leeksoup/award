import { getTextValue } from '@anu/utilities/fields';
// You need to make sure that path to Anu LMS is correct.
// In your case it will most likely be different.
import { transformParagraph as originalTransformParagraph } from '../../../../../js/src/utilities/transform.paragraphs';

/**
 * Custom transformation of all paragraph types.
 */
const transformParagraph = (paragraph, pageIndex = 0) => {
  const block = originalTransformParagraph(paragraph, pageIndex);
  if (!block) {
    return null;
  }

  // Process your custom paragraph here.
  // Properties returned at this stage will be available in the component.
  if (block.bundle === 'lesson_custom_paragraph_example') {
    return {
      // Bundle and id are mandatory attributes.
      bundle: block.bundle,
      id: block.id,
      // Any custom fields go here.
      text: getTextValue(paragraph, 'field_example_field'),
    };
  }

  return block;
};

export { transformParagraph };
