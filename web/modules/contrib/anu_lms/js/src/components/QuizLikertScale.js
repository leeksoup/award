import React from 'react';
import PropTypes from 'prop-types';
import { useMediaQuery, withStyles } from '@material-ui/core';
import { useTheme } from '@material-ui/core/styles';
import Box from '@material-ui/core/Box';
import Typography from '@material-ui/core/Typography';
import FormGroup from '@material-ui/core/FormGroup';
import FormControl from '@material-ui/core/FormControl';
import QuizSubmit from '@anu/components/QuizSubmit';
import LessonGrid from '@anu/components/LessonGrid';
import { highlightText } from '@anu/utilities/searchHighlighter';
import { ToggleButtonGroup } from '@material-ui/lab';
import ToggleButtonWithValidation from '@anu/components/ToggleButtonWithValidation';

const StyledBox = withStyles((theme) => ({
  root: {
    marginBottom: theme.spacing(8),
  },
}))(Box);

const QuizLikertScale = ({
  question,
  options,
  value,
  correctValue,
  isSubmitting,
  isSubmitted,
  onChange,
  onSubmit,
}) => {
  const theme = useTheme();
  const matches = useMediaQuery(theme.breakpoints.up('md'));

  return (
    <StyledBox>
      <LessonGrid>
        <Typography variant="subtitle1" style={{ marginBottom: theme.spacing(1) }}>
          {highlightText(question)}
        </Typography>

        <FormGroup>
          <FormControl>
            <ToggleButtonGroup
              value={value}
              orientation={matches ? 'horizontal' : 'vertical'}
              onChange={onChange}
              exclusive
            >
              {options.map((option, index) => (
                <ToggleButtonWithValidation
                  key={option.id}
                  value={option.id}
                  disabled={isSubmitted || isSubmitting}
                  selected={option.id === value}
                  optionId={question + ':' + index}
                  correctValue={correctValue ? correctValue[0] : null}
                >
                  {option.value}
                </ToggleButtonWithValidation>
              ))}
            </ToggleButtonGroup>
          </FormControl>
        </FormGroup>
        {!isSubmitted && onSubmit && <QuizSubmit onSubmit={onSubmit} isSubmitting={isSubmitting} />}
      </LessonGrid>
    </StyledBox>
  );
};

QuizLikertScale.propTypes = {
  question: PropTypes.string,
  options: PropTypes.array,
  value: PropTypes.string,
  isSubmitting: PropTypes.bool,
  isSubmitted: PropTypes.bool,
  onChange: PropTypes.func,
  onSubmit: PropTypes.func,
  correctValue: PropTypes.array,
};

export default QuizLikertScale;
