<?php

namespace Opencontent\Installer;

use eZContentObject;
use eZDBInterface;
use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;
use Opencontent\Opendata\Rest\Client\PayloadBuilder;
use Psr\Log\LoggerInterface;

abstract class AbstractStepInstaller implements InterfaceStepInstaller
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    /**
     * @var IOTools
     */
    protected $ioTools;

    /**
     * @var array
     */
    protected $step;

    /**
     * @var eZDBInterface
     */
    protected $db;

    protected $stepIndex = false;

    /**
     * @var ?InterfaceStepInstaller
     */
    private $parentStepInstaller = null;

    /**
     * @return Logger
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param Logger $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return InstallerVars
     */
    public function getInstallerVars(): InstallerVars
    {
        return $this->installerVars;
    }

    /**
     * @param InstallerVars $installerVars
     */
    public function setInstallerVars(InstallerVars $installerVars): void
    {
        $this->installerVars = $installerVars;
    }

    /**
     * @return IOTools
     */
    public function getIoTools(): IOTools
    {
        return $this->ioTools;
    }

    /**
     * @param IOTools $ioTools
     */
    public function setIoTools(IOTools $ioTools): void
    {
        $this->ioTools = $ioTools;
    }

    /**
     * @return array
     */
    public function getStep()
    {
        return $this->step;
    }

    /**
     * @param $step
     * @throws \Exception
     */
    public function setStep($step)
    {
        if ($this->installerVars) {
            $stepString = json_encode($step);
            $stepString = $this->installerVars->filter($stepString);
            $this->installerVars->validate($stepString, isset($step['identifier']) ? $step['identifier'] : '');
            $step = json_decode($stepString, true);
        }

        $this->step = $step;
    }

    /**
     * @return eZDBInterface
     */
    public function getDb(): eZDBInterface
    {
        return $this->db;
    }

    /**
     * @param eZDBInterface $db
     */
    public function setDb(eZDBInterface $db): void
    {
        $this->db = $db;
    }

    public function sync(): void
    {
    }

    protected function resetContentFields(
        array $resetFields,
        PayloadBuilder $payload,
        eZContentObject $object = null
    ) {
        if (count($resetFields) && $object instanceof eZContentObject && $this->installerVars['reset']) {
            $data = $payload->getData();
            foreach ($data as $locale => $values) {
                foreach ($values as $id => $value) {
                    if (!in_array($id, $resetFields)) {
                        $payload->unSetData($id, $locale);
                    } elseif (empty($value)) {
                        $zoneRemoteId = substr('empty_' . md5(mt_rand() . microtime()), 0, 6);
                        $dataMap = $object->dataMap();
                        if ($dataMap[$id]->DataTypeString === \eZPageType::DATA_TYPE_STRING) {
                            $payload->setData($locale, $id, [
                                'zone_layout' => 'desItaGlobal',
                                'global' => [
                                    'zone_id' => $zoneRemoteId,
                                    'blocks' => [
                                        [
                                            'id' => 'empty_html_block',
                                            'name' => '',
                                            'type' => 'HTML',
                                            'view' => 'html',
                                            'custom_attributes' => [
                                                'html' => '',
                                            ],
                                            'valid_items' => [],
                                        ],
                                    ],
                                ],
                            ]);
                        }
                    }
                }
            }
            $contentRepository = new ContentRepository();
            $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
            eZContentObject::clearCache([$object->attribute('id')]);
            $contentRepository->update($payload->getArrayCopy());
            $this->logger->warning(" - Reset fields: " . implode(', ', $resetFields));
            eZContentObject::clearCache([$object->attribute('id')]);
        }
    }

    protected function lockContentByNode(\eZContentObjectTreeNode $node)
    {
        eZContentObject::clearCache();
        return $this->lockContent($node->object());
    }

    protected function lockContent(eZContentObject $object)
    {
        $stateGroup = \eZContentObjectStateGroup::fetchByIdentifier('opencity_lock');
        if ($stateGroup instanceof \eZContentObjectStateGroup) {
            $state = \eZContentObjectState::fetchByIdentifier('locked', $stateGroup->attribute('id'));
            $object->assignState($state);
            return true;
        }
        return false;
    }

    protected function lockContentByRemoteId($remoteId)
    {
        eZContentObject::clearCache();
        $object = eZContentObject::fetchByRemoteID($remoteId);
        if ($object instanceof eZContentObject) {
            return $this->lockContent($object);
        }
        return false;
    }

    public static function getStepName(array $step) : string
    {
        return isset($step['identifier']) ? $step['type'] . ' ' . $step['identifier'] : $step['type'];
    }

    public function getStepIndex(): string
    {
        if ($this->getParentStepInstaller() instanceof InterfaceStepInstaller
            && !empty($this->getParentStepInstaller()->getStepIndex())) {
            return $this->getParentStepInstaller()->getStepIndex() . '.' . $this->stepIndex;
        }
        return $this->stepIndex;
    }

    public function setStepIndex(string $stepIndex): void
    {
        $this->stepIndex = $stepIndex;
    }

    public function getParentStepInstaller(): ?InterfaceStepInstaller
    {
        return $this->parentStepInstaller;
    }

    public function setParentStepInstaller(?InterfaceStepInstaller $parentStepInstaller): void
    {
        $this->parentStepInstaller = $parentStepInstaller;
    }

//    protected function unlockObject(eZContentObject $object)
//    {
//        $stateGroup = \eZContentObjectStateGroup::fetchByIdentifier('opencity_lock');
//        if ($stateGroup instanceof \eZContentObjectStateGroup) {
//            $state = \eZContentObjectState::fetchByIdentifier('not_locked', $stateGroup->attribute('id'));
//            $object->assignState($state);
//            $this->getLogger()->info(' -> unlock');
//        }
//    }
}