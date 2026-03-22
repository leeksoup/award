import React, { useEffect, useRef, useState } from 'react';
import PropTypes from 'prop-types';
import Box from '@material-ui/core/Box';
import makeStyles from '@material-ui/core/styles/makeStyles';
import Hidden from '@material-ui/core/Hidden';
import Typography from '@material-ui/core/Typography';
import LinearProgress from '@material-ui/core/LinearProgress';
import ContentNavigation from '@anu/components/ContentNavigation';
import { coursePropTypes } from '@anu/utilities/transform.course';
import LessonNavigationMobile from '@anu/pages/lesson/NavigationMobile';

const useStyles = makeStyles((theme) => ({
  container: {
    background: theme.palette.grey[200],
    boxSizing: 'border-box',
    marginBottom: theme.spacing(8),
    marginTop: 0,
  },
  stickyFixedContainer: {
    position: 'fixed',
    top: 0,
    zIndex: 10,
    marginTop: 0,
    [theme.breakpoints.down('sm')]: {
      left: 0,
      width: '100%!important',
      margin: 0,
    },
  },
  layoutContainer: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: theme.spacing(3),
    padding: theme.spacing(1.5, 0, 1.5, 2),
    [theme.breakpoints.up('sm')]: {
      paddingLeft: theme.spacing(3),
    },
    [theme.breakpoints.up('md')]: {
      paddingLeft: theme.spacing(4),
    },
  },
  stickyNavBarContainer: {
    paddingTop: '115px',
  },
  actionsSection: {
    display: 'flex',
    justifyContent: 'flex-end',
    flexBasis: 240,
    [theme.breakpoints.up('md')]: {
      flexBasis: 360,
    },
    [theme.breakpoints.up('lg')]: {
      flexBasis: 380,
    },
  },
  title: {
    padding: theme.spacing(1.5, 10, 1.5, 0),
    [theme.breakpoints.up('lg')]: {
      paddingRight: theme.spacing(15),
    },
  },
  pageNumberSection: {
    fontWeight: theme.typography.fontWeightRegular,
    display: 'flex',
    alignItems: 'center',
    marginRight: theme.spacing(3),
    whiteSpace: 'nowrap',
  },
}));

// Sticky top navigation used for lessons and quizzes.
const ContentTopNavigation = ({
  isIntro,
  sections,
  currentLesson,
  nextLesson,
  prevLesson,
  currentIndex,
  isEnabled,
  course,
  setCurrentPage,
  currentPage,
}) => {
  const classes = useStyles();

  const [isSticky, _setIsSticky] = useState(false);

  // Hooks to track the "isSticky" value. Note that we have to use useRef here as
  // well as useState, in order to have the step data accessible in our
  // event listeners which normally don't get updates from change of the state.
  // See https://file-translate.com/en/blog/react-state-in-event.
  const isStickyRef = useRef(isSticky);

  const setIsSticky = (value) => {
    isStickyRef.current = value;
    _setIsSticky(value);
  };

  const staticNavigationContainerEl = useRef(null);
  const stickyNavigationContainerEl = useRef(null);

  const onScroll = () => {
    const body = document.querySelector('body');

    let stickyNavbarTopPadding =
      body.style.paddingTop.length && body.className.includes('toolbar-fixed')
        ? parseInt(body.style.paddingTop)
        : 0;
    stickyNavigationContainerEl.current.style.top = `${stickyNavbarTopPadding}px`;

    if (
      staticNavigationContainerEl.current.getBoundingClientRect().top - stickyNavbarTopPadding <=
        0 &&
      window.scrollY > 5
    ) {
      setIsSticky(true);
      stickyNavigationContainerEl.current.style.width = `${stickyNavigationContainerEl.current.parentElement.offsetWidth}px`;
    } else {
      setIsSticky(false);
      stickyNavigationContainerEl.current.style.width = `auto`;
    }
  };

  useEffect(() => {
    window.addEventListener('scroll', onScroll);
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  let currentProgress = ((currentPage + 1) / sections.length) * 100;

  // If this page is initial then progress bar shouldn't be animated.
  if (currentPage === null) {
    currentProgress = ((currentIndex + 1) / sections.length) * 100;
  }

  const [progress, setProgress] = useState(currentProgress);

  // TopContentNavigation is included in a lesson and overdrew whenever a user
  // goes between pages. This is a reason why important emulate previous position
  // initially and set the current in 100ms.
  setTimeout(() => {
    setProgress(((currentIndex + 1) / sections.length) * 100);
  }, 100);

  useEffect(() => {
    setCurrentPage(currentIndex);
  }, []);

  return (
    <div
      className={isSticky ? classes.stickyNavBarContainer : ''}
      ref={staticNavigationContainerEl}
    >
      <Box
        className={`${classes.container} ${isSticky ? classes.stickyFixedContainer : ''}`}
        ref={stickyNavigationContainerEl}
      >
        <Box className={classes.layoutContainer}>
          {/* Navigation drawer visible only on mobile */}
          <Hidden mdUp>
            <LessonNavigationMobile lesson={currentLesson} course={course} />
          </Hidden>

          <Hidden smDown>
            <Typography className={classes.title} variant="subtitle2">
              {currentLesson.title}
            </Typography>
          </Hidden>

          <Box className={classes.actionsSection}>
            <Typography variant="subtitle2" className={classes.pageNumberSection}>
              {Drupal.t(
                'Page !current of !all',
                { '!current': currentIndex + 1, '!all': Math.max(sections.length, 1) },
                { context: 'ANU LMS' }
              )}
            </Typography>
            <ContentNavigation
              isIntro={isIntro}
              sections={sections}
              currentLesson={currentLesson}
              nextLesson={nextLesson}
              prevLesson={prevLesson}
              currentIndex={currentIndex}
              isEnabled={isEnabled}
              lessonGridMode={'navigation'}
              hideButtonsLabelsOnMobile={true}
            />
          </Box>
        </Box>
        <LinearProgress variant="determinate" value={progress} />
      </Box>
    </div>
  );
};

ContentTopNavigation.propTypes = {
  isIntro: PropTypes.bool,
  sections: PropTypes.arrayOf(PropTypes.arrayOf(PropTypes.shape())),
  currentLesson: PropTypes.shape({
    title: PropTypes.string,
  }),
  nextLesson: PropTypes.shape(),
  prevLesson: PropTypes.shape(),
  currentIndex: PropTypes.number,
  isEnabled: PropTypes.bool,
  course: coursePropTypes,
  setCurrentPage: PropTypes.func,
  currentPage: PropTypes.number,
};

ContentTopNavigation.defaultProps = {
  currentPage: null,
  setCurrentPage: () => {},
};

export default ContentTopNavigation;
