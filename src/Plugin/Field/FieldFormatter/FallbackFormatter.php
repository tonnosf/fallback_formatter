<?php

/**
 * @file
 * Contains \Drupal\fallback_formatter\Plugin\Field\FieldFormatter\FallbackFormatter.
 */

namespace Drupal\fallback_formatter\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Fallback formatter.
 *
 * @FieldFormatter(
 *   id = "fallback",
 *   label = @Translation("Fallback"),
 *   weight = 100
 * )
 */
class FallbackFormatter extends FormatterBase {

  /**
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected $formatterManager;

  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);

    $this->formatterManager = \Drupal::service('plugin.manager.field.formatter');
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = array();
    $settings = $this->getSettings();

    $items_array = array();
    foreach ($items as $item) {
      $items_array[] = $item;
    }

    // Merge defaults from the formatters and ensure proper ordering.
    $this->prepareFormatters($this->fieldDefinition->getType(), $settings['formatters']);

    // Loop through each formatter in order.
    foreach ($settings['formatters'] as $name => $options) {

      // Run any unrendered items through the formatter.
      $formatter_items = array_diff_key($items_array, $element);

      $formatter_instance = $this->getFormatter($options);
      $formatter_instance->prepareView(array($items->getEntity()->id() => $items));

      if ($result = $formatter_instance->viewElements($items, $langcode)) {

        // Only add visible content from the formatter's render array result
        // that matches an unseen delta.
        $visible_deltas = Element::getVisibleChildren($result);
        $visible_deltas = array_intersect($visible_deltas, array_keys($formatter_items));
        $element += array_intersect_key($result, array_flip($visible_deltas));

        // If running this formatter completed the output for all items, then
        // there is no need to loop through the rest of the formatters.
        if (count($element) == count($items_array)) {
          break;
        }
      }
    }

    // Ensure the resulting elements are ordered properly by delta.
    ksort($element);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    $settings = $this->getSettings();

    $formatters = $settings['formatters'];
    $this->prepareFormatters($this->fieldDefinition->getType(), $formatters, FALSE);

    $elements['#attached']['library'][] = 'fallback_formatter/admin';

    $parents = array('fields', $this->fieldDefinition->getName(), 'settings_edit_form', 'settings', 'formatters');

    // Filter status.
    $elements['formatters']['status'] = array(
      '#type' => 'item',
      '#title' => t('Enabled formatters'),
      '#prefix' => '<div class="fallback-formatter-status-wrapper">',
      '#suffix' => '</div>',
    );
    foreach ($formatters as $name => $options) {
      $elements['formatters']['status'][$name] = array(
        '#type' => 'checkbox',
        '#title' => $options['label'],
        '#default_value' => !empty($options['status']),
        '#parents' => array_merge($parents, array($name, 'status')),
        '#weight' => $options['weight'],
      );
    }

    // Filter order (tabledrag).
    $elements['formatters']['order'] = array(
      '#type' => 'item',
      '#title' => t('Formatter processing order'),
      '#theme' => 'fallback_formatter_settings_order',
    );
    foreach ($formatters as $name => $options) {
      $elements['formatters']['order'][$name]['label'] = array(
        '#markup' => $options['label'],
      );
      $elements['formatters']['order'][$name]['weight'] = array(
        '#type' => 'weight',
        '#title' => t('Weight for @title', array('@title' => $options['label'])),
        '#title_display' => 'invisible',
        '#delta' => 50,
        '#default_value' => $options['weight'],
        '#parents' => array_merge($parents, array($name, 'weight')),
      );
      $elements['formatters']['order'][$name]['#weight'] = $options['weight'];
    }

    // Filter settings.
    foreach ($formatters as $name => $options) {
      $formatter_instance = $this->getFormatter($options);
      $settings_form = $formatter_instance->settingsForm($form, $form_state);

      if (!empty($settings_form)) {
        $elements['formatters']['settings'][$name] = array(
          '#type' => 'fieldset',
          '#title' => $options['label'],
          '#parents' => array_merge($parents, array($name, 'settings')),
          '#weight' => $options['weight'],
          '#group' => 'formatter_settings',
        );
        $elements['formatters']['settings'][$name] += $settings_form;
      }

      $elements['formatters']['settings'][$name]['formatter'] = array(
        '#type' => 'value',
        '#value' => $name,
        '#parents' => array_merge($parents, array($name, 'formatter')),
      );
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $settings = $this->getSettings();
    $formatters = $this->formatterManager->getDefinitions();

    $this->prepareFormatters($this->fieldDefinition->getType(), $settings['formatters']);

    $summary_items = array();
    foreach ($settings['formatters'] as $name => $options) {
      if (!isset($formatters[$name])) {
        $summary_items[] = t('Unknown formatter %name.', array('%name' => $name));
      }
      elseif (!in_array($this->fieldDefinition->getType(), $formatters[$name]['field_types'])) {
        $summary_items[] = t('Invalid formatter %name.', array('%name' => $formatters[$name]['label']));
      }
      else {

        $formatter_instance = $this->getFormatter($options);
        $result = $formatter_instance->settingsSummary();

        $summary_items[] = SafeMarkup::format('<strong>@label</strong>!settings_summary', array(
          '@label' => $formatter_instance->getPluginDefinition()['label'],
          '!settings_summary' => '<br>' . Xss::filter(!empty($result) ? implode(', ', $result) : ''),
        ));
      }
    }

    if (empty($summary_items)) {
      $summary = array(
        '#markup' => t('No formatters selected yet.'),
        '#prefix' => '<strong>',
        '#suffix' => '</strong>',
      );
    }
    else {
      $summary = array(
        '#theme' => 'item_list',
        '#items' => $summary_items,
        '#type' => 'ol'
      );
    }

    return array(drupal_render($summary));
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'formatters' => array(),
    );
  }

  /**
   * Gets an instance of a formatter.
   *
   * @param array $options
   *   Formatter options.
   *
   * @return \Drupal\Core\Field\FormatterInterface
   */
  protected function getFormatter($options) {
    if (!isset($options['settings'])) {
      $options['settings'] = array();
    }

    $options += array(
      'field_definition' => $this->fieldDefinition,
      'view_mode' => $this->viewMode,
      'configuration' => array('type' => $options['id'], 'settings' => $options['settings']),
    );

    return $this->formatterManager->getInstance($options);
  }

