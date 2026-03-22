import React from 'react';

import { withStyles } from '@material-ui/core';
import Clear from '@material-ui/icons/Clear';
import Radio from '@material-ui/core/Radio';
import PropTypes from 'prop-types';

const ErrorRadio = withStyles((theme) => ({
  root: {
    color: theme.palette.error.main,
  },
  checked: {
    color: theme.palette.error.main + ' !important',
  },
}))(Radio);

const SuccessRadio = withStyles((theme) => ({
  root: {
    color: theme.palette.success.main,
  },
  checked: {
    color: theme.palette.success.main + ' !important',
  },
}))(Radio);

const RadioWithValidation = ({ optionId, value, correctValue, checked, ...props }) => {
  if (correctValue) {
    if (checked && correctValue === Number.parseInt(value, 10)) {
      return (
        <SuccessRadio
          {...props}
          checked
          data-radio-option={optionId}
          data-test={'anu-lms-radio-success'}
        />
      );
    }

    if (!checked && correctValue === Number.parseInt(value, 10)) {
      return (
        <ErrorRadio
          value={value}
          {...props}
          checked
          data-radio-option={optionId}
          data-test={'anu-lms-radio-correct-error'}
        />
      );
    }

    if (checked && correctValue !== Number.parseInt(value, 10)) {
      return (
        <ErrorRadio
          {...props}
          checked
          checkedIcon={<Clear />}
          data-radio-option={optionId}
          data-test={'anu-lms-radio-incorrect-error'}
        />
      );
    }

    if (!checked && correctValue !== Number.parseInt(value, 10)) {
      return (
        <Radio value={value} {...props} data-radio-option={optionId} data-test={'anu-lms-radio'} />
      );
    }
  }

  return (
    <Radio value={value} {...props} data-radio-option={optionId} data-test={'anu-lms-radio'} />
  );
};

RadioWithValidation.propTypes = {
  optionId: PropTypes.string,
  value: PropTypes.string,
  correctValue: PropTypes.number,
  checked: PropTypes.bool,
};

export default RadioWithValidation;
