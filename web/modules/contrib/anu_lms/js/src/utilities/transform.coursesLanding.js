import PropTypes from 'prop-types';
import { transformCourse, coursePropTypes } from '@anu/utilities/transform.course';
import {
  transformCourseCategory,
  courseCategoryPropTypes,
} from '@anu/utilities/transform.courseCategory';
import { transformCourseTopic, courseTopicPropTypes } from '@anu/utilities/transform.courseTopic';
import * as fields from '@anu/utilities/fields';

const transformCoursesLandingPage = ({ data }) => {
  const node = data.courses_page || {};

  return {
    title: fields.getTextValue(node, 'title'),
    url: fields.getNodeUrl(node),
    courses: fields.getArrayValue(data, 'courses').map((item) => transformCourse(item, data)),
    categories: fields
      .getArrayValue(node, 'field_courses_page_categories')
      .map((category) => transformCourseCategory(category)),
    topics: fields
      .getArrayValue(node, 'field_courses_page_topics')
      .map((topic) => transformCourseTopic(topic)),
  };
};

/**
 * Define expected prop types for courses page.
 */
const CoursesLandingPagePropTypes = PropTypes.shape({
  title: PropTypes.string.isRequired,
  url: PropTypes.string.isRequired,
  courses: PropTypes.arrayOf(coursePropTypes),
  categories: PropTypes.arrayOf(courseCategoryPropTypes),
  topics: PropTypes.arrayOf(courseTopicPropTypes),
});

export { transformCoursesLandingPage, CoursesLandingPagePropTypes };
