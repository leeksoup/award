import React from 'react';
import PropTypes from 'prop-types';
import { useHistory, useLocation } from 'react-router-dom';
import Box from '@material-ui/core/Box';
import List from '@material-ui/core/List';
import ListItem from '@material-ui/core/ListItem';
import ListItemText from '@material-ui/core/ListItemText';
import makeStyles from '@material-ui/core/styles/makeStyles';
import { courseCategoryPropTypes } from '@anu/utilities/transform.courseCategory';
import { courseTopicPropTypes } from '@anu/utilities/transform.courseTopic';

const useStyles = makeStyles((theme) => ({
  listItem: {
    padding: theme.spacing(1, 2),
    '&.Mui-selected': {
      backgroundColor: 'transparent',
    },
    '&.Mui-selected .MuiTypography-root': {
      color: theme.palette.primary.main,
      fontWeight: theme.typography.fontWeightBold,
    },
  },
  listHeader: {
    padding: theme.spacing(3, 2, 1),
    fontWeight: theme.typography.fontWeightBold,
    textTransform: 'uppercase',
  },
}));

const CoursesFilters = ({
  categoriesFilterValue,
  topicsFilterValue,
  categories,
  topics,
  handleClose,
}) => {
  const history = useHistory();
  const location = useLocation();
  const classes = useStyles();

  const handleCategoryClick = (categoryId) => {
    history.push(
      topicsFilterValue === 'all'
        ? `${location.pathname}?category=${categoryId}`
        : `${location.pathname}?category=${categoryId}&topic=${topicsFilterValue}`
    );
    handleClose();
  };

  const handleTopicClick = (topicId) => {
    history.push(
      categoriesFilterValue === 'all'
        ? `${location.pathname}?topic=${topicId}`
        : `${location.pathname}?category=${categoriesFilterValue}&topic=${topicId}`
    );
    handleClose();
  };

  return (
    <>
      {categories.length > 0 && (
        <>
          <nav aria-label="categories">
            <Box className={classes.listHeader}>
              {Drupal.t('Categories', {}, { context: 'ANU LMS' })}
            </Box>
            <List>
              {/*
                `selected` property is deprecated for ListItem in newer MUI versions.
                When MUI will be updated the implementation should be updated to
                ListItem + ListItemButton and `selected` property moved to the ListItemButton.
              */}
              <ListItem
                className={classes.listItem}
                button
                selected={categoriesFilterValue === 'all'}
                onClick={() => handleCategoryClick('all')}
              >
                <ListItemText
                  primaryTypographyProps={{ variant: 'body2' }}
                  primary={Drupal.t('All categories', {}, { context: 'ANU LMS' })}
                />
              </ListItem>

              {categories.map((category) => (
                <ListItem
                  className={classes.listItem}
                  button
                  key={category.id}
                  selected={category.id === Number.parseInt(categoriesFilterValue, 10)}
                  onClick={() => handleCategoryClick(category.id)}
                >
                  <ListItemText
                    primaryTypographyProps={{ variant: 'body2' }}
                    primary={category.title}
                  />
                </ListItem>
              ))}
            </List>
          </nav>
        </>
      )}

      {topics.length > 0 && (
        <>
          <nav aria-label="topics">
            <Box className={classes.listHeader}>
              {Drupal.t('Topics', {}, { context: 'ANU LMS' })}
            </Box>
            <List>
              <ListItem
                className={classes.listItem}
                button
                selected={topicsFilterValue === 'all'}
                onClick={() => handleTopicClick('all')}
              >
                <ListItemText
                  primaryTypographyProps={{ variant: 'body2' }}
                  primary={Drupal.t('All topics', {}, { context: 'ANU LMS' })}
                />
              </ListItem>

              {topics.map((topic) => (
                <ListItem
                  className={classes.listItem}
                  button
                  key={topic.id}
                  selected={topic.id === Number.parseInt(topicsFilterValue, 10)}
                  onClick={() => handleTopicClick(topic.id)}
                >
                  <ListItemText
                    primaryTypographyProps={{ variant: 'body2' }}
                    primary={topic.title}
                  />
                </ListItem>
              ))}
            </List>
          </nav>
        </>
      )}
    </>
  );
};

CoursesFilters.propTypes = {
  categoriesFilterValue: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).isRequired,
  topicsFilterValue: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).isRequired,
  categories: PropTypes.arrayOf(courseCategoryPropTypes),
  topics: PropTypes.arrayOf(courseTopicPropTypes),
  handleClose: PropTypes.func,
};

CoursesFilters.defaultProps = {
  categories: [],
  topics: [],
  handleClose: () => {},
};

export default CoursesFilters;
