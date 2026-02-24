<?php

namespace Drupal\rio_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'News and Events' block.
 *
 * Displays a combined or filtered list of News and/or Events nodes,
 * each with Title, Summary, Image, Date, and Category fields.
 *
 * @Block(
 *   id = "rio_blocks_news_events",
 *   admin_label = @Translation("News and Events Block"),
 *   category = @Translation("Rio Blocks"),
 * )
 */
class NewsEventsBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new NewsEventsBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'block_title' => $this->t('News & Events'),
      'content_types' => ['news', 'events'],
      'filter_by_category' => FALSE,
      'category_tids' => [],
      'number_of_items' => 3,
      'image_style' => 'medium',
      'date_format' => 'F j, Y',
      'show_view_all' => TRUE,
      'view_all_url' => '/news-events',
      'view_all_text' => $this->t('View All'),
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['block_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block Heading Title'),
      '#description' => $this->t('The heading title displayed above the news and events list.'),
      '#default_value' => $config['block_title'],
    ];

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content Types to Display'),
      '#description' => $this->t('Select which content types to include in this block.'),
      '#options' => [
        'news' => $this->t('News'),
        'events' => $this->t('Events'),
      ],
      '#default_value' => $config['content_types'],
      '#required' => TRUE,
    ];

    $form['number_of_items'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Items'),
      '#description' => $this->t('The total number of items to display (combined from selected content types).'),
      '#default_value' => $config['number_of_items'],
      '#min' => 1,
      '#max' => 20,
    ];

    // Category filter.
    $form['category_filter'] = [
      '#type' => 'details',
      '#title' => $this->t('Category Filter'),
      '#open' => !empty($config['filter_by_category']),
    ];

    $form['category_filter']['filter_by_category'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Filter by Category'),
      '#description' => $this->t('Restrict items to specific categories from the "News & Events Category" vocabulary.'),
      '#default_value' => $config['filter_by_category'],
    ];

    // Load available terms from the news_events_category vocabulary.
    $category_options = [];
    try {
      $terms = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'news_events_category']);
      foreach ($terms as $term) {
        $category_options[$term->id()] = $term->label();
      }
    }
    catch (\Exception $e) {
      // Vocabulary may not exist yet.
    }

    $form['category_filter']['category_tids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Categories'),
      '#description' => $this->t('Select one or more categories to filter by. Leave all unchecked to show all categories.'),
      '#options' => $category_options,
      '#default_value' => $config['category_tids'],
      '#states' => [
        'visible' => [
          ':input[name="settings[category_filter][filter_by_category]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Get available image styles.
    $image_styles = image_style_options(FALSE);
    $form['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Style'),
      '#description' => $this->t('The image style to apply to the news/event images.'),
      '#options' => $image_styles,
      '#default_value' => $config['image_style'],
      '#empty_option' => $this->t('- Original image -'),
    ];

    $form['date_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Date Format'),
      '#description' => $this->t('PHP date format string (e.g. "F j, Y" for "January 1, 2024"). See <a href="https://www.php.net/manual/en/function.date.php" target="_blank">PHP date documentation</a>.'),
      '#default_value' => $config['date_format'],
    ];

    $form['view_all'] = [
      '#type' => 'details',
      '#title' => $this->t('View All Link'),
      '#open' => TRUE,
    ];

    $form['view_all']['show_view_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show "View All" link'),
      '#default_value' => $config['show_view_all'],
    ];

    $form['view_all']['view_all_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"View All" URL'),
      '#description' => $this->t('The URL for the "View All" link (e.g. /news-events).'),
      '#default_value' => $config['view_all_url'],
      '#states' => [
        'visible' => [
          ':input[name="settings[view_all][show_view_all]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['view_all']['view_all_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"View All" Link Text'),
      '#description' => $this->t('The text for the "View All" link.'),
      '#default_value' => $config['view_all_text'],
      '#states' => [
        'visible' => [
          ':input[name="settings[view_all][show_view_all]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['block_title'] = $values['block_title'];
    // Filter out unchecked (0) values, keep only selected content types.
    $this->configuration['content_types'] = array_values(array_filter($values['content_types']));
    $this->configuration['filter_by_category'] = $values['category_filter']['filter_by_category'];
    // Filter out unchecked (0) values, keep only selected term IDs.
    $this->configuration['category_tids'] = array_values(array_filter($values['category_filter']['category_tids']));
    $this->configuration['number_of_items'] = $values['number_of_items'];
    $this->configuration['image_style'] = $values['image_style'];
    $this->configuration['date_format'] = $values['date_format'];
    $this->configuration['show_view_all'] = $values['view_all']['show_view_all'];
    $this->configuration['view_all_url'] = $values['view_all']['view_all_url'];
    $this->configuration['view_all_text'] = $values['view_all']['view_all_text'];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    $items = [];

    // Determine which content types to query.
    $content_types = !empty($config['content_types']) ? $config['content_types'] : ['news', 'events'];

    // Query for nodes of the selected content types.
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', $content_types, 'IN')
      ->condition('status', 1)
      ->sort('field_date', 'DESC')
      ->range(0, $config['number_of_items'])
      ->accessCheck(TRUE);

    // Apply category filter if configured.
    if (!empty($config['filter_by_category']) && !empty($config['category_tids'])) {
      $query->condition('field_category', $config['category_tids'], 'IN');
    }

    $nids = $query->execute();

    if (!empty($nids)) {
      $nodes = $node_storage->loadMultiple($nids);

      foreach ($nodes as $node) {
        $item = [
          'title' => $node->getTitle(),
          'url' => $node->toUrl()->toString(),
          'content_type' => $node->bundle(),
          'content_type_label' => $node->type->entity ? $node->type->entity->label() : $node->bundle(),
          'summary' => NULL,
          'image' => NULL,
          'date' => NULL,
          'date_start' => NULL,
          'date_end' => NULL,
          'is_date_range' => FALSE,
          'categories' => [],
        ];

        // Get Summary field.
        if ($node->hasField('field_summary') && !$node->get('field_summary')->isEmpty()) {
          $item['summary'] = [
            '#type' => 'processed_text',
            '#text' => $node->get('field_summary')->value,
            '#format' => $node->get('field_summary')->format ?? 'plain_text',
          ];
        }
        elseif ($node->hasField('body') && !$node->get('body')->isEmpty()) {
          // Fallback to body summary.
          $body = $node->get('body')->first();
          $summary_text = $body->summary ?: $body->value;
          if ($summary_text) {
            $item['summary'] = [
              '#type' => 'processed_text',
              '#text' => text_summary($summary_text, $body->format, 200),
              '#format' => $body->format ?? 'plain_text',
            ];
          }
        }

        // Get Image field.
        if ($node->hasField('field_image') && !$node->get('field_image')->isEmpty()) {
          $image_field = $node->get('field_image')->first();
          if ($image_field) {
            $image_entity = $image_field->entity;
            if ($image_entity) {
              $image_style = $config['image_style'];
              $image_uri = $image_entity->getFileUri();
              $image_alt = $image_field->alt ?? $node->getTitle();

              if (!empty($image_style)) {
                /** @var \Drupal\image\Entity\ImageStyle $style */
                $style = $this->entityTypeManager->getStorage('image_style')->load($image_style);
                if ($style) {
                  $item['image'] = [
                    '#theme' => 'image_style',
                    '#style_name' => $image_style,
                    '#uri' => $image_uri,
                    '#alt' => $image_alt,
                    '#title' => $node->getTitle(),
                  ];
                }
              }

              if (empty($item['image'])) {
                $item['image'] = [
                  '#theme' => 'image',
                  '#uri' => $image_uri,
                  '#alt' => $image_alt,
                  '#title' => $node->getTitle(),
                ];
              }
            }
          }
        }

        // Get Event Date fields (events content type only).
        // field_date is the primary/start date (required for events)
        // field_date_end is optional end date for multi-day events.
        if ($node->bundle() === 'events' && $node->hasField('field_date') && !$node->get('field_date')->isEmpty()) {
          $date_value = $node->get('field_date')->value;
          if ($date_value) {
            try {
              $start_date = new DrupalDateTime($date_value);
              $item['date_start'] = $start_date->format($config['date_format']);
              $item['date'] = $item['date_start'];
            }
            catch (\Exception $e) {
              $item['date_start'] = $date_value;
              $item['date'] = $date_value;
            }
          }

          // Check for optional end date.
          if ($node->hasField('field_date_end') && !$node->get('field_date_end')->isEmpty()) {
            $end_value = $node->get('field_date_end')->value;
            if ($end_value && $end_value !== $date_value) {
              $item['is_date_range'] = TRUE;
              try {
                $end_date = new DrupalDateTime($end_value);
                $item['date_end'] = $end_date->format($config['date_format']);
              }
              catch (\Exception $e) {
                $item['date_end'] = $end_value;
              }
            }
          }
        }

        // Get Date field (fallback for events if no field_date, primary for news).
        if (empty($item['date']) && $node->hasField('field_date') && !$node->get('field_date')->isEmpty()) {
          $date_value = $node->get('field_date')->value;
          if ($date_value) {
            try {
              $date = new DrupalDateTime($date_value);
              $item['date'] = $date->format($config['date_format']);
            }
            catch (\Exception $e) {
              $item['date'] = $date_value;
            }
          }
        }

        // Get Category field (taxonomy term references).
        if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
          foreach ($node->get('field_category') as $term_ref) {
            $term = $term_ref->entity;
            if ($term) {
              $item['categories'][] = [
                'label' => $term->label(),
                'url' => $term->toUrl()->toString(),
                'tid' => $term->id(),
              ];
            }
          }
        }

        $items[] = $item;
      }
    }

    // Build "View All" link.
    $view_all_link = NULL;
    if ($config['show_view_all'] && !empty($config['view_all_url'])) {
      try {
        $url = Url::fromUserInput($config['view_all_url']);
        $view_all_link = Link::fromTextAndUrl($config['view_all_text'], $url)->toRenderable();
        $view_all_link['#attributes']['class'][] = 'rio-blocks-view-all-link';
      }
      catch (\Exception $e) {
        // Invalid URL, skip the link.
      }
    }

    // Build cache tags for all selected content types.
    $cache_tags = ['taxonomy_term_list:news_events_category'];
    foreach ($content_types as $type) {
      $cache_tags[] = 'node_list:' . $type;
    }

    return [
      '#theme' => 'rio_blocks_news_events',
      '#items' => $items,
      '#title' => $config['block_title'],
      '#view_all_link' => $view_all_link,
      '#attached' => [
        'library' => ['rio_blocks/rio_blocks'],
      ],
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => ['languages'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $config = $this->getConfiguration();
    $content_types = !empty($config['content_types']) ? $config['content_types'] : ['news', 'events'];
    $tags = Cache::mergeTags(parent::getCacheTags(), ['taxonomy_term_list:news_events_category']);
    foreach ($content_types as $type) {
      $tags = Cache::mergeTags($tags, ['node_list:' . $type]);
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['languages']);
  }

}
