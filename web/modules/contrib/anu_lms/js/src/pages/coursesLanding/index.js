import React from 'react';
import PropTypes from 'prop-types';
import { useLocation } from 'react-router-dom';
import CoursesLandingPageTemplate from '@anu/pages/coursesLanding/PageTemplate';
import { courseCategoryPropTypes } from '@anu/utilities/transform.courseCategory';
import { courseTopicPropTypes } from '@anu/utilities/transform.courseTopic';
import { coursePropTypes } from '@anu/utilities/transform.course';

const CoursesLandingPage = ({ title, courses, categories, topics }) => {
  // Get filter values from the URL.
  const urlQuery = new URLSearchParams(useLocation().search);
  const categoriesFilterValue = urlQuery.get('category') || 'all';
  const topicsFilterValue = urlQuery.get('topic') || 'all';

  // First filtering courses by category.
  const coursesInSelectedCategory =
    categoriesFilterValue === 'all'
      ? courses
      : courses.filter((course) =>
          course.categories.some((courseCategory) => courseCategory.id === +categoriesFilterValue)
        );
  // Then additionally filtering courses by topic.
  const filteredCourses =
    topicsFilterValue === 'all'
      ? coursesInSelectedCategory
      : coursesInSelectedCategory.filter((course) =>
          course.topics.some((courseTopic) => courseTopic.id === +topicsFilterValue)
        );
  // Filter out categories which have no courses in them based on topics filter.
  const categoriesWithCourses =
    topicsFilterValue === 'all'
      ? categories
      : categories.filter((category) =>
          filteredCourses.some((course) =>
            course.categories.some((courseCategory) => courseCategory.id === category.id)
          )
        );
  // Filter out categories which have no topics in them based on categories filter.
  const topicsWithCourses =
    categoriesFilterValue === 'all'
      ? topics
      : topics.filter((topic) =>
          filteredCourses.some((course) =>
            course.topics.some((courseTopic) => courseTopic.id === topic.id)
          )
        );

  // Sorting by weight.
  const sortedCategories = categoriesWithCourses.sort(
    (category1, category2) => category1.weight - category2.weight
  );
  const sortedTopics = topicsWithCourses.sort((topic1, topic2) => topic1.weight - topic2.weight);
  const sortedCourses = filteredCourses.sort((course1, course2) => {
    // Courses without categories should be displyed at the end of the list.
    if (course1.categories.length === 0 && course2.categories.length === 0) return 0;
    else if (course1.categories.length === 0 && course2.categories.length !== 0) return 1;
    else if (course1.categories.length !== 0 && course2.categories.length === 0) return -1;
    else return course1.categories[0].weight - course2.categories[0].weight;
  });

  return (
    <CoursesLandingPageTemplate
      pageTitle={title}
      courses={sortedCourses}
      categories={sortedCategories}
      topics={sortedTopics}
      categoriesFilterValue={categoriesFilterValue}
      topicsFilterValue={topicsFilterValue}
    />
  );
};

CoursesLandingPage.propTypes = {
  title: PropTypes.string.isRequired,
  courses: PropTypes.arrayOf(coursePropTypes),
  categories: PropTypes.arrayOf(courseCategoryPropTypes),
  topics: PropTypes.arrayOf(courseTopicPropTypes),
};

CoursesLandingPage.defaultProps = {
  courses: [],
  categories: [],
  topics: [],
};

export default CoursesLandingPage;
