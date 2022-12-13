<?php
/**
 * Reasons plugin for Craft CMS 3.x
 *
 * Adds conditionals to field layouts.
 *
 * @link      https://vaersaagod.no
 * @copyright Copyright (c) 2020 Mats Mikkel Rummelhoff
 */

namespace mmikkel\reasons;

use craft\web\Application;
use mmikkel\reasons\assetbundles\reasons\ReasonsAssetBundle;
use mmikkel\reasons\services\ReasonsService;

use Craft;
use craft\base\Plugin;
use craft\base\Element;
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\events\FieldLayoutEvent;
use craft\events\PluginEvent;
use craft\events\TemplateEvent;
use craft\events\RegisterElementHtmlAttributesEvent;
use craft\events\DefineFieldHtmlEvent;
use craft\events\DefineMetadataEvent;
use craft\events\DefineHtmlEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\helpers\Html;
use craft\web\View;

use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Class Reasons
 *
 * @author    Mats Mikkel Rummelhoff
 * @package   Reasons
 * @since     2.0.0
 *
 * @property  ReasonsService $reasons
 */
class Reasons extends Plugin
{

    /**
     * @var string
     */
    public string $schemaVersion = '2.1.1';

    /**
     * @var bool
     */
    public bool $hasCpSettings = false;

    /**
     * @var bool
     */
    public bool $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init():void {
        parent::init();

        $this->setComponents([
            'reasons' => ReasonsService::class,
        ]);

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent &$event) {
                if ($event->plugin === $this) {
                    $this->reasons->clearCache();
                }
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_UNINSTALL_PLUGIN,
            function (PluginEvent &$event) {
                if ($event->plugin === $this) {
                    $this->reasons->clearCache();
                    Craft::$app->getProjectConfig()->remove('reasons_conditionals');
                }
            }
        );

