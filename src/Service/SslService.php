<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface; 

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

       // Exécution de la commande OpenSSL pour récupérer le certificat
       $cmd = sprintf(
        "echo | openssl s_client -servername %s -connect %s:443 | openssl x509 -noout -issuer -enddate -subject",
        escapeshellarg($domain),
        escapeshellarg($domain)
      );
      $output = shell_exec($cmd);

      if (!$output) {
        return ['error' => "Impossible de récupérer le certificat SSL via OpenSSL."];
      }

      // Extraction des infos nécessaires
      $issuer = '';
      $valid_till = '';
      $subject = '';
      foreach (explode("\n", $output) as $line) {
        if (strpos($line, 'issuer=') === 0) {
          $fullIssuer = trim(substr($line, 7)); // toute la chaîne
          if (preg_match('/O=([^,]+)/', $fullIssuer, $matches)) {
              $issuer = trim($matches[1]);
          } else {
              $issuer = $fullIssuer;
          }
      }
        if (strpos($line, 'notAfter=') === 0) {
          $valid_till = trim(substr($line, 9));
        }
        if (strpos($line, 'subject=') === 0) {
          $subject = trim(substr($line, 8));
        }
      }

      if (empty($issuer) || empty($valid_till)) {
        return ['error' => "Le certificat SSL n'a pas pu être extrait."];
      }

      // Conversion date et calcul des jours restants
      $expires = \DateTime::createFromFormat('M d H:i:s Y T', $valid_till);
      $valid_till_formatted = $expires ? $expires->format('M j Y') : $valid_till;
      $days_left = $expires ? (int) floor(($expires->getTimestamp() - time()) / 86400) : 0;
      
      $data = [
        'host' => $domain,
        'issuer_o' => $issuer,
        'valid_till' => $valid_till_formatted,
        'cert_valid' => ($days_left > 0),
        'cert_exp' => ($days_left <= 0),
        'days_left' => $days_left,
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
