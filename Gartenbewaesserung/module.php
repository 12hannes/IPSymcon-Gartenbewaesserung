<?php

class ChatGPTGartenbewaesserung extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Hardware kommt ausschliesslich aus der Modulkonfiguration.
        $this->RegisterPropertyInteger('MainValveID', 0);
        $this->RegisterPropertyString('Valves', '[]');

        $this->RegisterPropertyInteger('OpenDelay', 1000);
        $this->RegisterPropertyInteger('CloseDelay', 5000);
        $this->RegisterPropertyInteger('DefaultRuntime', 20);
        $this->RegisterPropertyInteger('MaximumRuntime', 180);

        // Ventil-ID => Unix-Zeitpunkt fuer das Oeffnen.
        $this->RegisterAttributeString('PendingStarts', '{}');

        // Ventil-ID => Unix-Zeitpunkt fuer das automatische Ende.
        $this->RegisterAttributeString('EndTimes', '{}');

        // Unix-Zeitpunkt fuer das Schliessen des Haupthahns; 0 = nichts geplant.
        $this->RegisterAttributeInteger('MainCloseDue', 0);

        // Ein zentraler Zustandsautomat im Sekundentakt.
        $this->RegisterTimer('Tick', 1000, 'CGI_Tick($_IPS[\'TARGET\']);');

        $this->RegisterVariableString('SystemStatus', 'Systemstatus', '', 1);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 2);
        $this->RegisterVariableBoolean('MainValveState', 'Haupthahn Zustand', '~Switch', 3);
        $this->RegisterVariableInteger('ActiveCircuitCount', 'Aktive Kreise', '', 4);
        $this->RegisterVariableString('MainValveDecision', 'Letzte Haupthahn-Entscheidung', '', 5);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->SetValue('ModuleVersion', '2.0.0');
        $this->SetTimerInterval('Tick', 1000);

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        if (!$this->IsUsableBooleanActionVariable($mainValveID)) {
            $this->SetStatus(201);
            $this->SetValue('SystemStatus', 'Haupthahn nicht konfiguriert oder nicht schaltbar');
            $this->UpdateDiagnostics('ApplyChanges');
            return;
        }

        $valves = $this->GetConfiguredValves();
        if ($valves === null) {
            $this->SetStatus(202);
            $this->SetValue('SystemStatus', 'Ventilkonfiguration ist ungueltig');
            $this->UpdateDiagnostics('ApplyChanges');
            return;
        }

        $position = 0;
        foreach ($valves as $valve) {
            if (!$valve['Enabled']) {
                continue;
            }

            $valveID = (int) $valve['ValveID'];
            if (!$this->IsUsableBooleanActionVariable($valveID)) {
                $this->SetStatus(202);
                $this->SetValue('SystemStatus', 'Ventil "' . $valve['Name'] . '" ist nicht schaltbar');
                $this->UpdateDiagnostics('ApplyChanges');
                return;
            }

            $position++;
            $base = $this->IdentBase($valveID);

            $switchID = $this->RegisterVariableBoolean(
                $base . '_Switch',
                $valve['Name'],
                '~Switch',
                100 + ($position * 10)
            );
            $this->EnableAction($base . '_Switch');

            $runtimeID = $this->RegisterVariableInteger(
                $base . '_Runtime',
                $valve['Name'] . ' Laufzeit',
                'CGI.Minutes',
                101 + ($position * 10)
            );
            $this->EnableAction($base . '_Runtime');

            // Die Laufzeit der jeweiligen Ventilzeile ist die Vorgabe der Konfiguration.
            SetValueInteger($runtimeID, $this->ClampRuntime($valve['Runtime']));

            $remainingIdent = $base . '_Remaining';
            $existingRemainingID = @$this->GetIDForIdent($remainingIdent);
            if ($existingRemainingID > 0) {
                $info = IPS_GetVariable($existingRemainingID);
                if ((int) $info['VariableType'] !== 3) {
                    $this->UnregisterVariable($remainingIdent);
                }
            }

            $this->RegisterVariableString(
                $remainingIdent,
                $valve['Name'] . ' Restlaufzeit',
                '',
                102 + ($position * 10)
            );

            $this->RegisterVariableString(
                $base . '_Status',
                $valve['Name'] . ' Status',
                '',
                103 + ($position * 10)
            );
        }

        $this->SetStatus(102);
        $this->SetValue('SystemStatus', $position . ' Bewaesserungskreis(e) konfiguriert');
        $this->UpdateDiagnostics('ApplyChanges');
        $this->EvaluateMainValve('ApplyChanges');
    }

    public function RequestAction($Ident, $Value)
    {
        if (preg_match('/^V([0-9]+)_Switch$/', $Ident, $m)) {
            $valveID = (int) $m[1];

            if ((bool) $Value) {
                $this->StartValve($valveID);
            } else {
                $this->StopValve($valveID);
            }
            return;
        }

        if (preg_match('/^V([0-9]+)_Runtime$/', $Ident, $m)) {
            $valveID = (int) $m[1];
            if ($this->FindValve($valveID) === null) {
                throw new Exception('Unbekanntes Bewaesserungsventil');
            }

            $runtime = $this->ClampRuntime((int) $Value);
            SetValueInteger($this->GetIDForIdent($Ident), $runtime);

            if ($this->IsModuleSwitchOn($valveID) && $this->IsHardwareValveOn($valveID)) {
                $endTimes = $this->ReadMap('EndTimes');
                $endTimes[(string) $valveID] = time() + ($runtime * 60);
                $this->WriteMap('EndTimes', $endTimes);
            }
            return;
        }

        throw new Exception('Ungueltiger Ident: ' . $Ident);
    }

    public function StartValve(int $ValveID)
    {
        $valve = $this->FindValve($ValveID);
        if ($valve === null || !$valve['Enabled']) {
            throw new Exception('Ventil ist nicht konfiguriert oder deaktiviert');
        }

        if (!$this->IsUsableBooleanActionVariable($ValveID)) {
            throw new Exception('Bewaesserungsventil ist nicht schaltbar');
        }

        $base = $this->IdentBase($ValveID);
        $switchID = $this->GetIDForIdent($base . '_Switch');
        $statusID = $this->GetIDForIdent($base . '_Status');

        // Ab jetzt fordert dieser Kreis Wasser an.
        SetValueBoolean($switchID, true);

        // Eine laufende Haupthahn-Abschaltung sofort abbrechen.
        $this->WriteAttributeInteger('MainCloseDue', 0);

        // Haupthahn sofort oeffnen.
        $this->EnsureMainValveOpen();

        // Gartenventil nach der Einschaltverzoegerung oeffnen.
        if ($this->IsHardwareValveOn($ValveID)) {
            $this->StartRuntime($ValveID);
        } else {
            $pending = $this->ReadMap('PendingStarts');
            $delaySeconds = (int) ceil($this->GetOpenDelay() / 1000);
            $pending[(string) $ValveID] = time() + $delaySeconds;
            $this->WriteMap('PendingStarts', $pending);

            SetValueString(
                $statusID,
                $delaySeconds > 0
                    ? 'Startet - Ventil oeffnet in ' . $delaySeconds . ' s'
                    : 'Startet'
            );

            if ($delaySeconds === 0) {
                $this->ProcessPendingStarts();
            }
        }

        $this->UpdateDiagnostics('Start Ventil ' . $ValveID);
    }

    public function StopValve(int $ValveID)
    {
        $valve = $this->FindValve($ValveID);
        if ($valve === null) {
            throw new Exception('Ventil ist nicht konfiguriert');
        }

        $base = $this->IdentBase($ValveID);
        $switchID = $this->GetIDForIdent($base . '_Switch');
        $remainingID = $this->GetIDForIdent($base . '_Remaining');
        $statusID = $this->GetIDForIdent($base . '_Status');

        // Ausschliesslich diesen Kreis auf AUS setzen.
        SetValueBoolean($switchID, false);

        $pending = $this->ReadMap('PendingStarts');
        unset($pending[(string) $ValveID]);
        $this->WriteMap('PendingStarts', $pending);

        $endTimes = $this->ReadMap('EndTimes');
        unset($endTimes[(string) $ValveID]);
        $this->WriteMap('EndTimes', $endTimes);

        // Dieses Gartenventil sofort schliessen.
        if ($this->IsHardwareValveOn($ValveID)) {
            \RequestAction($ValveID, false);
        }

        SetValueString($remainingID, '00:00 min');
        SetValueString($statusID, 'Aus');

        // Haupthahn vollkommen neu bewerten.
        $this->EvaluateMainValve('Stop Ventil ' . $ValveID);
        $this->UpdateDiagnostics('Stop Ventil ' . $ValveID);
    }

    public function StopAll()
    {
        $valves = $this->GetConfiguredValves();
        if (!is_array($valves)) {
            return;
        }

        $this->WriteMap('PendingStarts', []);
        $this->WriteMap('EndTimes', []);

        foreach ($valves as $valve) {
            if (!$valve['Enabled']) {
                continue;
            }

            $valveID = (int) $valve['ValveID'];
            $base = $this->IdentBase($valveID);

            $switchID = @$this->GetIDForIdent($base . '_Switch');
            if ($switchID > 0) {
                SetValueBoolean($switchID, false);
            }

            if ($this->IsHardwareValveOn($valveID)) {
                try {
                    \RequestAction($valveID, false);
                } catch (Throwable $e) {
                    $this->SendDebug('StopAll', $e->getMessage(), 0);
                }
            }

            $remainingID = @$this->GetIDForIdent($base . '_Remaining');
            $statusID = @$this->GetIDForIdent($base . '_Status');
            if ($remainingID > 0) {
                SetValueString($remainingID, '00:00 min');
            }
            if ($statusID > 0) {
                SetValueString($statusID, 'Aus');
            }
        }

        $this->EvaluateMainValve('Alle AUS');
        $this->UpdateDiagnostics('Alle AUS');
    }

    public function Tick()
    {
        $this->ProcessPendingStarts();
        $this->ProcessRuntimeTimeouts();
        $this->ProcessMainValveClose();
        $this->UpdateRemainingTimes();

        // Zentrale Sicherheitsregel:
        // Mindestens ein EIN-Schalter => Haupthahn MUSS offen sein.
        if ($this->CountDemandingCircuits() > 0) {
            $this->WriteAttributeInteger('MainCloseDue', 0);
            try {
                $this->EnsureMainValveOpen();
            } catch (Throwable $e) {
                $this->SetValue('SystemStatus', 'Fehler Haupthahn: ' . $e->getMessage());
            }
        }

        $this->UpdateDiagnostics('Tick');
    }

    private function ProcessPendingStarts()
    {
        $pending = $this->ReadMap('PendingStarts');
        if (count($pending) === 0) {
            return;
        }

        $now = time();

        foreach ($pending as $key => $due) {
            if ((int) $due > $now) {
                continue;
            }

            $valveID = (int) $key;
            unset($pending[$key]);

            if (!$this->IsModuleSwitchOn($valveID)) {
                continue;
            }

            try {
                $this->EnsureMainValveOpen();

                if (!$this->IsHardwareValveOn($valveID)) {
                    \RequestAction($valveID, true);
                }

                $this->StartRuntime($valveID);
            } catch (Throwable $e) {
                $base = $this->IdentBase($valveID);
                $switchID = @$this->GetIDForIdent($base . '_Switch');
                $statusID = @$this->GetIDForIdent($base . '_Status');

                if ($switchID > 0) {
                    SetValueBoolean($switchID, false);
                }
                if ($statusID > 0) {
                    SetValueString($statusID, 'Fehler: ' . $e->getMessage());
                }
            }
        }

        $this->WriteMap('PendingStarts', $pending);
        $this->EvaluateMainValve('Startverzoegerung verarbeitet');
    }

    private function StartRuntime(int $ValveID)
    {
        $base = $this->IdentBase($ValveID);
        $runtimeID = $this->GetIDForIdent($base . '_Runtime');
        $statusID = $this->GetIDForIdent($base . '_Status');

        $runtime = $this->ClampRuntime(GetValueInteger($runtimeID));

        $endTimes = $this->ReadMap('EndTimes');
        $endTimes[(string) $ValveID] = time() + ($runtime * 60);
        $this->WriteMap('EndTimes', $endTimes);

        SetValueString($statusID, 'Bewaessert');
    }

    private function ProcessRuntimeTimeouts()
    {
        $endTimes = $this->ReadMap('EndTimes');
        if (count($endTimes) === 0) {
            return;
        }

        $now = time();
        $expired = [];

        foreach ($endTimes as $key => $endTime) {
            if ((int) $endTime <= $now) {
                $expired[] = (int) $key;
            }
        }

        foreach ($expired as $valveID) {
            try {
                $this->StopValve($valveID);
            } catch (Throwable $e) {
                $this->SendDebug('AutoStop', 'Ventil ' . $valveID . ': ' . $e->getMessage(), 0);
            }
        }
    }

    private function EvaluateMainValve(string $Reason)
    {
        $activeNames = $this->GetDemandingCircuitNames();

        if (count($activeNames) > 0) {
            // Sobald irgendein Kreis EIN ist, ist eine Schliessung verboten.
            $this->WriteAttributeInteger('MainCloseDue', 0);
            $this->EnsureMainValveOpen();
            $this->SetValue(
                'MainValveDecision',
                $Reason . ': BLEIBT EIN - aktiv: ' . implode(', ', $activeNames)
            );
            return;
        }

        // Erst wenn wirklich alle Modulschalter AUS sind, Nachlauf starten.
        if ($this->ReadAttributeInteger('MainCloseDue') <= 0) {
            $delaySeconds = (int) ceil($this->GetCloseDelay() / 1000);
            $this->WriteAttributeInteger('MainCloseDue', time() + $delaySeconds);
            $this->SetValue(
                'MainValveDecision',
                $Reason . ': AUS geplant in ' . $delaySeconds . ' s'
            );
        }
    }

    private function ProcessMainValveClose()
    {
        $due = $this->ReadAttributeInteger('MainCloseDue');
        if ($due <= 0 || $due > time()) {
            return;
        }

        $activeNames = $this->GetDemandingCircuitNames();
        if (count($activeNames) > 0) {
            // Sicherheitspruefung unmittelbar vor dem Schliessen.
            $this->WriteAttributeInteger('MainCloseDue', 0);
            $this->EnsureMainValveOpen();
            $this->SetValue(
                'MainValveDecision',
                'Schliessen abgebrochen - aktiv: ' . implode(', ', $activeNames)
            );
            return;
        }

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        if ($this->IsUsableBooleanActionVariable($mainValveID) && @GetValueBoolean($mainValveID)) {
            \RequestAction($mainValveID, false);
        }

        $this->WriteAttributeInteger('MainCloseDue', 0);
        $this->SetValue('MainValveDecision', 'Haupthahn AUS - kein Kreis aktiv');
    }

    private function EnsureMainValveOpen()
    {
        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        if (!$this->IsUsableBooleanActionVariable($mainValveID)) {
            throw new Exception('Haupthahn ist nicht schaltbar');
        }

        if (!@GetValueBoolean($mainValveID)) {
            \RequestAction($mainValveID, true);
        }
    }

    private function UpdateRemainingTimes()
    {
        $endTimes = $this->ReadMap('EndTimes');
        $now = time();
        $valves = $this->GetConfiguredValves();

        if (!is_array($valves)) {
            return;
        }

        foreach ($valves as $valve) {
            if (!$valve['Enabled']) {
                continue;
            }

            $valveID = (int) $valve['ValveID'];
            $base = $this->IdentBase($valveID);
            $remainingID = @$this->GetIDForIdent($base . '_Remaining');
            $statusID = @$this->GetIDForIdent($base . '_Status');

            if ($remainingID <= 0) {
                continue;
            }

            $remaining = isset($endTimes[(string) $valveID])
                ? max(0, (int) $endTimes[(string) $valveID] - $now)
                : 0;

            SetValueString($remainingID, $this->FormatRemaining($remaining));

            if ($statusID > 0 && $this->IsModuleSwitchOn($valveID) && $remaining > 0) {
                SetValueString(
                    $statusID,
                    'Bewaessert - ' . $this->FormatSeconds($remaining) . ' verbleibend'
                );
            }
        }
    }

    private function UpdateDiagnostics(string $Reason)
    {
        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        $mainState = false;

        if ($mainValveID > 0 && IPS_VariableExists($mainValveID)) {
            $mainState = (bool) @GetValueBoolean($mainValveID);
        }

        $this->SetValue('MainValveState', $mainState);
        $this->SetValue('ActiveCircuitCount', $this->CountDemandingCircuits());

        if ($this->CountDemandingCircuits() > 0 && !$mainState) {
            $this->SetValue(
                'MainValveDecision',
                $Reason . ': WARNUNG - aktive Kreise, Haupthahn meldet AUS'
            );
        }
    }

    private function CountDemandingCircuits()
    {
        return count($this->GetDemandingCircuitNames());
    }

    private function GetDemandingCircuitNames()
    {
        $result = [];
        $valves = $this->GetConfiguredValves();

        if (!is_array($valves)) {
            return $result;
        }

        foreach ($valves as $valve) {
            if (!$valve['Enabled']) {
                continue;
            }

            if ($this->IsModuleSwitchOn((int) $valve['ValveID'])) {
                $result[] = $valve['Name'];
            }
        }

        return $result;
    }

    private function IsModuleSwitchOn(int $ValveID)
    {
        $switchID = @$this->GetIDForIdent($this->IdentBase($ValveID) . '_Switch');
        return $switchID > 0 && (bool) @GetValueBoolean($switchID);
    }

    private function IsHardwareValveOn(int $ValveID)
    {
        return IPS_VariableExists($ValveID) && (bool) @GetValueBoolean($ValveID);
    }

    private function FindValve(int $ValveID)
    {
        $valves = $this->GetConfiguredValves();
        if (!is_array($valves)) {
            return null;
        }

        foreach ($valves as $valve) {
            if ((int) $valve['ValveID'] === $ValveID) {
                return $valve;
            }
        }

        return null;
    }

    private function GetConfiguredValves()
    {
        $raw = json_decode($this->ReadPropertyString('Valves'), true);
        if (!is_array($raw)) {
            return null;
        }

        $result = [];
        $seen = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                return null;
            }

            $enabled = isset($row['Enabled']) ? (bool) $row['Enabled'] : true;
            $name = isset($row['Name']) ? trim((string) $row['Name']) : '';
            $valveID = isset($row['ValveID']) ? (int) $row['ValveID'] : 0;
            $runtime = isset($row['Runtime'])
                ? (int) $row['Runtime']
                : $this->ReadPropertyInteger('DefaultRuntime');

            if (!$enabled && ($valveID <= 0 || $name === '')) {
                continue;
            }

            if ($valveID <= 0 || $name === '' || isset($seen[$valveID])) {
                return null;
            }

            $seen[$valveID] = true;
            $result[] = [
                'Enabled' => $enabled,
                'Name'    => $name,
                'ValveID' => $valveID,
                'Runtime' => $this->ClampRuntime($runtime)
            ];
        }

        return $result;
    }

    private function IsUsableBooleanActionVariable(int $VariableID)
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return false;
        }

        $variable = IPS_GetVariable($VariableID);
        if ((int) $variable['VariableType'] !== 0) {
            return false;
        }

        return ((int) $variable['VariableAction'] > 0 || (int) $variable['VariableCustomAction'] > 0);
    }

    private function ReadMap(string $Attribute)
    {
        $data = json_decode($this->ReadAttributeString($Attribute), true);
        return is_array($data) ? $data : [];
    }

    private function WriteMap(string $Attribute, array $Data)
    {
        $this->WriteAttributeString($Attribute, json_encode($Data));
    }

    private function ClampRuntime(int $Minutes)
    {
        $maximum = max(1, $this->ReadPropertyInteger('MaximumRuntime'));

        if ($Minutes <= 0) {
            $Minutes = max(1, $this->ReadPropertyInteger('DefaultRuntime'));
        }

        return max(1, min($maximum, $Minutes));
    }

    private function GetOpenDelay()
    {
        return max(0, min(10000, $this->ReadPropertyInteger('OpenDelay')));
    }

    private function GetCloseDelay()
    {
        return max(0, min(30000, $this->ReadPropertyInteger('CloseDelay')));
    }

    private function IdentBase(int $ValveID)
    {
        return 'V' . $ValveID;
    }

    private function FormatSeconds(int $Seconds)
    {
        $minutes = intdiv(max(0, $Seconds), 60);
        $seconds = max(0, $Seconds) % 60;
        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    private function FormatRemaining(int $Seconds)
    {
        return $this->FormatSeconds($Seconds) . ' min';
    }

    private function CreateProfiles()
    {
        if (!IPS_VariableProfileExists('CGI.Minutes')) {
            IPS_CreateVariableProfile('CGI.Minutes', 1);
        }

        IPS_SetVariableProfileValues('CGI.Minutes', 1, 1440, 1);
        IPS_SetVariableProfileText('CGI.Minutes', '', ' min');
    }
}
