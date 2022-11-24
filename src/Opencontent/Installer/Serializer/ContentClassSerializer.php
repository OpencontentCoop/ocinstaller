<?php

namespace Opencontent\Installer\Serializer;

use Opencontent\Installer\InstallerVars;
use Symfony\Component\Yaml\Yaml;
use Opencontent\Installer\Logger;

class ContentClassSerializer
{
    public static $properties = array(
        'identifier' => 'Identifier',
        'contentobject_name' => 'ContentObjectName',
        'serialized_name_list' => 'SerializedNameList',
        'serialized_description_list' => 'SerializedDescriptionList',
        'url_alias_name' => 'URLAliasName',
        'always_available' => 'AlwaysAvailable',
        'sort_field' => 'SortField',
        'sort_order' => 'SortOrder',
        'is_container' => 'IsContainer'
    );

    public static $fields = array(
        'identifier' => 'Identifier',
        'serialized_description_list' => 'SerializedDescriptionList',
        'serialized_name_list' => 'SerializedNameList',
        'data_type_string' => 'DataTypeString',
        'placement' => 'Position',
        'is_searchable' => 'IsSearchable',
        'is_required' => 'IsRequired',
        'is_information_collector' => 'IsInformationCollector',
        'can_translate' => 'CanTranslate',
        'data_int1' => 'DataInt1',
        'data_int2' => 'DataInt2',
        'data_int3' => 'DataInt3',
        'data_int4' => 'DataInt4',
        'data_float1' => 'DataFloat1',
        'data_float2' => 'DataFloat2',
        'data_float3' => 'DataFloat3',
        'data_float4' => 'DataFloat4',
        'data_text1' => 'DataText1',
        'data_text2' => 'DataText2',
        'data_text3' => 'DataText3',
        'data_text4' => 'DataText4',
        'data_text5' => 'DataText5',
        'category' => 'Category',
        'serialized_data_text' => 'SerializedDataText'
    );

    private $identifier;

    private $filename;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var InstallerVars
     */
    private $installerVars;

    private $attributeDefinition;

    private $classDefinition;

    private $ignoreDefaultValues = true;

    public function __construct(InstallerVars $installerVars = null)
    {
        $this->logger = new Logger();
        $this->installerVars = $installerVars;
        $this->attributeDefinition = \eZContentClassAttribute::definition()['fields'];
        $this->classDefinition = \eZContentClass::definition()['fields'];
    }

    /**
     * @return bool
     */
    public function isIgnoreDefaultValues()
    {
        return $this->ignoreDefaultValues;
    }

    /**
     * @param bool $ignoreDefaultValues
     */
    public function setIgnoreDefaultValues($ignoreDefaultValues)
    {
        $this->ignoreDefaultValues = $ignoreDefaultValues;
    }

    public function serialize($json)
    {
        if (is_string($json)) {
            $data = json_decode($json, true);
        } else {
            $data = $json;
        }

        $this->identifier = $data['Identifier'];
        $this->filename = $this->identifier . '.yml';

        $simplifiedData = [];

        foreach (self::$properties as $identifier => $name) {
            if (isset($data[$name])) {
                $value = $data[$name];
                if (strpos($identifier, 'serialized_') !== false) {
                    $value = unserialize($value);
                    ksort($value);
                }
                if ($this->classDefinition[$identifier]['datatype'] == 'integer') {
                    $value = intval($value);
                }
                if ($this->classDefinition[$identifier]['datatype'] == 'float') {
                    $value = floatval($value);
                }
                if ($this->isIgnoreDefaultValues()) {
                    if ($this->classDefinition[$identifier]['default'] != $value) {
                        $simplifiedData[$identifier] = $value;
                    }
                } else {
                    $simplifiedData[$identifier] = $value;
                }
            }
        }

        $simplifiedData['data_map'] = [];
        foreach ($data['DataMap'] as $attributes) {
            foreach ($attributes as $identifier => $attribute) {
                $simplifiedData['data_map'][$identifier] = $this->serializeField($attribute);
            }
        }

        $simplifiedData['groups'] = [];
        foreach ($data['InGroups'] as $group) {
            $simplifiedData['groups'][] = $group['GroupName'];
        }

        return $simplifiedData;
    }

