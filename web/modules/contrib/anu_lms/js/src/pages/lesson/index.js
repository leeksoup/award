import React, { useEffect } from 'react';

import Box from '@material-ui/core/Box';
import Hidden from '@material-ui/core/Hidden';
import makeStyles from '@material-ui/core/styles/makeStyles';

import LessonHeader from '@anu/pages/lesson/Header';
import LessonSidebar from '@anu/pages/lesson/Sidebar';
import ContentQuiz from '@anu/pages/lesson/ContentQuiz';
import ContentLesson from '@anu/pages/lesson/ContentLesson';

import { coursePropTypes } from '@anu/utilities/transform.course';
import { lessonPropTypes } from '@anu/utilities/transform.lesson';
import { quizPropTypes } from '@anu/utilities/transform.quiz';
import LoadingIndicator from '@anu/components/LoadingIndicator';

const useStyles = makeStyles((theme) => ({
  wrapper: {
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
  },
  container: {
    flexGrow: 1,
    display: 'flex',
    flexDirection: 'column',
  },
  contentArea: {
    [theme.breakpoints.up('md')]: {
      flexWrap: 'nowrap',
      flexGrow: 1,
      display: 'flex',
    },
  },
  sidebar: {
    width: 300,
    flexShrink: 0,
    flexGrow: 0,
  },
  content: {
    flexGrow: 1,
    paddingBottom: theme.spacing(8),
  },
}));

const LessonPage = ({ lesson, quiz, course }) => {
  const classes = useStyles();

  const courseContent = (course || {}).content || [];

  const courseSequence = [];
  for (const module of courseContent) {
    courseSequence.push(...module.lessons);

    if (module.quiz) {
      courseSequence.push(module.quiz);
    }
  }

  const content = lesson || quiz;
  const nextLesson = courseSequence[courseSequence.findIndex(({ id }) => id === content.id) + 1];
  const prevLesson = courseSequence[courseSequence.findIndex(({ id }) => id === content.id) - 1];

  // TODO: get URL of the current lesson.
  const fallbackUrl = '/';
  if ('isRestricted' in content) {
    useEffect(() => {
      if (content.isRestricted) {
        window.location.href = fallbackUrl;
      }
    }, [content.isRestricted, fallbackUrl]);
  }

  if (content.isRestricted) {
    return <LoadingIndicator isLoading={true} />;
  }

  return (
    <Box className={classes.wrapper}>
      {/* Header of the lesson page  */}
      {course && (
        <Hidden smDown>
          <LessonHeader lesson={content} course={course} />
        </Hidden>
      )}

      <Box className={classes.container}>
        <Box className={classes.contentArea}>
          {/* Left sidebar visible on tablet + desktop devices only */}
          <Hidden smDown>
            <Box className={classes.sidebar}>
              <LessonSidebar course={course} lesson={content} />
            </Box>
          </Hidden>

          <Box className={classes.content}>
            {quiz && (
              <ContentQuiz
                quiz={quiz}
                nextLesson={nextLesson}
                prevLesson={prevLesson}
                course={course}
              />
            )}

            {lesson && (
              <ContentLesson
                lesson={lesson}
                nextLesson={nextLesson}
                prevLesson={prevLesson}
                course={course}
              />
            )}
          </Box>
        </Box>
      </Box>
    </Box>
  );
};

LessonPage.propTypes = {
  lesson: lessonPropTypes,
  quiz: quizPropTypes,
  course: coursePropTypes,
};

export default LessonPage;
