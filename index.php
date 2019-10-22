<?php

use Kirby\Cms\Blueprint;
use Kirby\Cms\Api;
use Kirby\Cms\Form;
use Kirby\Cms\Content;
use Kirby\Form\Field;
use Kirby\Form\Fields;
use Kirby\Toolkit\I18n;

require_once __DIR__ . '/lib/BuilderBlueprint.php';
use KirbyBuilder\Builder\BuilderBlueprint;

function callFieldAPI($ApiInstance, $fieldPath, $context, $path) {
  $fieldPath = Str::split($fieldPath, '+');
  $form = Form::for($context);
  $field = fieldFromPath($fieldPath, $context, $form->fields()->toArray());
  $fieldApi = $ApiInstance->clone([
    'routes' => $field->api(),
    'data'   => array_merge($ApiInstance->data(), ['field' => $field])
  ]);
  return $fieldApi->call($path, $ApiInstance->requestMethod(), $ApiInstance->requestData());
}

function getBlockForm($value, $block, $model = null) {
  $fields = [];
  if (array_key_exists('fields', $block)) {
    $fields = $block['fields'];
  } else if (array_key_exists('tabs', $block)) {
    $tabs = $block['tabs'];
    foreach ( $tabs as $tabKey => $tab) {
      $fields = array_merge($fields, $tab['fields']);
    }
  }
  $form = new Form([
    'fields' => $fields,
    'values' => $value,
    'model'  => $model
  ]);
  return $form;
}

