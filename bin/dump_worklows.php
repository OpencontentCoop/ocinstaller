<?php

use Symfony\Component\Yaml\Yaml;

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Dump workflows in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[data:]',
    '',
    array(
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

$data = [];

/** @var eZTrigger[] $triggers */
$triggers = eZTrigger::fetchList();
foreach ($triggers as $trigger) {

    if ($trigger->attribute('connect_type') == 'b') {
        $connectionType = 'before';
    } else if ($trigger->attribute('connect_type') == 'a') {
        $connectionType = 'after';
    }

    $key = $trigger->attribute('module_name') . ':' . $trigger->attribute('function_name') . ':' . $connectionType;

    /** @var eZWorkflow $workflow */
    $workflow = eZWorkflow::fetch($trigger->attribute('workflow_id'));

    if (eZWorkflow::fetchEventCountByWorkflowID($workflow->attribute('id')) > 0) {
        /** @var eZWorkflowEvent[] $events */
        $events = $workflow->fetchEvents();

        $dataEvents = [];
        foreach ($events as $event) {
            $dataEvent = [
                'workflow_type_string' => $event->attribute('workflow_type_string'),
                'description' => $event->attribute('description'),
                'data_int1' => $event->attribute('data_int1'),
                'data_int2' => $event->attribute('data_int2'),
                'data_int3' => $event->attribute('data_int3'),
                'data_int4' => $event->attribute('data_int4'),
                'data_text1' => $event->attribute('data_text1'),
                'data_text2' => $event->attribute('data_text2'),
                'data_text3' => $event->attribute('data_text3'),
                'data_text4' => $event->attribute('data_text4'),
                'data_text5' => $event->attribute('data_text5'),
                'placement' => $event->attribute('placement'),
            ];

            if ($event->attribute('workflow_type_string') == 'event_ezmultiplexer') {
                /** @var eZWorkflow $nestedWorkflow */
                $nestedWorkflow = eZWorkflow::fetch($event->attribute('data_int1'));
                if (eZWorkflow::fetchEventCountByWorkflowID($nestedWorkflow->attribute('id')) > 0) {

                    /** @var eZWorkflowEvent[] $nestedEvents */
                    $nestedEvents = $nestedWorkflow->fetchEvents();
                    $nestedWorkflowEvents = [];
                    foreach ($nestedEvents as $nestedEvent) {
                        $nestedWorkflowEvents[] = [
                            'workflow_type_string' => $nestedEvent->attribute('workflow_type_string'),
                            'description' => $nestedEvent->attribute('description'),
                            'data_int1' => $nestedEvent->attribute('data_int1'),
                            'data_int2' => $nestedEvent->attribute('data_int2'),
                            'data_int3' => $nestedEvent->attribute('data_int3'),
                            'data_int4' => $nestedEvent->attribute('data_int4'),
                            'data_text1' => $nestedEvent->attribute('data_text1'),
                            'data_text2' => $nestedEvent->attribute('data_text2'),
                            'data_text3' => $nestedEvent->attribute('data_text3'),
                            'data_text4' => $nestedEvent->attribute('data_text4'),
                            'data_text5' => $nestedEvent->attribute('data_text5'),
                            'placement' => $nestedEvent->attribute('placement'),
                        ];
                    }

                    $dataEvent['data_int1'] = [
                        'name' => $nestedWorkflow->attribute('name'),
                        'events' => $nestedWorkflowEvents
                    ];
                }
            }

            $dataEvents[] = $dataEvent;
        }


        $data[$key] = array(
            'name' => $workflow->attribute('name'),
            'events' => $dataEvents
        );
    }
}

$dataYaml = Yaml::dump($data, 10);

foreach ($data as $key => $values) {

    $dataYaml = Yaml::dump($values, 10);

    if ($options['data']) {

        $workflowName = \Opencontent\Installer\Dumper\Tool::slugize($options['name']);
        list($module, $function, $connectionType) = explode(':', $key);
        $filename = $workflowName . '.yml';
        $directory = rtrim($options['data'], '/') . '/workflows';
        \eZDir::mkdir($directory, false, true);
        \eZFile::create($filename, $directory, $dataYaml);
        eZCLI::instance()->output($directory . '/' . $filename);

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'workflow',
            'identifier' => $workflowName,
            'trigger' => [
                'module' => $module,
                'function' => $function,
                'connection_type' => $connectionType
            ]
        ]);

    } else {
        print_r($dataYaml);
    }
}

$script->shutdown();