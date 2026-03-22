import React from 'react';
import PropTypes from 'prop-types';
import Box from '@material-ui/core/Box';
import makeStyles from '@material-ui/core/styles/makeStyles';
import CourseCard from '@anu/components/CourseCard';
import { coursePropTypes } from '@anu/utilities/transform.course';

const useStyles = makeStyles((theme) => ({
  gridWrapper: {
    padding: theme.spacing(2, 2, 11),
    [theme.breakpoints.up('sm')]: {
      paddingBottom: theme.spacing(9),
    },
    [theme.breakpoints.up('md')]: {
      paddingBottom: theme.spacing(8),
    },
  },
  gridContainer: {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(224px, max-content))',
    gap: theme.spacing(2),
  },
  gridItem: {
    width: 600 - theme.spacing(2) * 2, // Breakpoint width minus spacing.
    maxWidth: '100%',
    [theme.breakpoints.up('sm')]: {
      width: 344, // Precisely calculated value for best fit.
      marginBottom: theme.spacing(2),
    },
    [theme.breakpoints.up('md')]: {
      marginBottom: theme.spacing(3),
    },
    [theme.breakpoints.up('lg')]: {
      width: 256,
    },
  },
}));

const CoursesGrid = ({ courses }) => {
  const classes = useStyles();

  return (
    <Box className={classes.gridWrapper} data-test={'anu-lms-courses-list'}>
      <Box className={classes.gridContainer}>
        {courses.map((course) => (
          <Box key={course.id} className={classes.gridItem}>
            <CourseCard course={course} />
          </Box>
        ))}
      </Box>
    </Box>
  );
};

CoursesGrid.propTypes = {
  courses: PropTypes.arrayOf(coursePropTypes),
};

CoursesGrid.defaultProps = {
  courses: [],
};

export default CoursesGrid;