  /**
   * Decorates formatters definitions to be complete for plugin instantiation.
   *
   * @param string $field_type
   *   The field type for which to prepare the formatters.
   * @param array $formatters
   *   The formatter definitions we want to prepare.
   * @param bool $filter_enabled
   *   If TRUE (default) will filter out any disabled formatters. If FALSE
   *   will return all possible formatters.
   *
   * @todo - this might be merged with getFormatter()?
   */
  protected function prepareFormatters($field_type, array &$formatters, $filter_enabled = TRUE) {
    $default_weight = 0;

    $allowed_formatters = $this->getPossibleFormatters($field_type);
    $formatters += $allowed_formatters;

    $formatters = array_intersect_key($formatters, $allowed_formatters);

    foreach ($formatters as $formatter => $info) {
      // Remove disabled formatters.
      if ($filter_enabled && empty($info['status'])) {
        unset($formatters[$formatter]);
        continue;
      }

      // Provide some default values.
      $formatters[$formatter] += array('weight' => $default_weight++);
      // Merge in defaults.
      $formatters[$formatter] += $allowed_formatters[$formatter];
      if (!empty($allowed_formatters[$formatter]['settings'])) {
        $formatters[$formatter]['settings'] += $allowed_formatters[$formatter]['settings'];
      }
    }

    // Sort by weight.
    uasort($formatters, array('Drupal\Component\Utility\SortArray', 'sortByWeightElement'));
  }

  /**
   * Gets possible formatters for the given field type.
   *
   * @param string $field_type
   *   Field type for which we want to get the possible formatters.
   *
   * @return array
   *   Formatters info array.
   */
  protected function getPossibleFormatters($field_type) {
    $return = array();

    foreach (\Drupal::service('plugin.manager.field.formatter')->getDefinitions() as $formatter => $info) {
      // The fallback formatter cannot be used as a fallback formatter.
      if ($formatter == 'fallback') {
        continue;
      }
      // Check that the field type is allowed for the formatter.
      elseif (!in_array($field_type, $info['field_types'])) {
        continue;
      }
      elseif (!$info['class']::isApplicable($this->fieldDefinition)) {
        continue;
      }
      else {
        $return[$formatter] = $info;
      }
    }

    return $return;
  }


}
