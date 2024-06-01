<?php

declare(strict_types=1);

namespace Z2MS;

trait Zigbee2MQTTHelper
{
    private $stateTypeMapping = [
        'Z2MS_child_lock'                         => ['type' => 'lockunlock', 'dataType' =>'string'],
        'Z2MS_state_window'                       => ['type' => 'openclose', 'dataType' =>'string'],
        'Z2MS_autolock'                          => ['type' => 'automode', 'dataType' => 'string'],
        'Z2MS_valve_state'                        => ['type' => 'valve', 'dataType' => 'string'],
    ];

    public function RequestAction($ident, $value)
    {
        // Behandle spezielle Fälle separat
        // Fälle, wie z.B. die ganzen Farben, wo nicht einfach nur das $value gesetzt werden kann
        switch ($ident) {
        case 'Z2MS_color_temp_presets':
            $this->SendDebug(__FUNCTION__ . ' ColorTempPresets', $value, 0);
            // Erstellen des Payloads für die Farbtemperatur
            $payload = json_encode(['color_temp' => $value], JSON_UNESCAPED_SLASHES);

            // Senden des Payloads
            $this->Z2MSet($payload);
            return;
        case 'Z2MS_color':
            $this->SendDebug(__FUNCTION__ . ' Color', $value, 0);
            $this->setColor($value, 'cie');
            return;
        case 'Z2MS_color_hs':
            $this->SendDebug(__FUNCTION__ . ' Color HS', $value, 0);
            $this->setColor($value, 'hs');
            return;
        case 'Z2MS_color_rgb':
            $this->SendDebug(__FUNCTION__ . ' :: Color RGB', $value, 0);
            $this->setColor($value, 'cie', 'color_rgb');
            return;
        case 'Z2MS_color_temp_kelvin':
            $convertedValue = strval(intval(round(1000000 / $value, 0)));
            $payloadKey = str_replace('Z2MS_', '', $ident);
            $payload = [$payloadKey => $convertedValue];
            $payloadJSON = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $this->Z2MSet($payloadJSON);
            return;
        }
        // Generelle Logik für die meisten anderen Fälle
        // ermitteln des Variablen-Typs
        $variableID = $this->GetIDForIdent($ident);
        $variableInfo = IPS_GetVariable($variableID);
        $variableType = $variableInfo['VariableType'];
        $payloadKey = str_replace('Z2MS_', '', $ident);

        // konvertiert den Wert in ein für Z2MSet nutzbaren Wert
        // Keine Unterscheidung mehr in strval($value), $value (numerisch), etc. notwendig
        $payload = [$payloadKey => $this->convertStateBasedOnMapping($ident, $value, $variableType)];
        $this->SendDebug(__FUNCTION__, "Payload: ". json_encode($payload), 0);
        // Erstellung des passenden Payloads und versand durch Z2MSet
        $payloadJSON = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__, "PayloadJSON: ". $payloadJSON, 0);
        $this->Z2MSet($payloadJSON);
    }

    public function getDeviceInfo() // Unverändert
    {
        $this->symconExtensionCommand('getDevice', $this->ReadPropertyString('MQTTTopic'));
    }

    public function getGroupInfo() // Unverändert
    {
        $this->symconExtensionCommand('getGroup', $this->ReadPropertyString('MQTTTopic'));
    }

    public function ReceiveData($JSONString) {
        if (!empty($this->ReadPropertyString('MQTTTopic')))
        {
            $Buffer = json_decode($JSONString, true);

            if (IPS_GetKernelDate() > 1670886000) {
                $Buffer['Payload'] = utf8_decode($Buffer['Payload']);
            }

            $this->SendDebug('MQTT Topic', $Buffer['Topic'], 0);
            $this->SendDebug('MQTT Payload', $Buffer['Payload'], 0);

            if (array_key_exists('Topic', $Buffer)) {
                if (fnmatch('*/availability', $Buffer['Topic'])) {
                    $this->RegisterVariableBoolean('Z2MS_status', $this->Translate('Status'), 'Z2MS.device_status');
                    if ($Buffer['Payload'] == 'online') {
                        $this->SetValue('Z2MS_status', true);
                    } else {
                        $this->SetValue('Z2MS_status', false);
                    }
                }
            }

            $Payload = json_decode($Buffer['Payload'], true);
            if (fnmatch('symcon/' . $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/deviceInfo', $Buffer['Topic'])) {
                if (is_array($Payload['exposes'])) {
                    $this->mapExposesToVariables($Payload['exposes']);
                }
            }
            if (fnmatch('symcon/' . $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/groupInfo', $Buffer['Topic'])) {
                if (is_array($Payload)) {
                    $this->mapExposesToVariables($Payload);
                }
            }

            $payload = json_decode($Buffer['Payload'], true);
            foreach ($payload as $key => $value) {
                $ident = 'Z2MS_' . $key;
                $variableID = @$this->GetIDForIdent($ident);

                if ($variableID !== false) {
                    $variableInfo = IPS_GetVariable($variableID);
                    $variableType = $variableInfo['VariableType'];
                    $translate = $this->convertKeyToReadableFormat($key);
                    // Prüfen, ob der aktuelle Schlüssel spezielle Behandlung erfordert
                    // Spezielle Behandlungen unabhängig vom Typ durchführen
                    $handled = false; // Flag, um zu markieren, ob eine spezielle Behandlung durchgeführt wurde
                    switch ($key) {
                        case 'update_available':
                            $this->RegisterVariableBoolean('Z2MS_Update', $this->Translate('Update'), '');
                            $this->SetValue('Z2MS_Update', $payload['update_available']);
                            $handled = true;
                            break;

                        case 'voltage':
                            if ($payload['voltage'] > 400) { //Es gibt wahrscheinlich keine Zigbee Geräte mit über 400 Volt
                                $this->SetValue('Z2MS_Voltage', $payload['voltage'] / 1000);
                            } else {
                                $this->SetValue('Z2MS_Voltage', $payload['voltage']);
                            }
                            $handled = true;
                            break;
                        case 'action_rate':
                            $this->RegisterVariableInteger('Z2MS_ActionRate', $this->Translate('Action Rate'), $ProfileName);
                            $this->EnableAction('Z2MS_ActionRate');
                            $this->SetValue('Z2MS_ActionRate', $payload['action_rate']);
                            $handled = true;
                            break;
                        case 'action_level':
                            $this->RegisterVariableInteger('Z2MS_ActionLevel', $this->Translate('Action Level'), $ProfileName);
                            $this->EnableAction('Z2MS_ActionLevel');
                            $this->SetValue('Z2MS_ActionLevel', $payload['action_level']);
                            $handled = true;
                            break;
                        case 'action_transition_time':
                            $this->RegisterVariableInteger('Z2MS_ActionTransitionTime', $this->Translate('Action Transition Time'), $ProfileName);
                            $this->EnableAction('Z2MS_ActionTransitionTime');
                            $this->SetValue('Z2MS_ActionTransitionTime', $payload['action_transition_time']);
                            $handled = true;
                            break;
                        case 'child_lock':
                            $this->handleStateChange('child_lock', 'Z2MS_child_lock', 'Child Lock', $payload, ['LOCK' => true, 'UNLOCK' => false]);
                            $handled = true;
                            break;
                        case 'color':
                            if (is_array($value)) {
                                if (isset($value['x']) && isset($value['y'])) {
                                    $this->SendDebug(__FUNCTION__ . ' Color', $value['x'], 0);
                                    $brightness = isset($value['brightness']) ? $value['brightness'] : 255;
                                    $RGBColor = ltrim($this->xyToHEX($value['x'], $value['y'], $brightness), '#');
                                    $this->SendDebug(__FUNCTION__ . ' Color RGB HEX', $RGBColor, 0);
                                    $this->SetValue($ident, hexdec($RGBColor));
                                } elseif (isset($value['hue']) && isset($value['saturation'])) {
                                    $RGBColor = ltrim($this->HSToRGB($value['hue'], $value['saturation'], 255), '#');
                                    $this->SendDebug(__FUNCTION__ . ' Color RGB HEX', $RGBColor, 0);
                                    $this->SetValue($ident, hexdec($RGBColor));
                                }
                            }
                            $handled = true;
                            break;
                        case 'color_rgb':
                            if (isset($payload['color_rgb']) && is_array($payload['color_rgb'])) {
                                $colorRgb = $payload['color_rgb'];
                                $this->SendDebug(__FUNCTION__ . ':: Color X', $colorRgb['x'], 0);
                                $this->SendDebug(__FUNCTION__ . ':: Color Y', $colorRgb['y'], 0);
                                // Bestimmen der Helligkeit, falls vorhanden
                                $brightnessRgb = isset($payload['brightness_rgb']) ? $payload['brightness_rgb'] : 255;
                                $RGBColor = ltrim($this->xyToHEX($colorRgb['x'], $colorRgb['y'], $brightnessRgb), '#');
                                $this->SendDebug(__FUNCTION__ . ' Color :: RGB HEX', $RGBColor, 0);
                                $this->SetValue('Z2MS_color_rgb', hexdec($RGBColor));
                            }
                            $handled = true;
                            break;
                        case 'color_temp_cct':
                            $this->SetValue('Z2MS_Color_temp_cct', $payload['color_temp_cct']);
                            if ($payload['color_temp_cct'] > 0) {
                                $this->SetValue('Z2MS_color_temp_cct_kelvin', 1000000 / $payload['color_temp_cct']); //Convert to Kelvin
                            }
                            $handled = true;
                            break;
                        case 'color_temp_rgb':
                            $this->SetValue('Z2MS_color_temp_rgb', $payload['color_temp_rgb']);
                            if ($payload['color_temp_rgb'] > 0) {
                                $this->SetValue('Z2MS_color_temp_rgb_kelvin', 1000000 / $payload['color_temp_rgb']); //Convert to Kelvin
                            }
                            $handled = true;
                            break;
                        case 'color_temp':
                            $this->SetValue('Z2MS_color_temp', $payload['color_temp']);
                            if ($payload['color_temp'] > 0) {
                                $this->SetValue('Z2MS_colortemp_kelvin', 1000000 / $payload['color_temp']); //Convert to Kelvin
                            }
                            $handled = true;
                            break;
                        case 'brightness_rgb':
                            $this->EnableAction('Z2MS_brightness_rgb');
                            $this->SetValue('Z2MS_brightness_rgb', $payload['brightness_rgb']);
                            $handled = true;
                            break;
                        case 'color_temp_startup_rgb':
                            $this->SetValue('Z2MS_color_temp_startup_rgb', $payload['color_temp_startup_rgb']);
                            $this->EnableAction('Z2MS_color_tTempstartup_rgb');
                            $handled = true;
                            break;
                        case 'color_temp_startup_cct':
                            $this->SetValue('Z2MS_color_temp_startup_cct', $payload['color_temp_startup_cct']);
                            $this->EnableAction('Z2MS_color_temp_startup_cct');
                            $handled = true;
                            break;
                        case 'color_temp_startup':
                            $this->SetValue('Z2MS_color_temp_startup', $payload['color_temp_startup']);
                            $this->EnableAction('Z2MS_color_temp_startup');
                            $handled = true;
                            break;
                        case 'state_rgb':
                            $this->handleStateChange('state_rgb', 'Z2MS_state_rgb', 'State_rgb', $payload, );
                            $this->EnableAction('Z2MS_state_rgb');
                            $handled = true;
                            break;
                        case 'state_cct':
                            $this->handleStateChange('state_cct', 'Z2MS_state_cct', 'State_cct', $payload);
                            $this->EnableAction('Z2MS_state_cct');
                            $handled = true;
                            break;
                        case 'last_seen':
                            $translate = $this->convertKeyToReadableFormat($key);
                            $this->RegisterVariableInteger('Z2MS_last_seen', $this->Translate($translate), '~UnixTimestamp');
                            $this->SetValue($ident, $value / 1000);
                            $handled = true;
                            break;
                        case 'smoke_alarm_state':
                            $translate = $this->convertKeyToReadableFormat($key);
                            $this->handleStateChange($key, $ident, $translate, $payload);
                            $handled = true;
                            break;
                    }

                    if (!$handled) {
                        // Allgemeine Typbehandlung, wenn keine spezielle Behandlung durchgeführt wurde
                        switch ($variableType) {
                            case 0: // Boolean
                                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                                $this->SendDebug(__FUNCTION__, "Ident: $ident, Wert: $value, Typ: Boolean", 0);
                                break;
                            case 1: // Integer
                                $value = intval($value);
                                $this->SendDebug(__FUNCTION__, "Ident: $ident, Wert: $value, Typ: Integer", 0);
                                break;
                            case 2: // Float
                                $value = floatval($value);
                                $this->SendDebug(__FUNCTION__, "Ident: $ident, Wert: $value, Typ: Float", 0);
                                break;
                            case 3: // String
                                $this->SendDebug(__FUNCTION__, "Ident: $ident, Wert: " . json_encode($value) . ", Typ: String", 0);
                                if (is_array($value)) {
                                // Konvertiert das Array zu einem String
                                // $value = json_encode($value); // Für eine JSON-Darstellung
                                    $value = implode(', ', $value); // Für eine kommagetrennte Liste
                                } else {
                                    // Stellt sicher, dass der Wert ein String ist
                                    $value = strval($value);
                                }
                                break;
                        }

                        $this->SetValue($ident, $value);
                    }
                } else {
                    // Die Variable existiert nicht; hier könnte Logik zum Erstellen der Variable stehen
                    $this->SendDebug(__FUNCTION__, "Ident $ident nicht gefunden", 0);
                }
            }
        }
    }

    public function setColorExt($color, string $mode, array $params = [], string $Z2MMode = 'color')
    {
        switch ($mode) {
            case 'cie':
                $this->SendDebug(__FUNCTION__, $color, 0);
                $this->SendDebug(__FUNCTION__, $mode, 0);
                $this->SendDebug(__FUNCTION__, json_encode($params, JSON_UNESCAPED_SLASHES), 0);
                $this->SendDebug(__FUNCTION__, $Z2MMode, 0);
                if (preg_match('/^#[a-f0-9]{6}$/i', strval($color))) {
                    $color = ltrim($color, '#');
                    $color = hexdec($color);
                }
                $RGB = $this->HexToRGB($color);
                $cie = $this->RGBToXy($RGB);
                if ($Z2MMode = 'color') {
                    $Payload['color'] = $cie;
                    $Payload['brightness'] = $cie['bri'];
                } elseif ($Z2MMode == 'color_rgb') {
                    $Payload['color_rgb'] = $cie;
                } else {
                    return;
                }

                foreach ($params as $key => $value) {
                    $Payload[$key] = $value;
                }

                $PayloadJSON = json_encode($Payload, JSON_UNESCAPED_SLASHES);
                $this->SendDebug(__FUNCTION__, $PayloadJSON, 0);
                $this->Z2MSet($PayloadJSON);
                break;
            default:
                $this->SendDebug('setColor', 'Invalid Mode ' . $mode, 0);
                break;
        }
    }

    public function Z2MSet($payload) // Unverändert
    {
        $Data['DataID'] = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
        $Data['PacketType'] = 3;
        $Data['QualityOfService'] = 0;
        $Data['Retain'] = false;
        $Data['Topic'] = $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/set';
        $Data['Payload'] = $payload;
        $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__ . ' Topic', $Data['Topic'], 0);
        $this->SendDebug(__FUNCTION__ . ' Payload', $Data['Payload'], 0);
        $this->SendDataToParent($DataJSON);
    }

    protected function createVariableProfiles() // Unverändert
    {
        if (!IPS_VariableProfileExists('Z2M.DeviceStatus')) {
            $this->RegisterProfileBooleanEx('Z2M.DeviceStatus', 'Network', '', '', [
                [false, 'Offline',  '', 0xFF0000],
                [true, 'Online',  '', 0x00FF00]
            ]);
        }
        if (!IPS_VariableProfileExists('Z2M.ChargeState')) {
            $this->RegisterProfileBooleanEx('Z2M.ChargeState', 'Battery', '', '', [
                [false, 'Kein laden',  '', 0xFF0000],
                [true, 'wird geladen',  '', 0x00FF00]
            ]);
        }
        if (!IPS_VariableProfileExists('Z2M.AutoLock')) {
            $this->RegisterProfileBooleanEx('Z2M.AutoLock', 'Key', '', '', [
                [false, $this->Translate('Manual'),  '', 0xFF0000],
                [true, $this->Translate('Auto'),  '', 0x00FF00]
            ]);
        }
        if (!IPS_VariableProfileExists('Z2M.ValveState')) {
            $this->RegisterProfileBooleanEx('Z2M.ValveState', 'Radiator', '', '', [
                [false, $this->Translate('Valve Closed'),  '', 0xFF0000],
                [true, $this->Translate('Valve Open'),  '', 0x00FF00]
            ]);
        }
        if (!IPS_VariableProfileExists('Z2M.WindowOpenInternal')) {
            $Associations = [];
            $Associations[] = [0, $this->Translate('Quarantine'), '', -1];
            $Associations[] = [1, $this->Translate('Windows are closed'), '', -1];
            $Associations[] = [2, $this->Translate('Hold'), '', -1];
            $Associations[] = [3, $this->Translate('Open window detected'), '', -1];
            $Associations[] = [4, $this->Translate('In window open state from external but detected closed locally'), '', -1];
            $this->RegisterProfileIntegerEx('Z2M.WindowOpenInternal', '', '', '', $Associations);
        }
    }

    protected function SetValue($ident, $value) // Unverändert
    {
        if (@$this->GetIDForIdent($ident)) {
            $this->SendDebug('Info :: SetValue for ' . $ident, 'Value: ' . $value, 0);
            parent::SetValue($ident, $value);
        } else {
            $this->SendDebug('Error :: No Expose for Value', 'Ident: ' . $ident, 0);
        }
    }

    private function handleStateChange($payloadKey, $valueId, $debugTitle, $Payload, $stateMapping = null) // Neu
    {
        // Gehört zu RequestAction
        if (array_key_exists($payloadKey, $Payload)) {
            // Wenn ankommende Werte "ON" oder "OFF" sind
            $state = $Payload[$payloadKey];
            if ($stateMapping === null) {
                $stateMapping = ['ON' => true, 'OFF' => false];
            }
            // Prüfung stateMapping
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
                if ($Z2MMode = 'color') {
                    $Payload['color'] = $cie;
                    $Payload['brightness'] = $cie['bri'];
                } elseif ($Z2MMode == 'color_rgb') {
                    $Payload['color_rgb'] = $cie;
                } else {
                    return;
                }
                $PayloadJSON = json_encode($Payload, JSON_UNESCAPED_SLASHES);
                $this->Z2MSet($PayloadJSON);
                break;
            case 'hs':
                $this->SendDebug('setColor - Input Color', json_encode($color), 0);
                if (!is_array($color)) {
                    $RGB = $this->HexToRGB($color);
                    $HSB = $this->RGBToHSB($RGB[0], $RGB[1], $RGB[2]);
                } else {
                    $RGB = $color;
                    $HSB = $this->RGBToHSB($RGB[0], $RGB[1], $RGB[2]);
                }
                $this->SendDebug('setColor - RGB Values for HSB Conversion', 'R: ' . $RGB[0] . ', G: ' . $RGB[1] . ', B: ' . $RGB[2], 0);
                $HSB = $this->RGBToHSB($RGB[0], $RGB[1], $RGB[2]);
                if ($Z2MMode == 'color') {
                    $Payload = [
                        'color' => [
                            'hue'        => $HSB['hue'],
                            'saturation' => $HSB['saturation'],
                        ]
                    ];
                } else {
                    return;
                }
                $PayloadJSON = json_encode($Payload, JSON_UNESCAPED_SLASHES);
                $this->Z2MSet($PayloadJSON);
                break;
            default:
                $this->SendDebug('setColor', 'Invalid Mode ' . $mode, 0);
                break;
        }
    }

    private function registerVariableProfile($expose)
    {
        $ProfileName = 'Z2MS.' . $expose['name'];
        $unit = isset($expose['unit']) ? ' ' . $expose['unit'] : '';

        switch ($expose['type']) {
            case 'binary':
                switch ($expose['property']) {
                    case 'consumer_connected':
                        if (!IPS_VariableProfileExists($ProfileName)) {
                            $this->RegisterProfileBooleanEx($ProfileName, 'Plug', '', '', [
                                [false, $this->Translate('not connected'), '', 0xFF0000],
                                [true, $this->Translate('connected'), '', 0x00FF00]
                            ]);
                        }
                        break;
                    default:
                        $this->SendDebug(__FUNCTION__ . ':: Variableprofile missing', $ProfileName, 0);
                        return $ProfileName;
                }
                break;

            case 'enum':
                if (array_key_exists('values', $expose)) {
                    sort($expose['values']); // Sortieren, um Konsistenz beim Hashing zu gewährleisten
                    $tmpProfileName = implode('', $expose['values']);
                    $ProfileName .= '.' . dechex(crc32($tmpProfileName));

                    if (!IPS_VariableProfileExists($ProfileName)) {
                        $profileValues = [];
                        foreach ($expose['values'] as $value) {
                            $readableValue = ucwords(str_replace('_', ' ', $value));
                            $translatedValue = $this->Translate($readableValue);
                            if ($translatedValue === $readableValue) {
                                $this->SendDebug(__FUNCTION__ . ':: Missing Translation', "Keine Übersetzung für Wert: $readableValue", 0);
                            }
                            $profileValues[] = [$value, $translatedValue, '', 0x00FF00]; // Beispiel für eine Standardfarbe
                        }
                        $this->RegisterProfileStringEx($ProfileName, 'Menu', '', '', $profileValues);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__ . ':: Variableprofile missing', $ProfileName, 0);
                    $this->SendDebug(__FUNCTION__ . ':: ProfileName Values', json_encode($expose['values']), 0);
                    return false;
                }
                break;

            case 'numeric':
                // Auslagern der numeric Logik in eine spezialisierte Funktion
                $profile = $this->registerNumericProfile($expose);
                return $profile['mainProfile'];

            default:
                $this->SendDebug(__FUNCTION__ . ':: Type not handled', $ProfileName, 0);
                return false;
        }

        return $ProfileName;
    }


    private function registerNumericProfile($expose, $isFloat = false)
    {
        $ProfileName = 'Z2MS.' . $expose['name'];
        $min = isset($expose['value_min']) ? $expose['value_min'] : null;
        $max = isset($expose['value_max']) ? $expose['value_max'] : null;

        // ProfileName erweitern nur wenn min und max vorhanden sind
        $fullRangeProfileName = $ProfileName;
        if ($min !== null && $max !== null) {
            $fullRangeProfileName .= $min . '_' . $max;
        }

        $presetProfileName = $fullRangeProfileName . '_Presets';
        $unit = isset($expose['unit']) ? ' ' . $expose['unit'] : '';

        $this->SendDebug("registerNumericProfile", "ProfileName: $fullRangeProfileName, min: " . ($min ?? 'default') . ", max: " . ($max ?? 'default') . ", unit: $unit, isFloat: " . ($isFloat ? 'true' : 'false'), 0);

        // Register profile with min and max values if they are provided
        if (!IPS_VariableProfileExists($fullRangeProfileName)) {
            if ($min !== null && $max !== null) {
                if ($isFloat) {
                    $this->RegisterProfileFloat($fullRangeProfileName, 'Bulb', '', $unit, $min, $max, 1, 2);
                } else {
                    $this->RegisterProfileInteger($fullRangeProfileName, 'Bulb', '', $unit, $min, $max, 1);
                }
            } else {
                if ($isFloat) {
                    $this->RegisterProfileFloat($fullRangeProfileName, 'Bulb', '', $unit, 0, 0, 1, 2);
                } else {
                    $this->RegisterProfileInteger($fullRangeProfileName, 'Bulb', '', $unit, 0, 0, 1);
                }
            }
        }

        if (isset($expose['presets']) && !empty($expose['presets'])) {
            if (IPS_VariableProfileExists($presetProfileName)) {
                IPS_DeleteVariableProfile($presetProfileName);
            }
            if ($isFloat) {
                $this->RegisterProfileFloat($presetProfileName, 'Bulb', '', '', 0, 0, 0, 2);
            } else {
                $this->RegisterProfileInteger($presetProfileName, 'Bulb', '', '', 0, 0, 0);
            }
            foreach ($expose['presets'] as $preset) {
                $presetValue = $preset['value'];
                $presetName = $this->Translate(ucwords(str_replace('_', ' ', $preset['name'])));

                $this->SendDebug("Preset Info", "presetValue: $presetValue, presetName: $presetName", 0);

                IPS_SetVariableProfileAssociation($presetProfileName, $presetValue, $presetName, '', 0xFFFFFF);
            }
        }

        return ['mainProfile' => $fullRangeProfileName, 'presetProfile' => $presetProfileName];
    }

    private function mapExposesToVariables(array $exposes)
    {
        // Debugging für die übergebenen Exposes
        $this->SendDebug(__FUNCTION__ . ':: All Exposes', json_encode($exposes), 0);

        foreach ($exposes as $expose) {
            // Überprüfen, ob 'features' vorhanden ist, um die richtige Struktur zu identifizieren
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
        $ident = 'Z2MS_' . $property; // Kein ucfirst

        // Sicherstellen, dass jedes Wort im Label groß geschrieben wird
        $label = $feature['label'] ?? str_replace('_', ' ', $property);
        $label = ucwords($label);

        // Einheiten, die auf Float-Werte hinweisen
        $floatUnits = [
            '°C', '°F', 'K',            // Temperature
            'mg/L', 'µg/m³', 'g/m³',    // Concentration
            'mV', 'V', 'kV', 'µV',      // Voltage
            'A', 'mA', 'µA',            // Current
            'W', 'kW', 'MW', 'GW',      // Power
            'Wh', 'kWh', 'MWh', 'GWh',  // Energy
            'Hz', 'kHz', 'MHz', 'GHz',  // Frequency
            'lux', 'lx', 'cd',          // Light
            'ppm', 'ppb', 'ppt',        // Parts per...
            'pH',                       // pH
            'm', 'cm', 'mm', 'µm', 'nm', // Length
            'l', 'ml', 'dl', 'm³', 'cm³', 'mm³', // Volume
            'g', 'kg', 'mg', 'µg', 'ton', 'lb', // Mass
            's', 'ms', 'µs', 'ns', 'min', 'h', 'd', // Time
            'rad', 'sr',                // Angles
            'Bq', 'Gy', 'Sv', 'kat', 'mol', 'mol/l', // Radiation and chemistry
            'N', 'Pa', 'kPa', 'MPa', 'GPa', // Force and pressure
            'bar', 'mbar', 'atm', 'torr', 'psi', // Pressure
            'ohm', 'kohm', 'mohm',      // Resistance
            'S', 'mS', 'µS',            // Conductance
            'F', 'mF', 'µF', 'nF', 'pF', // Capacitance
            'H', 'mH', 'µH',            // Inductance
            '%',                        // Percentage
            'dB', 'dBA', 'dBC'          // Decibels
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
                $profileName = $this->registerNumericProfile($feature, $isFloat);
                if ($isFloat) {
                    $this->RegisterVariableFloat($ident, $this->Translate($label), $profileName['mainProfile']);
                } else {
                    $this->RegisterVariableInteger($ident, $this->Translate($label), $profileName['mainProfile']);
                }
                if ($feature['access'] & 0b010) {
                    $this->EnableAction($ident);
                }
                break;
            case 'enum':
                $profileName = $this->registerVariableProfile($feature);
                $this->SendDebug('registerVariable', 'Profile Name: ' . $profileName, 0);
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
                $missedVariables[$type][] = $feature;
                break;
        }

        // Für das Setzen der Werte basierend auf dem Payload
        $this->SendDebug(__FUNCTION__, 'booleanMappings: ' . json_encode($this->booleanMappings), 0);
    }
}
