import React from 'react';
import PropTypes from 'prop-types';
import Typography from '@material-ui/core/Typography';
import Box from '@material-ui/core/Box';
import { withStyles } from '@material-ui/core';
import LessonGrid from '@anu/components/LessonGrid';
import { highlightText } from '@anu/utilities/searchHighlighter';

const StyledBox = withStyles((theme) => ({
  root: {
    marginBottom: theme.spacing(4),
  },
}))(Box);

const CustomParagraph = ({ text }) => (
  <StyledBox>
    <LessonGrid>
      <div>Content of a custom paragraph:</div>
      <Typography>{highlightText(text)}</Typography>
    </LessonGrid>
  </StyledBox>
);

CustomParagraph.propTypes = {
  text: PropTypes.string.isRequired,
};

export default CustomParagraph;
