import React from 'react';

import { withStyles } from '@material-ui/core';
import PropTypes from 'prop-types';
import { ToggleButton } from '@material-ui/lab';

const PrimaryToggleButton = withStyles((theme) => ({
  root: {
    color: theme.palette.primary.main,
    borderColor: theme.palette.primary.main,
  },
}))(ToggleButton);

const ErrorToggleButton = withStyles((theme) => ({
  root: {
    color: theme.palette.success.light + '!important',
    borderColor: theme.palette.success.light,
  },
  selected: {
    color: theme.palette.error.main + '!important',
    borderColor: theme.palette.error.main,
  },
}))(ToggleButton);

const SuccessToggleButton = withStyles((theme) => ({
  root: {
    color: theme.palette.success.main,
    borderColor: theme.palette.success.main,
  },
  selected: {
    color: theme.palette.success.main + '!important',
  },
}))(ToggleButton);

const ToggleButtonWithValidation = ({ value, correctValue, selected, ...props }) => {
  if (correctValue) {
    if (selected && correctValue === Number.parseInt(value, 10)) {
      return (
        <SuccessToggleButton
          value={value}
          {...props}
          selected
          data-test={'anu-lms-toggle-button-success'}
        />
      );
    }

    if (!selected && correctValue === Number.parseInt(value, 10)) {
      return (
        <ErrorToggleButton
          value={value}
          {...props}
          selected
          data-test={'anu-lms-toggle-button-correct-error'}
        />
      );
    }

    if (selected && correctValue !== Number.parseInt(value, 10)) {
      return (
        <ErrorToggleButton
          value={value}
          {...props}
          data-test={'anu-lms-toggle-button-incorrect-error'}
        />
      );
    }

    if (!selected && correctValue !== Number.parseInt(value, 10)) {
      return <PrimaryToggleButton value={value} {...props} data-test={'anu-lms-toggle-button'} />;
    }
  }

  return (
    <PrimaryToggleButton
      value={value}
      selected={selected}
      {...props}
      data-test={'anu-lms-toggle-button'}
    />
  );
};

ToggleButtonWithValidation.propTypes = {
  optionId: PropTypes.string,
  value: PropTypes.string,
  correctValue: PropTypes.number,
  selected: PropTypes.bool,
};

export default ToggleButtonWithValidation;
