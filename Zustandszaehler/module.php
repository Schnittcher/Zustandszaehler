<?php

declare(strict_types=1);

class Zustandszaehler extends IPSModuleStrict
{
    private const KIND_LIGHT = 0;
    private const KIND_WINDOW = 1;
    private const KIND_CUSTOM = 2;

    private const MODULE_ID = '{73437995-EDA4-4A39-B147-DBFC5436F54C}';
    private const IDENT_GROUP_PREFIX = 'Group_';
    private const STATUS_VARIABLE_MISSING = 201;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Kind', self::KIND_LIGHT);
        $this->RegisterPropertyString('ActiveText', '');
        $this->RegisterPropertyString('InactiveText', '');
        $this->RegisterPropertyBoolean('GroupVariables', true);
        $this->RegisterPropertyBoolean('FillTile', true);
        $this->RegisterPropertyBoolean('CustomColors', false);
        $this->RegisterPropertyInteger('ActiveColor', 0xE2673F);
        $this->RegisterPropertyInteger('ClearColor', 0x2FB344);
        $this->RegisterPropertyString('Items', '[]');

        // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1, auf 9.0 fehlt auch INSTANCE_VISUALIZATION_TYPE_HTML.
        // Deshalb steht für HTML der Wert 1 aus der Doku. Der Vollbildtyp (2) braucht 9.1, sonst bleibt die maximierte Ansicht leer.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Alte Registrierungen entfernen, sonst bleiben entfernte Variablen aktiv
        foreach ($this->GetMessageList() as $SenderID => $Messages) {
            foreach ($Messages as $Message) {
                $this->UnregisterMessage($SenderID, $Message);
            }
        }
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        $Instances = [];
        foreach ($this->getItems($Instances) as $Item) {
            $VariableID = $Item['VariableID'];
            if ($this->variableUsable($VariableID)) {
                $this->RegisterMessage($VariableID, VM_UPDATE);
                $this->RegisterMessage($VariableID, VM_DELETE);
            } else {
                $this->SendDebug(__FUNCTION__, 'Variable ' . $VariableID . ' existiert nicht und wird als nicht verfügbar gezählt', 0);
            }
        }
        // Ändert sich die Liste einer übernommenen Instanz, baut sich diese Instanz neu auf
        foreach (array_keys($Instances) as $InstanceID) {
            $this->RegisterMessage($InstanceID, IM_CHANGESETTINGS);
        }

