<?php

namespace Opencontent\Installer;

use eZPersistentObject;
use eZWorkflow;
use eZWorkflowEvent;
use eZWorkflowType;
use eZTrigger;
use eZWorkflowGroupLink;
use eZWorkflowFunctions;

class Workflow extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function install()
    {
        $identifier = $this->step['identifier'];
        $definition = $this->ioTools->getJsonContents("workflows/{$identifier}.yml");

        foreach ($definition['events'] as $index => $event) {
            if ($event['workflow_type_string'] == 'event_ezmultiplexer') {
                $subWorkflow = $this->createWorkflow($event['data_int1']['name'], $event['data_int1']['events']);
                $definition['events'][$index]['data_int1'] = $subWorkflow->attribute('id');
            }
        }
        $this->logger->info("Install workflow " . $definition['name']);
        $workflow = $this->createWorkflow($definition['name'], $definition['events']);

        if (isset($this->step['trigger'])) {

            $triggerName = $this->step['trigger']['module'] . '/' . $this->step['trigger']['function'] . '/' . $this->step['trigger']['connection_type'];
            $connectType = $this->step['trigger']['connection_type'] == 'before' ? 'b' : 'a';

            /** @var eZTrigger[] $triggerList */
            $triggerList = eZTrigger::fetchList();
            foreach ($triggerList as $trigger) {
                if ($trigger->attribute('module_name') == $this->step['trigger']['module']
                    && $trigger->attribute('function_name') == $this->step['trigger']['function']
                    && $trigger->attribute('connect_type') == $connectType) {
                    $trigger->remove();
                }
            }
            $this->logger->info(" - set to trigger $triggerName");
            eZTrigger::createNew($this->step['trigger']['module'], $this->step['trigger']['function'], $connectType, $workflow->attribute('id'));
        }
    }

    private function createWorkflow($name, $events)
    {
        $workflow = eZPersistentObject::fetchObject(eZWorkflow::definition(), null, ["name" => $name, "version" => 0]);

        if ($workflow instanceof eZWorkflow) {
            eZWorkflowFunctions::removeGroup($workflow->attribute('id'), $workflow->attribute('version'), 1);
            $workflow->removeThis(true);
        }

        $time = time();
        $workflow = new eZWorkflow([
            "id" => null,
            "workflow_type_string" => "group_ezserial",
            "version" => 0,
            "is_enabled" => 1,
            "name" => $name,
            "creator_id" => \eZUser::currentUserID(),
            "modifier_id" => \eZUser::currentUserID(),
            "created" => $time,
            "modified" => $time
        ]);
        $workflow->store();

        $workflowEventList = [];
        foreach ($events as $event) {
            $event['id'] = null;
            $event['version'] = 0;
            $event['workflow_id'] = $workflow->attribute('id');
            $workflowEvent = new eZWorkflowEvent($event);
            /** @var eZWorkflowType $workflowEventType */
            $workflowEventType = $workflowEvent->eventType();
            $workflowEventType->initializeEvent($workflowEvent);
            $workflowEvent->store();
            $workflowEventList[] = $workflowEvent;
        }
        $workflow->store($workflowEventList);

        $ingroup = eZWorkflowGroupLink::create( $workflow->attribute('id'), $workflow->attribute('version'), 1, 'Standard' );
        $ingroup->store();

        return $workflow;
    }
}