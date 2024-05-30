<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/ColorHelper.php';
require_once __DIR__ . '/../libs/MQTTHelper.php';
require_once __DIR__ . '/../libs/VariableProfileHelper.php';
require_once __DIR__ . '/../libs/Zigbee2MQTTHelper.php';

class Zigbee2MQTTGroup extends IPSModule
{
    use \Zigbee2MQTT\ColorHelper;
    use \Zigbee2MQTT\MQTTHelper;
    use \Zigbee2MQTT\VariableProfileHelper;
    use \Zigbee2MQTT\Zigbee2MQTTHelper;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');

        $this->RegisterPropertyString('MQTTBaseTopic', 'zigbee2mqtt');
        $this->RegisterPropertyString('MQTTTopic', '');
        $this->createVariableProfiles();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        //Setze Filter für ReceiveData
        //$MQTTTopic = MQTT_GROUP_TOPIC . '/' . $this->ReadPropertyString('MQTTTopic');
        //$this->SetReceiveDataFilter('.*' . $MQTTTopic . '".*');

        $Filter1 = preg_quote('"Topic":"' . $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '"');
        $Filter2 = preg_quote('"Topic":"symcon/' . $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/');
        //$this->SendDebug('Filter ::', $MQTTTopic, 0);
        //$this->SetReceiveDataFilter('.*' . $MQTTTopic . '.*');

        $this->SendDebug('Filter ', '.*(' . $Filter1 . '|' . $Filter2 . ').*', 0);
        $this->SetReceiveDataFilter('.*(' . $Filter1 . '|' . $Filter2 . ').*');

        $this->getGroupInfo();
        $this->SetStatus(102);
    }
}