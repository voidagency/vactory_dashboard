<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\file\Entity\File;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Controller for the Vactory Dashboard module.
 */
class VactoryDashboardController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * A list of menu items.
   *
   * @var array
   */
  protected $menuItems = [];

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
 * Cache interne.
 *
 * @var array
 */
protected static $cache = [];


  /**
   * Constructs a VactoryDashboardController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, ModuleHandlerInterface $module_handler, FileUrlGeneratorInterface $fileUrlGenerator, ConfigFactoryInterface $configFactory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->moduleHandler = $module_handler;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('module_handler'),
      $container->get('file_url_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * Returns a list of taxonomy vocabularies as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the vocabularies.
   */
  public function getVocabularies() {
    $cid = 'vactory_dashboard.vocabularies';
  
    // Cache statique interne (pour la même requête PHP)
    if (isset(self::$cache[$cid])) {
      return new JsonResponse(self::$cache[$cid]);
    }
  
    // Cache Drupal persistant
    if ($cache = \Drupal::cache()->get($cid)) {
      self::$cache[$cid] = $cache->data;
      return new JsonResponse($cache->data);
    }
  
    // Pas de cache : on charge les données
    $vocabularies = vactory_dashboard_get_taxonomy_vocabularies();
  
    // Mise en cache Drupal (permanent, avec tag pour invalidation)
    \Drupal::cache()->set($cid, $vocabularies, Cache::PERMANENT, ['taxonomy_vocabulary_list']);
  
    // Mise en cache statique interne
    self::$cache[$cid] = $vocabularies;
  
    return new JsonResponse($vocabularies);
  }
  
  public function getContentTypesItems(Request $request) {
    $cid = 'vactory_dashboard.content_types_items';
  
    // Cache statique interne
    if (isset(self::$cache[$cid])) {
      return new JsonResponse(['items' => self::$cache[$cid]]);
    }
  
    // Cache Drupal persistant
    if ($cache = \Drupal::cache()->get($cid)) {
      self::$cache[$cid] = $cache->data;
      return new JsonResponse(['items' => $cache->data]);
    }

  
    $config = $this->configFactory->get('vactory_dashboard.global.settings');
    $selected_content_types = $config->get('dashboard_content_types') ?? [];
  
    if (empty($selected_content_types)) {
      // Mise en cache d'un tableau vide
      \Drupal::cache()->set($cid, [], Cache::PERMANENT);
      self::$cache[$cid] = [];
      return new JsonResponse(['items' => []]);
    }
  
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple($selected_content_types);
  
    $content_types = [];
    foreach ($types as $type) {
      $content_types[] = [
        'id' => $type->id(),
        'label' => $type->label(),
        'path' => Url::fromRoute('vactory_dashboard.content_types', ['bundle' => $type->id()])
          ->toString(),
      ];
    }
  
    // Mise en cache Drupal (permanent, avec tags pour invalidation)
    $cache_tags = [
      'config:vactory_dashboard.global.settings',
      'node_type_list'
    ];
    \Drupal::cache()->set($cid, $content_types, Cache::PERMANENT, $cache_tags);
    
    // Mise en cache statique interne
    self::$cache[$cid] = $content_types;
  
    return new JsonResponse(['items' => $content_types]);
  }
  
  /**
   * Returns a list of webforms as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the webforms.
   */

   public function getForms() {
    $cid = 'vactory_dashboard.forms';
  
    // Cache statique interne (dans la même requête PHP)
    if (isset(self::$cache[$cid])) {
      return new JsonResponse(['items' => self::$cache[$cid]]);
    }
  
    // Cache Drupal persistant
    if ($cache = \Drupal::cache()->get($cid)) {
      self::$cache[$cid] = $cache->data;
      return new JsonResponse(['items' => $cache->data]);
    }
  
    $config = $this->configFactory->get('vactory_dashboard.global.settings');
    $selected_webforms = $config->get('dashboard_webforms') ?? [];
  
    if (empty($selected_webforms)) {
      // Mettre en cache un tableau vide pour éviter les requêtes répétées
      \Drupal::cache()->set($cid, [], Cache::PERMANENT);
      self::$cache[$cid] = [];
      return new JsonResponse(['items' => []]);
    }
  
    $webform_entities = $this->entityTypeManager->getStorage('webform')
      ->loadMultiple($selected_webforms);
  
    $forms = [];
    foreach ($webform_entities as $webform) {
      $forms[] = [
        'id' => $webform->id(),
        'title' => $webform->label(),
        'path' => Url::fromRoute('vactory_dashboard.webform', ['id' => $webform->id()])
          ->toString(),
      ];
    }
  
    // Mise en cache Drupal (permanent)
    \Drupal::cache()->set($cid, $forms, Cache::PERMANENT);
  
    // Mise en cache statique interne
    self::$cache[$cid] = $forms;
  
    return new JsonResponse(['items' => $forms]);
  }

  /**
   * Get main menu items.
   */
  public function getPrincipalMenuItems() {
    $cid = 'vactory_dashboard.principal_menu_items';
    // Cache statique interne
    if (isset(self::$cache[$cid])) {
      return new JsonResponse(self::$cache[$cid]);
    }
  
    // Cache Drupal persistant
    $cache = \Drupal::cache()->get($cid);
    if ($cache) {
      self::$cache[$cid] = $cache->data;
      return new JsonResponse($cache->data);
    }
  
    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks();
    $parameters->setMaxDepth(2);
    $parameters->setMinDepth(1);
    $menu_tree = \Drupal::menuTree();
  
    $menu_id = $this->config('vactory_dashboard.global.settings')->get('menu_id') ?? 'main';
    $tree = $menu_tree->load($menu_id, $parameters);
  
    if (empty($tree)) {
      $response = [
        'items' => [],
      ];
      self::$cache[$cid] = $response;
      \Drupal::cache()->set($cid, $response, Cache::PERMANENT, ['config:vactory_dashboard.menu.settings', 'menu:' . $menu_id]);
      return new JsonResponse($response);
    }
  
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
    ];
  
    $tree = $menu_tree->transform($tree, $manipulators);
    $menu = $menu_tree->build($tree);
  
    $items = [];
    $this->getMenuItems($menu['#items'], $items);
  
    $response = [
      'items' => array_values($items),
    ];

    \Drupal::cache()->set($cid, $response, Cache::PERMANENT, [
      'config:vactory_dashboard.menu.settings',
      'menu:' . $menu_id,
    ]);
    self::$cache[$cid] = $response;
  
    return new JsonResponse($response);
  }
  
  protected function getMenuItems(array $tree, array &$items = []) {
    foreach ($tree as $item_value) {
      $menu_entity = NULL;
      if (!empty($item_value['original_link']) && method_exists($item_value['original_link'], 'getEntity')) {
        $menu_entity = $item_value['original_link']->getEntity();
      }
  
      if ($menu_entity && $menu_entity->hasField('hide_menu_item') && !$menu_entity->get('hide_menu_item')->isEmpty()) {
        $hide_menu_item = $menu_entity->get('hide_menu_item')->value ?? FALSE;
        if ($hide_menu_item) {
          continue; 
        }
      }
  
      /** @var \Drupal\Core\Menu\MenuLinkInterface $org_link */
      $org_link = $item_value['original_link'];
  
      $newValue = $this->getElementValue($org_link);
  
      if (!empty($item_value['below'])) {
        $newValue['below'] = [];
        $this->getMenuItems($item_value['below'], $newValue['below']);
      }
  
      $items[] = $newValue;
    }
  }
  

  protected function getElementValue(MenuLinkInterface $link) {
    $returnArray = [];
    $uuid = $link->getDerivativeId();
    if (empty($uuid)) {
      $uuid = $link->getBaseId();
    }
    $returnArray['id'] = $uuid;
    $returnArray['title'] = $link->getTitle();
    $url = $link->getUrlObject()->toString();
    $current_lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $path = \Drupal::service('path_alias.manager')
      ->getPathByAlias(str_replace('/' . $current_lang, '', $url));
    if (str_starts_with($path, '/node/')) {
      $node_id = str_replace('/node/', '', $path);
      if (is_numeric($node_id)) {
        $node_id = (int) $node_id;
        $returnArray['nid'] = $node_id;
        $returnArray['url'] = Url::fromRoute('vactory_dashboard.vactory_page.edit', ['id' => $node_id], ['absolute' => TRUE])
        ->toString();
      }
    }
    return $returnArray;
  }

  public function isModuleInstalled($module_name) {
    $exists = $this->moduleHandler->moduleExists($module_name);
    return new JsonResponse(['exists' => $exists]);
  }

  public function getAdvancedDashboard() {
    $config = \Drupal::config('vactory_dashboard.advanced.settings');

    $file_url = NULL;
    $video_url = $config->get('video_tutoriel');
    $file_id = $config->get('tutorial_file');

    if (!empty($file_id)) {
      $file = \Drupal\file\Entity\File::load(is_array($file_id) ? reset($file_id) : $file_id);
      if ($file) {
        $file_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    return new JsonResponse([
      'video_url' => $video_url,
      'file_url' => $file_url,
    ]);
  }

  public function getAdvancedDashboardSupport() {
    $config = \Drupal::config('vactory_dashboard.advanced.support');

    $file_url = "";
    $firstName = $config->get('first_name');
    $lastName = $config->get('last_name');
    $title = $config->get('title');
    $email = $config->get('email');
    $phone = $config->get('phone');
    $image = $config->get('image');

    $image_id = is_array($image) ? reset($image) : $image;

    if (!empty($image_id) && is_numeric($image_id)) {
      $file = File::load($image_id);
      if ($file) {
        $file_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    $isPopulated = !empty($firstName) && !empty($lastName) && !empty($title) && !empty($email) && !empty($phone);

    return new JsonResponse([
      'first_name' => $firstName,
      'last_name' => $lastName,
      'full_name' => "$firstName $lastName",
      'title' => $title,
      'email' => $email,
      'phone' => $phone,
      'image' => $file_url,
      'isPopulated' => $isPopulated,
    ]);
  }

}
