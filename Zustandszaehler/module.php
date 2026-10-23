<?php

declare(strict_types=1);

class Zustandszaehler extends IPSModuleStrict
{
    private const KIND_LIGHT = 0;
    private const KIND_WINDOW = 1;
    private const KIND_CUSTOM = 2;
    private const KIND_SHUTTER = 3;

    private const MODULE_ID = '{73437995-EDA4-4A39-B147-DBFC5436F54C}';
    private const IDENT_GROUP_PREFIX = 'Group_';
    private const STATUS_VARIABLE_MISSING = 201;
    private const STATUS_INVALID_STEPS = 202;

    // Die Konstanten VARIABLE_PRESENTATION_* gibt es nicht auf jedem System, deshalb stehen hier die GUIDs aus der Doku
    private const PRESENTATION_LEGACY = '{4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C}';
    private const PRESENTATION_VALUE_INPUT = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';
    private const PRESENTATION_TEXT_BOX = '{56696857-92B2-1780-16B8-EB6F09D4AEF7}';
    private const PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const PRESENTATION_COLOR = '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}';
    private const PRESENTATION_SHUTTER = '{6075FC22-69AF-B110-3749-C24138883082}';
    private const PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    private const DEFAULT_SHUTTER_STEPS = [25, 50, 75, 99];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Kind', self::KIND_LIGHT);
        $this->RegisterPropertyString('ActiveText', '');
        $this->RegisterPropertyString('InactiveText', '');
        $this->RegisterPropertyString('ShutterSteps', '25,50,75,99');
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
            case 'UIKind':
                // Aus dem Formular: Die Stufen für Rollos passen nur zur Art Rollos
                $this->UpdateFormField('ShutterSteps', 'visible', (int) $Value === self::KIND_SHUTTER);
                break;
            case 'UIColors':
                // Aus dem Formular: Die beiden Farben gelten nur mit eigenen Farben
                $Show = filter_var($Value, FILTER_VALIDATE_BOOLEAN);
                $this->UpdateFormField('ActiveColor', 'visible', $Show);
                $this->UpdateFormField('ClearColor', 'visible', $Show);
                break;
            case 'Set':
                // Die Kachel schickt {"id": <Variable>, "value": <Wert>} als JSON-Text
                $Data = json_decode((string) $Value, true);
                if (!is_array($Data) || !isset($Data['id']) || !array_key_exists('value', $Data)) {
                    $this->SendDebug(__FUNCTION__, 'Ungültige Daten für die Aktion Set', 0);
                    break;
                }
                $this->setFromTile((int) $Data['id'], $Data['value']);
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

        $State = $this->compute(true);

        $this->SetValue('Total', $State['Active']);
        $this->SetValue('AnyActive', $State['Active'] > 0);
        $this->SetValue('Summary', $this->buildSummary($State));
        if ($this->ReadPropertyBoolean('GroupVariables')) {
            foreach ($State['Groups'] as $Group) {
                $this->SetValue($Group['Ident'], $Group['Active']);
            }
        }

