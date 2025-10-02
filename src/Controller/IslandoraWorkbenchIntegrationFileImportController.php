<?php

namespace Drupal\islandora_workbench_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for importing files as file entities.
 */
class IslandoraWorkbenchIntegrationFileImportController extends ControllerBase
{

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    FileSystemInterface $file_system,
    LanguageManagerInterface $language_manager,
    AccountProxyInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->fileSystem = $file_system;
    $this->languageManager = $language_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('file_system'),
      $container->get('language_manager'),
      $container->get('current_user')
    );
  }

  /**
   * Check if a file exists.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response: true if file exists, false if not.
   */
  public function checkFile(Request $request): JsonResponse
  {
    // Example JSON:  {filepath:"/var/www/drupal/web/sites/default/files/file_attach/videosample.mp4"}
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (empty($data['filepath'])) {
      return new JsonResponse(false);
    }

    $filepath = $data['filepath'];

    // Check if file exists.
    if (file_exists($filepath)) {
      return new JsonResponse(true);
    }

    return new JsonResponse(false);
  }

  /**
   * Import a file as a file entity.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with file entity information.
   */
  public function importFile(Request $request): JsonResponse
  {
    // Example JSON:  {filepath:"/var/www/drupal/web/sites/default/files/file_attach/videosample.mp4"}
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    $filepath = $data['filepath'];

    try {
      // Get file extension.
      $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

      // Determine media type from extension.
      $bundle = $this->getMediaTypeFromExtension($extension);

      if (!$bundle) {
        return new JsonResponse(['error' => 'Could not determine media type for extension: ' . $extension], 400);
      }

      // Create file entity.
      $file = $this->createFileEntity($filepath, $bundle);

      if (!$file) {
        return new JsonResponse(['error' => 'Failed to create file entity'], 500);
      }

      return new JsonResponse([
        'message' => 'File entity created successfully',
        'file_id' => $file->id(),
        'file_name' => $file->getFilename(),
        'file_uri' => $file->getFileUri(),
      ], 201);
    } catch (\Exception $e) {
      return new JsonResponse(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
  }

  /**
   * Get media type from file extension.
   *
   * @param string $extension
   *   The file extension.
   *
   * @return string|null
   *   The media type bundle or NULL.
   */
  protected function getMediaTypeFromExtension(string $extension): ?string
  {
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();

    foreach ($media_types as $media_type) {
      $source_field = $media_type->getSource()->getSourceFieldDefinition($media_type);
      $allowed_extensions = $source_field->getSetting('file_extensions');

      if ($allowed_extensions) {
        $extensions_array = explode(' ', $allowed_extensions);
        if (in_array($extension, $extensions_array)) {
          return $media_type->id();
        }
      }
    }

    return NULL;
  }

  /**
   * Create file entity.
   *
   * @param string $filepath
   *   The file path.
   * @param string $bundle
   *   The media bundle.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity or NULL.
   */
  protected function createFileEntity(string $filepath, string $bundle): ?FileInterface
  {
    // Get target directory for this media type.
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($bundle);
    $source_field = $media_type->getSource()->getSourceFieldDefinition($media_type);

    //$scheme stores URI scheme like public:// or fedora://
    $scheme = $source_field->getFieldStorageDefinition()->getSetting('uri_scheme');
    //$directory stores year-month
    $directory = $source_field->getSetting('file_directory');

    // Replace tokens in directory.
    $token_service = \Drupal::token();
    $directory = $token_service->replace($directory);

    // Build target URI.
    $filename = basename($filepath);
    // URI example: public://2025/01/file.pdf
    $target_uri = $scheme . '://' . ($directory ? $directory . '/' : '') . $filename;

    // Check if file entity already exists.
    $existing_files = $this->entityTypeManager->getStorage('file')
      ->loadByProperties(['uri' => $target_uri]);

    if (!empty($existing_files)) {
      return reset($existing_files);
    }

    // Prepare directory.
    $target_directory = dirname($target_uri);
    $this->fileSystem->prepareDirectory($target_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Copy file to target location.
    $this->fileSystem->copy($filepath, $target_uri, FileSystemInterface::EXISTS_REPLACE);

    // Create file entity.
    $file = File::create([
      'uid' => $this->currentUser->id(),
      'filename' => $filename,
      'uri' => $target_uri,
      'status' => 1,
    ]);
    $file->save();

    return $file;
  }
}