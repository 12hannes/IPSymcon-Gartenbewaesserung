<?php

class ChatGPTGartenbewaesserung extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('MainValveID', 55511);
        $this->RegisterPropertyInteger('OpenDelay', 1000);
        $this->RegisterPropertyInteger('CloseDelay', 5000);
        $this->RegisterPropertyInteger('DefaultRuntime', 20);
        $this->RegisterPropertyInteger('MaximumRuntime', 180);
        $this->RegisterPropertyString('Valves', json_encode([
            [
                'Enabled' => true,
                'Name'    => 'Beetbewaesserung',
                'ValveID' => 30668,
                'Runtime' => 20
            ]
        ]));

        $this->RegisterAttributeString('EndTimes', '{}');
        $this->RegisterAttributeString('PendingStarts', '{}');
        $this->RegisterAttributeInteger('MainCloseDue', 0);
        $this->RegisterAttributeString('ConfiguredRuntimes', '{}');

        $this->RegisterTimer('Tick', 0, 'CGI_Tick($_IPS[\'TARGET\']);');

        $this->RegisterVariableString('SystemStatus', 'Systemstatus', '', 1);
        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 2);
        $this->RegisterVariableInteger('ActiveCircuitCount', 'Aktive Kreise', '', 3);
        $this->RegisterVariableString('MainValveDecision', 'Letzte Haupthahn-Entscheidung', '', 4);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->SetValue('ModuleVersion', '1.5.0');

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        if (!$this->IsUsableBooleanActionVariable($mainValveID)) {
            $this->SetStatus(201);
            $this->SetValue('SystemStatus', 'Haupthahn nicht konfiguriert oder nicht schaltbar');
            $this->SetTimerInterval('Tick', 0);
            return;
        }

        $valves = $this->GetConfiguredValves();
        if ($valves === null) {
            $this->SetStatus(202);
            $this->SetValue('SystemStatus', 'Ventilkonfiguration ist ungueltig');
            $this->SetTimerInterval('Tick', 0);
            return;
        }

        $previousConfiguredRuntimes = $this->ReadConfiguredRuntimes();
        $currentConfiguredRuntimes = [];
        $endTimes = $this->ReadEndTimes();
        $pendingStarts = $this->ReadPendingStarts();
        $position = 0;
        $now = time();

        foreach ($valves as $valve) {
            if (!$valve['Enabled']) {
                continue;
            }

            if (!$this->IsUsableBooleanActionVariable($valve['ValveID'])) {
                $this->SetStatus(202);
                $this->SetValue('SystemStatus', 'Ventil "' . $valve['Name'] . '" ist nicht schaltbar');
                $this->SetTimerInterval('Tick', 0);
                return;
            }

            $position++;
            $valveID = (int) $valve['ValveID'];
            $key = (string) $valveID;
            $base = $this->IdentBase($valveID);

            $switchID = $this->RegisterVariableBoolean($base . '_Switch', $valve['Name'], '~Switch', 100 + $position * 10);
            $this->EnableAction($base . '_Switch');

            $runtimeID = $this->RegisterVariableInteger($base . '_Runtime', $valve['Name'] . ' Laufzeit', 'CGI.Minutes', 101 + $position * 10);
            $this->EnableAction($base . '_Runtime');

            $remainingIdent = $base . '_Remaining';
            $existingRemainingID = @$this->GetIDForIdent($remainingIdent);
            if ($existingRemainingID > 0) {
                $info = IPS_GetVariable($existingRemainingID);
                if ((int) $info['VariableType'] !== 3) {
                    $this->UnregisterVariable($remainingIdent);
                }
            }
            $remainingID = $this->RegisterVariableString($remainingIdent, $valve['Name'] . ' Restlaufzeit', '', 102 + $position * 10);
            $statusID = $this->RegisterVariableString($base . '_Status', $valve['Name'] . ' Status', '', 103 + $position * 10);

            $configuredRuntime = $this->ClampRuntime($valve['Runtime']);
            $currentConfiguredRuntimes[$key] = $configuredRuntime;
            $previousRuntime = isset($previousConfiguredRuntimes[$key]) ? (int) $previousConfiguredRuntimes[$key] : null;
            if ($previousRuntime === null || $previousRuntime !== $configuredRuntime || GetValueInteger($runtimeID) <= 0) {
                SetValueInteger($runtimeID, $configuredRuntime);
            }

            $pending = isset($pendingStarts[$key]);
            $endTime = isset($endTimes[$key]) ? (int) $endTimes[$key] : 0;
            $physical = @GetValueBoolean($valveID);

            // Nach Modulupdates nur echte laufende Zustaende rekonstruieren.
            if ($pending) {
                SetValueBoolean($switchID, true);
                SetValueString($remainingID, '00:00 min');
                SetValueString($statusID, 'Startet');
            } elseif ($endTime > $now) {
                SetValueBoolean($switchID, true);
                SetValueString($remainingID, $this->FormatRemaining($endTime - $now));
                SetValueString($statusID, 'Bewaessert');
            } elseif ($physical) {
                SetValueBoolean($switchID, true);
                SetValueString($remainingID, '00:00 min');
                SetValueString($statusID, 'Extern aktiv');
            } else {
                SetValueBoolean($switchID, false);
                SetValueString($remainingID, '00:00 min');
                SetValueString($statusID, 'Aus');
                unset($endTimes[$key]);
            }
        }

        $this->WriteConfiguredRuntimes($currentConfiguredRuntimes);
        $this->WriteEndTimes($endTimes);
        $this->SetStatus(102);
        $this->SetValue('SystemStatus', $position . ' Bewaesserungskreis(e) konfiguriert');
        $this->RefreshDiagnostics('ApplyChanges');
        $this->UpdateTimerState();
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

            if ($this->IsCircuitDemandingWater($valveID)) {
                $endTimes = $this->ReadEndTimes();
                $endTimes[(string) $valveID] = time() + ($runtime * 60);
                $this->WriteEndTimes($endTimes);
                $this->UpdateValveStatus($valveID);
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

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        if (!$this->IsUsableBooleanActionVariable($mainValveID) || !$this->IsUsableBooleanActionVariable($ValveID)) {
            throw new Exception('Haupthahn oder Bewaesserungsventil ist nicht schaltbar');
        }

        $base = $this->IdentBase($ValveID);
        $switchID = $this->GetIDForIdent($base . '_Switch');
        $statusID = $this->GetIDForIdent($base . '_Status');

        // Jede neue Anforderung hebt eine geplante Haupthahn-Abschaltung sofort auf.
        $this->WriteAttributeInteger('MainCloseDue', 0);
        SetValueBoolean($switchID, true);

        try {
            if (!@GetValueBoolean($mainValveID)) {
                \RequestAction($mainValveID, true);
            }

            if (@GetValueBoolean($ValveID)) {
                $this->ActivateValveRuntime($ValveID);
            } else {
                $delaySeconds = (int) ceil($this->GetOpenDelay() / 1000);
                $pendingStarts = $this->ReadPendingStarts();
                $pendingStarts[(string) $ValveID] = time() + $delaySeconds;
                $this->WritePendingStarts($pendingStarts);
                SetValueString($statusID, $delaySeconds > 0 ? 'Startet - Ventil oeffnet in ' . $delaySeconds . ' s' : 'Startet');

                if ($delaySeconds === 0) {
                    $this->ProcessPendingStarts();
                }
            }
        } catch (Throwable $e) {
            SetValueBoolean($switchID, false);
            SetValueString($statusID, 'Fehler: ' . $e->getMessage());
            $this->RemovePendingStart($ValveID);
            $this->EvaluateMainValveAfterCircuitChange('Startfehler Ventil ' . $ValveID);
            throw $e;
        }

        $this->RefreshDiagnostics('Start Ventil ' . $ValveID);
        $this->UpdateTimerState();
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

        // Zuerst ausschliesslich diesen Kreis abmelden.
        SetValueBoolean($switchID, false);
        $this->RemovePendingStart($ValveID);

        $endTimes = $this->ReadEndTimes();
        unset($endTimes[(string) $ValveID]);
        $this->WriteEndTimes($endTimes);

        try {
            if (@GetValueBoolean($ValveID)) {
                \RequestAction($ValveID, false);
            }
            SetValueString($remainingID, '00:00 min');
            SetValueString($statusID, 'Aus');
        } catch (Throwable $e) {
            SetValueString($statusID, 'Fehler beim Schliessen: ' . $e->getMessage());
            throw $e;
        }

        // WICHTIG: Nicht pauschal Haupthahn abschalten.
        // Erst jetzt alle anderen Kreise einzeln pruefen.
        $this->EvaluateMainValveAfterCircuitChange('Stop Ventil ' . $ValveID);
        $this->RefreshDiagnostics('Stop Ventil ' . $ValveID);
        $this->UpdateTimerState();
    }

    public function StopAll()
    {
        $valves = $this->GetConfiguredValves();
        if (!is_array($valves)) {
            return;
        }

        $this->WritePendingStarts([]);
        $this->WriteEndTimes([]);

        foreach ($valves as $valve) {
            if (!$valve['Enabled']) {
                continue;
            }

            $valveID = (int) $valve['ValveID'];
            $base = $this->IdentBase($valveID);

            @SetValueBoolean($this->GetIDForIdent($base . '_Switch'), false);
            @SetValueString($this->GetIDForIdent($base . '_Remaining'), '00:00 min');
            @SetValueString($this->GetIDForIdent($base . '_Status'), 'Aus');

            if ($this->IsUsableBooleanActionVariable($valveID) && @GetValueBoolean($valveID)) {
                try {
                    \RequestAction($valveID, false);
                } catch (Throwable $e) {
                    $this->SendDebug('StopAll', $valve['Name'] . ': ' . $e->getMessage(), 0);
                }
            }
        }

        $this->ScheduleMainValveClose('Alle Kreise AUS');
        $this->RefreshDiagnostics('StopAll');
        $this->UpdateTimerState();
    }

    public function Tick()
    {
        $this->ProcessPendingStarts();
        $this->ProcessRuntimeTimeouts();
        $this->ProcessMainValveClose();
        $this->UpdateRunningValveStatuses();
        $this->RefreshDiagnostics('Tick');
        $this->UpdateTimerState();
    }

    private function ProcessPendingStarts()
    {
        $pendingStarts = $this->ReadPendingStarts();
        if (count($pendingStarts) === 0) {
            return;
        }

        $now = time();
        $changed = false;

        foreach ($pendingStarts as $key => $due) {
            if ((int) $due > $now) {
                continue;
            }

            $valveID = (int) $key;
            unset($pendingStarts[$key]);
            $changed = true;

            $valve = $this->FindValve($valveID);
            if ($valve === null || !$valve['Enabled']) {
                continue;
            }

            $base = $this->IdentBase($valveID);
            $switchID = @$this->GetIDForIdent($base . '_Switch');
            $statusID = @$this->GetIDForIdent($base . '_Status');

            // Zwischenzeitlich ausgeschaltet -> nicht mehr oeffnen.
            if ($switchID <= 0 || !GetValueBoolean($switchID)) {
                continue;
            }

            try {
                $mainValveID = $this->ReadPropertyInteger('MainValveID');
                if (!@GetValueBoolean($mainValveID)) {
                    \RequestAction($mainValveID, true);
                }
                \RequestAction($valveID, true);
                $this->ActivateValveRuntime($valveID);
            } catch (Throwable $e) {
                SetValueBoolean($switchID, false);
                if ($statusID > 0) {
                    SetValueString($statusID, 'Fehler: ' . $e->getMessage());
                }
                $this->EvaluateMainValveAfterCircuitChange('Startfehler Ventil ' . $valveID);
            }
        }

        if ($changed) {
            $this->WritePendingStarts($pendingStarts);
        }
    }

    private function ActivateValveRuntime(int $ValveID)
    {
        $base = $this->IdentBase($ValveID);
        $runtimeID = $this->GetIDForIdent($base . '_Runtime');
        $switchID = $this->GetIDForIdent($base . '_Switch');
        $statusID = $this->GetIDForIdent($base . '_Status');

        $runtime = $this->ClampRuntime(GetValueInteger($runtimeID));
        SetValueInteger($runtimeID, $runtime);

        $endTimes = $this->ReadEndTimes();
        $endTimes[(string) $ValveID] = time() + ($runtime * 60);
        $this->WriteEndTimes($endTimes);

        SetValueBoolean($switchID, true);
        SetValueString($statusID, 'Bewaessert');
        $this->UpdateValveStatus($ValveID);
    }

    private function ProcessRuntimeTimeouts()
    {
        $endTimes = $this->ReadEndTimes();
        $now = time();
        $toStop = [];

        foreach ($endTimes as $key => $endTime) {
            if ((int) $endTime <= $now) {
                $toStop[] = (int) $key;
            }
        }

        foreach ($toStop as $valveID) {
            try {
                $this->StopValve($valveID);
            } catch (Throwable $e) {
                $this->SendDebug('AutoStop', 'Ventil ' . $valveID . ': ' . $e->getMessage(), 0);
            }
        }
    }

    private function EvaluateMainValveAfterCircuitChange(string $Reason)
    {
        $demanding = $this->GetDemandingCircuitNames();

        if (count($demanding) > 0) {
            // Mindestens ein anderer Kreis fordert Wasser. Eine alte Abschaltung wird geloescht.
            $this->WriteAttributeInteger('MainCloseDue', 0);
            $this->SetValue('MainValveDecision', $Reason . ': Haupthahn BLEIBT EIN - aktiv: ' . implode(', ', $demanding));
            return;
        }

        $this->ScheduleMainValveClose($Reason . ': kein aktiver Kreis');
    }

    private function ScheduleMainValveClose(string $Reason)
    {
        $delaySeconds = (int) ceil($this->GetCloseDelay() / 1000);
        $this->WriteAttributeInteger('MainCloseDue', time() + $delaySeconds);
        $this->SetValue('MainValveDecision', $Reason . ': Haupthahn AUS in ' . $delaySeconds . ' s');

        if ($delaySeconds === 0) {
            $this->ProcessMainValveClose();
        }
    }

    private function ProcessMainValveClose()
    {
        $due = $this->ReadAttributeInteger('MainCloseDue');
        if ($due <= 0 || $due > time()) {
            return;
        }

        // Direkt vor dem Schliessen nochmals von Grund auf pruefen.
        $demanding = $this->GetDemandingCircuitNames();
        if (count($demanding) > 0) {
            $this->WriteAttributeInteger('MainCloseDue', 0);
            $this->SetValue('MainValveDecision', 'Abschaltung verworfen - aktiv: ' . implode(', ', $demanding));
            return;
        }

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        try {
            if ($this->IsUsableBooleanActionVariable($mainValveID) && @GetValueBoolean($mainValveID)) {
                \RequestAction($mainValveID, false);
            }
            $this->WriteAttributeInteger('MainCloseDue', 0);
            $this->SetValue('MainValveDecision', 'Haupthahn AUS - kein aktiver Kreis');
        } catch (Throwable $e) {
            $this->SetValue('SystemStatus', 'Fehler beim Schliessen des Haupthahns: ' . $e->getMessage());
        }
    }

    private function GetDemandingCircuitNames()
    {
        $result = [];
        $valves = $this->GetConfiguredValves();
        if (!is_array($valves)) {
            return $result;
        }

        $pendingStarts = $this->ReadPendingStarts();

        foreach ($valves as $valve) {
            if (!$valve['Enabled']) {
                continue;
            }

            $valveID = (int) $valve['ValveID'];
            $base = $this->IdentBase($valveID);
            $switchID = @$this->GetIDForIdent($base . '_Switch');

            // Die Modul-Schaltvariable ist die primaere Wahrheit fuer eine Wasseranforderung.
            if ($switchID > 0 && @GetValueBoolean($switchID)) {
                $result[] = $valve['Name'];
                continue;
            }

            // Ein noch ausstehender Start darf den Haupthahn ebenfalls nicht schliessen lassen.
            if (isset($pendingStarts[(string) $valveID])) {
                $result[] = $valve['Name'] . ' (Start wartet)';
                continue;
            }

            // Physisch offenes Ventil ist die letzte Sicherheitsstufe.
            if (IPS_VariableExists($valveID) && @GetValueBoolean($valveID)) {
                $result[] = $valve['Name'] . ' (Hardware offen)';
            }
        }

        return $result;
    }

    private function IsCircuitDemandingWater(int $ValveID)
    {
        $valve = $this->FindValve($ValveID);
        if ($valve === null || !$valve['Enabled']) {
            return false;
        }

        $base = $this->IdentBase($ValveID);
        $switchID = @$this->GetIDForIdent($base . '_Switch');
        if ($switchID > 0 && @GetValueBoolean($switchID)) {
            return true;
        }

        $pending = $this->ReadPendingStarts();
        if (isset($pending[(string) $ValveID])) {
            return true;
        }

        return IPS_VariableExists($ValveID) && @GetValueBoolean($ValveID);
    }

    private function RefreshDiagnostics(string $Context)
    {
        $demanding = $this->GetDemandingCircuitNames();
        $this->SetValue('ActiveCircuitCount', count($demanding));

        if ($this->ReadAttributeInteger('MainCloseDue') <= 0 && count($demanding) > 0) {
            $this->SetValue('MainValveDecision', $Context . ': aktiv: ' . implode(', ', $demanding));
        }
    }

    private function UpdateRunningValveStatuses()
    {
        $endTimes = $this->ReadEndTimes();
        foreach ($endTimes as $key => $endTime) {
            if ((int) $endTime > time()) {
                $this->UpdateValveStatus((int) $key);
            }
        }
    }

    private function UpdateValveStatus(int $ValveID)
    {
        $base = $this->IdentBase($ValveID);
        $endTimes = $this->ReadEndTimes();
        $endTime = isset($endTimes[(string) $ValveID]) ? (int) $endTimes[(string) $ValveID] : 0;
        $remaining = max(0, $endTime - time());

        $remainingID = @$this->GetIDForIdent($base . '_Remaining');
        if ($remainingID > 0) {
            SetValueString($remainingID, $this->FormatRemaining($remaining));
        }

        $switchID = @$this->GetIDForIdent($base . '_Switch');
        $statusID = @$this->GetIDForIdent($base . '_Status');
        if ($switchID > 0 && $statusID > 0 && GetValueBoolean($switchID)) {
            SetValueString($statusID, 'Bewaessert - ' . $this->FormatSeconds($remaining) . ' verbleibend');
        }
    }

    private function UpdateTimerState()
    {
        $active = false;
        $now = time();

        foreach ($this->ReadEndTimes() as $endTime) {
            if ((int) $endTime > $now) {
                $active = true;
                break;
            }
        }

        if (!$active && count($this->ReadPendingStarts()) > 0) {
            $active = true;
        }

        if (!$active && $this->ReadAttributeInteger('MainCloseDue') > 0) {
            $active = true;
        }

        if (!$active && count($this->GetDemandingCircuitNames()) > 0) {
            $active = true;
        }

        $this->SetTimerInterval('Tick', $active ? 1000 : 0);
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

            $valveID = isset($row['ValveID']) ? (int) $row['ValveID'] : 0;
            $name = isset($row['Name']) ? trim((string) $row['Name']) : '';
            $enabled = isset($row['Enabled']) ? (bool) $row['Enabled'] : true;
            $runtime = isset($row['Runtime']) ? (int) $row['Runtime'] : $this->ReadPropertyInteger('DefaultRuntime');

            if ($valveID <= 0 || $name === '') {
                if ($enabled) {
                    return null;
                }
                continue;
            }

            if (isset($seen[$valveID])) {
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

    private function ReadEndTimes()
    {
        $data = json_decode($this->ReadAttributeString('EndTimes'), true);
        return is_array($data) ? $data : [];
    }

    private function WriteEndTimes(array $EndTimes)
    {
        $this->WriteAttributeString('EndTimes', json_encode($EndTimes));
    }

    private function ReadPendingStarts()
    {
        $data = json_decode($this->ReadAttributeString('PendingStarts'), true);
        return is_array($data) ? $data : [];
    }

    private function WritePendingStarts(array $PendingStarts)
    {
        $this->WriteAttributeString('PendingStarts', json_encode($PendingStarts));
    }

    private function RemovePendingStart(int $ValveID)
    {
        $pending = $this->ReadPendingStarts();
        unset($pending[(string) $ValveID]);
        $this->WritePendingStarts($pending);
    }

    private function ReadConfiguredRuntimes()
    {
        $data = json_decode($this->ReadAttributeString('ConfiguredRuntimes'), true);
        return is_array($data) ? $data : [];
    }

    private function WriteConfiguredRuntimes(array $Runtimes)
    {
        $this->WriteAttributeString('ConfiguredRuntimes', json_encode($Runtimes));
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
