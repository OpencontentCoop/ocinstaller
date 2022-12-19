<?php

namespace Opencontent\Installer;

use OCClassTools;
use OCOpenDataClassRepositoryCache;
use Opencontent\Installer\Serializer\ContentClassSerializer;

class ContentClass extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install class $identifier");
        $this->installerVars['class_' . $identifier] = 0;
    }

    public function install()
    {
        $this->identifier = $this->step['identifier'];
        $sourcePath = "classes/{$this->identifier}.yml";
        $definitionData = $this->ioTools->getJsonContents($sourcePath);
        $definitionJsonFile = $this->createJsonFile($sourcePath);

        $this->logger->info("Install class $this->identifier");
        $force = isset($this->step['force']) && $this->step['force'];
        if ($force) {
            $this->logger->info(' - forcing sync');
        }
        $removeExtras = isset($this->step['remove_extra']) && $this->step['remove_extra'];
        if ($removeExtras) {
            $this->logger->info(' - removing extra attributes');
        }

        $tools = new OCClassTools($definitionData['identifier'], true, array(), $definitionJsonFile);

        $tools->compare();
        $this->logCompare($tools->getData());

        $tools->sync($force, $removeExtras);

        $class = $tools->getLocale();
        $this->installerVars['class_' . $this->identifier] = $class->attribute('id');

        OCOpenDataClassRepositoryCache::clearCache();

        $handler = \eZExpiryHandler::instance();
        $handler->setTimestamp('class-identifier-cache', -1);

        @unlink($definitionJsonFile);
    }

    private function logCompare($result)
    {
        if ($result->missingAttributes) {
            $this->logger->debug('    Attributi mancanti rispetto al prototipo: ' . count($result->missingAttributes));
            foreach ($result->missingAttributes as $identifier => $original) {
                $this->logger->debug("     -> $identifier ({$original->DataTypeString})");
            }
        }
        if ($result->extraAttributes) {
            $this->logger->debug('    Attributi aggiuntivi rispetto al prototipo: ' . count($result->extraAttributes));
            foreach ($result->extraAttributes as $attribute) {
                $detail = $result->extraDetails[$attribute->Identifier];
                $this->logger->debug("     -> {$attribute->Identifier} ({$attribute->DataTypeString})");
            }
        }
        if ($result->hasDiffAttributes) {
            $identifiers = array_keys($result->diffAttributes);
            $errors = array_intersect(array_keys($result->errors), $identifiers);
            $warnings = array_intersect(array_keys($result->warnings), $identifiers);

            if (count($errors) > 0)
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));
            elseif (count($warnings) > 0)
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));
            else
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));

            foreach ($result->diffAttributes as $identifier => $value) {
                if (isset($result->errors[$identifier]))
                    $this->logger->debug("     -> $identifier");
                elseif (isset($result->warnings[$identifier]))
                    $this->logger->debug("     -> $identifier");
                else
                    $this->logger->debug("     -> $identifier");

                foreach ($value as $diff) {
                    if (isset($result->errors[$identifier][$diff['field_name']]))
                        $this->logger->debug("        {$diff['field_name']}");
                    elseif (isset($result->warnings[$identifier][$diff['field_name']]))
                        $this->logger->debug("        {$diff['field_name']}");
                    else
                        $this->logger->debug("        {$diff['field_name']}");
                }
            }
        }
        if ($result->hasDiffProperties) {
            if (isset($result->errors['properties']))
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));
            elseif (isset($result->warnings['properties']))
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));
            else
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));

            foreach ($result->diffProperties as $property) {
                if (isset($result->errors['properties'][$property['field_name']]))
                    $this->logger->debug("        {$property['field_name']}");
                elseif (isset($result->warnings['properties'][$property['field_name']]))
                    $this->logger->debug("        {$property['field_name']}");
                else
                    $this->logger->debug("        {$property['field_name']}");
            }
        }
    }

    private function createJsonFile($source)
    {
        $data = $this->ioTools->getJsonContents($source);
        $serializer = new ContentClassSerializer($this->installerVars);
        $data = $serializer->unserialize($data);

        if ($data) {
            $filePath = $this->ioTools->getFile($source);
            $destinationFilePath = substr($filePath, 0, -4) . '.json';
            \eZFile::create(basename($destinationFilePath), dirname($destinationFilePath), json_encode($data));

            return $destinationFilePath;
        }

        return false;
    }

    public function sync()
    {
        $identifier = $this->step['identifier'];

        try {
            $tools = new OCClassTools($identifier);
            $result = $tools->getLocale();
            $result->attribute('data_map');
            $result->fetchGroupList();
            $result->fetchAllGroups();
            $json = json_encode($result);

            $preSerializer = function ($data){
                $relationNodes = [
                    '"51"' => '"$content_Images_node"',
                    '"53"' => '"$content_Multimedia_node"',
                    '"77"' => '"$contenttree_OpenCity_Servizi_node"',
                    '"100"' => '"$contenttree_Classificazioni_Punti-di-contatto_node"',
                    '"97"' => '"$contenttree_Documenti-e-dati_Dataset_node"',
                    '"93"' => '"$contenttree_Documenti-e-dati_Documenti-tecnici-di-supporto_node"',
                    '"80"' => '"$contenttree_Amministrazione_Politici_node"',
                    '"83"' => '"$contenttree_Amministrazione_Uffici_node"',
                    '"70"' => '"$contenttree_OpenCity_Argomenti_node"',
                    '"106"' => '"$contenttree_Classificazioni_Condizioni-di-accesso_node"',
                    '"78"' => '"$contenttree_Amministrazione_Enti-e-fondazioni_node"',
                    '"82"' => '"$contenttree_Amministrazione_Aree-amministrative_node"',
                    '"79"' => '"$contenttree_Amministrazione_Organi-politici_node"',
                    '"105"' => '"$contenttree_Classificazioni_Costi-e-tariffe_node"',
                    '"??"' => '"$contenttree_Classificazioni_Regole-leggi-riferimenti-normativi-e-linee-guida-per-i-servizi_node"',
                    '"103"' => '"$contenttree_Classificazioni_Orari-strutture_node"',
                    '"102"' => '"$contenttree_Classificazioni_Estensioni-temporali-dei-dataset_node"',
                    '"104"' => '"$contenttree_Classificazioni_Orari-servizi_node"',
                    '"107"' => '"$contenttree_Classificazioni_Canali-digitali_node"',
                    '"101"' => '"$contenttree_Classificazioni_Cosa-puoi-richiedere_node"',
                    '"90"' => '"$contenttree_Vivere-il-comune_Luoghi_node"',
                    '"91"' => '"$contenttree_Vivere-il-comune_Eventi_node"',
                    '"76"' => '"$contenttree_OpenCity_Amministrazione_node"',
                    '"95"' => '"$contenttree_Documenti-e-dati_Modulistica_node"',
                    '"94"' => '"$contenttree_Documenti-e-dati_Documenti-albo-pretorio_node"',
                    '"99"' => '"$contenttree_Documenti-e-dati_Normative_node"',
                    '"191"' => '"$content_Ruoli_node"',

                ];
                $reverseRelations = [
                    '{"attribute_id_list":[450,470],"sort":"name","order":"asc","limit":4,"attribute_id_subtree":{"95":0,"99":0}}'
                 => '{"attribute_id_list":[classattributeid_list(public_service/has_module_input,public_service/is_compliant_with_rule)],"sort":"name","order":"asc","limit":4,"attribute_id_subtree":{"$contenttree_Documenti-e-dati_Modulistica_node":0,"$contenttree_Documenti-e-dati_Normative_node":0}}',


                ];
                $identifierHash = array_flip(self::classAttributeIdentifiersHash());
                foreach ($data['DataMap'] as $index => $dataMap){
                    foreach ($dataMap as $attributeIdentifier => $datum){
                        if ($datum['DataTypeString'] === 'ezobjectrelationlist'){
                            $dataText5 = $datum['DataText5'];
                            foreach ($relationNodes as $find => $replace){
                                $dataText5 = str_replace($find, $replace, $dataText5);
                            }
                            $data['DataMap'][$index][$attributeIdentifier]['DataText5'] = $dataText5;
                        }
                        if ($datum['DataTypeString'] === 'openpareverserelationlist'){
                            $dataText5 = $datum['DataText5'];
                            foreach ($relationNodes as $find => $replace){
                                $dataText5 = str_replace($find, $replace, $dataText5);
                            }

                            $settings = json_decode($dataText5, true);
                            //'{"attribute_id_list":[classattributeid_list(public_service/has_module_input,public_service/is_compliant_with_rule)],"sort":"name","order":"asc","limit":4,"attribute_id_subtree":{"$contenttree_Documenti-e-dati_Modulistica_node":0,"$contenttree_Documenti-e-dati_Normative_node":0}}'
                            $attrIdListPlaceholder = '##ail##';
                            $attrIdFunctionString = '[classattributeid_list(';
                            foreach ($settings['attribute_id_list'] as $indexAil => $attributeId){
                                if ($indexAil > 0)
                                    $attrIdFunctionString .= ',';

                                if (isset($identifierHash[$attributeId])) {
                                    $attrIdFunctionString .= $identifierHash[$attributeId];
                                }
                            }
                            $attrIdFunctionString .= ')]';
                            $settings['attribute_id_list'] = $attrIdListPlaceholder;
                            $data['DataMap'][$index][$attributeIdentifier]['DataText5'] = str_replace("\"$attrIdListPlaceholder\"", $attrIdFunctionString, json_encode($settings));
                        }
                    }
                }

                return $data;
            };

            $serializer = new ContentClassSerializer($this->installerVars, $preSerializer);
            $serializer->serializeToYaml($json, $this->ioTools->getDataDir());
        }catch (\Exception $e){
            $this->getLogger()->error($e->getMessage());
        }
    }

    private static $identifierHash;

    private static function classAttributeIdentifiersHash()
    {
        if ( self::$identifierHash === null )
        {
            $db = \eZDB::instance();
            $dbName = md5( $db->DB );

            $cacheDir = \eZSys::cacheDirectory();
            $phpCache = new \eZPHPCreator( $cacheDir,
                'classattributeidentifiers_' . $dbName . '.php',
                '',
                array( 'clustering' => 'classattridentifiers' ) );

            $handler = \eZExpiryHandler::instance();
            $expiryTime = 0;
            if ( $handler->hasTimestamp( 'class-identifier-cache' ) )
            {
                $expiryTime = $handler->timestamp( 'class-identifier-cache' );
            }

            if ( $phpCache->canRestore( $expiryTime ) )
            {
                $var = $phpCache->restore( array( 'identifierHash' => 'identifier_hash' ) );
                self::$identifierHash = $var['identifierHash'];
            }
            else
            {
                // Fetch identifier/id pair from db
                $query = "SELECT ezcontentclass_attribute.id as attribute_id, ezcontentclass_attribute.identifier as attribute_identifier, ezcontentclass.identifier as class_identifier
                          FROM ezcontentclass_attribute, ezcontentclass
                          WHERE ezcontentclass.id=ezcontentclass_attribute.contentclass_id";
                $identifierArray = $db->arrayQuery( $query );

                self::$identifierHash = array();
                foreach ( $identifierArray as $identifierRow )
                {
                    $combinedIdentifier = $identifierRow['class_identifier'] . '/' . $identifierRow['attribute_identifier'];
                    self::$identifierHash[$combinedIdentifier] = (int) $identifierRow['attribute_id'];
                }

                // Store identifier list to cache file
                $phpCache->addVariable( 'identifier_hash', self::$identifierHash );
                $phpCache->store();
            }
        }
        return self::$identifierHash;
    }
}