        // Ungültige Stufen für Rollos werden ignoriert, die Instanz meldet es im Status
        $InvalidSteps = false;
        $this->shutterSteps($InvalidSteps);
        if ($State['Configured'] == 0) {
            $this->SetStatus(IS_INACTIVE);
        } elseif ($State['Missing'] > 0) {
            $this->SetStatus(self::STATUS_VARIABLE_MISSING);
        } elseif ($InvalidSteps) {
            $this->SetStatus(self::STATUS_INVALID_STEPS);
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

    /**
     * Baut auf der statischen form.json auf und blendet beim Öffnen die Felder aus, die nicht zur gespeicherten
     * Konfiguration passen. Beim Ändern im geöffneten Formular übernehmen das die onChange-Aktionen UIKind und UIColors.
     * Die Texte bleiben die englischen Originale, die Konsole übersetzt sie.
     */
    public function GetConfigurationForm(): string
    {
        $Json = file_get_contents(__DIR__ . '/form.json');
        $Form = $Json === false ? null : json_decode($Json, true);
        if (!is_array($Form)) {
            $this->SendDebug(__FUNCTION__, 'form.json konnte nicht gelesen werden', 0);
            return '{}';
        }
        $Custom = $this->ReadPropertyBoolean('CustomColors');
        $this->applyVisibility($Form['elements'], [
            'ShutterSteps' => $this->ReadPropertyInteger('Kind') === self::KIND_SHUTTER,
            'ActiveColor'  => $Custom,
            'ClearColor'   => $Custom
        ]);
        return (string) json_encode($Form);
    }

    public function GetVisualizationTile(): string
    {
        $Html = file_get_contents(__DIR__ . '/module.html');
        if ($Html === false) {
            $this->SendDebug(__FUNCTION__, 'module.html konnte nicht gelesen werden', 0);
            return '';
        }
        // handleMessage steht erst im HTML, deshalb folgen die Startdaten dahinter
        return $Html . '<script>handleMessage(' . json_encode(json_encode($this->tileData($this->compute(true)))) . ');</script>';
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
     * Mit $Controls liest die Auswertung auch Darstellung und formatierten Wert für die Steuerelemente der Kachel.
     */
    private function compute(bool $Controls = false): array
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
                'Switchable' => $State !== null && $this->variableSwitchable($Item['VariableID']),
                'Control'    => $Controls && $State !== null ? $this->controlFor($Item['VariableID']) : null,
                'Formatted'  => $Controls && $State !== null ? $this->formatted($Item['VariableID']) : ''
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
        $Type = IPS_GetVariable($VariableID)['VariableType'];

        // Bei der Art Rollos zählt ein Rollladen als aktiv, solange er nicht ganz offen ist
        if ($this->ReadPropertyInteger('Kind') === self::KIND_SHUTTER && ($Type === VARIABLETYPE_INTEGER || $Type === VARIABLETYPE_FLOAT)) {
            $Range = $this->shutterRange($VariableID);
            if ($Range !== null) {
                $NotOpen = abs((float) $Value - $Range['open']) > 0.005 * abs($Range['close'] - $Range['open']);
                return $NotOpen !== $Invert;
            }
        }

        switch ($Type) {
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
        if (!$this->isListed($VariableID) || !$this->variableSwitchable($VariableID)) {
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
     * Setzt visible bei den benannten Elementen, auch in verschachtelten Layouts.
     */
    private function applyVisibility(array &$Elements, array $Visible): void
    {
        foreach ($Elements as &$Element) {
            if (isset($Element['name']) && array_key_exists($Element['name'], $Visible)) {
                $Element['visible'] = $Visible[$Element['name']];
            }
            if (isset($Element['items']) && is_array($Element['items'])) {
                $this->applyVisibility($Element['items'], $Visible);
            }
        }
    }

    private function isListed(int $VariableID): bool
    {
        foreach ($this->getItems() as $Item) {
            if ($Item['VariableID'] === $VariableID) {
                return true;
            }
        }
        return false;
    }

    /**
     * Setzt den Wert einer Variable der Liste, den die Kachel mit einem Steuerelement gewählt hat. Das Modul prüft den
     * Wert gegen die Darstellung der Variable und schreibt über deren Aktion. Die Kachel läuft im Browser, deshalb
     * stehen nur Variablen der Liste mit Aktion und passendem Wert zur Auswahl.
     */
    private function setFromTile(int $VariableID, mixed $Value): void
    {
        if (!$this->isListed($VariableID) || !$this->variableUsable($VariableID) || !HasAction($VariableID)) {
            $this->SendDebug(__FUNCTION__, 'Variable ' . $VariableID . ' ist nicht in der Liste oder hat keine Aktion', 0);
            return;
        }
        $Type = IPS_GetVariable($VariableID)['VariableType'];
        try {
            if ($Type === VARIABLETYPE_BOOLEAN) {
                $Converted = filter_var($Value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($Converted === null) {
                    throw new InvalidArgumentException('kein Ja/Nein-Wert');
                }
            } else {
                $Control = $this->controlFor($VariableID);
                if ($Control === null) {
                    throw new InvalidArgumentException('die Darstellung hat kein Steuerelement');
                }
                $Converted = $this->validateValue($Control, $Type, $Value);
            }
            if (!@RequestAction($VariableID, $Converted)) {
                $this->SendDebug(__FUNCTION__, 'RequestAction für ' . $VariableID . ' nicht erfolgreich', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'Wert für Variable ' . $VariableID . ' abgelehnt: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Prüft einen Wert aus der Kachel gegen das Steuerelement und gibt ihn im Typ der Variable zurück.
     */
    private function validateValue(array $Control, int $Type, mixed $Value): mixed
    {
        switch ($Control['t']) {
            case 'enum':
                foreach ($Control['o'] as $Option) {
                    if ($this->sameValue($Option['v'], $Value)) {
                        return $Option['v'];
                    }
                }
                throw new InvalidArgumentException('der Wert gehört nicht zur Auswahl');

            case 'shutter':
                $Low = min($Control['o'][0]['v'], $Control['o'][count($Control['o']) - 1]['v']);
                $High = max($Control['o'][0]['v'], $Control['o'][count($Control['o']) - 1]['v']);
                return $this->numberInRange($Value, (float) $Low, (float) $High, $Type);

            case 'slider':
                return $this->numberInRange($Value, (float) $Control['min'], (float) $Control['max'], $Type);

            case 'color':
                if (!is_numeric($Value) || (int) $Value < 0 || (int) $Value > 0xFFFFFF) {
                    throw new InvalidArgumentException('keine Farbe');
                }
                return (int) $Value;

            case 'text':
                if ($Type === VARIABLETYPE_STRING) {
                    $Text = (string) $Value;
                    if (strlen($Text) > 255) {
                        throw new InvalidArgumentException('Text zu lang');
                    }
                    return $Text;
                }
                if (!is_numeric($Value)) {
                    throw new InvalidArgumentException('keine Zahl');
                }
                return $Type === VARIABLETYPE_INTEGER ? (int) round((float) $Value) : (float) $Value;
        }
        throw new InvalidArgumentException('unbekanntes Steuerelement');
    }

    private function numberInRange(mixed $Value, float $Low, float $High, int $Type): int|float
    {
        if (!is_numeric($Value)) {
            throw new InvalidArgumentException('keine Zahl');
        }
        $Number = (float) $Value;
        $Tolerance = 0.0001 * max(1.0, abs($High - $Low));
        if ($Number < $Low - $Tolerance || $Number > $High + $Tolerance) {
            throw new InvalidArgumentException('Wert außerhalb des Bereichs');
        }
        $Number = min($High, max($Low, $Number));
        return $Type === VARIABLETYPE_INTEGER ? (int) round($Number) : round($Number, 4);
    }

    private function sameValue(mixed $A, mixed $B): bool
    {
        if (is_numeric($A) && is_numeric($B)) {
            return abs((float) $A - (float) $B) < 0.000001;
        }
        return (string) $A === (string) $B;
    }

    /**
     * Darstellung einer Variable als Array. Leer, wenn das System keine Darstellungen kennt oder die Variable keine hat.
     */
    private function presentation(int $VariableID): array
    {
        if (!function_exists('IPS_GetVariablePresentation')) {
            return [];
        }
        try {
            $Presentation = @IPS_GetVariablePresentation($VariableID);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($Presentation) ? $Presentation : [];
    }

    private function formatted(int $VariableID): string
    {
        try {
            return (string) @GetValueFormatted($VariableID);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Offener und geschlossener Wert, wenn die Variable die Darstellung Rollladen hat.
     */
    private function shutterRange(int $VariableID): ?array
    {
        $Presentation = $this->presentation($VariableID);
        if (strcasecmp((string) ($Presentation['PRESENTATION'] ?? ''), self::PRESENTATION_SHUTTER) !== 0
            || !isset($Presentation['OPEN_OUTSIDE_VALUE'], $Presentation['CLOSE_INSIDE_VALUE'])) {
            return null;
        }
        $Open = (float) $Presentation['OPEN_OUTSIDE_VALUE'];
        $Close = (float) $Presentation['CLOSE_INSIDE_VALUE'];
        return $Open === $Close ? null : ['open' => $Open, 'close' => $Close];
    }

    /**
     * Stufen für Rollos in Prozent geschlossen. Ungültige Einträge fallen weg und setzen $Invalid, ein leeres Feld
     * gilt als Vorgabe.
     */
    private function shutterSteps(?bool &$Invalid = null): array
    {
        $Invalid = false;
        $Raw = trim($this->ReadPropertyString('ShutterSteps'));
        if ($Raw === '') {
            return self::DEFAULT_SHUTTER_STEPS;
        }
        $Steps = [];
        foreach (preg_split('/[\s,;]+/', $Raw) as $Token) {
            $Token = rtrim($Token, '%');
            if ($Token === '') {
                continue;
            }
            if (preg_match('/^\d{1,2}$/', $Token) === 1 && (int) $Token >= 1 && (int) $Token <= 99) {
                $Steps[(int) $Token] = (int) $Token;
            } else {
                $Invalid = true;
            }
        }
        if ($Steps === []) {
            $Invalid = true;
            return self::DEFAULT_SHUTTER_STEPS;
        }
        ksort($Steps);
        return array_values($Steps);
    }

    /**
     * Beschreibt das Steuerelement, das die Kachel für eine Variable zeigt, nach deren Darstellung. Null heißt: kein
     * Steuerelement. Ja/Nein-Variablen mit Aktion haben den Schalter und brauchen keine Beschreibung.
     */
    private function controlFor(int $VariableID): ?array
    {
        if (!$this->variableUsable($VariableID) || !HasAction($VariableID)) {
            return null;
        }
        $Type = IPS_GetVariable($VariableID)['VariableType'];
        if ($Type === VARIABLETYPE_BOOLEAN) {
            return null;
        }
        $Numeric = $Type === VARIABLETYPE_INTEGER || $Type === VARIABLETYPE_FLOAT;
        $Value = GetValue($VariableID);
        $Presentation = $this->presentation($VariableID);
        $Id = strtoupper((string) ($Presentation['PRESENTATION'] ?? ''));

        switch ($Id) {
            case self::PRESENTATION_SHUTTER:
                $Range = $this->shutterRange($VariableID);
                if (!$Numeric || $Range === null) {
                    return null;
                }
                $Options = [['p' => 0, 'v' => $this->shutterValue($Range, 0, $Type)]];
                foreach ($this->shutterSteps() as $Step) {
                    $Options[] = ['p' => $Step, 'v' => $this->shutterValue($Range, $Step, $Type)];
                }
                $Options[] = ['p' => 100, 'v' => $this->shutterValue($Range, 100, $Type)];
                $Closed = ((float) $Value - $Range['open']) / ($Range['close'] - $Range['open']) * 100;
                return ['t' => 'shutter', 'o' => $Options, 'p' => round(min(100.0, max(0.0, $Closed)), 1)];

            case self::PRESENTATION_ENUMERATION:
                $List = json_decode((string) ($Presentation['OPTIONS'] ?? '[]'), true);
                $Options = [];
                foreach (is_array($List) ? $List : [] as $Option) {
                    if (isset($Option['Value'])) {
                        $Color = (int) ($Option['Color'] ?? -1);
                        $Options[] = ['v' => $Option['Value'], 'c' => (string) ($Option['Caption'] ?? $Option['Value']), 'k' => $Color >= 0 ? sprintf('#%06X', $Color & 0xFFFFFF) : null];
                    }
                }
                return $Options === [] ? null : ['t' => 'enum', 'o' => array_slice($Options, 0, 100), 'v' => $Value];

            case self::PRESENTATION_LEGACY:
                return $this->legacyControl((string) ($Presentation['PROFILE'] ?? ''), $Type, $Value);

            case self::PRESENTATION_SLIDER:
                if (!$Numeric) {
                    return null;
                }
                $Step = (float) ($Presentation['STEP_SIZE'] ?? 1);
                return ['t' => 'slider', 'min' => (float) ($Presentation['MIN'] ?? 0), 'max' => (float) ($Presentation['MAX'] ?? 100), 'step' => $Step > 0 ? $Step : 1.0, 'v' => $Value];

            case self::PRESENTATION_COLOR:
                return $Type === VARIABLETYPE_INTEGER ? ['t' => 'color', 'v' => $Value] : null;

            case self::PRESENTATION_VALUE_INPUT:
            case self::PRESENTATION_TEXT_BOX:
                return ['t' => 'text', 'num' => $Numeric, 'v' => $Value];
        }
        return null;
    }

    private function shutterValue(array $Range, int $ClosedPercent, int $Type): int|float
    {
        $Value = $Range['open'] + ($Range['close'] - $Range['open']) * $ClosedPercent / 100;
        return $Type === VARIABLETYPE_INTEGER ? (int) round($Value) : round($Value, 4);
    }

    /**
     * Steuerelement für Variablen mit einem klassischen Variablenprofil: Auswahl aus den Assoziationen, sonst ein
     * Schieberegler bei Zahlenprofilen mit Wertebereich. Schreibgeschützte Profile haben keins.
     */
    private function legacyControl(string $ProfileName, int $Type, mixed $Value): ?array
    {
        if ($ProfileName === '' || !IPS_VariableProfileExists($ProfileName)) {
            return null;
        }
        $Profile = IPS_GetVariableProfile($ProfileName);
        if (!empty($Profile['IsReadOnly'])) {
            return null;
        }
        $Options = [];
        foreach ($Profile['Associations'] ?? [] as $Association) {
            $Name = (string) $Association['Name'];
            // Namen im Profil dürfen eine Formatangabe wie %d enthalten, ein ungültiges Format lässt den Namen stehen
            try {
                $Caption = @sprintf($Name, $Association['Value']);
            } catch (Throwable $e) {
                $Caption = $Name;
            }
            $Color = (int) ($Association['Color'] ?? -1);
            $Options[] = ['v' => $Association['Value'], 'c' => $Caption === '' ? $Name : $Caption, 'k' => $Color >= 0 ? sprintf('#%06X', $Color & 0xFFFFFF) : null];
        }
        if ($Options !== []) {
            return ['t' => 'enum', 'o' => array_slice($Options, 0, 100), 'v' => $Value];
        }
        if (($Type === VARIABLETYPE_INTEGER || $Type === VARIABLETYPE_FLOAT) && $Profile['MaxValue'] > $Profile['MinValue']) {
            $Step = (float) $Profile['StepSize'];
            return ['t' => 'slider', 'min' => (float) $Profile['MinValue'], 'max' => (float) $Profile['MaxValue'], 'step' => $Step > 0 ? $Step : 1.0, 'v' => $Value];
        }
        return null;
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
     * Texte nach Art des Zählers. Die beiden Texte aus der Konfiguration gelten für jede Art: Ist ein Feld leer,
     * bleibt die Vorgabe der Art. Der Text für aktiv ersetzt die Überschrift, bei der Art Benutzerdefiniert auch die
     * Bezeichnung des Zustands in der Liste. Der Text für nichts aktiv ersetzt die Meldung, wenn nichts aktiv ist.
     */
    private function texts(): array
    {
        switch ($this->ReadPropertyInteger('Kind')) {
            case self::KIND_WINDOW:
                $Texts = [
                    'Headline' => $this->Translate('Windows open'),
                    'Clear'    => $this->Translate('All closed'),
                    'Active'   => $this->Translate('open'),
                    'Inactive' => $this->Translate('closed')
                ];
                break;
            case self::KIND_SHUTTER:
                $Texts = [
                    'Headline' => $this->Translate('Shutters not open'),
                    'Clear'    => $this->Translate('All open'),
                    'Active'   => $this->Translate('not open'),
                    'Inactive' => $this->Translate('open')
                ];
                break;
            case self::KIND_CUSTOM:
                $Texts = [
                    'Headline' => $this->Translate('active'),
                    'Clear'    => $this->Translate('All inactive'),
                    'Active'   => $this->Translate('active'),
                    'Inactive' => $this->Translate('inactive')
                ];
                break;
            default:
                $Texts = [
                    'Headline' => $this->Translate('Lights on'),
                    'Clear'    => $this->Translate('All off'),
                    'Active'   => $this->Translate('on'),
                    'Inactive' => $this->Translate('off')
                ];
                break;
        }

        $Active = trim($this->ReadPropertyString('ActiveText'));
        $Inactive = trim($this->ReadPropertyString('InactiveText'));
        if ($Active !== '') {
            $Texts['Headline'] = $Active;
            if ($this->ReadPropertyInteger('Kind') === self::KIND_CUSTOM) {
                $Texts['Active'] = $Active;
            }
        }
        if ($Inactive !== '') {
            $Texts['Clear'] = $Inactive;
        }
        return $Texts;
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
                $Entry = [
                    'n'  => $Item['Name'],
                    'a'  => $Item['State'] === null ? null : ($Item['State'] ? 1 : 0),
                    'id' => $Item['ID'],
                    's'  => $Item['Switchable'] ? 1 : 0,
                    'fv' => $Item['Formatted']
                ];
                // Steuerelement nach der Darstellung der Variable, nur wenn es eins gibt
                if ($Item['Control'] !== null) {
                    $Entry['c'] = $Item['Control'];
                }
                $Items[] = $Entry;
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
                'ok'            => $this->Translate('OK'),
                'headline'      => $Texts['Headline'],
                'clear'         => $Texts['Clear'],
                'of'            => $this->Translate('of'),
                'empty'         => $this->Translate('No variables configured.'),
                'missing'       => $this->Translate('not available'),
                'showAll'       => $this->Translate('Show all'),
                'showActive'    => $this->Translate('Show active only'),
                'noneActive'    => $this->Translate('Nothing active'),
                'unit'          => $Texts['Active'],
                'off'           => $Texts['Inactive'],
                'shutterOpen'   => $this->Translate('Open'),
                'shutterClosed' => $this->Translate('Closed')
            ]
        ];
    }
}