    private function preSerialize($data)
    {
        $staticMap = [
            '"51"' => '"$content_Images_node"',
            '"53"' => '"$content_Multimedia_node"',
            '"66"' => '"$contenttree_OpenCity_Servizi_node"',
            '"96"' => '"$contenttree_Classificazioni_Punti-di-contatto_node"',
            '"82"' => '"$contenttree_Documenti-e-dati_Dataset_node"',
            '"86"' => '"$contenttree_Documenti-e-dati_Documenti-tecnici-di-supporto_node"',
            '"75"' => '"$contenttree_Amministrazione_Politici_node"',
            '"76"' => '"$contenttree_Amministrazione_Uffici_node"',
            '"60"' => '"$contenttree_OpenCity_Argomenti_node"',
            '"90"' => '"$contenttree_Classificazioni_Condizioni-di-accesso_node"',
            '"71"' => '"$contenttree_Amministrazione_Enti-e-fondazioni_node"',
            '"70"' => '"$contenttree_Amministrazione_Aree-amministrative_node"',
            '"73"' => '"$contenttree_Amministrazione_Organi-politici_node"',
            '"92"' => '"$contenttree_Classificazioni_Costi-e-tariffe_node"',
            '"97"' => '"$contenttree_Classificazioni_Regole-leggi-riferimenti-normativi-e-linee-guida-per-i-servizi_node"',
            '"95"' => '"$contenttree_Classificazioni_Orari-strutture_node"',
            '"93"' => '"$contenttree_Classificazioni_Estensioni-temporali-dei-dataset_node"',
            '"94"' => '"$contenttree_Classificazioni_Orari-servizi_node"',
            '"89"' => '"$contenttree_Classificazioni_Canali-digitali_node"',
            '"91"' => '"$contenttree_Classificazioni_Cosa-puoi-richiedere_node"',
            '"72"' => '"$contenttree_Vivere-il-comune_Luoghi_node"',
            '"79"' => '"$contenttree_Vivere-il-comune_Eventi_node"',
            '"59"' => '"$contenttree_OpenCity_Amministrazione_node"',
            '"87"' => '"$contenttree_Documenti-e-dati_Modulistica_node"',
            '"83"' => '"$contenttree_Documenti-e-dati_Documenti-albo-pretorio_node"',
            '"88"' => '"$contenttree_Documenti-e-dati_Normative_node"',
            '"139"' => '"$content_Ruoli_node"',

        ];
        foreach ($data['DataMap'] as $index => $dataMap){
            foreach ($dataMap as $identifier => $datum){
                if ($datum['DataTypeString'] === 'ezobjectrelationlist'){
                    $dataText5 = $datum['DataText5'];
                    foreach ($staticMap as $find => $replace){
                        $dataText5 = str_replace($find, $replace, $dataText5);
                    }
                    $data['DataMap'][$index][$identifier]['DataText5'] = $dataText5;
                }
            }
        }

        return $data;
    }

    public function serializeToYaml($source, $targetDir)
    {
        if (is_string($source)) {
            $source = json_decode($source, true);
        }
        $source = $this->preSerialize($source);

        $data = $this->serialize($source);
        $dataYaml = Yaml::dump($data, 10);

        $identifier = \Opencontent\Installer\Dumper\Tool::slugize($data['identifier']);
        $filename = $identifier . '.yml';

        $directory = rtrim($targetDir, '/') . '/classes';

        \eZDir::mkdir($directory, false, true);
        \eZFile::create($filename, $directory, $dataYaml);

        return $identifier;
    }

