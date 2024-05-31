<?php

declare(strict_types=1);

namespace Z2MS;

trait ConvertHelper
{
    private function convertIdentToPayloadKey($ident)
    {
        // Gehört zu RequestAction
        $identWithoutPrefix = str_replace('Z2MS_', '', $ident);
        $this->SendDebug('Info :: convertIdentToPayloadKey', 'Ident: '. $ident.'-> IdentWithoutPrefix: '. $identWithoutPrefix, 0);
        $payloadKey = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $identWithoutPrefix));
        $this->SendDebug('Info :: convertIdentToPayloadKey', 'Ident: '. $ident.'-> PayloadKey: '. $payloadKey, 0);
        return $payloadKey;
    }
    private function convertKeyToReadableFormat($key)
    {
        $this->SendDebug(__FUNCTION__, "Schlüssel: $key", 0);
        $translateParts = explode('_', $key); // Teilt den Schlüssel in Teile
        $translatedParts = array_map('ucfirst', $translateParts); // Kapitalisiert jeden Teil
        $translate = implode(' ', $translatedParts); // Fügt die Teile mit einem Leerzeichen zusammen
        return $translate;
    }
    private function convertKeyToIdent($key)
    {
        $identParts = explode('_', $key); // Teilt den Schlüssel an Unterstrichen
        $capitalizedParts = array_map('ucfirst', $identParts); // Kapitalisiert jeden Teil
        $ident = 'Z2MS_' . implode('', $capitalizedParts); // Fügt die Teile mit einem Präfix zusammen
        $this->SendDebug(__FUNCTION__, "Ident: $ident", 0);
        return $ident;
    }
    private function convertStateBasedOnMapping($key, $value, $variableType) // Neu
    {
        // Gehört zu RequestAction
        // Überprüfe zuerst das spezielle Mapping für den Schlüssel
        if (array_key_exists($key, $this->stateTypeMapping)) {
            $mapping = $this->stateTypeMapping[$key];
            $dataType = $mapping['dataType'] ?? 'string'; // Standard auf 'string', falls nicht definiert
            // Spezielle Konvertierung basierend auf dem Typ im Mapping
            if (isset($mapping['type'])) {
                return $this->convertState($value, $mapping['type']);
            }
            // Formatierung des Wertes basierend auf dem definierten Datentyp
            // Verhindert "cannot autoconvert"-Fehler
            switch ($dataType) {
                case 'string':
                    return strval($value);
                case 'float':
                    $format = $mapping['format'] ?? '%f';
                    return sprintf($format, $value);
                case 'numeric':
                    return $value; // Keine Umwandlung notwendig
                default:
                    return strval($value); // Standardfall: Konvertiere zu String
            }
        }
        // Direkte Behandlung für boolesche Werte, wenn kein spezielles Mapping vorhanden ist
        // Setzt true/false auf "ON"/"OFF"
        if ($variableType === 0) { // Boolean
            return $value ? 'ON' : 'OFF';
        }
        // Standardbehandlung für Werte ohne spezifisches Mapping
        return is_numeric($value) ? $value : strval($value);
    }

    private function convertState($value, $type) // Neu
    {
        // Gehört zu RequestAction
        // Erweiterte Zustandsmappings
        // Setzt ankommende Werte auf true/false zur Nutzung als boolean in Symcon
        $stateMappings = [
            'onoff'      => ['ON', 'OFF'],
            'openclose'  => ['OPEN', 'CLOSE'],
            'lockunlock' => ['LOCK', 'UNLOCK'],
            'automanual' => ['AUTO', 'MANUAL'],
            'valve'      => ['OPEN', 'CLOSED'],
        ];
        // Prüfe, ob der Zustandstyp in den Mappings vorhanden ist
        if (array_key_exists($type, $stateMappings)) {
            // Wähle den korrekten Wert basierend auf dem booleschen $value
            return $value ? $stateMappings[$type][0] : $stateMappings[$type][1];
        } else {
            // Fallback für nicht definierte Zustandstypen
            return $value ? 'true' : 'false';
        }
    }
}
