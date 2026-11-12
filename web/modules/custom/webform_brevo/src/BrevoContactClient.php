<?php

declare(strict_types=1);

namespace Drupal\webform_brevo;

use Brevo\Client\Api\ContactsApi;
use Brevo\Client\ApiException;
use Brevo\Client\Configuration;
use Brevo\Client\Model\CreateContact;
use Brevo\Client\ObjectSerializer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Adapts SDK results to the responses expected by Webform's error helpers.
 */
final class BrevoContactClient {

  public function __construct(private ClientInterface $httpClient) {}

  /**
   * Creates or updates a contact and returns its HTTP result.
   */
  public function upsert(string $api_key, CreateContact $contact): ResponseInterface {
    if (trim($api_key) === '') {
      return new Response(503, [], '{"code":"missing_api_key"}');
    }
    // Configuration::getDefaultConfiguration() is shared between handlers.
    // Each delivery must use only the key configured for this handler.
    $configuration = (new Configuration())->setApiKey('api-key', $api_key);
    $api = new ContactsApi($this->httpClient, $configuration);
    try {
      [$result, $status, $headers] = $api->createContactWithHttpInfo($contact);
      $body = $status === 204 || $result === NULL ? '' : json_encode(ObjectSerializer::sanitizeForSerialization($result), JSON_THROW_ON_ERROR);
      return new Response($status, $headers, $body);
    }
    catch (ApiException $exception) {
      $status = (int) $exception->getCode();
      if ($status < 400 || $status > 599) {
        $status = 502;
      }
      $body = $exception->getResponseBody();
      if ($body instanceof StreamInterface) {
        $body = (string) $body;
      }
      if (!is_string($body)) {
        $body = json_encode($body ?? [], JSON_THROW_ON_ERROR);
      }
      return new Response($status, $exception->getResponseHeaders() ?? [], $body);
    }
    catch (TransferException) {
      return new Response(503, [], '{"code":"connection_failed"}');
    }
  }

}
