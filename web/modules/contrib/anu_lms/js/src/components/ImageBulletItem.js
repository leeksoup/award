import React from 'react';
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core';
import Box from '@material-ui/core/Box';
import Typography from '@material-ui/core/Typography';
import { textStyle } from '../theme';

const StyledTypography = withStyles((theme) => ({
  root: {
    // Common styles for elements with an enabled rich text editor.
    ...textStyle(theme),
  },
}))(Typography);

const ImageContainerSmall = withStyles((theme) => ({
  root: {
    marginTop: theme.spacing(1),
    marginRight: theme.spacing(2),
  },
}))(Box);

const ImageContainerLarge = withStyles((theme) => ({
  root: {
    marginTop: theme.spacing(1),
    marginRight: theme.spacing(3),
  },
}))(Box);

const ImageBulletItem = ({ image, size, align, text }) => {
  const Image = () => (
    <img src={image.url} alt={image.alt} height={size} width={size} style={{ maxWidth: 'none' }} />
  );

  return (
    <Box size={size} display="flex" alignItems={align} mb={2}>
      {parseInt(size, 10) === 20 ? (
        <ImageContainerSmall>
          <Image />
        </ImageContainerSmall>
      ) : (
        <ImageContainerLarge>
          <Image />
        </ImageContainerLarge>
      )}

      <StyledTypography component="div" dangerouslySetInnerHTML={{ __html: text }} />
    </Box>
  );
};

ImageBulletItem.propTypes = {
  image: PropTypes.shape({
    url: PropTypes.string,
    alt: PropTypes.string,
  }).isRequired,
  size: PropTypes.number.isRequired,
  align: PropTypes.oneOf(['center', 'flex-start']).isRequired,
  text: PropTypes.string.isRequired,
};

export default ImageBulletItem;