Kirby::plugin('timoetting/kirbybuilder', [
  'fields' => [
    'builder' => [
      'computed' => [
        'pageId' => function () {
          return $this->model()->id();
        },
        'pageUid' => function () {
          return $this->model()->uid();
        },
        'encodedPageId' => function () {
          return str_replace('/', '+', $this->model()->id());
        },
        'blockConfigs' => function () {
          return $this->fieldsets;
        },
        'value' => function () {
          $values = [];
          if ($this->value) {
            $values = Yaml::decode($this->value);
          } else if ($this->default) {
            $values = Yaml::decode($this->default);
          }
          return $values;
        },
        'cssUrls' => function() {
          return [];
        },
        'jsUrls' => function() {
          return [];
        }       
      ],
      'methods' => [
        'getData' => function ($values) {
          $vals = [];
          if ($values == null) {
            return $vals;
          }
          foreach ($values as $key => $value) {
            $blockKey = $value['_key'];
            if (array_key_exists($blockKey, $this->fieldsets)) {
              $block = $this->fieldsets[$blockKey];
              $form = $this->getBlockForm($value, $block);
            }
            $vals[] = $form->data();
          }
          return $vals;
        },
        'getBlockForm' => function ($value, $block) {
          return getBlockForm($value, $block, $this->model());
        },
      ],
      // TODO: Rebuild Validation (pass _blueprint along with _key and _uid)
      // 'validations' => [
      //   'validateChildren' => function ($values) {
      //     $errorMessages = [];
      //     foreach ($values as $key => $value) {
      //       $blockKey = $value['_key'];
      //       $block = $this->fieldsets[$blockKey];
      //       if (array_key_exists($blockKey, $this->fieldsets)) {
      //         $form = $this->getBlockForm($value, $block);
      //         if ($form->errors()) {
      //           foreach ($form->errors() as $fieldKey => $error) {
      //             foreach ($error["message"] as $errorKey => $message) {
      //               if ($errorKey != "validateChildren") {
      //                 $errorMessages[] = $error['label'] . ': ' . $message;
      //               } else {
      //                 $errorMessages[] = $message;
      //               }
      //             }
      //           }
      //         }
      //       }
      //     }
      //     if (count($errorMessages)) {
      //       throw new Exception(implode("\n", $errorMessages));
      //     }
      //   }
      // ],
      'save' => function ($values = null) {
        // return $this->getData($values);
        return $values;
      },
    ],
  ],
  'routes' => [
    [
      'pattern' => 'test/pages/(:any)/blockblueprint/(:any)/fields/(:any)/(:all?)',
      'action'  => function (string $id, string $blockBlueprint, string $field, string $apiPath = null) {
        if ($page = kirby()->page($id)) {
          return callFieldAPI($this, $page, $blockBlueprint, $field, $apiPath);
        }
        return "true";
      }
    ],
  ],
  'api' => [
    'routes' => [
      [
        'pattern' => 'kirby-builder/pages/(:any)/blockformbyconfig',
        'method' => 'POST',
        'action'  => function (string $pageUid) {
          $page = kirby()->page($pageUid);
          $blockConfig = kirby()->request()->data();
          $extendedProps = extendRecursively($blockConfig, $page);
          $defaultValues = [];
          if(array_key_exists("tabs", $extendedProps)) {
            $tabs = $extendedProps['tabs'];
            foreach ( $tabs as $tabKey => &$tab) {
              foreach ($tab["fields"] as $fieldKey => &$field) {
                if (array_key_exists("default", $field)) {
                  $defaultValues[$fieldKey] = $field["default"];
                }
              }
            }
          } else {
            foreach ($extendedProps["fields"] as $fieldKey => &$field) {
              if (array_key_exists("default", $field)) {
                $defaultValues[$fieldKey] = $field["default"];
              }
            }
          }
          $extendedProps["defaultValues"] = $defaultValues;
          return $extendedProps;
        }
      ],
      [
        'pattern' => 'kirby-builder/preview',
        'method' => 'POST',
        'action'  => function () {
          $existingPreviews = kirby()->session()->data()->get('kirby-builder-previews');
          $newPreview = [get('blockUid') => get('blockcontent')];
          if (isset($existingPreviews)) {
            $updatedPreviews = $existingPreviews;
            $updatedPreviews[get('blockUid')] = get('blockcontent');
            kirby()->session()->set('kirby-builder-previews', $updatedPreviews);
          } else {
            $newPreview = [get('blockUid') => get('blockcontent')];
            kirby()->session()->set('kirby-builder-previews', $newPreview);
          }
          return [
            'code' => 200,
            'status' => 'ok'
          ];
        }
      ],
      [
        'pattern' => 'kirby-builder/rendered-preview',
        'method' => 'POST',
        'action'  => function () {
          $kirby            = kirby();
          $blockUid         = get('blockUid');
          $blockContent     = get('blockContent');
          $block            = get('block');
          $previewOptions   = get('preview');
          $cache            = $kirby->cache('timoetting.builder');
          $existingPreviews = $cache->get('previews');
          if(isset($existingPreviews)) {
            $updatedPreviews            = $existingPreviews;
            $updatedPreviews[$blockUid] = $blockContent;
            $cache->set('previews', $updatedPreviews);
          } else {
            $newPreview = [$blockUid => $blockContent];
            $cache->set('previews', $newPreview);
          }
          $snippet      = $previewOptions['snippet'] ?? null;
          $modelName    = $previewOptions['modelname'] ?? 'data';
          $originalPage = $kirby->page(get('pageid'));
          $form = getBlockForm($blockContent, $block,$originalPage);
          return array(
            'preview' => snippet($snippet, ['page' => $originalPage, $modelName => new Content($form->data(), $originalPage)], true) ,
            'content' => get('blockContent')
          );
        }
      ],
      [
        'pattern' => 'kirby-builder/site/fields/(:any)/(:all?)',
        'method' => 'ALL',
        'action'  => function (string $fieldPath, string $path = null) {            
          return callFieldAPI($this, $fieldPath, site(), $path);
        }
      ],
      [
        'pattern' => 'kirby-builder/pages/(:any)/fields/(:any)/(:all?)',
        'method' => 'ALL',
        'action'  => function (string $id, string $fieldPath, string $path = null) {            
          if ($page = $this->page($id)) {
            return callFieldAPI($this, $fieldPath, $page, $path);
          }
        }
      ],
      [
        'pattern' => 'kirby-builder/pages/(:any)/blockblueprint/(:any)/fields/(:any)/(:all?)',
        'method' => 'ALL',
        'action'  => function (string $id, string $blockBlueprint, string $field, string $apiPath = null) {
          if ($page = $this->page($id)) {
            return callFieldAPI($this, $page, $blockBlueprint, $field, $apiPath);
          }
        }
      ],
    ],
  ],
  'translations' => [
    'en' => [
      'builder.clone' => 'Clone',
      'builder.preview' => 'Preview',
    ],
    'fr' => [
      'builder.clone' => 'Dupliquer',
      'builder.preview' => 'Aperçu',
    ],
    'de' => [
      'builder.clone' => 'Duplizieren',
      'builder.preview' => 'Vorschau',
    ],
    'sv' => [
      'builder.clone' => 'Duplicera',
      'builder.preview' => 'Förhandsgranska',
    ],
  ],  
  'templates' => [
    'snippet-wrapper' => __DIR__ . '/templates/snippet-wrapper.php'
  ],
  'fieldMethods' => [
    'toBuilderBlocks' => function ($field) {
      return $field->toStructure();
    }
  ]
]);

