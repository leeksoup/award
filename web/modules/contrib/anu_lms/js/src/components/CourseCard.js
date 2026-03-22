import React from 'react';
import Box from '@material-ui/core/Box';
import Button from '@material-ui/core/Button';
import Card from '@material-ui/core/Card';
import CardMedia from '@material-ui/core/CardMedia';
import CardContent from '@material-ui/core/CardContent';
import CardActions from '@material-ui/core/CardActions';
import Typography from '@material-ui/core/Typography';
import makeStyles from '@material-ui/core/styles/makeStyles';
import LinearProgress from '@material-ui/core/LinearProgress';
import LockIcon from '@material-ui/icons/Lock';
import ArrowForwardIcon from '@material-ui/icons/ArrowForward';
import LibraryBooksIcon from '@material-ui/icons/LibraryBooks';
import TrophyIcon from '@material-ui/icons/EmojiEvents';
import Avatar from '@material-ui/core/Avatar';
import DownloadCoursePopup from '@anu/components/DownloadCoursePopup';
import { coursePropTypes } from '@anu/utilities/transform.course';
import { getPwaSettings } from '@anu/utilities/settings';
import { getEntityLabel } from '@anu/utilities/helpers';

const useStyles = makeStyles((theme) => ({
  card: {
    boxShadow: '0px 1px 3px rgba(0,0,0,0.2)',
    height: '100%',
    display: 'flex',
    flexDirection: 'column',
    '&.locked': {
      backgroundColor: theme.palette.grey[200],
    },
  },
  mediaWrapper: {
    possition: 'relative',
  },
  media: {
    height: 0,
    paddingTop: '56.25%', // 16:9
    '&.locked': {
      filter: 'opacity(40%)',
    },
  },
  content: {
    paddingBottom: theme.spacing(1.5),
    flexGrow: 1,
  },
  additionalContent: {
    paddingTop: theme.spacing(0.5),
    paddingBottom: theme.spacing(0.5),
  },
  title: {
    '&.locked': {
      color: theme.palette.grey[400],
    },
  },
  progress: {
    marginTop: theme.spacing(1),
    marginBottom: theme.spacing(1),
    height: 12,
    width: '100%',
    borderRadius: 5,
    backgroundColor: theme.palette.grey[300],
    '& .MuiLinearProgress-barColorPrimary': {
      backgroundColor: theme.palette.accent2.main,
    },
  },
  lockIcon: {
    color: theme.palette.common.white,
    fontSize: 20,
  },
  trophyIcon: {
    fontSize: 25,
  },
  completedBadge: {
    backgroundColor: theme.palette.accent2.main,
    height: 50,
    width: 50,
    marginLeft: theme.spacing(2),
    marginRight: theme.spacing(2),
    marginTop: -66,
    position: 'absolute',
    border: '1px solid white',
  },
  lockedBadge: {
    backgroundColor: theme.palette.grey[400],
    height: 32,
    width: 32,
    marginLeft: theme.spacing(2),
    marginRight: theme.spacing(2),
    marginTop: -48,
    position: 'absolute',
  },
  info: {
    display: 'flex',
    gap: theme.spacing(1),
    alignItems: 'center',
    color: theme.palette.grey[400],
    '& .MuiSvgIcon-root': {
      width: 20,
      height: 20,
      color: theme.palette.grey[350],
    },
  },
  offlineWrapper: {
    margin: theme.spacing(0.5, -1),
  },
  actions: {
    padding: theme.spacing(2),
  },
  button: {
    flexGrow: 1,
    textTransform: 'none',
    '& .MuiButton-label': {
      gap: theme.spacing(1),
    },
  },
}));

const CourseCard = ({ course }) => {
  const classes = useStyles();

  return (
    <Card elevation={0} className={`${classes.card} ${course.locked && 'locked'}`}>
      {course.image && course.image.url && (
        <Box className={classes.mediaWrapper}>
          <CardMedia
            className={`${classes.media} ${course.locked && 'locked'}`}
            image={course.image.url}
            title={course.title}
            alt={course.image.alt}
          />

          {course.progress_percent === 100 && (
            <Avatar className={classes.completedBadge}>
              <TrophyIcon className={classes.trophyIcon} />
            </Avatar>
          )}

          {course.locked && (
            <Avatar className={classes.lockedBadge}>
              <LockIcon className={classes.lockIcon} />
            </Avatar>
          )}
        </Box>
      )}

      <CardContent className={classes.content}>
        <Typography
          variant="subtitle2"
          component="h3"
          className={`${classes.title} ${course.locked && 'locked'}`}
        >
          {course.title}
        </Typography>
      </CardContent>

      <CardContent className={classes.additionalContent}>
        {course.progress && course.progress_percent > 0 && course.progress_percent < 100 && (
          <LinearProgress
            className={classes.progress}
            variant="determinate"
            value={course.progress_percent}
          />
        )}

        {course && getPwaSettings() && (
          <Box className={classes.offlineWrapper}>
            <DownloadCoursePopup course={course} showButton={true} variant="compact" />
          </Box>
        )}
        <Box className={classes.info}>
          <LibraryBooksIcon />
          <Typography variant="caption">
            {course.content.length} {getEntityLabel('Module', course.content.length)}
            {' · '}
            {course.lessons_count} {getEntityLabel('Lesson', course.lessons_count)}
          </Typography>
        </Box>
      </CardContent>

      <CardActions className={classes.actions}>
        <Button
          color="primary"
          variant="contained"
          size="large"
          disableElevation
          href={course.first_lesson_url}
          disabled={!course.first_lesson_url || course.locked}
          className={classes.button}
          endIcon={<ArrowForwardIcon />}
        >
          {Drupal.t('Go to course', {}, { context: 'ANU LMS' })}
        </Button>
      </CardActions>
    </Card>
  );
};

CourseCard.propTypes = {
  course: coursePropTypes.isRequired,
};

export default CourseCard;
