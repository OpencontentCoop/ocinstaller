<?php

namespace Opencontent\Installer;

use eZDBInterface;
use Exception;

class StepInstallerFactory
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    /**
     * @var IOTools
     */
    protected $ioTools;

    /**
     * @var eZDBInterface
     */
    protected $db;

    private $currentVersion;

    protected $isWaitForUser = false;

    public function __construct($logger, $installerVars, $ioTools, $db, $currentVersion, bool $isWaitForUser)
    {
        $this->logger = $logger;
        $this->installerVars = $installerVars;
        $this->ioTools = $ioTools;
        $this->db = $db;
        $this->currentVersion = $currentVersion;
        $this->isWaitForUser = $isWaitForUser;
    }

    /**
     * @param AbstractStepInstaller $installer
     * @return AbstractStepInstaller|InterfaceStepInstaller
     */
    public function factory(AbstractStepInstaller $installer): InterfaceStepInstaller
    {
        $installer->setLogger($this->logger);
        $installer->setInstallerVars($this->installerVars);
        $installer->setIoTools($this->ioTools);
        $installer->setDb($this->db);

        return $installer;
    }

    public function factoryByType(string $type): InterfaceStepInstaller
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

            case 'steps':
                $installer = new Steps($this);
                break;

            default:
                throw new Exception("Step type $type not handled");
        }

        return $this->factory($installer);
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    public function getInstallerVars(): InstallerVars
    {
        return $this->installerVars;
    }

    public function setInstallerVars(InstallerVars $installerVars): void
    {
        $this->installerVars = $installerVars;
    }

    public function getIoTools(): IOTools
    {
        return $this->ioTools;
    }

    public function setIoTools(IOTools $ioTools): void
    {
        $this->ioTools = $ioTools;
    }

    public function getDb(): eZDBInterface
    {
        return $this->db;
    }

    public function setDb(eZDBInterface $db): void
    {
        $this->db = $db;
    }

    public function getCurrentVersion()
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion($currentVersion): void
    {
        $this->currentVersion = $currentVersion;
    }

    public function isWaitForUser(): bool
    {
        return $this->isWaitForUser;
    }

    public function setIsWaitForUser(bool $isWaitForUser): void
    {
        $this->isWaitForUser = $isWaitForUser;
    }
}