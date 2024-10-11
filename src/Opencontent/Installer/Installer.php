<?php

namespace Opencontent\Installer;

use Exception;
use eZCharTransform;
use eZContentObjectTrashNode;
use eZDBInterface;
use eZSiteData;
use eZUser;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class Installer
{
    const INSTALLER_TYPE_DEFAULT = 'default';

    const INSTALLER_TYPE_MODULE = 'module';

    protected $db;

    protected $dataDir;

    protected $installerData = [];

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var IOTools
     */
    protected $ioTools;

    /**
     * @var StepInstallerFactory
     */
    protected $installerFactory;

    /**
     * @var bool
     */
    protected $dryRun = false;

    /**
     * @var bool
     */
    protected $isWaitForUser = false;

    protected $type;

    protected $currentVersion;

    protected $ignoreVersionCheck = false;

    protected $initLogMessage = '%s version %s';

    /**
     * OpenContentInstaller constructor.
     * @param eZDBInterface $db
     * @param $dataDir
     * @throws Exception
     */
    public function __construct(eZDBInterface $db, $dataDir)
    {
        $this->logger = new Logger();
        $this->installerVars = new InstallerVars();
        $this->installerVars->setLogger($this->logger);

        $this->db = $db;
        $this->dataDir = rtrim($dataDir, '/');
        $this->validateData();
        $this->installerData = Yaml::parse(file_get_contents($this->dataDir . '/installer.yml'));

        $this->type = $this->installerData['type'] ?? self::INSTALLER_TYPE_DEFAULT;

        if ($this->type !== self::INSTALLER_TYPE_DEFAULT && $this->type !== self::INSTALLER_TYPE_MODULE) {
            throw new Exception("Invalid installer type $this->type");
        }

        $this->logger->info(
            sprintf($this->initLogMessage, $this->installerData['name'], $this->installerData['version'])
        );

        $this->ioTools = new IOTools($this->dataDir, $this->installerVars);

        $this->installerFactory = new StepInstallerFactory(
            $this->logger,
            $this->installerVars,
            $this->ioTools,
            $this->db
        );
    }

    /**
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * @return InstallerVars
     */
    public function getInstallerVars(): InstallerVars
    {
        return $this->installerVars;
    }

    public function getType()
    {
        return $this->type;
    }

    /**
     * @param $cleanDb
     * @param $installBaseSchema
     * @param $installExtensionsSchema
     * @param $languageList
     * @param $cleanDataDirectory
     * @param $installDfsSchema
     * @return AbstractStepInstaller
     */
    public function installSchema(
        $cleanDb,
        $installBaseSchema,
        $installExtensionsSchema,
        $languageList,
        $cleanDataDirectory,
        $installDfsSchema
    ): AbstractStepInstaller {
        $installer = $this->installerFactory->factory(
            new Schema(
                $cleanDb,
                $installBaseSchema,
                $installExtensionsSchema,
                $languageList,
                $cleanDataDirectory,
                $installDfsSchema
            )
        );
        if ($this->dryRun) {
            $installer->dryRun();
        } else {
            $installer->install();
        }

        return $installer;
    }

    public function canInstallSchema(): bool
    {
        return $this->type == self::INSTALLER_TYPE_DEFAULT;
    }

    private function getSiteDataName(): string
    {
        if ($this->type == self::INSTALLER_TYPE_MODULE) {
            $identifier = eZCharTransform::instance()->transformByGroup($this->installerData['name'], 'identifier');

            return "ocinstaller_{$identifier}_version";
        }

        return 'ocinstaller_version';
    }

    public function getName()
    {
        return $this->installerData['name'];
    }

    public function getVariables()
    {
        $installerVariables = $this->installerData['variables'];
        $varParser = new InstallerVars();
        foreach ($installerVariables as $key => $value) {
            $installerVariables[$key]['parsed_value'] = $varParser->parseVarValue($value['value']);
        }
        return $installerVariables;
    }

    public function canUpdate()
    {
        if (\eZINI::instance('openpa.ini')->hasVariable('CreditsSettings', 'IsArchived')){
            return false;
        }
        return $this->getCurrentVersion() !== '0.0.0' && version_compare(
                $this->getCurrentVersion(),
                $this->installerData['version'],
                '<'
            );
    }

    public function needUpdate()
    {
        if ($this->ignoreVersionCheck) {
            return true;
        }
        $this->getLogger()->info("Installed version " . $this->getCurrentVersion());

        return version_compare($this->getCurrentVersion(), $this->installerData['version'], '<');
    }

    public function setIgnoreVersionCheck()
    {
        $this->ignoreVersionCheck = true;
    }

    private function getCurrentVersion()
    {
        if ($this->currentVersion === null) {
            $version = eZSiteData::fetchByName($this->getSiteDataName());
            if (!$version instanceof eZSiteData) {
                $this->currentVersion = '0.0.0';
            } else {
                $this->currentVersion = $version->attribute('value');
            }
        }

        return $this->currentVersion;
    }

    private function storeVersion()
    {
        $version = eZSiteData::fetchByName($this->getSiteDataName());
        if (!$version instanceof eZSiteData) {
            $version = new eZSiteData([
                'name' => $this->getSiteDataName(),
                'value' => $this->installerData['version'],
            ]);
        } else {
            $version->setAttribute('value', $this->installerData['version']);
        }
        $version->store();
        $this->storeDataDirForVersion();
    }

    private function storeDataDirForVersion()
    {
        $siteDataName = 'path_' . $this->getSiteDataName() . '@' . $this->installerData['version'];
        $dataDir = realpath($this->dataDir);
        $version = eZSiteData::fetchByName($siteDataName);
        if (!$version instanceof eZSiteData) {
            $version = new eZSiteData([
                'name' => $siteDataName,
                'value' => $dataDir,
            ]);
        } else {
            $version->setAttribute('value', $dataDir);
        }
        $version->store();
    }

    /**
     * @param array $options
     * @return void
     * @throws Throwable
     */
    public function install(array $options = [])
    {
        if (eZContentObjectTrashNode::trashListCount(['Limitation' => []]) > 0) {
            $trashErrorMessage = "There are objects in trash: please empty trash before running installer";
            if ($this->isDryRun()){
                $this->getLogger()->error($trashErrorMessage);
            }else {
                throw new Exception($trashErrorMessage);
            }
        }

        $this->installerVars['current_version'] = $this->getCurrentVersion();
        $this->getLogger()->info("Update to version " . $this->installerData['version']);
        $onlyStep = $options['only-step'];

        $steps = $this->installerData['steps'];
        $onlySteps = array_keys($steps);

        if ($onlyStep !== null) {
            $onlySteps = explode(',', $onlyStep);
        }

        if (!$this->isDryRun()) {
            /** @var eZUser $adminUser */
            $adminUser = eZUser::fetchByName('admin');
            eZUser::setCurrentlyLoggedInUser($adminUser, $adminUser->id());
        }

        $this->loadDataVariables();

        if (isset($options['vars']) && !empty($options['vars'])) {
            $keyValueList = explode(',', $options['vars']);
            foreach ($keyValueList as $keyValue) {
                [$key, $value] = explode(':', $keyValue);
                $this->logger->warning("Override variable $key = $value");
                $this->installerVars[trim($key)] = trim($value);
            }
        }

        foreach ($steps as $index => $step) {
            $stepName = isset($step['identifier']) ? $step['type'] . ' ' . $step['identifier'] : $step['type'];
            if (!in_array($index, $onlySteps)) {
                $this->logger->debug("(skip) [$index] $stepName");
                continue;
            }

            $installer = $this->buildInstallerByType($step['type']);
            $this->installStep($step, $installer, $index, $stepName);
        }

        if (!$this->dryRun) {
            $this->storeVersion();
        }
    }

    /**
     * @param $step
     * @return AbstractStepInstaller
     * @throws Exception
     */
    protected function buildInstallerByType($type): AbstractStepInstaller
    {
        switch ($type) {
            case 'rename':
                $installer = new Renamer();
                break;

            case 'tagtree_csv':
                $installer = new TagTreeCsv();
                break;

            case 'class_with_extra':
                $installer = new ContentClassWithExtra();
                break;

            case 'tagtree':
                $installer = new TagTree();
                break;

            case 'state':
                $installer = new State();
                break;

            case 'states':
                $installer = new States();
                break;

            case 'section':
                $installer = new Section();
                break;

            case 'sections':
                $installer = new Sections();
                break;

            case 'class':
                $installer = new ContentClass();
                break;

            case 'content':
                $installer = new Content();
                break;

            case 'role':
                $installer = new Role();
                break;

            case 'workflow':
                $installer = new Workflow();
                break;

            case 'contenttree':
                $installer = new ContentTree();
                break;

            case 'classextra':
                $installer = new ContentClassExtra();
                break;

            case 'openparecaptcha':
                $installer = new OpenPARecaptcha();
                break;
            case 'openparecaptcha_v3':
                $installer = new OpenPARecaptcha(3);
                break;

            case 'sql':
            case 'sql_copy_from_tsv':
                $installer = new Sql();
                break;

            case 'add_tag':
            case 'remove_tag':
            case 'move_tag':
            case 'rename_tag':
                $installer = new Tag();
                break;

            case 'patch_content':
                $installer = new PatchContent();
                break;

            case 'change_state':
                $installer = new ChangeState();
                break;

            case 'change_section':
                $installer = new ChangeSection();
                break;

            case 'tag_description':
                $installer = new TagDescription();
                break;

            case 'reindex':
                $installer = new Reindex();
                break;

            case 'deprecate_topic':
                $installer = new DeprecateTopic();
                break;

            case 'php_callable':
                $installer = new PhpCallable();
                break;

            case 'images_from_url':
                $installer = new ImagesFromUrl();
                break;

            case 'move_content':
                $installer = new MoveContent();
                break;

            default:
                throw new Exception("Step type $type not handled");
        }

        return $installer;
    }

    /**
     * @param $step
     * @param $installer
     * @param $index
     * @param $stepName
     * @return void
     * @throws Throwable
     */
    protected function installStep($step, $installer, $index, $stepName)
    {
        if ($installer instanceof AbstractStepInstaller) {
            $installer = $this->installerFactory->factory($installer);
            $ignoreError = false;
            if (isset($step['ignore_error'])) {
                $ignoreError = (bool)$step['ignore_error'];
            }
            if (isset($step['condition']) && $step['condition'] == '$is_install_from_scratch' && !$this->installerVars['is_install_from_scratch']) {
                $ignoreError = true;
            }
            try {
                $installer->setStep($step);

                $skip = false;

                $countVersionCheck = 0;
                $versionCheckValidCount = 0;
                $skipByVersionDebug = [];
                if (isset($installer->getStep()['current_version_lt'])) {
                    $check = version_compare(
                        $this->getCurrentVersion(),
                        $installer->getStep()['current_version_lt'],
                        'lt'
                    );
                    if ($check) {
                        $versionCheckValidCount++;
                    }
                    $skipByVersionDebug['current_version_lt'] = $this->getCurrentVersion(
                        ) . ' < ' . $installer->getStep()['current_version_lt'] . ' -> ' . (int)$check;
                    $countVersionCheck++;
                }
                if (isset($installer->getStep()['current_version_le'])) {
                    $check = version_compare(
                        $this->getCurrentVersion(),
                        $installer->getStep()['current_version_le'],
                        'le'
                    );
                    if ($check) {
                        $versionCheckValidCount++;
                    }
                    $skipByVersionDebug['current_version_le'] = $this->getCurrentVersion(
                        ) . ' <= ' . $installer->getStep()['current_version_le'] . ' -> ' . (int)$check;
                    $countVersionCheck++;
                }
                if (isset($installer->getStep()['current_version_eq'])) {
                    $check = version_compare(
                        $this->getCurrentVersion(),
                        $installer->getStep()['current_version_eq'],
                        'eq'
                    );
                    if ($check) {
                        $versionCheckValidCount++;
                    }
                    $skipByVersionDebug['current_version_eq'] = $this->getCurrentVersion(
                        ) . ' = ' . $installer->getStep()['current_version_eq'] . ' -> ' . (int)$check;
                    $countVersionCheck++;
                }
                if (isset($installer->getStep()['current_version_ge'])) {
                    $check = version_compare(
                        $this->getCurrentVersion(),
                        $installer->getStep()['current_version_ge'],
                        'ge'
                    );
                    if ($check) {
                        $versionCheckValidCount++;
                    }
                    $skipByVersionDebug['current_version_ge'] = $this->getCurrentVersion(
                        ) . ' >= ' . $installer->getStep()['current_version_ge'] . ' -> ' . (int)$check;
                    $countVersionCheck++;
                }
                if (isset($installer->getStep()['current_version_gt'])) {
                    $check = version_compare(
                        $this->getCurrentVersion(),
                        $installer->getStep()['current_version_gt'],
                        'gt'
                    );
                    if ($check) {
                        $versionCheckValidCount++;
                    }
                    $skipByVersionDebug['current_version_gt'] = $this->getCurrentVersion(
                        ) . ' > ' . $installer->getStep()['current_version_gt'] . ' -> ' . (int)$check;
                    $countVersionCheck++;
                }
                if ($countVersionCheck > 0) {
                    $skip = $countVersionCheck != $versionCheckValidCount;
                    if ($skip) {
                        $this->logger->debug(
                            "[$index] $stepName skipped by version compare parameters ($versionCheckValidCount/$countVersionCheck)"
                        );
                    }
                    foreach ($skipByVersionDebug as $skipCond => $skipDebug) {
                        $this->logger->debug("[$index] version compare: $skipCond $skipDebug");
                    }
                }

                if (isset($installer->getStep()['condition']) && (bool)$installer->getStep()['condition'] !== true) {
                    $this->logger->debug("[$index] $stepName skipped by condition parameter");
                    $skip = true;
                }

                if (!$skip) {
                    $this->logger->debug("[$index] $stepName");
                    if ($this->dryRun) {
                        $installer->dryRun();
                        if ($this->isWaitForUser && !$this->waitForUser('  -> next step?')) {
                            throw new \RuntimeException('Aborted');
                        }
                    } elseif ($this->isWaitForUser) {
                        $installer->dryRun();
                        if ($this->waitForUser(' -> install step?')) {
                            $installer->install();
                        }
                    } else {
                        $installer->install();
                    }
                }
            } catch (Throwable $e) {
                if ($ignoreError) {
                    $this->getLogger()->error($e->getMessage());
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function validateData()
    {
        if (!file_exists($this->dataDir . '/installer.yml')) {
            throw new Exception("File $this->dataDir/installer.yml not found");
        }
    }

    protected function loadDataVariables()
    {
        if (isset($this->installerData['variables'])) {
            $this->logger->info("Load installer variables");

            foreach ($this->installerData['variables'] as $variable) {
                $this->installerVars[$variable['name']] = $variable['value'];
            }
        }
    }

    public function setDryRun()
    {
        $this->logger->info("Dry-run mode enabled");
        $this->dryRun = true;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun === true;
    }

    public function setIsWaitForUser()
    {
        $this->logger->info("Wait mode enabled");
        $this->isWaitForUser = true;
    }

    private function waitForUser(string $question)
    {
        return \ezcConsoleDialogViewer::displayDialog(
                \ezcConsoleQuestionDialog::YesNoQuestion(
                    new \ezcConsoleOutput(),
                    $question,
                    'y'
                )
            ) === 'y';
    }

    public static function parseDataDir(?string $dataDir = null): string
    {
        if (!$dataDir) {
            throw new Exception("Missing data argument");
        }

        if (is_dir($dataDir)) {
            return realpath($dataDir);
        }

        $prebuiltDataDirList = [
            'opencity' => 'vendor/opencity-labs/opencity-installer',
            'openagenda' => 'vendor/opencity-labs/openagenda-installer',
            'opencity-asl' => 'vendor/opencity-labs/opencity-asl-installer',
        ];

        $installerDirectory = $dataDir;
        $module = false;
        if (strpos($dataDir, '::')) {
            [$installerDirectory, $module] = explode('::', $dataDir, 2);
            if (empty($module)) {
                $module = '?';
            }
        }
        if (isset($prebuiltDataDirList[$installerDirectory]) && is_dir($prebuiltDataDirList[$installerDirectory])) {
            $installerDirectory = $prebuiltDataDirList[$installerDirectory];
            if ($module === '?') {
                throw new Exception(
                    "Missing module suffix in data argument. Available modules are: " . PHP_EOL . implode(
                        PHP_EOL,
                        self::findModules($installerDirectory)
                    )
                );
            }
            if ($module) {
                $installerDirectory .= '/modules/' . $module;
            }
        }

        if (!is_dir($installerDirectory)) {
            throw new Exception("Installer $dataDir not found");
        }

        return realpath($installerDirectory);
    }

    private static function findModules(string $baseDir)
    {
        $dirs = \eZDir::findSubitems($baseDir . '/modules', 'd');
        sort($dirs);

        return $dirs;
    }

    public function getCurrentVersions()
    {
        $list = [];
        $installerDirectory = $this->dataDir;
        if (strpos($installerDirectory, '/modules/')) {
            $installerDirectory = realpath(rtrim($installerDirectory . '/') . '/../..');
        }
        $list = [];
        $installerData = Yaml::parse(file_get_contents($installerDirectory . '/installer.yml'));
        $list['version'] = [
            'name' => $installerData['name'],
            'path' => '',
            'identifier' => $moduleName,
            'current' => 'not-installed',
            'available' => $installerData['version'],
        ];
        $modules = self::findModules($installerDirectory);
        foreach ($modules as $module) {
            if (empty($module)) {
                continue;
            }
            if (file_exists($installerDirectory . '/modules/' . $module . '/installer.yml')) {
                $installerData = Yaml::parse(
                    file_get_contents($installerDirectory . '/modules/' . $module . '/installer.yml')
                );
                $moduleName = eZCharTransform::instance()->transformByGroup($installerData['name'], 'identifier');
                $list[$moduleName] = [
                    'name' => $installerData['name'],
                    'path' => $module,
                    'identifier' => $moduleName,
                    'current' => 'not-installed',
                    'available' => $installerData['version'],
                ];
            }
        }
        $rows = eZSiteData::fetchObjectList(eZSiteData::definition(), null, ['name' => ['like', 'ocinstaller_%']]);
        foreach ($rows as $row) {
            $name = str_replace(['ocinstaller_', '_version'], '', $row->attribute('name'));
            if (isset($list[$name])) {
                $list[$name]['current'] = $row->attribute('value');
            }else{
                $list[$name] = [
                    'name' => '?',
                    'path' => '?',
                    'identifier' => $name,
                    'current' => $row->attribute('value'),
                    'available' => '?',
                ];
            }
        }
        krsort($list);
        return $list;
    }
}