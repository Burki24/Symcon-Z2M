<?php

declare(strict_types=1);

namespace Z2MS;

trait Zigbee2MQTTHelper
{
    private $stateTypeMapping = [
        'Z2MS_child_lock' => ['type' => 'lockunlock', 'dataType' =>'string'],
        'Z2MS_state_window' => ['type' => 'openclose', 'dataType' =>'string'],
        'Z2MS_autolock' => ['type' => 'automode', 'dataType' => 'string'],
        'Z2MS_valve_state' => ['type' => 'valve', 'dataType' => 'string'],
    ];

    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'Z2MS_color_temp_presets':
                $this->SendDebug(__FUNCTION__ . ' ColorTempPresets', $value, 0);
                $this->Z2MSet(json_encode(['color_temp' => $value], JSON_UNESCAPED_SLASHES));
                return;
            case 'Z2MS_color':
            case 'Z2MS_color_hs':
            case 'Z2MS_color_rgb':
                $this->SendDebug(__FUNCTION__ . ' Color', $value, 0);
                $mode = str_replace('Z2MS_color_', '', $ident);
                $this->setColor($value, $mode);
                return;
            case 'Z2MS_color_temp_kelvin':
                $convertedValue = strval(intval(round(1000000 / $value, 0)));
                $payload = [str_replace('Z2MS_', '', $ident) => $convertedValue];
                $this->Z2MSet(json_encode($payload, JSON_UNESCAPED_SLASHES));
                return;
        }

        $variableID = $this->GetIDForIdent($ident);
        $variableInfo = IPS_GetVariable($variableID);
        $payloadKey = str_replace('Z2MS_', '', $ident);
        $payload = [$payloadKey => $this->convertStateBasedOnMapping($ident, $value, $variableInfo['VariableType'])];
        $this->Z2MSet(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public function getDeviceInfo()
    {
        $this->symconExtensionCommand('getDevice', $this->ReadPropertyString('MQTTTopic'));
    }

    public function getGroupInfo()
    {
        $this->symconExtensionCommand('getGroup', $this->ReadPropertyString('MQTTTopic'));
    }

    public function ReceiveData($JSONString)
    {
        if (!empty($this->ReadPropertyString('MQTTTopic'))) {
            $Buffer = json_decode($JSONString, true);
            $Buffer['Payload'] = IPS_GetKernelDate() > 1670886000 ? utf8_decode($Buffer['Payload']) : $Buffer['Payload'];

            $this->SendDebug('MQTT Topic', $Buffer['Topic'], 0);
            $this->SendDebug('MQTT Payload', $Buffer['Payload'], 0);

            if (array_key_exists('Topic', $Buffer) && fnmatch('*/availability', $Buffer['Topic'])) {
                $this->RegisterVariableBoolean('Z2MS_status', $this->Translate('Status'), 'Z2MS.device_status');
                $this->SetValue('Z2MS_status', $Buffer['Payload'] === 'online');
            }

            $Payload = json_decode($Buffer['Payload'], true);
            if (is_array($Payload['exposes'])) {
                $this->mapExposesToVariables($Payload['exposes']);
            }

            foreach ($Payload as $key => $value) {
                $ident = 'Z2MS_' . $key;
                $variableID = @$this->GetIDForIdent($ident);

                if ($variableID !== false) {
                    $variableInfo = IPS_GetVariable($variableID);
                    $handled = $this->handleSpecialCases($key, $ident, $value, $Payload);

                    if (!$handled) {
                        $value = $this->convertValueBasedOnType($value, $variableInfo['VariableType']);
                        $this->SetValue($ident, $value);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, "Ident $ident nicht gefunden", 0);
                }
            }
        }
    }

    private function handleSpecialCases($key, $ident, $value, $payload)
    {
        switch ($key) {
            case 'update_available':
                $this->RegisterVariableBoolean('Z2MS_Update', $this->Translate('Update'), '');
                $this->SetValue('Z2MS_Update', $payload['update_available']);
                return true;
            case 'voltage':
                $this->SetValue('Z2MS_Voltage', $payload['voltage'] > 400 ? $payload['voltage'] / 1000 : $payload['voltage']);
                return true;
            case 'color':
                return $this->handleColor($value, $ident);
            // Weitere spezifische Fälle hier...
        }
        return false;
    }

    private function handleColor($value, $ident)
    {
        if (is_array($value)) {
            if (isset($value['x']) && isset($value['y'])) {
                $RGBColor = ltrim($this->xyToHEX($value['x'], $value['y'], $value['brightness'] ?? 255), '#');
                $this->SetValue($ident, hexdec($RGBColor));
            } elseif (isset($value['hue']) && isset($value['saturation'])) {
                $RGBColor = ltrim($this->HSToRGB($value['hue'], $value['saturation'], 255), '#');
                $this->SetValue($ident, hexdec($RGBColor));
            }
            return true;
        }
        return false;
    }

    private function convertValueBasedOnType($value, $type)
    {
        switch ($type) {
            case 0: // Boolean
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 1: // Integer
                return intval($value);
            case 2: // Float
                return floatval($value);
            case 3: // String
                return is_array($value) ? implode(', ', $value) : strval($value);
        }
        return $value;
    }

    public function Z2MSet($payload)
    {
        $Data = [
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType' => 3,
            'QualityOfService' => 0,
            'Retain' => false,
            'Topic' => $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/set',
            'Payload' => $payload,
        ];
        $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__ . ' Topic', $Data['Topic'], 0);
        $this->SendDebug(__FUNCTION__ . ' Payload', $Data['Payload'], 0);
        $this->SendDataToParent($DataJSON);
    }

    protected function createVariableProfiles()
    {
        $profiles = [
            'Z2M.DeviceStatus' => ['type' => 'boolean', 'icon' => 'Network', 'associations' => [[false, 'Offline', '', 0xFF0000], [true, 'Online', '', 0x00FF00]]],
            'Z2M.ChargeState' => ['type' => 'boolean', 'icon' => 'Battery', 'associations' => [[false, 'Kein laden', '', 0xFF0000], [true, 'wird geladen', '', 0x00FF00]]],
            'Z2M.AutoLock' => ['type' => 'boolean', 'icon' => 'Key', 'associations' => [[false, 'Manual', '', 0xFF0000], [true, 'Auto', '', 0x00FF00]]],
            'Z2M.ValveState' => ['type' => 'boolean', 'icon' => 'Radiator', 'associations' => [[false, 'Valve Closed', '', 0xFF0000], [true, 'Valve Open', '', 0x00FF00]]],
            'Z2M.WindowOpenInternal' => ['type' => 'integer', 'icon' => '', 'associations' => [
                [0, 'Quarantine', '', -1],
                [1, 'Windows are closed', '', -1],
                [2, 'Hold', '', -1],
                [3, 'Open window detected', '', -1],
                [4, 'In window open state from external but detected closed locally', '', -1]
            ]]
        ];

        foreach ($profiles as $name => $profile) {
            if (!IPS_VariableProfileExists($name)) {
                switch ($profile['type']) {
                    case 'boolean':
                        $this->RegisterProfileBooleanEx($name, $profile['icon'], '', '', $profile['associations']);
                        break;
                    case 'integer':
                        $this->RegisterProfileIntegerEx($name, $profile['icon'], '', '', $profile['associations']);
                        break;
                }
            }
        }
    }

    protected function SetValue($ident, $value)
    {
        if (@$this->GetIDForIdent($ident)) {
            $this->SendDebug('Info :: SetValue for ' . $ident, 'Value: ' . $value, 0);
            parent::SetValue($ident, $value);
        } else {
            $this->SendDebug('Error :: No Expose for Value', 'Ident: ' . $ident, 0);
        }
    }

    private function handleStateChange($payloadKey, $valueId, $debugTitle, $Payload, $stateMapping = null)
    {
        if (array_key_exists($payloadKey, $Payload)) {
            $state = $Payload[$payloadKey];
            $stateMapping = $stateMapping ?? ['ON' => true, 'OFF' => false];

            if (array_key_exists($state, $stateMapping)) {
                $this->SetValue($valueId, $stateMapping[$state]);
            } else {
                $this->SendDebug($debugTitle, 'Undefined State: ' . $state, 0);
            }
        }
    }

    private function setColor(int $color, string $mode, string $Z2MMode = 'color')
    {
        switch ($mode) {
            case 'cie':
                $RGB = $this->HexToRGB($color);
                $cie = $this->RGBToXy($RGB);
                $Payload = $Z2MMode === 'color' ? ['color' => $cie, 'brightness' => $cie['bri']] : ['color_rgb' => $cie];
                break;
            case 'hs':
                $RGB = is_array($color) ? $color : $this->HexToRGB($color);
                $HSB = $this->RGBToHSB($RGB[0], $RGB[1], $RGB[2]);
                $Payload = ['color' => ['hue' => $HSB['hue'], 'saturation' => $HSB['saturation']]];
                break;
            default:
                $this->SendDebug('setColor', 'Invalid Mode ' . $mode, 0);
                return;
        }
        $this->Z2MSet(json_encode($Payload, JSON_UNESCAPED_SLASHES));
    }

    private function registerVariableProfile($expose)
    {
        $ProfileName = 'Z2MS.' . $expose['name'];
        $unit = isset($expose['unit']) ? ' ' . $expose['unit'] : '';

        switch ($expose['type']) {
            case 'binary':
                if ($expose['property'] === 'consumer_connected' && !IPS_VariableProfileExists($ProfileName)) {
                    $this->RegisterProfileBooleanEx($ProfileName, 'Plug', '', '', [
                        [false, $this->Translate('not connected'), '', 0xFF0000],
                        [true, $this->Translate('connected'), '', 0x00FF00]
                    ]);
                } else {
                    $this->SendDebug(__FUNCTION__ . ':: Variableprofile missing', $ProfileName, 0);
                    return $ProfileName;
                }
                break;

            case 'enum':
                if (array_key_exists('values', $expose)) {
                    sort($expose['values']);
                    $tmpProfileName = implode('', $expose['values']);
                    $ProfileName .= '.' . dechex(crc32($tmpProfileName));

                    if (!IPS_VariableProfileExists($ProfileName)) {
                        $profileValues = [];
                        foreach ($expose['values'] as $value) {
                            $readableValue = ucwords(str_replace('_', ' ', $value));
                            $translatedValue = $this->Translate($readableValue);
                            $profileValues[] = [$value, $translatedValue, '', 0x00FF00];
                        }
                        $this->RegisterProfileStringEx($ProfileName, 'Menu', '', '', $profileValues);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__ . ':: Variableprofile missing', $ProfileName, 0);
                    return false;
                }
                break;

            case 'numeric':
                return $this->registerNumericProfile($expose)['mainProfile'];

            default:
                $this->SendDebug(__FUNCTION__ . ':: Type not handled', $ProfileName, 0);
                return false;
        }

        return $ProfileName;
    }

    private function registerNumericProfile($expose, $isFloat = false)
    {
        $ProfileName = 'Z2MS.' . $expose['name'];
        $min = $expose['value_min'] ?? null;
        $max = $expose['value_max'] ?? null;
        $unit = isset($expose['unit']) ? ' ' . $expose['unit'] : '';

        $fullRangeProfileName = $min !== null && $max !== null ? $ProfileName . $min . '_' . $max : $ProfileName;
        $presetProfileName = $fullRangeProfileName . '_Presets';

        $this->SendDebug("registerNumericProfile", "ProfileName: $fullRangeProfileName, min: " . ($min ?? 'default') . ", max: " . ($max ?? 'default') . ", unit: $unit, isFloat: " . ($isFloat ? 'true' : 'false'), 0);

        if (!IPS_VariableProfileExists($fullRangeProfileName)) {
            if ($min !== null && $max !== null) {
                $isFloat
                    ? $this->RegisterProfileFloat($fullRangeProfileName, 'Bulb', '', $unit, $min, $max, 1, 2)
                    : $this->RegisterProfileInteger($fullRangeProfileName, 'Bulb', '', $unit, $min, $max, 1);
            } else {
                $isFloat
                    ? $this->RegisterProfileFloat($fullRangeProfileName, 'Bulb', '', $unit, 0, 0, 1, 2)
                    : $this->RegisterProfileInteger($fullRangeProfileName, 'Bulb', '', $unit, 0, 0, 1);
            }
        }

        if (isset($expose['presets']) && !empty($expose['presets'])) {
            if (IPS_VariableProfileExists($presetProfileName)) {
                IPS_DeleteVariableProfile($presetProfileName);
            }
            $isFloat
                ? $this->RegisterProfileFloat($presetProfileName, 'Bulb', '', '', 0, 0, 0, 2)
                : $this->RegisterProfileInteger($presetProfileName, 'Bulb', '', '', 0, 0, 0);

            foreach ($expose['presets'] as $preset) {
                $presetValue = $preset['value'];
                $presetName = $this->Translate(ucwords(str_replace('_', ' ', $preset['name'])));
                IPS_SetVariableProfileAssociation($presetProfileName, $presetValue, $presetName, '', 0xFFFFFF);
            }
        }

        return ['mainProfile' => $fullRangeProfileName, 'presetProfile' => $presetProfileName];
    }

    private function mapExposesToVariables(array $exposes)
    {
        $this->SendDebug(__FUNCTION__ . ':: All Exposes', json_encode($exposes), 0);

        foreach ($exposes as $expose) {
            if (isset($expose['features'])) {
                foreach ($expose['features'] as $feature) {
                    $this->registerVariable($feature);
                }
            } else {
                $this->registerVariable($expose);
            }
        }
    }

    private function registerVariable($feature)
    {
        $this->SendDebug('registerVariable', 'Feature: ' . json_encode($feature), 0);

        $type = $feature['type'];
        $property = $feature['property'];
        $ident = 'Z2MS_' . $property;
        $label = ucwords(str_replace('_', ' ', $feature['label'] ?? $property));

        $floatUnits = [
            '°C', '°F', 'K', 'mg/L', 'µg/m³', 'g/m³', 'mV', 'V', 'kV', 'µV', 'A', 'mA', 'µA', 'W', 'kW', 'MW', 'GW',
            'Wh', 'kWh', 'MWh', 'GWh', 'Hz', 'kHz', 'MHz', 'GHz', 'lux', 'lx', 'cd', 'ppm', 'ppb', 'ppt', 'pH', 'm', 'cm',
            'mm', 'µm', 'nm', 'l', 'ml', 'dl', 'm³', 'cm³', 'mm³', 'g', 'kg', 'mg', 'µg', 'ton', 'lb', 's', 'ms', 'µs',
            'ns', 'min', 'h', 'd', 'rad', 'sr', 'Bq', 'Gy', 'Sv', 'kat', 'mol', 'mol/l', 'N', 'Pa', 'kPa', 'MPa', 'GPa',
            'bar', 'mbar', 'atm', 'torr', 'psi', 'ohm', 'kohm', 'mohm', 'S', 'mS', 'µS', 'F', 'mF', 'µF', 'nF', 'pF', 'H',
            'mH', 'µH', '%', 'dB', 'dBA', 'dBC'
        ];

        $isFloat = in_array($feature['unit'] ?? '', $floatUnits);

        switch ($type) {
            case 'binary':
                $this->RegisterVariableBoolean($ident, $this->Translate($label), '~Switch');
                if ($feature['access'] & 0b010) {
                    $this->EnableAction($ident);
                }
                break;
            case 'numeric':
                $profiles = $this->registerNumericProfile($feature, $isFloat);
                $profileName = $profiles['mainProfile'];
                $this->SendDebug('registerVariable', 'Profile Name: ' . $profileName, 0);

                // Register the main variable
                if ($isFloat) {
                    $this->RegisterVariableFloat($ident, $this->Translate($label), $profileName);
                } else {
                    $this->RegisterVariableInteger($ident, $this->Translate($label), $profileName);
                }

                if ($feature['access'] & 0b010) {
                    $this->EnableAction($ident);
                }

                // Register the preset variable if presets are available
                if (isset($profiles['presetProfile']) && $profiles['presetProfile'] !== null) {
                    $presetIdent = $ident . '_presets';
                    $presetLabel = $label . ' Presets';
                    $presetProfileName = $profiles['presetProfile'];

                    $this->SendDebug('registerVariable', 'Preset Profile Name: ' . $presetProfileName, 0);

                    if ($isFloat) {
                        $this->RegisterVariableFloat($presetIdent, $this->Translate($presetLabel), $presetProfileName);
                    } else {
                        $this->RegisterVariableInteger($presetIdent, $this->Translate($presetLabel), $presetProfileName);
                    }

                    if ($feature['access'] & 0b010) {
                        $this->EnableAction($presetIdent);
                    }
                }
                break;
            case 'enum':
                $profileName = $this->registerVariableProfile($feature);
                if ($profileName !== false) {
                    $this->RegisterVariableString($ident, $this->Translate($label), $profileName);
                    if ($feature['access'] & 0b010) {
                        $this->EnableAction($ident);
                    }
                }
                break;
            case 'text':
                $this->RegisterVariableString($ident, $this->Translate($label));
                if ($feature['access'] & 0b010) {
                    $this->EnableAction($ident);
                }
                break;
            default:
                $this->SendDebug('registerVariable', 'Unhandled type: ' . $type, 0);
                break;
        }
    }

}
