<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface; 

class SslService {

  protected $configFactory;
  protected $httpClient;
  protected $logger;

  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('vactory_dashboard_ssl');
  }

  /**
   * Vérifie les prérequis système pour la vérification SSL.
   *
   * @return array
   *   Tableau avec 'success' (bool) et 'message' (string).
   */
  private function checkSystemRequirements(): array {
    // Vérifier si shell_exec est disponible
    if (!function_exists('shell_exec')) {
      $message = 'La fonction shell_exec n\'est pas disponible. Vérifiez la configuration PHP (disable_functions).';
      $this->logger->error($message);
      return ['success' => FALSE, 'message' => $message];
    }

    // Vérifier si shell_exec est désactivée
    $disabled_functions = explode(',', ini_get('disable_functions'));
    if (in_array('shell_exec', array_map('trim', $disabled_functions))) {
      $message = 'La fonction shell_exec est désactivée dans la configuration PHP (disable_functions).';
      $this->logger->error($message);
      return ['success' => FALSE, 'message' => $message];
    }


    // Vérifier si OpenSSL est installé
    $openssl_check = shell_exec('which openssl 2>/dev/null');
    if (empty($openssl_check)) {
      $message = 'La commande OpenSSL n\'est pas installée sur le système. Installez OpenSSL pour utiliser la vérification SSL.';
      $this->logger->error($message);
      return ['success' => FALSE, 'message' => $message];
    }
    return ['success' => TRUE];

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
      // Vérifier les prérequis système avant tout
      $requirements_check = $this->checkSystemRequirements();
      if (!$requirements_check['success']) {
        $this->logger->error('Échec de la vérification SSL : ' . $requirements_check['message']);
        return ['error' => $requirements_check['message']];
      }

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

      if ($output === NULL) {
        $error_message = "Échec de l'exécution de la commande shell_exec pour OpenSSL.";
        $this->logger->error($error_message);
        return ['error' => $error_message];
      }

      if (empty($output) || trim($output) === '') {
        $error_message = "La commande OpenSSL n'a retourné aucun résultat. Vérifiez la connectivité au domaine: " . $domain;
        $this->logger->error($error_message);
        return ['error' => $error_message];
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
        $error_message = "Le certificat SSL n'a pas pu être extrait. Données manquantes : issuer=" . ($issuer ?: 'vide') . ", valid_till=" . ($valid_till ?: 'vide');
        $this->logger->error($error_message);
        return ['error' => $error_message];
      }

      // Conversion date et calcul des jours restants
      $expires = \DateTime::createFromFormat('M d H:i:s Y T', $valid_till);
      if (!$expires) {
        $this->logger->warning('Impossible de parser la date d\'expiration : ' . $valid_till);
      }
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
      $error_message = 'Exception lors de la vérification SSL : ' . $e->getMessage();
      $this->logger->error($error_message);
      return ['error' => $error_message];
    }
  }

}