        // Save or delete conditionals when field layout is saved
        Event::on(
            Fields::class,
            Fields::EVENT_AFTER_SAVE_FIELD_LAYOUT,
            function (FieldLayoutEvent &$event) {
                if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                    return;
                }
                $fieldLayout = $event->layout;
                if (!$fieldLayout) {
                    return;
                }
                $conditionals = Craft::$app->getRequest()->getBodyParam('_reasons', null);
                if ($conditionals === null) {
                    return;
                }
                try {
                    if ($conditionals) {
                        $this->reasons->saveFieldLayoutConditionals($fieldLayout, $conditionals);
                    } else {
                        $this->reasons->deleteFieldLayoutConditionals($fieldLayout);
                    }
                } catch (\Throwable $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                    if (Craft::$app->getConfig()->getGeneral()->devMode) {
                        throw $e;
                    }
                }
            }
        );

        // Delete conditionals when field layouts are deleted
        Event::on(
            Fields::class,
            Fields::EVENT_AFTER_DELETE_FIELD_LAYOUT,
            function (FieldLayoutEvent &$event) {
                $fieldLayout = $event->layout;
                if (!$fieldLayout) {
                    return;
                }
                $this->reasons->deleteFieldLayoutConditionals($fieldLayout);
            }
        );

        // Clear data caches when fields are saved
        Event::on(
            Fields::class,
            Fields::EVENT_AFTER_SAVE_FIELD,
            [$this->reasons, 'clearCache']
        );

        // Clear data caches when fields are deleted
        Event::on(
            Fields::class,
            Fields::EVENT_AFTER_DELETE_FIELD,
            [$this->reasons, 'clearCache']
        );

        // Support Project Config rebuild
        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            [$this->reasons, 'onProjectConfigRebuild']
        );

        // Listen for appropriate Project Config changes
        Craft::$app->getProjectConfig()
            ->onAdd('reasons_conditionals.{uid}', [$this->reasons, 'onProjectConfigChange'])
            ->onUpdate('reasons_conditionals.{uid}', [$this->reasons, 'onProjectConfigChange'])
            ->onRemove('reasons_conditionals.{uid}', [$this->reasons, 'onProjectConfigDelete']);

        // Queue up asset bundle or handle AJAX action requests
        Event::on(
            Application::class,
            Application::EVENT_INIT,
            [$this, 'initReasons']
        );

        Event::on (
            // Element::class, Element::EVENT_DEFINE_META_FIELDS_HTML, function (DefineHtmlEvent &$event) {
            Element::class, Element::EVENT_DEFINE_METADATA, function (DefineMetadataEvent $event) {
                if (!isset($event->sender->id) && !isset($event->sender->sectionId) && !isset($event->sender->typeId)) {
                    return;
                }

                $sectionTag = Html::tag('span', $event->sender->section->name, [
                    'data' => [
                        'section-id' => $event->sender->sectionId
                    ],
                    'aria' => ['hidden' => 'true'],
                ]);

                $typeTag = Html::tag('span', $event->sender->type->name, [
                    'data' => [
                        'type-id' => $event->sender->typeId
                    ],
                    'aria' => ['hidden' => 'true'],
                ]);

                $sectionInput = Html::hiddenInput('sectionId', $event->sender->sectionId);
                $typeInput = Html::hiddenInput('typeId', $event->sender->typeId);

                $event->metadata['Section'] = $sectionTag . $sectionInput;
                $event->metadata['Type'] = $typeTag . $typeInput;

                if (getenv('ENVIRONMENT') === 'dev') {
                    $prefix = str_replace('\\', '-', get_class($event->sender));
                    $output = $event;
                    //$output = array_keys(get_object_vars($event));
                    //file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/assets/' . $prefix . '-Event-' . time() . '.txt', print_r($output, TRUE));
                }
            }
        );
    }

    /**
     * @return void
     */
    public function initReasons()
    {

        $request = Craft::$app->getRequest();

        /** @var User $user */
        $user = Craft::$app->getUser()->getIdentity();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || $request->getIsSiteRequest() || $request->getIsLoginRequest() || !$user || !$user->can('accessCp')) {
            return;
        }

        $isAjax = $request->getIsAjax() || $request->getAcceptsJson();
        if ($isAjax) {
            $this->initAjaxRequest();
        } else {
            $this->registerAssetBundle();
        }

    }

    // Protected Methods
    // =========================================================================

    /**
     * @return void
     */
    protected function registerAssetBundle()
    {
        // Register asset bundle
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            function (/** @scrutinizer ignore-unused */ TemplateEvent $event) {
                try {
                    Craft::$app->getView()->registerAssetBundle(ReasonsAssetBundle::class);
                } catch (InvalidConfigException $e) {
                    Craft::error(
                        'Error registering AssetBundle - ' . $e->getMessage(),
                        __METHOD__
                    );
                }
            }
        );
    }

    /**
     * @return void
     */
    protected function initAjaxRequest()
    {

        $request = Craft::$app->getRequest();
        if (!$request->getIsPost() || !$request->getIsActionRequest()) {
            return;
        }

        $actionSegments = $request->getActionSegments();
        $actionSegment = $actionSegments[\count($actionSegments) - 1] ?? null;

        if (!$actionSegment) {
            return;
        }

        if ($actionSegment === 'switch-entry-type') {
            Craft::$app->getView()->registerJs('Craft.ReasonsPlugin.initPrimaryForm();');
            return;
        }

        if ($actionSegment === 'get-editor-html') {

            $conditionalsKey = null;
            $elementId = (int)$request->getBodyParam('elementId');

            if (!$elementId) {

                // Probably a new element
                $attributes = $request->getBodyParam('attributes', []);
                $elementType = $request->getBodyParam('elementType');

                if ($elementType === Entry::class && $typeId = ($attributes['typeId'] ?? null)) {
                    $conditionalsKey = "entryType:$typeId";
                } else if ($elementType === Category::class && $groupId = ($attributes['groupId'] ?? null)) {
                    $conditionalsKey = "categoryGroup:$groupId";
                }

                if (!$conditionalsKey) {
                    return;
                }

            } else if ($element = Craft::$app->getElements()->getElementById($elementId)) {

                $elementType = \get_class($element);

                switch ($elementType) {
                    case Entry::class:
                        /** @var Entry $element */
                        $conditionalsKey = "entryType:{$element->typeId}";
                        break;

                    case GlobalSet::class:
                        /** @var GlobalSet $element */
                        $conditionalsKey = "globalSet:{$element->id}";
                        break;

                    case Asset::class:
                        /** @var Asset $element */
                        $conditionalsKey = "assetSource:{$element->volumeId}";
                        break;

                    case Category::class:
                        /** @var Category $element */
                        $conditionalsKey = "categoryGroup:{$element->groupId}";
                        break;

                    case Tag::class:
                        /** @var Tag $element */
                        $conditionalsKey = "tagGroup:{$element->groupId}";
                        break;

                    case User::class:
                        $conditionalsKey = "users";
                        break;

                    default:
                        return;

                }
            }

            Craft::$app->getView()->registerJs("Craft.ReasonsPlugin.initElementEditor('{$conditionalsKey}');");

        }

    }

}
