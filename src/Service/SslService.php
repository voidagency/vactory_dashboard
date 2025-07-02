<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface; // <-- Change this line

class SslService {

  protected $configFactory;
  protected $httpClient;

  // Change the type hint for $http_client
  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
  }

  /**
   * Récupère le statut SSL.
   *
   * @param string|null $domain
   *   Le nom de domaine à vérifier. Si null, utilise la config ou domaine actuel.
   * @param bool $force_refresh
   *   Forcer la récupération des données même si pas nécessaire.
   *
   * @return array
   *   Les données SSL ou tableau avec clé 'error' en cas de problème.
   */
  public function getSSLStatus(?string $domain = NULL, bool $force_refresh = FALSE): array {
    try {
      $config = $this->configFactory->getEditable('vactory_dashboard.ssl.settings');
      $stored_data = $config->get('ssl_info');

      if (!$force_refresh && !empty($stored_data['days_left']) && $stored_data['days_left'] > 10) {
        return $stored_data;
      }

      if (empty($domain)) {
        $domain = $config->get('name_domain');
      }

      if (empty($domain)) {
        // Pas de domaine fourni ni en config.
        return ['error' => 'Domaine non défini.'];
      }

      // Pour Guzzle, utilise request() au lieu de request() de Symfony HttpClientInterface
      // et pour le contenu, utilise getBody()->getContents() puis json_decode().
      $response = $this->httpClient->request('GET', "https://ssl-checker.io/api/v1/check/{$domain}", [
        'timeout' => 10,
      ]);

      if ($response->getStatusCode() !== 200) {
        return ['error' => 'Erreur lors de la récupération des données SSL.'];
      }

      $content = json_decode($response->getBody()->getContents(), TRUE); // <-- Change this line for Guzzle

      if (!isset($content['result'])) {
        return ['error' => 'Format de réponse inattendu.'];
      }

      $result = $content['result'];

      $data = [
        'host' => $result['host'] ?? $domain,
        'issuer_o' => $result['issuer_o'] ?? '',
        'valid_till' => $result['valid_till'] ?? NULL,
        'cert_valid' => $result['cert_valid'] ?? FALSE,
        'cert_exp' => $result['cert_exp'] ?? TRUE,
        'days_left' => $result['days_left'] ?? 0,
      ];

      $config->set('ssl_info', $data)
        ->set('ssl_info_last_check', time())
        ->save();

      return $data;
    }
    catch (\Exception $e) {
      return ['error' => 'Exception: ' . $e->getMessage()];
    }
  }

}
