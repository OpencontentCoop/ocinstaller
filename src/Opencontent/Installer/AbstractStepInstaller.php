<?php

namespace Opencontent\Installer;

use eZDBInterface;
use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Rest\Client\PayloadBuilder;

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

    /**
     * @return Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param Logger $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return InstallerVars
     */
    public function getInstallerVars()
    {
        return $this->installerVars;
    }

    /**
     * @param InstallerVars $installerVars
     */
    public function setInstallerVars($installerVars)
    {
        $this->installerVars = $installerVars;
    }

    /**
     * @return IOTools
     */
    public function getIoTools()
    {
        return $this->ioTools;
    }

    /**
     * @param IOTools $ioTools
     */
    public function setIoTools($ioTools)
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
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param eZDBInterface $db
     */
    public function setDb($db)
    {
        $this->db = $db;
    }

    public function sync()
    {
    }

    protected function resetContentFields(
        array $resetFields,
        PayloadBuilder $payload,
        \eZContentObjectTreeNode $node,
        ContentRepository $contentRepository
    ) {
        if (count($resetFields)) {
            $data = $payload->getData();
            foreach ($data as $locale => $values){
                foreach ($values as $id => $value){
                    if (!in_array($id, $resetFields)){
                        $payload->unSetData($id, $locale);
                    }elseif (empty($value)){
                        $zoneRemoteId = substr('empty_' . md5(mt_rand() . microtime()), 0, 6);
                        $dataMap = $node->dataMap();
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
                                            'valid_items' => []
                                        ]
                                    ],
                                ],
                            ]);
                        }
                    }
                }
            }
            $this->logger->warning(" - Reset fields: " . implode(', ', $resetFields));
            $contentRepository->update($payload->getArrayCopy());
        }
    }
}