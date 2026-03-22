import React, { useState } from 'react';
import PropTypes from 'prop-types';
import Box from '@material-ui/core/Box';
import Typography from '@material-ui/core/Typography';
import Hidden from '@material-ui/core/Hidden';
import IconButton from '@material-ui/core/IconButton';
import FilterListIcon from '@material-ui/icons/FilterList';
import CloseIcon from '@material-ui/icons/Close';
import makeStyles from '@material-ui/core/styles/makeStyles';

import Slide from '@material-ui/core/Slide';
import Dialog from '@material-ui/core/Dialog';
import CoursesGrid from '@anu/pages/coursesLanding/CoursesGrid';
import CoursesFilters from '@anu/pages/coursesLanding/CoursesFilters';
import { courseCategoryPropTypes } from '@anu/utilities/transform.courseCategory';
import { courseTopicPropTypes } from '@anu/utilities/transform.courseTopic';
import { coursePropTypes } from '@anu/utilities/transform.course';
import { getEntityLabel } from '@anu/utilities/helpers';

const useStyles = makeStyles((theme) => ({
  wrapper: {
    background: theme.palette.grey[100],
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
  },
  contentWrapper: {
    flexGrow: 1,
    display: 'flex',
    [theme.breakpoints.up('lg')]: {
      gap: theme.spacing(4),
    },
  },
  sidebar: {
    width: 268,
    flex: '0 0 auto',
    padding: theme.spacing(4, 2),
    [theme.breakpoints.up('lg')]: {
      width: 316,
    },
  },
  content: {
    flexGrow: 1,
  },
  header: {
    background: theme.palette.grey[200],
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: theme.spacing(4),
    borderBottom: '2px solid ' + theme.palette.common.black,
    padding: theme.spacing(1, 3),
    minHeight: 58,
  },
  contentHeader: {
    padding: theme.spacing(4, 2, 0),
    '& .MuiTypography-root': {
      display: 'block',
      color: theme.palette.common.black,
      borderBottom: '1px solid ' + theme.palette.grey[300],
      paddingBottom: theme.spacing(1),
    },
  },
  filterButton: {
    margin: theme.spacing(-0.5, -1),
    padding: theme.spacing(1),
    '& .MuiIconButton-label': {
      flexDirection: 'column',
      color: theme.palette.common.black,
      width: 32,
      height: 32,
    },
    '& .MuiTypography-root': {
      fontSize: '0.625rem',
      lineHeight: '1em',
      textTransform: 'uppercase',
      color: theme.palette.common.black,
    },
  },
  dialogHeader: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: theme.spacing(4),
    borderBottom: '1px solid ' + theme.palette.grey[300],
    padding: theme.spacing(1, 3),
  },
}));

const Transition = React.forwardRef(function Transition(props, ref) {
  return <Slide direction="up" ref={ref} {...props} />;
});

const CoursesPageTemplate = ({
  pageTitle,
  courses,
  categories,
  topics,
  categoriesFilterValue,
  topicsFilterValue,
}) => {
  const classes = useStyles();
  const [open, setOpen] = useState(false);

  const handleClickOpen = () => {
    setOpen(true);
  };

  const handleClose = () => {
    setOpen(false);
  };

  return (
    <Box className={classes.wrapper}>
      <Hidden mdUp>
        <Box className={classes.header}>
          {/* Page title (basically, title of the Drupal node) */}
          <Typography variant="subtitle2">{pageTitle}</Typography>

          {/* Mobile filters toggle */}
          {(categories.length > 0 || topics.length > 0) && (
            <>
              <IconButton
                aria-label="filter"
                size="medium"
                className={classes.filterButton}
                onClick={handleClickOpen}
              >
                <FilterListIcon />
                <Typography variant="caption">
                  {Drupal.t('Filter', {}, { context: 'ANU LMS' })}
                </Typography>
              </IconButton>
              <Dialog fullScreen open={open} onClose={handleClose} TransitionComponent={Transition}>
                <Box className={classes.dialogHeader}>
                  <Typography variant="subtitle2">
                    {Drupal.t('Filter courses', {}, { context: 'ANU LMS' })}
                  </Typography>
                  <IconButton edge="end" color="inherit" onClick={handleClose} aria-label="close">
                    <CloseIcon />
                  </IconButton>
                </Box>
                <Box p={1}>
                  <CoursesFilters
                    categoriesFilterValue={categoriesFilterValue}
                    topicsFilterValue={topicsFilterValue}
                    categories={categories}
                    topics={topics}
                    handleClose={handleClose}
                  />
                </Box>
              </Dialog>
            </>
          )}
        </Box>
      </Hidden>

      <Box className={classes.contentWrapper}>
        {/* Left sidebar visible on tablet + desktop devices only */}
        <Hidden smDown>
          {/* Course categories filter */}
          {(categories.length > 0 || topics.length > 0) && (
            <Box className={classes.sidebar}>
              <CoursesFilters
                categoriesFilterValue={categoriesFilterValue}
                topicsFilterValue={topicsFilterValue}
                categories={categories}
                topics={topics}
              />
            </Box>
          )}
        </Hidden>

        {/* Courses Grid */}
        <Box className={classes.content}>
          <Box className={classes.contentHeader}>
            <Typography variant="caption" data-test={'anu-lms-amount-courses'}>
              {courses.length} {getEntityLabel('Course', courses.length)}
            </Typography>
          </Box>
          <CoursesGrid courses={courses} />
        </Box>
      </Box>
    </Box>
  );
};

CoursesPageTemplate.propTypes = {
  pageTitle: PropTypes.string.isRequired,
  courses: PropTypes.arrayOf(coursePropTypes),
  categories: PropTypes.arrayOf(courseCategoryPropTypes),
  topics: PropTypes.arrayOf(courseTopicPropTypes),
  categoriesFilterValue: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).isRequired,
  topicsFilterValue: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).isRequired,
};

CoursesPageTemplate.defaultProps = {
  courses: [],
  categories: [],
  topics: [],
};

export default CoursesPageTemplate;
