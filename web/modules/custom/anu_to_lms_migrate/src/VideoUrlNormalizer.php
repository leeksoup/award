<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate;

/**
 * Normalizes approved public video-provider URLs to safe embed URLs.
 */
final class VideoUrlNormalizer {

  /**
   * Converts a YouTube or Vimeo URL to an HTTPS embed URL.
   */
  public static function normalize(string $url): ?string {
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) {
      return NULL;
    }

    $host = strtolower(preg_replace('/^www\./', '', $parts['host']));
    $path = trim((string) ($parts['path'] ?? ''), '/');
    $video_id = NULL;

    if ($host === 'youtu.be') {
      $video_id = explode('/', $path)[0] ?? NULL;
    }
    elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], TRUE)) {
      if (str_starts_with($path, 'embed/')) {
        $video_id = explode('/', $path)[1] ?? NULL;
      }
      elseif (str_starts_with($path, 'shorts/') || str_starts_with($path, 'live/')) {
        $video_id = explode('/', $path)[1] ?? NULL;
      }
      else {
        parse_str((string) ($parts['query'] ?? ''), $query);
        $video_id = $query['v'] ?? NULL;
      }
    }
    elseif (in_array($host, ['vimeo.com', 'player.vimeo.com'], TRUE)) {
      $segments = array_values(array_filter(explode('/', $path)));
      $video_id = end($segments) ?: NULL;
      if ($video_id !== NULL && ctype_digit((string) $video_id)) {
        return 'https://player.vimeo.com/video/' . $video_id;
      }
      return NULL;
    }

    if ($video_id !== NULL && preg_match('/^[A-Za-z0-9_-]{6,20}$/', (string) $video_id)) {
      return 'https://www.youtube-nocookie.com/embed/' . $video_id;
    }
    return NULL;
  }

}
