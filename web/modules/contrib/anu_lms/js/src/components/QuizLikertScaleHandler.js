import React, { useEffect, useState } from 'react';
import QuizLikertScale from '@anu/components/QuizLikertScale';
import * as questionsAPI from '@anu/api/questionsAPI';
import PropTypes from 'prop-types';

const QuizLikertScaleHandler = ({
  aqid,
  question,
  options,
  isQuiz,
  submittedAnswer,
  onQuestionComplete,
  isSubmitting: pIsSubmitting,
  isSubmitted: pIsSubmitted,
  onChange: pOnChange,
  correctQuizValue = null,
}) => {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [value, setValue] = useState(null);
  const [correctValue, setCorrectValue] = useState(null);

  useEffect(() => {
    // During the initialization set the default value for the widget.
    if (pOnChange) {
      pOnChange(null);
    }
  }, []);

  const onSubmit = async () => {
    setIsSubmitting(true);
    setCorrectValue(null);

    const response = await questionsAPI.postQuestion(aqid, Number.parseInt(value, 10));
    if (response.ok) {
      const payload = await response.json();

      setIsSubmitted(true);
      setCorrectValue(payload.correctAnswer);
      // MCQ has been answered, fire callback for page validation.
      onQuestionComplete(true);
    } else {
      alert(Drupal.t('Question submission failed. Please try again.', {}, { context: 'ANU LMS' }));
      console.error(response.status, await response.text());
    }

    setIsSubmitting(false);
  };

  const onChange = (event, value) => {
    setValue(value);
    if (isQuiz && pOnChange) {
      pOnChange(value);
    }
  };

  return (
    <QuizLikertScale
      question={question}
      options={options}
      value={submittedAnswer || value}
      correctValue={correctValue || correctQuizValue}
      isSubmitting={pIsSubmitting || isSubmitting}
      isSubmitted={pIsSubmitted || isSubmitted}
      onChange={onChange}
      onSubmit={!isQuiz ? onSubmit : null}
    />
  );
};

QuizLikertScaleHandler.propTypes = {
  onChange: PropTypes.func,
  isQuiz: PropTypes.bool,
  aqid: PropTypes.number,
  onQuestionComplete: PropTypes.func,
  question: PropTypes.string,
  options: PropTypes.array,
  submittedAnswer: PropTypes.string,
  correctQuizValue: PropTypes.array,
  isSubmitting: PropTypes.bool,
  isSubmitted: PropTypes.bool,
};

export default QuizLikertScaleHandler;
