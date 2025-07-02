<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use Drupal\vactory_dashboard\Service\SslService;
/**
 * Controller for dashboard page.
 */
class DashboardController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The SSL service.
   *
   * @var \Drupal\vactory_dashboard\Service\SSLService
   */
  protected $sslService;

  protected $currentUser;

  /**
   *
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ClientInterface $http_client, AccountProxyInterface $currentUser,SslService $ssl_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->httpClient = $http_client;
    $this->currentUser = $currentUser;
    $this->sslService = $ssl_service;
  }

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('http_client'),
      $container->get('current_user'),
      $container->get('vactory_dashboard.ssl_service')

    );
  }

  /**
   * Renders the dashboard page.
   *
   * @return array
   *   A render array.
   */
  public function content() {
    $totalPages = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'vactory_page')
      ->accessCheck(TRUE)
      ->count()
      ->execute();

    $totalUsers = $this->entityTypeManager->getStorage('user')
      ->getQuery()
      ->accessCheck(TRUE)
      ->count()
      ->execute();

    $totalMedia = $this->entityTypeManager->getStorage('media')
      ->getQuery()
      ->accessCheck(TRUE)
      ->count()
      ->execute();

    $pagesModifiedThisWeek = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'vactory_page')
      ->condition('changed', strtotime('last week'), '>')
      ->accessCheck(TRUE)
      ->count()
      ->execute();

    $lastModifiedPages = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'vactory_page')
      ->sort('changed', 'DESC')
      ->range(0, 5)
      ->accessCheck(TRUE)
      ->execute();

    $lastModifiedPagesData = [];
    foreach ($lastModifiedPages as $page) {
      $node = $this->entityTypeManager->getStorage('node')->load($page);
      $lastModifiedPagesData[] = [
        'id' => $node->id(),
        'title' => $node->label(),
        'changed' => $node->getChangedTime(),
      ];
    }

    // Retrieve Redmine tickets via API.
    $config = \Drupal::config('vactory_dashboard.settings');
    $apiKey = $config->get('redmine_api_key');
    $url = $config->get('redmine_url');
    $project_id = $config->get('redmine_project_id');
    $email = $this->currentUser->getEmail();

    $issues = [];
    $error_message = NULL;

    // Pre-validation checks.
    if (empty($email)) {
      $error_message = $this->t('User email is missing.');
    }
    elseif (empty($apiKey)) {
      $error_message = $this->t('API key missing or invalid.');
    }
    elseif (empty($project_id)) {
      $error_message = $this->t('Project ID missing or invalid.');
    }
    else {
      // Proceed with API call only if all prerequisites are met.
      try {
        $query = [
          'email' => $email,
          'projectIdentifier' => $project_id,
        // 0 = tous les statuts
          'statusId' => 0,
        // 0 = toutes les priorités
          'priorityId' => 0,
          'sortBy' => 'updated_on',
          'sortOrder' => 'DESC',
          'limit' => 10,
        ];

        $headers = [
          'x-api-key' => $apiKey,
        ];

        $response = $this->httpClient->request('GET', $url, [
          'query' => $query,
          'headers' => $headers,
          'timeout' => 10,
        ]);

        $statusCode = $response->getStatusCode();
        $data = json_decode($response->getBody(), TRUE);

        // Handle API-specific errors.
        if (isset($data['error'])) {
          throw new \Exception('Redmine API error: ' . $data['error']);
        }

        // Handle successful response.
        if ($statusCode === 200) {
          $issues = $data ?? [];
          // Note: If user email is not in Redmine, API returns 400 "User not found"
          // So empty issues here means user exists but has no tickets assigned.
          if (empty($issues)) {
            $error_message = $this->t('No tickets found for your account.');
          }
        }
        else {
          // Handle non-200 status codes.
          throw new \Exception('Unexpected HTTP status code: ' . $statusCode);
        }

      }
      catch (ConnectException $e) {
        // Network/timeout specific error.
        \Drupal::logger('vactory_dashboard')->error('Timeout: Redmine API unreachable. @message', ['@message' => $e->getMessage()]);
        $error_message = $this->t('Timeout: Unable to connect to Redmine.');

      }
      catch (ClientException $e) {
        // Handle 4xx client errors (including 400 Bad Request)
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 'unknown';
        $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';

        \Drupal::logger('vactory_dashboard')->error('Client Error @status: @message', [
          '@status' => $statusCode,
          '@message' => $e->getMessage(),
        ]);

        // Handle specific 400 error for user not found.
        if ($statusCode === 400 && !empty($responseBody)) {
          $errorData = json_decode($responseBody, TRUE);
          if (isset($errorData['message']) && $errorData['message'] === 'User not found') {
            $error_message = $this->t('Check your email account to be in Redmine accounts.');
          }
          else {
            $error_message = $this->t('Bad request: @message', ['@message' => $errorData['message'] ?? 'Invalid request parameters']);
          }
        }
        elseif ($statusCode === 401 || $statusCode === 403) {
          $error_message = $this->t('Authentication failed. Please check your API key.');
        }
        elseif ($statusCode === 404) {
          $error_message = $this->t('Redmine project or endpoint not found.');
        }
        else {
          $error_message = $this->t('Client error (HTTP @status).', ['@status' => $statusCode]);
        }

      }
      catch (RequestException $e) {
        // Handle other HTTP errors (5xx server errors, etc.)
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 'unknown';

        \Drupal::logger('vactory_dashboard')->error('HTTP Error @status: @message', [
          '@status' => $statusCode,
          '@message' => $e->getMessage(),
        ]);

        $error_message = $this->t('Server error connecting to Redmine (HTTP @status).', ['@status' => $statusCode]);

      }
      catch (\Exception $e) {
        // General catch-all for other exceptions.
        \Drupal::logger('vactory_dashboard')->error('Error fetching Redmine tickets: @message', ['@message' => $e->getMessage()]);
        $error_message = $this->t('An unexpected error occurred while retrieving Redmine tickets.');
      }
    }

    return [
      '#theme' => 'vactory_dashboard_home',
      '#total_pages' => $totalPages,
      '#total_users' => $totalUsers,
      '#total_media' => $totalMedia,
      '#pages_modified_this_week' => $pagesModifiedThisWeek,
      '#last_modified_pages' => $lastModifiedPagesData,
      '#issues' => $issues,
      '#projetID' => $project_id,
      '#error_message' => $error_message,
    ];
  }

  /**
   * Clears the cache.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function clearCache() {
    try {
      $drupalRoot = DRUPAL_ROOT;

      $commonPath = $drupalRoot . '/core/includes/common.inc';
      if (!file_exists($commonPath)) {
        \Drupal::logger('vactory_dashboard')
          ->error('common.inc file not found at: @path', ['@path' => $commonPath]);
        return new JsonResponse([
          'message' => 'common.inc file not found.',
          'path' => $commonPath,
        ], 500);
      }

      include_once $commonPath;

      if (!function_exists('drupal_flush_all_caches')) {
        \Drupal::logger('vactory_dashboard')
          ->error('Function drupal_flush_all_caches() not found after including common.inc.');
        return new JsonResponse([
          'message' => 'Function drupal_flush_all_caches() not found after including common.inc.',
        ], 500);
      }

      drupal_flush_all_caches();
      \Drupal::logger('vactory_dashboard')
        ->info('Caches have been successfully cleared.');

      return new JsonResponse(['message' => 'Caches cleared successfully'], 200);
    }
    catch (\Exception $e) {
      \Drupal::logger('vactory_dashboard')
        ->error('Error clearing cache: ' . $e->getMessage());
      return new JsonResponse(['message' => 'Error clearing cache: ' . $e->getMessage()], 500);
    }
  }

  /**
   * Returns SSL status as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing SSL status.
   */
  public function getSSLStatus(Request $request) {
    $force_refresh = $request->query->get('force_refresh') === '1';

    $domain = $request->getHost();

    $result = $this->sslService->getSSLStatus($domain, $force_refresh);

    if (isset($result['error'])) {
      return new JsonResponse(['error' => $result['error']], 500);
    }

    return new JsonResponse($result);
  }

}
