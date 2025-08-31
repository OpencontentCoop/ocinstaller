<?php

namespace Opencontent\Installer;

use eZUser;

class InstallerSynchronizer extends Installer
{
    protected $initLogMessage = 'Sync %s installer: current data version is %s';

    public function install(array $options = array())
    {
        throw new \Exception('Can not install from synchronizer');
    }

    public function installSchema($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory, $installDfsSchema) :AbstractStepInstaller
    {
        throw new \Exception('Can not install from synchronizer');
    }

    /**
     * @param array $options
     * @return void
     * @throws \Throwable
     */
    public function sync(array $options = array())
    {
        InstallerVars::$useExceptions = null;
        $this->getLogger()->isVerbose = true;
        $steps = $this->installerData['steps'];

        /** @var eZUser $adminUser */
        $adminUser = eZUser::fetchByName('admin');
        eZUser::setCurrentlyLoggedInUser($adminUser, $adminUser->id());

        $this->loadDataVariables();

        foreach ($steps as $index => $step) {

            $stepName = isset($step['identifier']) ? $step['type'] . ' ' . $step['identifier'] : $step['type'];

            $installer = $this->installerFactory->factoryByType($step['type']);
            $installer->setStep($step);
            $this->logger->debug("[$index] $stepName");
            $installer->sync();
        }
    }
}