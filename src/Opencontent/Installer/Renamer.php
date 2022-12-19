<?php

namespace Opencontent\Installer;

class Renamer extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $this->getLogger()->debug('Do rename...');
    }

    public function install()
    {
        $cli = \eZCLI::instance();

        $topNodeArray = \eZPersistentObject::fetchObjectList(
            \eZContentObjectTreeNode::definition(),
            null,
            [
                'parent_node_id' => 1,
                'depth' => 1,
            ]
        );
        $subTreeCount = 0;
        foreach ($topNodeArray as $node) {
            $subTreeCount += $node->subTreeCount(
                [
                    'Limitation' => [],
                ]
            );
        }

        $this->getLogger()->info("Number of objects to update: $subTreeCount");

        $i = 0;
        $dotMax = 70;
        $dotCount = 0;
        $limit = 50;

        foreach ($topNodeArray as $node) {
            $offset = 0;
            $subTree = $node->subTree([
                'Offset' => $offset,
                'Limit' => $limit,
                'Limitation' => [],
            ]);

            while ($subTree != null) {
                foreach ($subTree as $innerNode) {
                    $object = $innerNode->attribute('object');
                    $class = $object->contentClass();
                    $object->setName($class->contentObjectName($object));
                    $object->store();
                    unset($object);
                    unset($class);

                    // show progress bar
                    ++$i;
                    ++$dotCount;
                    $cli->output('.', false);
                    if ($dotCount >= $dotMax || $i >= $subTreeCount) {
                        $dotCount = 0;
                        $percent = number_format(($i * 100.0) / $subTreeCount, 2);
                        $cli->output(" " . $percent . "%");
                    }
                }
                $offset += $limit;
                unset($subTree);
                $subTree = $node->subTree([
                    'Offset' => $offset,
                    'Limit' => $limit,
                    'Limitation' => [],
                ]);
            }
        }
    }

}