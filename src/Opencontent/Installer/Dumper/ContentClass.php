<?php

namespace Opencontent\Installer\Dumper;

use Opencontent\Installer\InstallerVars;
use Symfony\Component\Yaml\Yaml;
use Opencontent\Installer\Logger;

class ContentClass
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

    private $data;

    private $logger;

    private function __construct()
    {
        $this->logger = new Logger();
    }

    public static function fromJSON($json)
    {
        $instance = new static();
        $dataArray = json_decode($json, true);
        $instance->identifier = $dataArray['Identifier'];
        $instance->filename = $instance->identifier . '.yml';
        $instance->data = $instance->dump($dataArray);

        return $instance;
    }

    /**
     * @return mixed
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return mixed
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    public function store($targetDir)
    {
        $dataYaml = Yaml::dump($this->data, 10);
        $directory = rtrim($targetDir, '/') . '/classes';

        \eZDir::mkdir($directory, false, true);
        \eZFile::create($this->filename, $directory, $dataYaml);

        $this->logger->info($directory . '/' . $this->filename);
    }

    private function dump($data)
    {
        $simplifiedData = [];

        foreach (self::$properties as $identifier => $name){
            if (isset($data[$name])){
                $value = $data[$name];
                if (strpos($identifier, 'serialized_') !== false){
                    $value = unserialize($value);
                }
                $simplifiedData[$identifier] = $value;
            }
        }

        $simplifiedData['data_map'] = [];
        foreach ($data['DataMap'] as $attributes){
            foreach ($attributes as $identifier => $attribute) {
                $simplifiedData['data_map'][$identifier] = $this->dumpAttribute($attribute);
            }
        }

        $simplifiedData['groups'] = [];
        foreach ($data['InGroups'] as $group){
            $simplifiedData['groups'][] = $group['GroupName'];
        }

        return $simplifiedData;
    }

    private function dumpAttribute($data)
    {
        $simplifiedData = [];
        foreach (self::$fields as $identifier => $name){
            if (isset($data[$name])){
                $value = $data[$name];
                if (strpos($identifier, 'serialized_') !== false){
                    $value = unserialize($value);
                }
                $simplifiedData[$identifier] = $value;
            }
        }

        return $simplifiedData;
    }

    public static function hydrateData($data, InstallerVars $installerVars = null)
    {
        $hydrateData = [];
        foreach (\Opencontent\Installer\Dumper\ContentClass::$properties as $source => $target){
            if (isset($data[$source])){
                $value = $installerVars ? $installerVars->parseVarValue($data[$source]) : $data[$source];
                if (strpos($source, 'serialized_') !== false){
                    $value = serialize($value);
                }
                $hydrateData[$target] = $value;
            }
        }

        $DataMap = [];
        foreach ($data['data_map'] as $identifier => $values){
            $DataMap[$identifier] = self::hydrateField($values, $installerVars);
        }
        $hydrateData['DataMap'] = [$DataMap];

        $hydrateData['InGroups'] = [];
        foreach ($data['groups'] as $name){
            $hydrateData['InGroups'][] = ['GroupName' => $name];
        }


        return $hydrateData;
    }

    public static function hydrateField($data, InstallerVars $installerVars = null)
    {
        $hydrateData = [];
        foreach (\Opencontent\Installer\Dumper\ContentClass::$fields as $source => $target){
            if (isset($data[$source])){
                $value = $installerVars ? $installerVars->parseVarValue($data[$source]) : $data[$source];
                if (strpos($source, 'serialized_') !== false){
                    $value = serialize($value);
                }
                $hydrateData[$target] = $value;
            }
        }

        return $hydrateData;
    }
}