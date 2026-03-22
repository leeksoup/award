import React from 'react';
import PropTypes from 'prop-types';
import Box from '@material-ui/core/Box';
import makeStyles from '@material-ui/core/styles/makeStyles';

const useStyles = makeStyles((theme) => ({
  default: {
    paddingLeft: theme.spacing(2),
    paddingRight: theme.spacing(2),
    [theme.breakpoints.up('sm')]: {
      paddingLeft: theme.spacing(3),
      paddingRight: theme.spacing(3),
    },
    [theme.breakpoints.up('md')]: {
      paddingLeft: theme.spacing(4),
      paddingRight: theme.spacing(4),
      maxWidth: '800px',
      marginLeft: 'auto',
      marginRight: 'auto',
    },
  },
  navigation: {
    marginRight: theme.spacing(4),
    [theme.breakpoints.down('md')]: {
      marginRight: theme.spacing(2),
    },
  },
  button: {},
}));

const LessonGrid = ({ children, mode }) => {
  const classes = useStyles();
  return <Box className={classes[mode]}>{children}</Box>;
};

LessonGrid.propTypes = {
  children: PropTypes.node,
  mode: PropTypes.oneOf(['default', 'navigation', 'button']),
};

LessonGrid.defaultProps = {
  mode: 'default',
};

export default LessonGrid;