        $this->maintainVariables();
        $this->Update();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Toggle':
                $this->toggle((int) $Value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
                break;
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELMESSAGE:
                if ($Data[0] == KR_READY) {
                    $this->ApplyChanges();
                }
                break;
            case IM_CHANGESETTINGS:
                $this->SendDebug(__FUNCTION__, 'Instanz ' . $SenderID . ' wurde geändert, die Liste wird neu aufgebaut', 0);
                $this->ApplyChanges();
                break;
            case VM_UPDATE:
            case VM_DELETE:
                $this->SendDebug(__FUNCTION__, 'SenderID ' . $SenderID . ' Message: ' . $Message, 0);
                $this->Update();
                break;
            default:
                break;
        }
    }

    /**
     * Zählt alle Variablen neu, schreibt die Variablen der Instanz und aktualisiert die Kachel.
     * Läuft bei jeder Änderung einer überwachten Variable.
     */
    public function Update(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        $State = $this->compute();

        $this->SetValue('Total', $State['Active']);
        $this->SetValue('AnyActive', $State['Active'] > 0);
        $this->SetValue('Summary', $this->buildSummary($State));
        if ($this->ReadPropertyBoolean('GroupVariables')) {
            foreach ($State['Groups'] as $Group) {
                $this->SetValue($Group['Ident'], $Group['Active']);
            }
        }

        if ($State['Configured'] == 0) {
            $this->SetStatus(IS_INACTIVE);
        } elseif ($State['Missing'] > 0) {
            $this->SetStatus(self::STATUS_VARIABLE_MISSING);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }

        // Nur senden, wenn sich für die Kachel etwas geändert hat
        $Data = json_encode($this->tileData($State));
        $Hash = md5((string) $Data);
        if ($this->GetBuffer('TileHash') !== $Hash) {
            $this->SetBuffer('TileHash', $Hash);
            $this->UpdateVisualizationValue((string) $Data);
        }
    }

    /**
     * Liefert die Namen der aktiven Elemente. Bei einer Gruppe nur deren Elemente, bei einem leeren Text alle.
     * Symcon erzeugt die Funktion für Skripte ohne Standardwerte, der Parameter ist dort Pflicht.
     */
    public function GetActive(string $Group): array
    {
        $Names = [];
        foreach ($this->compute()['Groups'] as $Entry) {
            if ($Group !== '' && $Entry['Name'] !== $Group) {
                continue;
            }
            foreach ($Entry['Items'] as $Item) {
                if ($Item['State'] === true) {
                    $Names[] = $Item['Name'];
                }
            }
        }
        return $Names;
    }

    public function GetVisualizationTile(): string
    {
        $Html = file_get_contents(__DIR__ . '/module.html');
        if ($Html === false) {
            $this->SendDebug(__FUNCTION__, 'module.html konnte nicht gelesen werden', 0);
            return '';
        }
        // handleMessage steht erst im HTML, deshalb folgen die Startdaten dahinter
        return $Html . '<script>handleMessage(' . json_encode(json_encode($this->tileData($this->compute()))) . ');</script>';
    }

    /**
     * Liest die Konfiguration und gibt jede Variable mit festen Typen zurück. Zeilen mit einer Zustandszähler-Instanz
     * werden durch deren Variablen ersetzt. In $Instances landen alle dabei gelesenen Instanzen.
     */
    private function getItems(?array &$Instances = null): array
    {
        $Rows = json_decode($this->ReadPropertyString('Items'), true);
        if (!is_array($Rows)) {
            return [];
        }

        // Eine Variable zählt nur einmal, auch wenn sie über mehrere Wege in der Liste steht
        $Items = [];
        $Seen = [];
        foreach ($this->expandRows($Rows, [$this->InstanceID], '', $Instances) as $Item) {
            if ($Item['VariableID'] > 0) {
                if (isset($Seen[$Item['VariableID']])) {
                    $this->SendDebug(__FUNCTION__, 'Variable ' . $Item['VariableID'] . ' steht mehrfach in der Liste und zählt nur einmal', 0);
                    continue;
                }
                $Seen[$Item['VariableID']] = true;
            }
            $Items[] = $Item;
        }
        return $Items;
    }

    /**
     * Löst die Zeilen einer Liste auf. Eine Instanz wird rekursiv durch ihre Zeilen ersetzt, alle ihre Variablen landen
     * in der Gruppe der Zeile (ohne Eintrag: im Namen der Instanz). $Visited verhindert Kreise zwischen Instanzen.
     */
    private function expandRows(array $Rows, array $Visited, string $GroupOverride, ?array &$Instances): array
    {
        $Items = [];
        foreach ($Rows as $Row) {
            $Name = trim((string) ($Row['Name'] ?? ''));
            $RowGroup = $GroupOverride !== '' ? $GroupOverride : trim((string) ($Row['Group'] ?? ''));
            $InstanceID = (int) ($Row['InstanceID'] ?? 0);

            if ($InstanceID > 0) {
                $Known = $this->isCounterInstance($InstanceID);
                if (!$Known || in_array($InstanceID, $Visited, true)) {
                    $this->SendDebug(__FUNCTION__, 'Instanz ' . $InstanceID . ' ist kein Zustandszähler oder verweist im Kreis und wird als nicht verfügbar gezählt', 0);
                    $Items[] = [
                        'Group'      => $RowGroup !== '' ? $RowGroup : $this->Translate('No group'),
                        'Name'       => $Name !== '' ? $Name : ($Known ? IPS_GetName($InstanceID) : $this->Translate('Unknown')),
                        'VariableID' => 0,
                        'Invert'     => false
                    ];
                    continue;
                }
                if ($Instances !== null) {
                    $Instances[$InstanceID] = true;
                }
                $ChildRows = json_decode(IPS_GetProperty($InstanceID, 'Items'), true);
                $Items = array_merge($Items, $this->expandRows(
                    is_array($ChildRows) ? $ChildRows : [],
                    array_merge($Visited, [$InstanceID]),
                    $RowGroup !== '' ? $RowGroup : IPS_GetName($InstanceID),
                    $Instances
                ));
                continue;
            }

            $Items[] = [
                'Group'      => $RowGroup !== '' ? $RowGroup : $this->Translate('No group'),
                'Name'       => $Name,
                'VariableID' => (int) ($Row['VariableID'] ?? 0),
                'Invert'     => (bool) ($Row['Invert'] ?? false)
            ];
        }
        return $Items;
    }

    private function isCounterInstance(int $InstanceID): bool
    {
        return $InstanceID > 0 && $InstanceID <= 60000 && IPS_InstanceExists($InstanceID)
            && strcasecmp(IPS_GetInstance($InstanceID)['ModuleInfo']['ModuleID'], self::MODULE_ID) === 0;
    }

    /**
     * Wertet alle konfigurierten Variablen aus. Die Reihenfolge der Gruppen entspricht dem ersten Auftreten in der Liste.
     */
    private function compute(): array
    {
        $Groups = [];
        $UsedIdents = [];
        $Active = 0;
        $Missing = 0;
        $Configured = 0;

        foreach ($this->getItems() as $Item) {
            $Configured++;
            $Group = $Item['Group'];
            if (!isset($Groups[$Group])) {
                $Groups[$Group] = [
                    'Name'   => $Group,
                    'Ident'  => $this->groupIdent($Group, $UsedIdents),
                    'Active' => 0,
                    'Known'  => 0,
                    'Items'  => []
                ];
            }

            $State = $this->readState($Item['VariableID'], $Item['Invert']);
            $Name = $Item['Name'];
            if ($Name === '') {
                $Name = $this->variableUsable($Item['VariableID']) ? IPS_GetName($Item['VariableID']) : $this->Translate('Unknown');
            }

            if ($State === null) {
                $Missing++;
            } else {
                $Groups[$Group]['Known']++;
                if ($State) {
                    $Groups[$Group]['Active']++;
                    $Active++;
                }
            }
            $Groups[$Group]['Items'][] = [
                'Name'       => $Name,
                'State'      => $State,
                'ID'         => $Item['VariableID'],
                'Switchable' => $State !== null && $this->variableSwitchable($Item['VariableID'])
            ];
        }

        return [
            'Groups'     => array_values($Groups),
            'Active'     => $Active,
            'Missing'    => $Missing,
            'Configured' => $Configured
        ];
    }

    /**
     * Liefert true für aktiv, false für nicht aktiv und null, wenn die Variable fehlt.
     * Zahlen zählen ab einem Wert über 0, Texte ab einem Wert ungleich leer und 0.
     */
    private function readState(int $VariableID, bool $Invert): ?bool
    {
        if (!$this->variableUsable($VariableID)) {
            return null;
        }
        $Value = GetValue($VariableID);
        switch (IPS_GetVariable($VariableID)['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $Active = (bool) $Value;
                break;
            case VARIABLETYPE_INTEGER:
            case VARIABLETYPE_FLOAT:
                $Active = $Value > 0;
                break;
            default:
                $Active = trim((string) $Value) !== '' && trim((string) $Value) !== '0';
                break;
        }
        return $Active !== $Invert;
    }

    private function variableUsable(int $VariableID): bool
    {
        // Symcon meldet eine Warnung, wenn die ID außerhalb des gültigen Bereichs liegt
        return $VariableID > 0 && $VariableID <= 60000 && IPS_VariableExists($VariableID);
    }

    /**
     * Schaltbar sind Ja/Nein-Variablen mit Aktion. Bei Zahlen ist der Einschaltwert nicht bekannt.
     */
    private function variableSwitchable(int $VariableID): bool
    {
        return $this->variableUsable($VariableID)
            && IPS_GetVariable($VariableID)['VariableType'] === VARIABLETYPE_BOOLEAN
            && HasAction($VariableID);
    }

    /**
     * Schaltet eine Variable der Liste um. Die Aktion der Variable setzt den Wert, das Gerät wird also tatsächlich geschaltet.
     * Variablen außerhalb der Liste lehnt das Modul ab, die Kachel läuft im Browser und ist keine vertrauenswürdige Quelle.
     */
    private function toggle(int $VariableID): void
    {
        $Listed = false;
        foreach ($this->getItems() as $Item) {
            if ($Item['VariableID'] === $VariableID) {
                $Listed = true;
                break;
            }
        }
        if (!$Listed || !$this->variableSwitchable($VariableID)) {
            $this->SendDebug(__FUNCTION__, 'Variable ' . $VariableID . ' ist nicht in der Liste oder nicht schaltbar', 0);
            return;
        }
        try {
            if (!@RequestAction($VariableID, !GetValueBoolean($VariableID))) {
                $this->SendDebug(__FUNCTION__, 'RequestAction für ' . $VariableID . ' nicht erfolgreich', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'Variable ' . $VariableID . ' nicht erreichbar: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Leitet aus dem Gruppennamen einen stabilen Ident ab. Bei gleichem Ergebnis zweier Namen hängt ein Kürzel an.
     */
    private function groupIdent(string $Name, array &$UsedIdents): string
    {
        $Ascii = strtr($Name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $Clean = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $Ascii), '_');
        $Ident = self::IDENT_GROUP_PREFIX . ($Clean !== '' ? $Clean : substr(md5($Name), 0, 8));
        if (isset($UsedIdents[$Ident])) {
            $Ident .= '_' . substr(md5($Name), 0, 4);
        }
        $UsedIdents[$Ident] = true;
        return $Ident;
    }

    /**
     * Legt die festen Variablen und je Gruppe eine Zählvariable an. Variablen entfernter Gruppen werden gelöscht.
     */
    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $AnyActive = $Value + [
            'OPTIONS' => json_encode([
                ['Caption' => $this->Translate('None'), 'ColorActive' => true, 'ColorValue' => 65280, 'ContentColorActive' => false, 'ContentColorValue' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => false],
                ['Caption' => $this->Translate('At least one'), 'ColorActive' => true, 'ColorValue' => 16744192, 'ContentColorActive' => false, 'ContentColorValue' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => true]
            ])
        ];
        $this->MaintainVariable('Total', $this->Translate('Total'), VARIABLETYPE_INTEGER, $Value, 1, true);
        $this->MaintainVariable('AnyActive', $this->Translate('At least one active'), VARIABLETYPE_BOOLEAN, $AnyActive, 2, true);
        $this->MaintainVariable('Summary', $this->Translate('Overview'), VARIABLETYPE_STRING, $Value, 3, true);

        $Wanted = [];
        if ($this->ReadPropertyBoolean('GroupVariables')) {
            foreach ($this->compute()['Groups'] as $Index => $Group) {
                $Wanted[$Group['Ident']] = true;
                $this->MaintainVariable($Group['Ident'], $Group['Name'], VARIABLETYPE_INTEGER, $Value, 10 + $Index, true);
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $ChildID) {
            $Ident = IPS_GetObject($ChildID)['ObjectIdent'];
            if (str_starts_with($Ident, self::IDENT_GROUP_PREFIX) && !isset($Wanted[$Ident])) {
                $this->UnregisterVariable($Ident);
            }
        }
    }

    /**
     * Texte nach Art des Zählers. Bei einer eigenen Art kommen sie aus der Konfiguration.
     */
    private function texts(): array
    {
        switch ($this->ReadPropertyInteger('Kind')) {
            case self::KIND_WINDOW:
                return [
                    'Headline' => $this->Translate('Windows open'),
                    'Clear'    => $this->Translate('All closed'),
                    'Active'   => $this->Translate('open'),
                    'Inactive' => $this->Translate('closed')
                ];
            case self::KIND_CUSTOM:
                $Active = trim($this->ReadPropertyString('ActiveText'));
                $Inactive = trim($this->ReadPropertyString('InactiveText'));
                return [
                    'Headline' => $Active !== '' ? $Active : $this->Translate('active'),
                    'Clear'    => $Inactive !== '' ? $Inactive : $this->Translate('All inactive'),
                    'Active'   => $Active !== '' ? $Active : $this->Translate('active'),
                    'Inactive' => $this->Translate('inactive')
                ];
            default:
                return [
                    'Headline' => $this->Translate('Lights on'),
                    'Clear'    => $this->Translate('All off'),
                    'Active'   => $this->Translate('on'),
                    'Inactive' => $this->Translate('off')
                ];
        }
    }

    /**
     * Kurztext für die Textvariable: je Gruppe die aktiven Elemente, getrennt durch Mittelpunkte.
     */
    private function buildSummary(array $State): string
    {
        $Parts = [];
        foreach ($State['Groups'] as $Group) {
            $Names = [];
            foreach ($Group['Items'] as $Item) {
                if ($Item['State'] === true) {
                    $Names[] = $Item['Name'];
                }
            }
            if ($Names !== []) {
                $Parts[] = $Group['Name'] . ': ' . implode(', ', $Names);
            }
        }
        return $Parts === [] ? $this->texts()['Clear'] : implode(' · ', $Parts);
    }

    /**
     * Daten für die Kachel. Die Texte sind übersetzt, die module.html enthält keine Sprache.
     */
    private function tileData(array $State): array
    {
        $Texts = $this->texts();
        $Groups = [];
        foreach ($State['Groups'] as $Group) {
            $Items = [];
            foreach ($Group['Items'] as $Item) {
                $Items[] = [
                    'n'  => $Item['Name'],
                    'a'  => $Item['State'] === null ? null : ($Item['State'] ? 1 : 0),
                    'id' => $Item['ID'],
                    's'  => $Item['Switchable'] ? 1 : 0
                ];
            }
            $Groups[] = ['name' => $Group['Name'], 'active' => $Group['Active'], 'known' => $Group['Known'], 'items' => $Items];
        }

        return [
            'kind'   => $this->ReadPropertyInteger('Kind'),
            'fill'   => $this->ReadPropertyBoolean('FillTile'),
            // Ohne eigene Farben übernimmt die Kachel das Design der Visualisierung
            'colors' => $this->ReadPropertyBoolean('CustomColors') ? [
                'active' => sprintf('#%06X', $this->ReadPropertyInteger('ActiveColor') & 0xFFFFFF),
                'clear'  => sprintf('#%06X', $this->ReadPropertyInteger('ClearColor') & 0xFFFFFF)
            ] : null,
            'active' => $State['Active'],
            'total'  => $State['Configured'],
            'groups' => $Groups,
            'texts'  => [
                'headline'   => $Texts['Headline'],
                'clear'      => $Texts['Clear'],
                'of'         => $this->Translate('of'),
                'empty'      => $this->Translate('No variables configured.'),
                'missing'    => $this->Translate('not available'),
                'showAll'    => $this->Translate('Show all'),
                'showActive' => $this->Translate('Show active only'),
                'noneActive' => $this->Translate('Nothing active'),
                'unit'       => $Texts['Active'],
                'off'        => $Texts['Inactive']
            ]
        ];
    }
}