// example: http://localhost:8889/api/kirby-builder/pages/projects+trees-and-stars-and-stuff/fields/test+events+eventlist+event+downloads
function fieldFromPath($fieldPath, $page, $fields) {
  $fieldName = array_shift($fieldPath);
  $fieldProps = $fields[$fieldName];
  if ($fieldProps['type'] === 'builder' && count($fieldPath) > 0) {
    $fieldsetKey = array_shift($fieldPath);
    // $fieldset = $fieldProps['fieldsets'][$fieldsetKey];
    // $fieldset = $fieldProps['blocks'][$fieldsetKey];
    $fieldset = $fieldProps['fieldsets'][$fieldsetKey];
    // $fieldset = extendRecursively($fieldset, $page);
    $fieldset = Blueprint::extend($fieldset);
    $fieldset = extendRecursively($fieldset, $page, '__notNull');
    if (array_key_exists('tabs', $fieldset) && is_array($fieldset['tabs'])) {
      $fieldsetFields = [];
      foreach ( $fieldset['tabs'] as $tabKey => $tab) {
        $fieldsetFields = array_merge($fieldsetFields, $tab['fields']);
      }
    } else {
      $fieldsetFields = $fieldset['fields'];
    }
    return fieldFromPath($fieldPath, $page, $fieldsetFields);
  } else if ($fieldProps['type'] === 'structure' && count($fieldPath) > 0) {
    return fieldFromPath($fieldPath, $page, $fieldProps['fields']);
  } else {
    $fieldProps['model'] = $page;
    return new Field($fieldProps['type'], $fieldProps);
  }
}

function extendRecursively($properties, $page, $currentPropertiesName = null) {
  // $properties = BuilderBlueprint::extend($properties);
  if (array_key_exists("extends", $properties)) {
    $properties = BuilderBlueprint::extend($properties);
  }
  foreach ($properties as $propertyName => $property) {
    // if(is_array($property) || (is_string($property) && $currentPropertiesName === "fieldsets")){
    // TODO: müsste es $currentPropertiesName !== "blocks" sein?
    if(is_array($property) && $currentPropertiesName !== "fieldsets"){
      $properties[$propertyName] = BuilderBlueprint::extend($property);
      $properties[$propertyName] = extendRecursively($properties[$propertyName], $page, $propertyName);
    }
    if($propertyName === "label" || $propertyName === "name") {
      $translatedText = I18n::translate($property, $property);
      if (!empty($translatedText)) {
        $properties[$propertyName] = $translatedText;
      }
    }
  }
  if ($currentPropertiesName === 'fields') {
    $fieldForm = new Form([
      'fields' => $properties,
      'model'  => $page ?? null
    ]);
    $properties = $fieldForm->fields()->toArray();
  }
  return $properties;
}

function getExtendedBlockBlueprintProps($blockBlueprint, $page) {
  $mixin = Blueprint::find($blockBlueprint);
  $props = Data::read($mixin);
  return extendRecursively($props, $page);
}