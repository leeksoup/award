import React, { useState } from 'react';

import Box from '@material-ui/core/Box';
import Drawer from '@material-ui/core/Drawer';
import CloseIcon from '@material-ui/icons/Close';
import makeStyles from '@material-ui/core/styles/makeStyles';
import KeyboardArrowDownIcon from '@material-ui/icons/KeyboardArrowDown';

import LessonNavigation from '@anu/pages/lesson/Navigation';
import { lessonPropTypes } from '@anu/utilities/transform.lesson';
import { coursePropTypes } from '@anu/utilities/transform.course';
import { getPwaSettings } from '@anu/utilities/settings';
import DownloadCoursePopup from '@anu/components/DownloadCoursePopup';

const useStyles = makeStyles((theme) => ({
  chevron: ({ isVisible }) => ({
    transform: isVisible ? 'rotate(180deg)' : 'rotate(0)',
    transition: '.2s transform',
    fontSize: '1.5rem',
    '&:last-child': {
      marginTop: '-1rem',
    },
  }),
  openSection: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    cursor: 'pointer',
    height: 24,
    padding: theme.spacing(1.5),
  },
  closeIcon: {
    cursor: 'pointer',
  },
}));

const LessonNavigationMobile = ({ lesson, course }) => {
  const [isVisible, toggleVisibility] = useState(false);
  const classes = useStyles({ isVisible });

  return (
    <>
      <Box onClick={() => toggleVisibility(true)} className={classes.openSection}>
        <KeyboardArrowDownIcon className={classes.chevron} />
        <KeyboardArrowDownIcon className={classes.chevron} />
      </Box>

      <Drawer anchor="bottom" open={isVisible} onClose={() => toggleVisibility(false)}>
        {/* Close button to hide the drawer */}
        <Box p={1} display="flex" justifyContent="flex-end">
          <CloseIcon className={classes.closeIcon} onClick={() => toggleVisibility(false)} />
        </Box>
        {course && getPwaSettings() && <DownloadCoursePopup course={course} />}

        {/* Course content */}
        <LessonNavigation course={course} lesson={lesson} />
      </Drawer>
    </>
  );
};

LessonNavigationMobile.propTypes = {
  lesson: lessonPropTypes.isRequired,
  course: coursePropTypes,
};

LessonNavigationMobile.defaultProps = {
  course: null,
};

export default LessonNavigationMobile;
