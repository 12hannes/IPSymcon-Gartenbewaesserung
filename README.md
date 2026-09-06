# ChatGPT Gartenbewaesserung fuer IP-Symcon

Version 1.0

## Zweck

Zentrale Gartenbewaesserung mit einem gemeinsamen Haupthahn und mehreren Bewaesserungsventilen.

Schaltfolge beim Einschalten:
1. Haupthahn EIN
2. konfigurierbare Verzoegerung (Standard 1 Sekunde)
3. gewaehltes Gartenventil EIN
4. automatische Abschaltung nach einstellbarer Laufzeit (Standard 20 Minuten)

Schaltfolge beim Ausschalten:
1. Gartenventil AUS
2. Verzoegerung
3. Haupthahn AUS, sofern kein anderes konfiguriertes Ventil mehr offen ist

## Vorkonfiguration

- Haupthahn: Variable 55511
- Erstes Ventil: Beetbewaesserung, Variable 30668
- Standardlaufzeit: 20 Minuten
- Maximale Laufzeit: 180 Minuten
- Schaltverzoegerung: 1000 ms

Alle Werte koennen in der Instanzkonfiguration geaendert werden.

## IPSView

Das Modul erzeugt je Bewaesserungsventil:
- einen Boolean-Schalter mit dem Namen des Ventils
- eine einstellbare Laufzeit in Minuten
- eine Restlaufzeit in Sekunden
- einen Textstatus

Fuer IPSView soll der vom Modul erzeugte Boolean-Schalter verwendet werden, NICHT direkt die Hardwarevariable.

## Installation

IP-Symcon bindet eigene PHP-Module ueber eine Git-Repository-URL in der Kerninstanz "Modules" / "Module Control" ein.

1. Den Inhalt dieses Ordners in ein Git-Repository (z. B. GitHub) legen. `library.json` muss im Wurzelverzeichnis des Repositories liegen.
2. In IP-Symcon die Kerninstanz `Modules` oeffnen.
3. Ueber `+` die Git-Repository-URL hinzufuegen.
4. Danach `Instanz hinzufuegen` -> Hersteller `ChatGPT` -> `ChatGPT Gartenbewaesserung`.
5. Konfiguration pruefen und uebernehmen.

## Sicherheit

Das Modul schaltet beim Installieren oder beim reinen Oeffnen der Konfiguration keine Hardware. Erst eine Bedienaktion auf einer der vom Modul erzeugten Schaltvariablen loest eine Schaltung aus.

Die Funktion `CGI_StopAll(<InstanzID>)` kann alle konfigurierten Gartenventile schliessen und danach den Haupthahn schliessen.
