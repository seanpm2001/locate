<?php
/**
 * Locate plugin for Craft CMS 4.x
 *
 * Harness the power of the Google Autocomplete API inside Craft. Adds an autocomplete search box to Craft entries.
 *
 * @link      https://www.vaersaagod.no/
 * @copyright Copyright (c) 2022 Værsågod AS
 */

namespace vaersaagod\locate\fields;

use vaersaagod\locate\Locate;
use vaersaagod\locate\assetbundles\locatefield\LocateFieldAsset;
use vaersaagod\locate\models\LocateModel;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

use yii\db\Schema;

/**
 * LocateField Field
 *
 * @author    Værsågod
 * @package   Locate
 * @since     2.0.0
 */
class LocateField extends Field
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $optionsObject = '';

    // Static Methods
    // =========================================================================

    /**
     * @return string The display name of this class.
     */
    public static function displayName(): string
    {
        return Craft::t('locate', 'Location');
    }

    // Public Methods
    // =========================================================================

    /** @inheritdoc */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules = array_merge($rules, [
            ['optionsObject', 'string'],
            ['optionsObject', 'default', 'value' => ''],
        ]);
        return $rules;
    }

    /** @inheritdoc */
    public function getContentColumnType(): string
    {
        return Schema::TYPE_TEXT;
    }

    /** @inheritdoc */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof LocateModel) {
            return $value;
        }

        $attr = [];

        if (is_string($value)) {
            $attr += array_filter(json_decode($value, true) ?: [],
                function ($key) {
                    return in_array($key, ['lat', 'lng', 'location', 'placeid', 'locationData']);
                }, ARRAY_FILTER_USE_KEY);
        } else if (is_array($value) && isset($value['isCpFormData'])) {
            if (!array_key_exists('location', $value) || $value['location']  === '') {
                return new LocateModel();
            }
            $attr += [
                'lat' => $value['lat'] ?? null,
                'lng' => $value['lng'] ?? null,
                'location' => $value['location'],
                'locationData' => $this->formatLocationData(Json::decode($value['locationData'] ?? '')),
                'placeid' => $value['placeid'],
            ];
        } else if (is_array($value)) {
            $attr = $value;
        }

        return new LocateModel($attr);
    }

    /** @inheritdoc */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return parent::serializeValue(StringHelper::encodeMb4(StringHelper::encodeMb4(json_encode($value))), $element);
    }

    /** @inheritdoc */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'locate/_components/fields/LocateField_settings',
            [
                'field' => $this,
                'settings' => $this->getSettings(),
                'pluginSettingsUrl' => UrlHelper::cpUrl('settings/plugins/' . Locate::getInstance()->getHandle())
            ]
        );
    }

    /** @inheritdoc */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        if (!$value) {
            $value = new LocateModel();
        }

        // Register our asset bundle
        Craft::$app->getView()->registerAssetBundle(LocateFieldAsset::class);

        $settings = Locate::getInstance()->getSettings();
        $projectConfigSettings = Craft::$app->getProjectConfig()->get('plugins.locate.settings') ?? [];

        $apiKey = App::parseEnv($settings->googleMapsApiKey ?: $projectConfigSettings['googleMapsApiKey'] ?? null);

        $id = Html::id($this->handle);

        if ($apiKey) {

            $apiUrl = UrlHelper::url('https://maps.googleapis.com/maps/api/js', [
                'key' => $apiKey,
                'libraries' => 'places',
            ]);

            $apiLanguage = App::parseEnv($settings->apiLanguage ?: $projectConfigSettings['apiLanguage'] ?? null);
            if ($apiLanguage) {
                $apiUrl .= "&language={$apiLanguage}";
            }

            $apiRegion = App::parseEnv($settings->apiRegion ?: $projectConfigSettings['apiRegion'] ?? null);
            if ($apiRegion) {
                $apiUrl .= "&region={$apiRegion}";
            }

            if (($this->getSettings()['optionsObject'] ?? null)) {
                $jsonOptions = $this->getSettings()['optionsObject'];
            } else {
                $jsonOptions = App::parseEnv($settings->autocompleteOptions ?: $projectConfigSettings['autocompleteOptions'] ?? null);
            }

            $namespacedId = Craft::$app->getView()->namespaceInputId($id);

            $jsonVars = [
                'id' => $id,
                'name' => $this->handle,
                'optionsObject' => $jsonOptions,
                'namespace' => $namespacedId,
            ];

            $jsonVars = Json::encode($jsonVars);

            Craft::$app->getView()->registerJsFile($apiUrl);

            $js = <<<JS
$('#$namespacedId-field').LocateField($jsonVars);
JS;
            Craft::$app->getView()->registerJs($js);
        }

        return Craft::$app->getView()->renderTemplate(
            'locate/_components/fields/LocateField_input',
            [
                'id' => $id,
                'name' => $this->handle,
                'field' => $this,
                'value' => $value,
                'apiKey' => $apiKey,
            ]
        );
    }

    /**
     * @param array|null $data
     * @return array
     */
    private function formatLocationData(?array $data = null): array
    {

        if (!$data) {
            return [];
        }

        $returnData = $data;
        $components = [];
        $addressComponents = $data['address_components'] ?? [];

        foreach ($addressComponents as $component) {
            $type = $component['types'][0];

            if (!$type) continue;

            $components[$type] = $component['long_name'];
            $components[$type . "_short"] = $component['short_name'];
        }

        $returnData['components'] = $components;

        return $returnData;

    }
}
