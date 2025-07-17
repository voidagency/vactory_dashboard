<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\vactory_dashboard\Service\NodeService;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Controller for autocomplete functionality.
 */
class AutocompleteController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The node service.
   *
   * @var \Drupal\vactory_dashboard\Service\NodeService
   */
  protected $nodeService;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager; // NOUVEAU: Propriété pour le language manager

  /**
   * Constructs a new AutocompleteController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, NodeService $node_service,  LanguageManagerInterface $language_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->nodeService = $node_service;
    $this->languageManager = $language_manager; 
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('vactory_dashboard.node_service'),
      $container->get('language_manager')
    );
  }

  /**
   * Autocomplete callback.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing the autocomplete results.
   */
  public function autocomplete(Request $request) {
    $field = $request->query->get('field');
    $query = $request->query->get('q');
    $results = [];

    // Get bundle from query (you must pass this parameter)
    $bundle = $request->query->get('bundle');
    $current_langcode = $this->languageManager->getCurrentLanguage()->getId();
    
    if (!$field || !$bundle) {
      return new JsonResponse(['results' => []]);
    }

    // Get all field definitions for the node bundle.
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    if (!isset($fields[$field])) {
      return new JsonResponse(['results' => []]);
    }

    // Load options for the entity reference field.
    $options = $this->nodeService->load_entity_reference_options([
        'settings' => $fields[$field]->getSettings(),
        'langcode' => $current_langcode, 
    ]);
    

    // Filter results based on query.
    foreach ($options as $id => $label) {
      if (!$query || stripos($label, $query) !== FALSE) {
        $results[] = [
          'id' => $id,
          'label' => $label,
          'value' => $id,
        ];
      }
    }

    // Limit to 10 results.
    $results = array_slice($results, 0, 10);

    return new JsonResponse(['results' => $results]);
  }

  
}
