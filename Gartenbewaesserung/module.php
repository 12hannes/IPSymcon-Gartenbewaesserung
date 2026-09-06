<?php

class ChatGPTGartenbewaesserung extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('MainValveID', 55511);
        $this->RegisterPropertyInteger('SwitchDelay', 1000);
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

        // Endzeit je Hardware-Ventil-ID als Unix-Timestamp.
        $this->RegisterAttributeString('EndTimes', '{}');

        // Ein zentraler 1-Sekunden-Timer reicht auch für mehrere parallele Kreise.
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
            $this->SetValue('SystemStatus', 'Ventilkonfiguration ist ungültig');
            $this->SetTimerInterval('Tick', 0);
            return;
        }

        $activeConfiguredValves = 0;
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

            $remainingID = $this->RegisterVariableInteger($base . '_Remaining', $valve['Name'] . ' Restlaufzeit', 'CGI.Seconds', 102 + $activeConfiguredValves * 10);
            $this->RegisterVariableString($base . '_Status', $valve['Name'] . ' Status', '', 103 + $activeConfiguredValves * 10);

            if (GetValueInteger($runtimeID) <= 0) {
                SetValueInteger($runtimeID, $this->ClampRuntime($valve['Runtime']));
            }

            $endTimes = $this->ReadEndTimes();
            $endTime = isset($endTimes[(string) $valve['ValveID']]) ? (int) $endTimes[(string) $valve['ValveID']] : 0;
            if ($endTime > time() && @GetValueBoolean($valve['ValveID'])) {
                SetValueBoolean($switchID, true);
                SetValueInteger($remainingID, max(0, $endTime - time()));
                SetValueString($this->GetIDForIdent($base . '_Status'), 'Bewässert');
            } else {
                if ($endTime > 0 && $endTime <= time()) {
                    unset($endTimes[(string) $valve['ValveID']]);
                    $this->WriteEndTimes($endTimes);
                }
                SetValueBoolean($switchID, false);
                SetValueInteger($remainingID, 0);
                SetValueString($this->GetIDForIdent($base . '_Status'), @GetValueBoolean($valve['ValveID']) ? 'Extern aktiv' : 'Aus');
            }
        }

        $this->SetStatus(102);
        $this->SetValue('SystemStatus', $activeConfiguredValves . ' Bewässerungskreis(e) konfiguriert');
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
                throw new Exception('Unbekanntes Bewässerungsventil');
            }

            $runtime = $this->ClampRuntime((int) $Value);
            SetValueInteger($this->GetIDForIdent($Ident), $runtime);

            // Bei laufender Bewässerung wird die Endzeit ab jetzt neu gesetzt.
            $base = $this->IdentBase($valveID);
            if (GetValueBoolean($this->GetIDForIdent($base . '_Switch'))) {
                $endTimes = $this->ReadEndTimes();
                $endTimes[(string) $valveID] = time() + ($runtime * 60);
                $this->WriteEndTimes($endTimes);
                $this->UpdateValveStatus($valveID);
            }
            return;
        }

        throw new Exception('Ungültiger Ident: ' . $Ident);
    }

    public function StartValve(int $ValveID)
    {
        $valve = $this->FindValve($ValveID);
        if ($valve === null || !$valve['Enabled']) {
            throw new Exception('Ventil ist nicht konfiguriert oder deaktiviert');
        }

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        if (!$this->IsUsableBooleanActionVariable($mainValveID) || !$this->IsUsableBooleanActionVariable($ValveID)) {
            throw new Exception('Haupthahn oder Bewässerungsventil ist nicht schaltbar');
        }

        $base = $this->IdentBase($ValveID);
        $switchID = $this->GetIDForIdent($base . '_Switch');
        $runtimeID = $this->GetIDForIdent($base . '_Runtime');
        $statusID = $this->GetIDForIdent($base . '_Status');

        try {
            // Gewünschte Reihenfolge: Haupthahn zuerst.
            \RequestAction($mainValveID, true);
            IPS_Sleep($this->GetDelay());

            // Danach Gartenventil.
            \RequestAction($ValveID, true);

            $runtime = $this->ClampRuntime(GetValueInteger($runtimeID));
            SetValueInteger($runtimeID, $runtime);

            $endTimes = $this->ReadEndTimes();
            $endTimes[(string) $ValveID] = time() + ($runtime * 60);
            $this->WriteEndTimes($endTimes);

            SetValueBoolean($switchID, true);
            SetValueString($statusID, 'Bewässert');
            $this->UpdateValveStatus($ValveID);
            $this->UpdateTimerState();
        } catch (Throwable $e) {
            SetValueBoolean($switchID, false);
            SetValueString($statusID, 'Fehler: ' . $e->getMessage());

            // Wenn kein anderes Gartenventil offen ist, Haupthahn sicher schließen.
            if (!$this->AnyConfiguredValvePhysicallyOpen($ValveID)) {
                try {
                    \RequestAction($mainValveID, false);
                } catch (Throwable $ignored) {
                }
            }
            throw $e;
        }
    }

    public function StopValve(int $ValveID)
    {
        $valve = $this->FindValve($ValveID);
        if ($valve === null) {
            throw new Exception('Ventil ist nicht konfiguriert');
        }

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        $base = $this->IdentBase($ValveID);
        $switchID = $this->GetIDForIdent($base . '_Switch');
        $remainingID = $this->GetIDForIdent($base . '_Remaining');
        $statusID = $this->GetIDForIdent($base . '_Status');

        try {
            // Gewünschte Reihenfolge: Gartenventil zuerst schließen.
            \RequestAction($ValveID, false);

            SetValueBoolean($switchID, false);
            SetValueInteger($remainingID, 0);
            SetValueString($statusID, 'Aus');

            $endTimes = $this->ReadEndTimes();
            unset($endTimes[(string) $ValveID]);
            $this->WriteEndTimes($endTimes);

            IPS_Sleep($this->GetDelay());

            // Haupthahn nur schließen, wenn kein anderes konfiguriertes Ventil offen ist.
            if (!$this->AnyConfiguredValvePhysicallyOpen()) {
                \RequestAction($mainValveID, false);
            }

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

        // Alle Gartenventile zuerst schließen.
        foreach ($valves as $valve) {
            if (!$valve['Enabled'] || !$this->IsUsableBooleanActionVariable($valve['ValveID'])) {
                continue;
            }

            try {
                \RequestAction($valve['ValveID'], false);
            } catch (Throwable $e) {
                $this->SendDebug('StopAll', $valve['Name'] . ': ' . $e->getMessage(), 0);
            }

            $base = $this->IdentBase($valve['ValveID']);
            @SetValueBoolean($this->GetIDForIdent($base . '_Switch'), false);
            @SetValueInteger($this->GetIDForIdent($base . '_Remaining'), 0);
            @SetValueString($this->GetIDForIdent($base . '_Status'), 'Aus');
        }

        $this->WriteEndTimes([]);
        IPS_Sleep($this->GetDelay());

        $mainValveID = $this->ReadPropertyInteger('MainValveID');
        if ($this->IsUsableBooleanActionVariable($mainValveID)) {
            \RequestAction($mainValveID, false);
        }

        $this->SetTimerInterval('Tick', 0);
    }

    public function Tick()
    {
        $endTimes = $this->ReadEndTimes();
        if (count($endTimes) === 0) {
            $this->SetTimerInterval('Tick', 0);
            return;
        }

        $now = time();
        $toStop = [];

        foreach ($endTimes as $valveIDString => $endTime) {
            $valveID = (int) $valveIDString;
            if ((int) $endTime <= $now) {
                $toStop[] = $valveID;
            } else {
                $this->UpdateValveStatus($valveID);
            }
        }

        foreach ($toStop as $valveID) {
            try {
                $this->StopValve($valveID);
            } catch (Throwable $e) {
                $this->SendDebug('AutoStop', 'Ventil ' . $valveID . ': ' . $e->getMessage(), 0);
            }
        }

        $this->UpdateTimerState();
    }

    private function UpdateValveStatus(int $ValveID)
    {
        $base = $this->IdentBase($ValveID);
        $endTimes = $this->ReadEndTimes();
        $endTime = isset($endTimes[(string) $ValveID]) ? (int) $endTimes[(string) $ValveID] : 0;
        $remaining = max(0, $endTime - time());

        $remainingID = @$this->GetIDForIdent($base . '_Remaining');
        if ($remainingID > 0) {
            SetValueInteger($remainingID, $remaining);
        }

        $switchID = @$this->GetIDForIdent($base . '_Switch');
        $statusID = @$this->GetIDForIdent($base . '_Status');
        if ($switchID > 0 && $statusID > 0 && GetValueBoolean($switchID)) {
            SetValueString($statusID, 'Bewässert - ' . $this->FormatSeconds($remaining) . ' verbleibend');
        }
    }

    private function UpdateTimerState()
    {
        $endTimes = $this->ReadEndTimes();
        $active = false;
        $now = time();

        foreach ($endTimes as $endTime) {
            if ((int) $endTime > $now) {
                $active = true;
                break;
            }
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

    private function GetDelay()
    {
        return max(0, min(10000, $this->ReadPropertyInteger('SwitchDelay')));
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

    private function FormatSeconds(int $Seconds)
    {
        $minutes = intdiv(max(0, $Seconds), 60);
        $seconds = max(0, $Seconds) % 60;
        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    private function CreateProfiles()
    {
        if (!IPS_VariableProfileExists('CGI.Minutes')) {
            IPS_CreateVariableProfile('CGI.Minutes', 1);
        }
        IPS_SetVariableProfileValues('CGI.Minutes', 1, 1440, 1);
        IPS_SetVariableProfileText('CGI.Minutes', '', ' min');

        if (!IPS_VariableProfileExists('CGI.Seconds')) {
            IPS_CreateVariableProfile('CGI.Seconds', 1);
        }
        IPS_SetVariableProfileValues('CGI.Seconds', 0, 86400, 1);
        IPS_SetVariableProfileText('CGI.Seconds', '', ' s');
    }
}