    public function unserialize($data)
    {
        $unserializedData = [];
        foreach (self::$properties as $source => $target) {
            if (isset($data[$source])) {
                $value = $this->installerVars ? $this->installerVars->parseVarValue($data[$source]) : $data[$source];
                if (strpos($source, 'serialized_') !== false) {
                    $value = serialize($value);
                }
                $unserializedData[$target] = $value;
            } else {
                $unserializedData[$target] = $this->classDefinition[$source]['default'];
            }
        }

        $DataMap = [];
        foreach ($data['data_map'] as $identifier => $values) {
            $DataMap[$identifier] = $this->unserializeField($values);
        }
        $unserializedData['DataMap'] = [$DataMap];

        $unserializedData['InGroups'] = [];
        foreach ($data['groups'] as $name) {
            $unserializedData['InGroups'][] = ['GroupName' => $name];
        }


        return $unserializedData;
    }

    private function serializeField($data)
    {
        $simplifiedData = [];
        foreach (self::$fields as $identifier => $name) {
            if (isset($data[$name])) {
                $value = $data[$name];
                if ($this->isIgnoreDefaultValues()) {
                    if ((string)$this->attributeDefinition[$identifier]['default'] != (string)$value) {
                        $simplifiedData[$identifier] = $this->serializeValue($value, $identifier, $data);
                    }
                } else {
                    $simplifiedData[$identifier] = $this->serializeValue($value, $identifier, $data);
                }
            }
        }

        return $simplifiedData;
    }

    private function serializeValue($value, $identifier, $data)
    {
        if (strpos($identifier, 'serialized_') !== false) {
            $value = unserialize($value);
            ksort($value);
        }
        if (is_string($value) && strpos($value, '<?xml') !== false) {
            $value = $this->fromXML($value, $data['DataTypeString']);
        }

        $doVarCast = true;

        if ($data['DataTypeString'] == 'eztags' && $identifier == 'data_int1') {
            if (strpos($value, 'tag(') === false) {
                $tag = \eZTagsObject::fetch($value);
                if ($tag instanceof \eZTagsObject) {
                    $keywordsArray = array();
                    $path = $tag->getPath(false, true);
                    foreach ($path as $item) {
                        $keywordsArray[] = $item->attribute('keyword');
                    }
                    $keywordsArray[] = $tag->attribute('keyword');
                    $value = 'tag(' . implode(' / ', $keywordsArray) . ')';
                }

            }
            $doVarCast = false;
        }

        if ($doVarCast) {
            if ($this->attributeDefinition[$identifier]['datatype'] == 'integer') {
                $value = intval($value);
            }
            if ($this->attributeDefinition[$identifier]['datatype'] == 'float') {
                $value = floatval($value);
            }
        }

        return $value;
    }

    private function fromXML($value, $dataTypeString)
    {
        if ($dataTypeString == 'ezobjectrelationlist') {
            $dataType = new \eZObjectRelationListType();
            $doc = \eZObjectRelationListType::parseXML($value);
            $value = $dataType->createClassContentStructure($doc);
        }
        return $value;
    }

    private function unserializeField($data)
    {
        $unserializedData = [];
        foreach (self::$fields as $source => $target) {
            if (isset($data[$source])) {
                $value = $this->installerVars ? $this->installerVars->parseVarValue($data[$source]) : $data[$source];
                $unserializedData[$target] = $this->unserializeValue($value, $source, $data);
            } else {
                $unserializedData[$target] = $this->attributeDefinition[$source]['default'];
            }
        }

        return $unserializedData;
    }

    private function unserializeValue($value, $identifier, $data)
    {
        if (strpos($identifier, 'serialized_') !== false) {
            $value = serialize($value);
        }
        if (is_array($value)) {
            if (in_array($data['data_type_string'], ['ezobjectrelationlist'])) {
                $value = $this->toXML($value, $data['data_type_string']);
            }
        }
        return $value;
    }

    private function toXML($value, $dataTypeString)
    {
        if ($dataTypeString == 'ezobjectrelationlist') {
            $value = \eZObjectRelationListType::createClassDOMDocument($value)->saveXML();
        }
        return $value;
    }

}