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
        $this->RegisterPropertyString(
            'Valves',
            json_encode([
                [
                    'Enabled' => true,
                    'Name'    => 'Beetbewaesserung',
                    'ValveID' => 30668,
                    'Runtime' => 20
                ]
            ])
        );

        $this->RegisterAttributeString('EndTimes', '{}');
        $this->RegisterAttributeString('PendingStarts', '{}');
        $this->RegisterAttributeInteger('MainCloseDue', 0);

        $this->RegisterTimer('Tick', 0, 'CGI_Tick($_IPS[\'TARGET\']);');
        $this->RegisterVariableString('SystemStatus', 'Systemstatus', '', 1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();

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

        $activeConfiguredValves = 0;
        $endTimes = $this->ReadEndTimes();
        $pendingStarts = $this->ReadPendingStarts();

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

            $activeConfiguredValves++;
            $base = $this->IdentBase($valve['ValveID']);

            $switchID = $this->RegisterVariableBoolean($base . '_Switch', $valve['Name'], '~Switch', 100 + $activeConfiguredValves * 10);
            $this->EnableAction($base . '_Switch');

            $runtimeID = $this->RegisterVariableInteger($base . '_Runtime', $valve['Name'] . ' Laufzeit', 'CGI.Minutes', 101 + $activeConfiguredValves * 10);
            $this->EnableAction($base . '_Runtime');

            // Migration von der bisherigen Integer-Sekundenvariable auf eine String-Variable.
            // Damit zeigt auch IPSView die Restlaufzeit direkt als MM:SS an.
            $remainingIdent = $base . '_Remaining';
            $existingRemainingID = @$this->GetIDForIdent($remainingIdent);
            if ($existingRemainingID > 0) {
                $variableInfo = IPS_GetVariable($existingRemainingID);
                if ((int) $variableInfo['VariableType'] !== 3) {
                    $this->UnregisterVariable($remainingIdent);
                }
            }
            $remainingID = $this->RegisterVariableString($remainingIdent, $valve['Name'] . ' Restlaufzeit', '', 102 + $activeConfiguredValves * 10);
            $this->RegisterVariableString($base . '_Status', $valve['Name'] . ' Status', '', 103 + $activeConfiguredValves * 10);

            if (GetValueInteger($runtimeID) <= 0) {
                SetValueInteger($runtimeID, $this->ClampRuntime($valve['Runtime']));
            }

            $valveKey = (string) $valve['ValveID'];
            $endTime = isset($endTimes[$valveKey]) ? (int) $endTimes[$valveKey] : 0;
            $pending = isset($pendingStarts[$valveKey]);
            $physicallyOpen = @GetValueBoolean($valve['ValveID']);

            if ($pending) {
                SetValueBoolean($switchID, true);
                SetValueString($remainingID, '00:00 min');
                SetValueString($this->GetIDForIdent($base . '_Status'), 'Startet - Ventil oeffnet nach Verzoegerung');
            } elseif ($endTime > time() && $physicallyOpen) {
                SetValueBoolean($switchID, true);
                SetValueString($remainingID, $this->FormatRemaining(max(0, $endTime - time())));
                SetValueString($this->GetIDForIdent($base . '_Status'), 'Bewaessert');
            } else {
                if ($endTime > 0 && $endTime <= time()) {
                    unset($endTimes[$valveKey]);
                }
                SetValueBoolean($switchID, false);
                SetValueString($remainingID, '00:00 min');
                SetValueString($this->GetIDForIdent($base . '_Status'), $physicallyOpen ? 'Extern aktiv' : 'Aus');
            }
        }

        $this->WriteEndTimes($endTimes);
        $this->SetStatus(102);
        $this->SetValue('SystemStatus', $activeConfiguredValves . ' Bewaesserungskreis(e) konfiguriert');
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
            $valve = $this->FindValve($valveID);
            if ($valve === null) {
                throw new Exception('Unbekanntes Bewaesserungsventil');
            }

            $runtime = $this->ClampRuntime((int) $Value);
            SetValueInteger($this->GetIDForIdent($Ident), $runtime);

            if (@GetValueBoolean($valveID)) {
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

        $this->WriteAttributeInteger('MainCloseDue', 0);

        try {
            if (!@GetValueBoolean($mainValveID)) {
                \RequestAction($mainValveID, true);
            }

            SetValueBoolean($switchID, true);

            if (@GetValueBoolean($ValveID)) {
                $this->ActivateValveRuntime($ValveID);
                return;
            }

            $delaySeconds = (int) ceil($this->GetOpenDelay() / 1000);
            $pendingStarts = $this->ReadPendingStarts();
            $pendingStarts[(string) $ValveID] = time() + $delaySeconds;
            $this->WritePendingStarts($pendingStarts);

            SetValueString($statusID, $delaySeconds > 0 ? 'Startet - Ventil oeffnet in ' . $delaySeconds . ' s' : 'Startet');

            if ($delaySeconds === 0) {
                $this->ProcessPendingStarts();
            }

            $this->UpdateTimerState();
        } catch (Throwable $e) {
            SetValueBoolean($switchID, false);
            SetValueString($statusID, 'Fehler: ' . $e->getMessage());
            $this->RemovePendingStart($ValveID);
            $this->ScheduleMainValveCloseIfPossible();
            $this->UpdateTimerState();
            throw $e;
        }
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

        try {
            $this->RemovePendingStart($ValveID);

            if (@GetValueBoolean($ValveID)) {
                \RequestAction($ValveID, false);
            }

            SetValueBoolean($switchID, false);
            SetValueString($remainingID, '00:00 min');
            SetValueString($statusID, 'Aus');

            $endTimes = $this->ReadEndTimes();
            unset($endTimes[(string) $ValveID]);
            $this->WriteEndTimes($endTimes);

            $this->ScheduleMainValveCloseIfPossible();
            $this->UpdateTimerState();
        } catch (Throwable $e) {
            SetValueString($statusID, 'Fehler: ' . $e->getMessage());
            throw $e;
        }
    }

    public function StopAll()
    {
        $valves = $this->GetConfiguredValves();
        if (!is_array($valves)) {
            return;
        }

        $this->WritePendingStarts([]);

        foreach ($valves as $valve) {
            if (!$valve['Enabled'] || !$this->IsUsableBooleanActionVariable($valve['ValveID'])) {
                continue;
            }

            try {
                if (@GetValueBoolean($valve['ValveID'])) {
                    \RequestAction($valve['ValveID'], false);
                }
            } catch (Throwable $e) {
                $this->SendDebug('StopAll', $valve['Name'] . ': ' . $e->getMessage(), 0);
            }

            $base = $this->IdentBase($valve['ValveID']);
            @SetValueBoolean($this->GetIDForIdent($base . '_Switch'), false);
            @SetValueString($this->GetIDForIdent($base . '_Remaining'), '00:00 min');
            @SetValueString($this->GetIDForIdent($base . '_Status'), 'Aus');
        }

        $this->WriteEndTimes([]);
        $this->ScheduleMainValveCloseIfPossible(true);
        $this->UpdateTimerState();
    }

    public function Tick()
    {
        $this->ProcessPendingStarts();
        $this->ProcessRuntimeTimeouts();
        $this->ProcessMainValveClose();
        $this->UpdateRunningValveStatuses();
        $this->UpdateTimerState();
    }

    private function ProcessPendingStarts()
    {
        $pendingStarts = $this->ReadPendingStarts();
        if (count($pendingStarts) === 0) {
            return;
        }

        $now = time();

        foreach ($pendingStarts as $valveIDString => $due) {
            if ((int) $due > $now) {
                continue;
            }

            $valveID = (int) $valveIDString;
            unset($pendingStarts[$valveIDString]);

            $valve = $this->FindValve($valveID);
            if ($valve === null || !$valve['Enabled']) {
                continue;
            }

            $base = $this->IdentBase($valveID);
            $switchID = @$this->GetIDForIdent($base . '_Switch');
            $statusID = @$this->GetIDForIdent($base . '_Status');

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
                if ($switchID > 0) {
                    SetValueBoolean($switchID, false);
                }
                if ($statusID > 0) {
                    SetValueString($statusID, 'Fehler: ' . $e->getMessage());
                }
                $this->ScheduleMainValveCloseIfPossible();
            }
        }

        $this->WritePendingStarts($pendingStarts);
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
        if (count($endTimes) === 0) {
            return;
        }

        $now = time();
        $toStop = [];

        foreach ($endTimes as $valveIDString => $endTime) {
            if ((int) $endTime <= $now) {
                $toStop[] = (int) $valveIDString;
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

    private function ScheduleMainValveCloseIfPossible(bool $Force = false)
    {
        if (!$Force) {
            if ($this->AnyConfiguredValvePhysicallyOpen() || $this->HasPendingStarts()) {
                $this->WriteAttributeInteger('MainCloseDue', 0);
                return;
            }
        }

        $delaySeconds = (int) ceil($this->GetCloseDelay() / 1000);
        $this->WriteAttributeInteger('MainCloseDue', time() + $delaySeconds);

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

        if ($this->HasPendingStarts() || $this->AnyConfiguredValvePhysicallyOpen()) {
            $this->WriteAttributeInteger('MainCloseDue', 0);
            return;
        }

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        try {
            if ($this->IsUsableBooleanActionVariable($mainValveID) && @GetValueBoolean($mainValveID)) {
                \RequestAction($mainValveID, false);
            }
            $this->WriteAttributeInteger('MainCloseDue', 0);
        } catch (Throwable $e) {
            $this->SetValue('SystemStatus', 'Fehler beim Schliessen des Haupthahns: ' . $e->getMessage());
        }
    }

    private function UpdateRunningValveStatuses()
    {
        $endTimes = $this->ReadEndTimes();
        foreach ($endTimes as $valveIDString => $endTime) {
            if ((int) $endTime > time()) {
                $this->UpdateValveStatus((int) $valveIDString);
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

        if (!$active && $this->HasPendingStarts()) {
            $active = true;
        }

        if (!$active && $this->ReadAttributeInteger('MainCloseDue') > 0) {
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

    private function AnyConfiguredValvePhysicallyOpen(int $IgnoreValveID = 0)
    {
        $valves = $this->GetConfiguredValves();
        if (!is_array($valves)) {
            return false;
        }

        foreach ($valves as $valve) {
            if (!$valve['Enabled'] || $valve['ValveID'] === $IgnoreValveID) {
                continue;
            }

            if (IPS_VariableExists($valve['ValveID']) && @GetValueBoolean($valve['ValveID'])) {
                return true;
            }
        }

        return false;
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
        $pendingStarts = $this->ReadPendingStarts();
        unset($pendingStarts[(string) $ValveID]);
        $this->WritePendingStarts($pendingStarts);
    }

    private function HasPendingStarts()
    {
        return count($this->ReadPendingStarts()) > 0;
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
