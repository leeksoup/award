import PropTypes from 'prop-types';
import * as fields from '@anu/utilities/fields';

/**
 * Transform course topic term from Drupal backend
 * into frontend-friendly object.
 */
const transformCourseTopic = (term) => {
  // Make sure the term data exists.
  if (!fields.getNumberValue(term, 'tid')) {
    return null;
  }

  const transformedData = {
    id: fields.getNumberValue(term, 'tid'),
    title: fields.getTextValue(term, 'name'),
    weight: fields.getNumberValue(term, 'weight'),
  };

  return transformedData;
};

/**
 * Define expected prop types for course topic.
 */
const courseTopicPropTypes = PropTypes.shape({
  id: PropTypes.number.isRequired,
  title: PropTypes.string.isRequired,
  weight: PropTypes.number.isRequired,
});

export { transformCourseTopic, courseTopicPropTypes };
