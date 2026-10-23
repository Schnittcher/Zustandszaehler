<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ZustandszaehlerTest extends TestCase
{
    private const MODULE_ID = '{73437995-EDA4-4A39-B147-DBFC5436F54C}';
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_VARIABLE_MISSING = 201;

    protected function setUp(): void
    {
        // Jeder Test startet mit einem leeren Kernel und lädt die Bibliothek neu
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
    }

    // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1. Die Stubs definieren sie nicht, der Test bildet also ein
    // System ohne sie nach (Symcon 9.0): Die Instanz muss sich trotzdem anlegen lassen.
    public function testInstanceCanBeCreatedOnSystemsWithoutVisualizationConstants(): void
    {
        $this->assertFalse(defined('INSTANCE_VISUALIZATION_TYPE_HTML'));
        $this->assertFalse(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN'));

        $counter = $this->counter([$this->row('EG', $this->boolVar(true))]);

        $this->assertSame(1, $this->total($counter));
    }

    // --- Zähllogik ---

    public function testBooleanCountsWhenTrue(): void
    {
        $on = $this->boolVar(true);
        $off = $this->boolVar(false);

        $counter = $this->counter([$this->row('EG', $on), $this->row('EG', $off)]);

        $this->assertSame(1, $this->total($counter));
        $this->assertTrue(GetValueBoolean($this->ident($counter, 'AnyActive')));
        $this->assertSame(self::STATUS_ACTIVE, $this->instanceStatus($counter));
    }

    public function testNumbersCountAboveZeroAndTextsWhenNeitherEmptyNorZero(): void
    {
        $rows = [
            $this->row('A', $this->intVar(5)),
            $this->row('A', $this->intVar(0)),
            $this->row('A', $this->intVar(-1)),
            $this->row('A', $this->floatVar(0.5)),
            $this->row('A', $this->floatVar(0.0)),
            $this->row('A', $this->stringVar('on')),
            $this->row('A', $this->stringVar('')),
            $this->row('A', $this->stringVar('0')),
            $this->row('A', $this->stringVar('   '))
        ];

        $counter = $this->counter($rows);

        // Aktiv sind 5, 0.5 und "on"
        $this->assertSame(3, $this->total($counter));
    }

    public function testInvertCountsTheOpposite(): void
    {
        $closedReportsTrue = $this->boolVar(true);
        $openReportsFalse = $this->boolVar(false);

        $counter = $this->counter([
            $this->row('EG', $closedReportsTrue, 'Fenster 1', true),
            $this->row('EG', $openReportsFalse, 'Fenster 2', true)
        ]);

        $this->assertSame(1, $this->total($counter));
        $this->assertSame(['Fenster 2'], ZSZ_GetActive($counter, ''));
    }

    public function testGroupsFollowFirstAppearanceAndGetOwnVariables(): void
    {
        $counter = $this->counter([
            $this->row('OG', $this->boolVar(true)),
            $this->row('EG', $this->boolVar(true)),
            $this->row('OG', $this->boolVar(true)),
            $this->row('Büro DG', $this->boolVar(false))
        ]);

        $this->assertSame(2, GetValueInteger($this->ident($counter, 'Group_OG')));
        $this->assertSame(1, GetValueInteger($this->ident($counter, 'Group_EG')));
        // Umlaute und Leerzeichen werden im Ident umgeschrieben, der Name der Variable bleibt lesbar
        $this->assertSame(0, GetValueInteger($this->ident($counter, 'Group_Buero_DG')));
        $this->assertSame('Büro DG', IPS_GetName($this->ident($counter, 'Group_Buero_DG')));
        // Die Positionen folgen der Reihenfolge der Liste
        $positions = [];
        foreach (['Group_OG', 'Group_EG', 'Group_Buero_DG'] as $ident) {
            $positions[] = IPS_GetObject($this->ident($counter, $ident))['ObjectPosition'];
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function testGroupNamesWithSameIdentGetDifferentVariables(): void
    {
        $counter = $this->counter([
            $this->row('EG-1', $this->boolVar(true)),
            $this->row('EG 1', $this->boolVar(false))
        ]);

        $groupVariables = $this->groupIdents($counter);
        $this->assertCount(2, $groupVariables);
    }

    public function testGroupVariablesCanBeDisabledAndStaleOnesAreRemoved(): void
    {
        $a = $this->boolVar(true);
        $b = $this->boolVar(true);
        $counter = $this->counter([$this->row('EG', $a), $this->row('OG', $b)]);
        $this->assertCount(2, $this->groupIdents($counter));

        // Gruppe entfernen: ihre Variable verschwindet
        IPS_SetProperty($counter, 'Items', json_encode([$this->row('EG', $a)]));
        IPS_ApplyChanges($counter);
        $this->assertSame(['Group_EG'], $this->groupIdents($counter));

        // Option abwählen: keine Gruppenvariablen mehr, die festen Variablen bleiben
        IPS_SetProperty($counter, 'GroupVariables', false);
        IPS_ApplyChanges($counter);
        $this->assertSame([], $this->groupIdents($counter));
        $this->assertSame(1, $this->total($counter));
    }

    public function testRepeatedApplyChangesKeepsTheSameVariables(): void
    {
        $counter = $this->counter([$this->row('EG', $this->boolVar(true)), $this->row('OG', $this->boolVar(false))]);
        $before = $this->childIdents($counter);

        IPS_ApplyChanges($counter);
        IPS_ApplyChanges($counter);

        $this->assertSame($before, $this->childIdents($counter));
        $this->assertSame(1, $this->total($counter));
    }

    public function testStatusWithoutVariablesAndWithMissingVariable(): void
    {
        $empty = $this->counter([]);
        $this->assertSame(self::STATUS_INACTIVE, $this->instanceStatus($empty));
        $this->assertSame(0, $this->total($empty));

        $on = $this->boolVar(true);
        $withMissing = $this->counter([$this->row('EG', $on), $this->row('EG', 99999, 'Weg')]);
        $this->assertSame(self::STATUS_VARIABLE_MISSING, $this->instanceStatus($withMissing));
        // Die vorhandene Variable zählt weiter, die fehlende zählt nicht mit
        $this->assertSame(1, $this->total($withMissing));
    }

    public function testSummaryListsActiveNamesPerGroup(): void
    {
        $kitchen = $this->boolVar(true);
        $bath = $this->boolVar(true);
        $named = $this->boolVar(false);
        IPS_SetName($named, 'Flur');
        $unnamed = $this->boolVar(true);
        IPS_SetName($unnamed, 'Name der Variable');

        $counter = $this->counter([
            $this->row('EG', $kitchen, 'Küche'),
            $this->row('EG', $named),
            $this->row('OG', $bath, 'Bad'),
            $this->row('OG', $unnamed)
        ]);

        $this->assertSame('EG: Küche · OG: Bad, Name der Variable', GetValueString($this->ident($counter, 'Summary')));
    }

    public function testSummaryShowsClearTextByKind(): void
    {
        $off = $this->boolVar(false);
        $rows = [$this->row('EG', $off)];

        $this->assertSame('All off', GetValueString($this->ident($this->counter($rows), 'Summary')));
        $this->assertSame('All closed', GetValueString($this->ident($this->counter($rows, ['Kind' => 1]), 'Summary')));
        $this->assertSame('Ruhe', GetValueString($this->ident($this->counter($rows, ['Kind' => 2, 'InactiveText' => 'Ruhe']), 'Summary')));
    }

    public function testGetActiveFiltersByGroup(): void
    {
        $counter = $this->counter([
            $this->row('EG', $this->boolVar(true), 'A'),
            $this->row('EG', $this->boolVar(false), 'B'),
            $this->row('OG', $this->boolVar(true), 'C')
        ]);

        $this->assertSame(['A', 'C'], ZSZ_GetActive($counter, ''));
        $this->assertSame(['A'], ZSZ_GetActive($counter, 'EG'));
        $this->assertSame(['C'], ZSZ_GetActive($counter, 'OG'));
        $this->assertSame([], ZSZ_GetActive($counter, 'Unbekannt'));
    }

    public function testUpdateAndMessageSinkFollowVariableChanges(): void
    {
        $lamp = $this->boolVar(false);
        $counter = $this->counter([$this->row('EG', $lamp)]);
        $this->assertSame(0, $this->total($counter));

        SetValueBoolean($lamp, true);
        ZSZ_Update($counter);
        $this->assertSame(1, $this->total($counter));

        // Eine Variablenmeldung zählt neu
        SetValueBoolean($lamp, false);
        IPS\InstanceManager::getInstanceInterface($counter)->MessageSink(0, $lamp, VM_UPDATE, [true, true, false]);
        $this->assertSame(0, $this->total($counter));
        $this->assertFalse(GetValueBoolean($this->ident($counter, 'AnyActive')));
    }

    // --- Instanzverweise ---

    public function testInstanceReferenceTakesOverVariablesAsOneGroup(): void
    {
        $eg = $this->counter([$this->row('x', $this->boolVar(true)), $this->row('x', $this->boolVar(true))]);
        IPS_SetName($eg, 'Licht EG');
        $og = $this->counter([$this->row('x', $this->boolVar(true)), $this->row('x', $this->boolVar(false))]);
        IPS_SetName($og, 'Licht OG');

        // Ohne Eintrag in "Gruppe" gilt der Name der Instanz, mit Eintrag dieser
        $total = $this->counter([$this->instanceRow('', $eg), $this->instanceRow('OG', $og)]);

        $this->assertSame(3, $this->total($total));
        $this->assertSame(2, GetValueInteger($this->ident($total, 'Group_Licht_EG')));
        $this->assertSame(1, GetValueInteger($this->ident($total, 'Group_OG')));
        $this->assertSame(self::STATUS_ACTIVE, $this->instanceStatus($total));
    }

    public function testInstanceReferenceCanBeMixedWithVariablesAndNested(): void
    {
        $inner = $this->counter([$this->row('x', $this->boolVar(true))]);
        IPS_SetName($inner, 'Innen');
        $middle = $this->counter([$this->instanceRow('', $inner), $this->row('x', $this->boolVar(true))]);
        IPS_SetName($middle, 'Mitte');
        $outer = $this->counter([$this->instanceRow('Haus', $middle), $this->row('Garten', $this->boolVar(true))]);

        // Alle Variablen einer übernommenen Instanz landen in der Gruppe der Zeile, auch die verschachtelten
        $this->assertSame(3, $this->total($outer));
        $this->assertSame(2, GetValueInteger($this->ident($outer, 'Group_Haus')));
        $this->assertSame(1, GetValueInteger($this->ident($outer, 'Group_Garten')));
    }

    public function testInstanceReferenceRebuildsWhenTheReferencedListChanges(): void
    {
        $a = $this->boolVar(true);
        $b = $this->boolVar(true);
        $child = $this->counter([$this->row('x', $a)]);
        $parent = $this->counter([$this->instanceRow('EG', $child)]);
        $this->assertSame(1, $this->total($parent));

        IPS_SetProperty($child, 'Items', json_encode([$this->row('x', $a), $this->row('x', $b)]));
        IPS_ApplyChanges($child);
        // Die globale Instanz bekommt die Meldung "Einstellungen geändert" und baut sich neu auf
        IPS\InstanceManager::getInstanceInterface($parent)->MessageSink(0, $child, IM_CHANGESETTINGS, []);

        $this->assertSame(2, $this->total($parent));
    }

    // Ob die Instanz die Nachrichten wirklich registriert, bilden die Stubs nicht ab (GetMessageList liefert dort immer []).
    // Das Nachzählen über Nachrichten ist auf symcon-dev geprüft.

    public function testSameVariableCountsOnlyOnce(): void
    {
        $lamp = $this->boolVar(true);
        $other = $this->boolVar(true);
        $child = $this->counter([$this->row('x', $lamp)]);

        // Dieselbe Variable steht direkt, noch einmal direkt und über die Instanz in der Liste
        $parent = $this->counter([
            $this->row('EG', $lamp),
            $this->row('EG', $lamp),
            $this->instanceRow('OG', $child),
            $this->row('DG', $other)
        ]);

        // Zwei verschiedene Variablen sind aktiv: die doppelte zählt einmal, die andere ebenfalls
        $this->assertSame(2, $this->total($parent));
        $this->assertSame(self::STATUS_ACTIVE, $this->instanceStatus($parent));
    }

    public function testReferenceToMissingOrForeignInstanceCountsAsUnavailable(): void
    {
        $lamp = $this->boolVar(true);
        $counter = $this->counter([$this->row('EG', $lamp), $this->instanceRow('OG', 99999)]);

        $this->assertSame(1, $this->total($counter));
        $this->assertSame(self::STATUS_VARIABLE_MISSING, $this->instanceStatus($counter));
    }

    // --- Kreise ---

    public function testSelfReferenceIsReportedAndDoesNotLoop(): void
    {
        $lamp = $this->boolVar(true);
        $counter = $this->counter([$this->row('EG', $lamp)]);

        IPS_SetProperty($counter, 'Items', json_encode([$this->row('EG', $lamp), $this->instanceRow('X', $counter)]));
        IPS_ApplyChanges($counter);

        $this->assertSame(1, $this->total($counter));
        $this->assertSame(self::STATUS_VARIABLE_MISSING, $this->instanceStatus($counter));
    }

    public function testCircularReferenceBetweenInstancesIsReportedAndDoesNotLoop(): void
    {
        $a = $this->counter([$this->row('x', $this->boolVar(true))]);
        $b = $this->counter([$this->row('x', $this->boolVar(true))]);

        IPS_SetProperty($a, 'Items', json_encode([$this->row('A', $this->boolVar(true)), $this->instanceRow('B', $b)]));
        IPS_ApplyChanges($a);
        IPS_SetProperty($b, 'Items', json_encode([$this->row('B', $this->boolVar(true)), $this->instanceRow('A', $a)]));
        IPS_ApplyChanges($b);
        IPS_ApplyChanges($a);

        $this->assertSame(self::STATUS_VARIABLE_MISSING, $this->instanceStatus($a));
        $this->assertSame(self::STATUS_VARIABLE_MISSING, $this->instanceStatus($b));
        // Jede Instanz zählt ihre eigene und die Variable der anderen einmal, der Rückverweis zählt als nicht verfügbar
        $this->assertSame(2, $this->total($a));
        $this->assertSame(2, $this->total($b));
    }

    public function testRemovingTheCircleRestoresTheStatus(): void
    {
        $a = $this->counter([$this->row('x', $this->boolVar(true))]);
        $b = $this->counter([$this->instanceRow('A', $a)]);
        IPS_SetProperty($a, 'Items', json_encode([$this->instanceRow('B', $b)]));
        IPS_ApplyChanges($a);
        $this->assertSame(self::STATUS_VARIABLE_MISSING, $this->instanceStatus($a));

        IPS_SetProperty($a, 'Items', json_encode([$this->row('x', $this->boolVar(true))]));
        IPS_ApplyChanges($a);

        $this->assertSame(self::STATUS_ACTIVE, $this->instanceStatus($a));
        $this->assertSame(1, $this->total($a));
    }

    // --- Schalten aus der Kachel ---

    public function testToggleSwitchesListedBooleanWithAction(): void
    {
        $lamp = $this->switchableBool(false);
        $counter = $this->counter([$this->row('EG', $lamp)]);

        // Die Stubs liefern keine Variablenmeldungen. Das Nachzählen nach dem Schalten übernimmt hier ZSZ_Update,
        // auf einem Symcon-System kommt die Meldung von selbst (auf symcon-dev geprüft).
        IPS_RequestAction($counter, 'Toggle', $lamp);
        $this->assertTrue(GetValueBoolean($lamp));
        ZSZ_Update($counter);
        $this->assertSame(1, $this->total($counter));

        IPS_RequestAction($counter, 'Toggle', $lamp);
        $this->assertFalse(GetValueBoolean($lamp));
        ZSZ_Update($counter);
        $this->assertSame(0, $this->total($counter));
    }

    public function testToggleRefusesVariablesOutsideTheListWithoutActionOrNotBoolean(): void
    {
        $listed = $this->switchableBool(false);
        $notListed = $this->switchableBool(false);
        $withoutAction = $this->boolVar(false);
        $number = $this->intVar(0);
        IPS_SetVariableCustomAction($number, $this->actionScript());
        $counter = $this->counter([
            $this->row('EG', $listed),
            $this->row('EG', $withoutAction),
            $this->row('EG', $number)
        ]);

        IPS_RequestAction($counter, 'Toggle', $notListed);
        IPS_RequestAction($counter, 'Toggle', $withoutAction);
        IPS_RequestAction($counter, 'Toggle', $number);
        IPS_RequestAction($counter, 'Toggle', 99999);
        IPS_RequestAction($counter, 'Unbekannt', $listed);

        $this->assertFalse(GetValueBoolean($notListed));
        $this->assertFalse(GetValueBoolean($withoutAction));
        $this->assertSame(0, GetValueInteger($number));
        $this->assertFalse(GetValueBoolean($listed));
    }

    public function testToggleWorksForVariablesTakenOverFromAnotherInstance(): void
    {
        $lamp = $this->switchableBool(false);
        $child = $this->counter([$this->row('x', $lamp)]);
        $parent = $this->counter([$this->instanceRow('EG', $child)]);

        IPS_RequestAction($parent, 'Toggle', $lamp);

        $this->assertTrue(GetValueBoolean($lamp));
    }

    // --- Kachel ---

    public function testTileDataContainsCountsItemsAndSwitchability(): void
    {
        $switchable = $this->switchableBool(true);
        $plain = $this->boolVar(false);
        $counter = $this->counter([$this->row('EG', $switchable, 'Lampe'), $this->row('EG', $plain, 'Kontakt'), $this->row('EG', 99999, 'Weg')]);

        $data = $this->tileData($counter);

        $this->assertSame(1, $data['active']);
        $this->assertSame(3, $data['total']);
        $this->assertCount(1, $data['groups']);
        $items = $data['groups'][0]['items'];
        $this->assertSame(['Lampe', 'Kontakt', 'Weg'], array_column($items, 'n'));
        $this->assertSame([1, 0, null], array_column($items, 'a'));
        $this->assertSame([1, 0, 0], array_column($items, 's'));
        $this->assertSame($switchable, $items[0]['id']);
    }

    public function testTileUsesTheVisualizationDesignByDefaultAndCustomColorsOnRequest(): void
    {
        $counter = $this->counter([$this->row('EG', $this->boolVar(true))]);
        $this->assertNull($this->tileData($counter)['colors']);
        $this->assertTrue($this->tileData($counter)['fill']);

        IPS_SetProperty($counter, 'CustomColors', true);
        IPS_SetProperty($counter, 'ActiveColor', 0x3F8FE2);
        IPS_SetProperty($counter, 'ClearColor', 0x2FB344);
        IPS_SetProperty($counter, 'FillTile', false);
        IPS_ApplyChanges($counter);

        $data = $this->tileData($counter);
        $this->assertSame(['active' => '#3F8FE2', 'clear' => '#2FB344'], $data['colors']);
        $this->assertFalse($data['fill']);
    }

    public function testTileTextsDependOnKind(): void
    {
        $rows = [$this->row('EG', $this->boolVar(true))];

        $this->assertSame('Lights on', $this->tileData($this->counter($rows))['texts']['headline']);
        // Ist nichts aktiv, zeigt die Kachel "OK" statt eines Hakens
        $this->assertSame('OK', $this->tileData($this->counter($rows))['texts']['ok']);
        $this->assertSame('Windows open', $this->tileData($this->counter($rows, ['Kind' => 1]))['texts']['headline']);
        $custom = $this->tileData($this->counter($rows, ['Kind' => 2, 'ActiveText' => 'Geräte aktiv', 'InactiveText' => 'Ruhe']));
        $this->assertSame('Geräte aktiv', $custom['texts']['headline']);
        $this->assertSame('Ruhe', $custom['texts']['clear']);
        // Bei der Art Benutzerdefiniert steht der Text auch als Bezeichnung des Zustands in der Liste
        $this->assertSame('Geräte aktiv', $custom['texts']['unit']);
    }

    public function testCustomTextsApplyToEveryKindAndEmptyFieldsKeepTheDefaults(): void
    {
        $rows = [$this->row('EG', $this->boolVar(true))];

        // Licht und Fenster: Der eigene Text ersetzt Überschrift und Meldung, die Bezeichnung des Zustands bleibt
        $light = $this->tileData($this->counter($rows, ['Kind' => 0, 'ActiveText' => 'Brennt', 'InactiveText' => 'Dunkel']));
        $this->assertSame('Brennt', $light['texts']['headline']);
        $this->assertSame('Dunkel', $light['texts']['clear']);
        $this->assertSame('on', $light['texts']['unit']);

        $window = $this->tileData($this->counter($rows, ['Kind' => 1, 'ActiveText' => 'Offen', 'InactiveText' => 'Zu']));
        $this->assertSame('Offen', $window['texts']['headline']);
        $this->assertSame('Zu', $window['texts']['clear']);
        $this->assertSame('open', $window['texts']['unit']);

        // Nur ein Feld gesetzt: das andere behält die Vorgabe der Art. Leerzeichen allein zählen als leer.
        $onlyActive = $this->tileData($this->counter($rows, ['Kind' => 1, 'ActiveText' => 'Offen', 'InactiveText' => '   ']));
        $this->assertSame('Offen', $onlyActive['texts']['headline']);
        $this->assertSame('All closed', $onlyActive['texts']['clear']);

        // Auch die Textvariable nutzt den eigenen Text, wenn nichts aktiv ist
        $off = $this->counter([$this->row('EG', $this->boolVar(false))], ['Kind' => 0, 'InactiveText' => 'Dunkel']);
        $this->assertSame('Dunkel', GetValueString($this->ident($off, 'Summary')));
    }

    public function testItemsAndGroupsFollowTheOrderOfTheList(): void
    {
        $a = $this->row('EG', $this->boolVar(true), 'A');
        $b = $this->row('OG', $this->boolVar(true), 'B');
        $c = $this->row('EG', $this->boolVar(true), 'C');
        $counter = $this->counter([$a, $b, $c]);
        $this->assertSame(['EG', 'OG'], array_column($this->tileData($counter)['groups'], 'name'));
        $this->assertSame(['A', 'C'], array_column($this->tileData($counter)['groups'][0]['items'], 'n'));

        // Nach dem Umsortieren (Drag and Drop in der Liste) folgen Gruppen und Elemente der neuen Reihenfolge
        IPS_SetProperty($counter, 'Items', json_encode([$c, $b, $a]));
        IPS_ApplyChanges($counter);

        $data = $this->tileData($counter);
        $this->assertSame(['EG', 'OG'], array_column($data['groups'], 'name'));
        $this->assertSame(['C', 'A'], array_column($data['groups'][0]['items'], 'n'));
        // GetActive liefert gruppenweise in der Reihenfolge der Liste
        $this->assertSame(['C', 'A', 'B'], ZSZ_GetActive($counter, ''));
        $this->assertSame('EG: C, A · OG: B', GetValueString($this->ident($counter, 'Summary')));
    }

    public function testTheListInTheFormCanBeReorderedAndIsNotSorted(): void
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/../Zustandszaehler/form.json'), true);
        $list = null;
        foreach ($form['elements'] as $element) {
            if (($element['name'] ?? '') === 'Items') {
                $list = $element;
            }
        }

        $this->assertNotNull($list);
        // Mit changeOrder lässt sich die Reihenfolge per Drag and Drop ändern, eine Sortierung nach Spalten schließt das aus
        $this->assertTrue($list['changeOrder']);
        $this->assertArrayNotHasKey('sort', $list);
    }

    public function testTileHtmlIsDeliveredWithStartData(): void
    {
        $counter = $this->counter([$this->row('EG', $this->boolVar(true))]);

        $html = IPS\InstanceManager::getInstanceInterface($counter)->GetVisualizationTile();

        $this->assertStringContainsString('window.handleMessage', $html);
        $this->assertMatchesRegularExpression('/<script>handleMessage\(".*"\);<\/script>\s*$/s', $html);
    }

    // --- Rollos ---

    public function testShutterKindCountsShuttersThatAreNotFullyOpen(): void
    {
        $open = $this->shutterVar(1.0);
        $partlyClosed = $this->shutterVar(0.06);
        $closed = $this->shutterVar(0.0);
        $almostOpen = $this->shutterVar(0.999);

        $counter = $this->counter([
            $this->row('EG', $open),
            $this->row('EG', $partlyClosed),
            $this->row('EG', $closed),
            $this->row('EG', $almostOpen)
        ], ['Kind' => 3]);

        // Aktiv sind das teilweise und das ganz geschlossene Rollo, ein Wert innerhalb von 0,5 % der Strecke gilt als offen
        $this->assertSame(2, $this->total($counter));
        $this->assertSame('Shutters not open', $this->tileData($counter)['texts']['headline']);
        $this->assertSame('All open', $this->tileData($this->counter([$this->row('EG', $open)], ['Kind' => 3]))['texts']['clear']);
    }

    public function testShutterOpenAndClosedValuesComeFromThePresentation(): void
    {
        // Bei diesem Rollladen ist 0 offen und 150 geschlossen
        $open = $this->shutterVar(0.0, 0.0, 150.0);
        $closed = $this->shutterVar(150.0, 0.0, 150.0);
        $half = $this->shutterVar(75.0, 0.0, 150.0);

        $counter = $this->counter([$this->row('EG', $open), $this->row('EG', $closed), $this->row('EG', $half)], ['Kind' => 3]);
        $this->assertSame(2, $this->total($counter));

        // Mit Invertiert zählt das Gegenteil
        $inverted = $this->counter([$this->row('EG', $open, '', true), $this->row('EG', $closed, '', true)], ['Kind' => 3]);
        $this->assertSame(1, $this->total($inverted));
    }

    public function testOtherKindsAndOtherVariablesUseTheGenericRule(): void
    {
        $shutter = $this->shutterVar(1.0);
        $plainNumber = $this->floatVar(0.0);

        // Bei Licht zählt die Zahl über 0, auch bei einem Rollladen
        $this->assertSame(1, $this->total($this->counter([$this->row('EG', $shutter)], ['Kind' => 0])));
        // Bei Rollos zählt eine Zahl ohne die Darstellung Rollladen nach der allgemeinen Regel
        $this->assertSame(0, $this->total($this->counter([$this->row('EG', $plainNumber)], ['Kind' => 3])));
    }

    public function testShutterControlHasTheStepsAndTheCurrentPosition(): void
    {
        $counter = $this->counter([$this->row('EG', $this->shutterVar(0.06))], ['Kind' => 3]);

        $control = $this->tileData($counter)['groups'][0]['items'][0]['c'];

        $this->assertSame('shutter', $control['t']);
        $this->assertSame([0, 25, 50, 75, 99, 100], array_column($control['o'], 'p'));
        $this->assertEqualsWithDelta([1.0, 0.75, 0.5, 0.25, 0.01, 0.0], array_column($control['o'], 'v'), 0.00001);
        $this->assertEqualsWithDelta(94.0, $control['p'], 0.01);
    }

    public function testShutterStepsAreConfigurableAndInvalidEntriesAreReported(): void
    {
        $rows = [$this->row('EG', $this->shutterVar(0.5))];
        $steps = fn (array $configuration) => array_column($this->tileData($this->counter($rows, ['Kind' => 3] + $configuration))['groups'][0]['items'][0]['c']['o'], 'p');

        $this->assertSame([0, 10, 40, 90, 100], $steps(['ShutterSteps' => '90%, 10; 40 40']));
        // Ein leeres Feld gilt als Vorgabe und ist kein Fehler
        $empty = $this->counter($rows, ['Kind' => 3, 'ShutterSteps' => '']);
        $this->assertSame(self::STATUS_ACTIVE, $this->instanceStatus($empty));
        $this->assertSame([0, 25, 50, 75, 99, 100], $steps(['ShutterSteps' => '  ']));

        // Ungültige Einträge fallen weg und die Instanz meldet es im Status, gültige bleiben
        $mixed = $this->counter($rows, ['Kind' => 3, 'ShutterSteps' => 'abc, 0, 100, 30, 150']);
        $this->assertSame(202, $this->instanceStatus($mixed));
        $this->assertSame([0, 30, 100], $steps(['ShutterSteps' => 'abc, 0, 100, 30, 150']));
        // Sind alle ungültig, gilt die Vorgabe
        $none = $this->counter($rows, ['Kind' => 3, 'ShutterSteps' => 'x, y']);
        $this->assertSame(202, $this->instanceStatus($none));
        $this->assertSame([0, 25, 50, 75, 99, 100], $steps(['ShutterSteps' => 'x, y']));
    }

    // --- Steuerelemente nach der Darstellung ---

    public function testControlsFollowThePresentationOfTheVariable(): void
    {
        $enum = $this->presentedVar(VARIABLETYPE_INTEGER, 1, $this->enumPresentation([[0, 'Aus'], [1, 'Stufe 1'], [2, 'Stufe 2']]));
        $slider = $this->presentedVar(VARIABLETYPE_INTEGER, 40, ['PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 5]);
        $color = $this->presentedVar(VARIABLETYPE_INTEGER, 0xFF8800, ['PRESENTATION' => '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}']);
        $text = $this->presentedVar(VARIABLETYPE_STRING, 'Hallo', ['PRESENTATION' => '{6F477326-1683-A2FD-D2E7-477F366ECB62}']);
        $number = $this->presentedVar(VARIABLETYPE_FLOAT, 21.5, ['PRESENTATION' => '{6F477326-1683-A2FD-D2E7-477F366ECB62}']);
        $display = $this->presentedVar(VARIABLETYPE_INTEGER, 3, ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}']);
        $readOnlyEnum = $this->presentedVar(VARIABLETYPE_INTEGER, 1, $this->enumPresentation([[0, 'Aus'], [1, 'An']]), false);
        $switch = $this->switchableBool(true);

        $counter = $this->counter(array_map(fn (int $id) => $this->row('EG', $id), [$enum, $slider, $color, $text, $number, $display, $readOnlyEnum, $switch]));
        $items = $this->tileData($counter)['groups'][0]['items'];

        $this->assertSame('enum', $items[0]['c']['t']);
        $this->assertSame(['Aus', 'Stufe 1', 'Stufe 2'], array_column($items[0]['c']['o'], 'c'));
        $this->assertSame('slider', $items[1]['c']['t']);
        $this->assertEquals(['min' => 0.0, 'max' => 100.0, 'step' => 5.0], ['min' => $items[1]['c']['min'], 'max' => $items[1]['c']['max'], 'step' => $items[1]['c']['step']]);
        $this->assertSame('color', $items[2]['c']['t']);
        $this->assertSame(['t' => 'text', 'num' => false, 'v' => 'Hallo'], $items[3]['c']);
        $this->assertSame(['t' => 'text', 'num' => true, 'v' => 21.5], $items[4]['c']);
        // Wertanzeige, Variable ohne Aktion und Ja/Nein-Variable haben kein Steuerelement, der Schalter hat das Kennzeichen s
        $this->assertArrayNotHasKey('c', $items[5]);
        $this->assertArrayNotHasKey('c', $items[6]);
        $this->assertArrayNotHasKey('c', $items[7]);
        $this->assertSame(1, $items[7]['s']);
    }

    public function testClassicProfilesBecomeChoicesOrSliders(): void
    {
        IPS_CreateVariableProfile('ZSZ.Mode', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation('ZSZ.Mode', 0, 'Off', '', -1);
        IPS_SetVariableProfileAssociation('ZSZ.Mode', 1, 'Level %d', '', 0xFF0000);
        IPS_CreateVariableProfile('ZSZ.Level', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileValues('ZSZ.Level', 0, 50, 5);
        IPS_CreateVariableProfile('ZSZ.Fixed', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation('ZSZ.Fixed', 0, 'Zero', '', -1);

        $choice = $this->intVar(1);
        IPS_SetVariableCustomProfile($choice, 'ZSZ.Mode');
        IPS_SetVariableCustomAction($choice, $this->actionScript());
        $level = $this->intVar(10);
        IPS_SetVariableCustomProfile($level, 'ZSZ.Level');
        IPS_SetVariableCustomAction($level, $this->actionScript());
        $noProfile = $this->intVar(1);
        IPS_SetVariableCustomAction($noProfile, $this->actionScript());

        $counter = $this->counter([$this->row('EG', $choice), $this->row('EG', $level), $this->row('EG', $noProfile)]);
        $items = $this->tileData($counter)['groups'][0]['items'];

        $this->assertSame('enum', $items[0]['c']['t']);
        $this->assertSame(['Off', 'Level 1'], array_column($items[0]['c']['o'], 'c'));
        $this->assertSame([null, '#FF0000'], array_column($items[0]['c']['o'], 'k'));
        $this->assertSame('slider', $items[1]['c']['t']);
        $this->assertEquals(50.0, $items[1]['c']['max']);
        $this->assertArrayNotHasKey('c', $items[2]);
    }

    // --- Werte aus der Kachel setzen ---

    public function testSetWritesValuesThatFitTheControl(): void
    {
        $shutter = $this->shutterVar(1.0);
        $enum = $this->presentedVar(VARIABLETYPE_INTEGER, 0, $this->enumPresentation([[0, 'Aus'], [1, 'Stufe 1'], [2, 'Stufe 2']]));
        $slider = $this->presentedVar(VARIABLETYPE_INTEGER, 0, ['PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 5]);
        $color = $this->presentedVar(VARIABLETYPE_INTEGER, 0, ['PRESENTATION' => '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}']);
        $text = $this->presentedVar(VARIABLETYPE_STRING, '', ['PRESENTATION' => '{56696857-92B2-1780-16B8-EB6F09D4AEF7}']);
        $number = $this->presentedVar(VARIABLETYPE_FLOAT, 0.0, ['PRESENTATION' => '{6F477326-1683-A2FD-D2E7-477F366ECB62}']);
        $lamp = $this->switchableBool(false);
        $counter = $this->counter(array_map(fn (int $id) => $this->row('EG', $id), [$shutter, $enum, $slider, $color, $text, $number, $lamp]), ['Kind' => 3]);

        $this->set($counter, $shutter, 0.25);
        $this->set($counter, $enum, '2');
        $this->set($counter, $slider, 55.4);
        $this->set($counter, $color, 0xFF8800);
        $this->set($counter, $text, 'Küche');
        $this->set($counter, $number, '3,5');
        $this->set($counter, $lamp, true);

        $this->assertEqualsWithDelta(0.25, GetValueFloat($shutter), 0.00001);
        $this->assertSame(2, GetValueInteger($enum));
        // Zahlen werden in den Typ der Variable gebracht
        $this->assertSame(55, GetValueInteger($slider));
        $this->assertSame(0xFF8800, GetValueInteger($color));
        $this->assertSame('Küche', GetValueString($text));
        $this->assertTrue(GetValueBoolean($lamp));
        // Eine Zahl mit Komma ist keine Zahl für PHP, das Modul lehnt sie ab
        $this->assertEqualsWithDelta(0.0, GetValueFloat($number), 0.00001);
        $this->set($counter, $number, 3.5);
        $this->assertEqualsWithDelta(3.5, GetValueFloat($number), 0.00001);
    }

    public function testSetRefusesValuesThatDoNotFit(): void
    {
        $shutter = $this->shutterVar(0.5);
        $enum = $this->presentedVar(VARIABLETYPE_INTEGER, 1, $this->enumPresentation([[0, 'Aus'], [1, 'Stufe 1']]));
        $slider = $this->presentedVar(VARIABLETYPE_INTEGER, 20, ['PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 5]);
        $color = $this->presentedVar(VARIABLETYPE_INTEGER, 5, ['PRESENTATION' => '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}']);
        $text = $this->presentedVar(VARIABLETYPE_STRING, 'alt', ['PRESENTATION' => '{56696857-92B2-1780-16B8-EB6F09D4AEF7}']);
        $counter = $this->counter(array_map(fn (int $id) => $this->row('EG', $id), [$shutter, $enum, $slider, $color, $text]), ['Kind' => 3]);

        $this->set($counter, $shutter, 1.5);
        $this->set($counter, $enum, 7);
        $this->set($counter, $slider, 500);
        $this->set($counter, $color, 0x1000000);
        $this->set($counter, $text, str_repeat('x', 300));
        // Ein Text statt einer Zahl und ein falsches Datenformat
        $this->set($counter, $shutter, 'offen');
        IPS_RequestAction($counter, 'Set', 'kein JSON');
        IPS_RequestAction($counter, 'Set', json_encode(['id' => $enum]));

        $this->assertEqualsWithDelta(0.5, GetValueFloat($shutter), 0.00001);
        $this->assertSame(1, GetValueInteger($enum));
        $this->assertSame(20, GetValueInteger($slider));
        $this->assertSame(5, GetValueInteger($color));
        $this->assertSame('alt', GetValueString($text));
    }

    public function testSetRefusesVariablesOutsideTheListOrWithoutAction(): void
    {
        $listed = $this->presentedVar(VARIABLETYPE_INTEGER, 0, $this->enumPresentation([[0, 'Aus'], [1, 'An']]));
        $notListed = $this->presentedVar(VARIABLETYPE_INTEGER, 0, $this->enumPresentation([[0, 'Aus'], [1, 'An']]));
        $withoutAction = $this->presentedVar(VARIABLETYPE_INTEGER, 0, $this->enumPresentation([[0, 'Aus'], [1, 'An']]), false);
        $plain = $this->intVar(0);
        IPS_SetVariableCustomAction($plain, $this->actionScript());
        $counter = $this->counter(array_map(fn (int $id) => $this->row('EG', $id), [$listed, $withoutAction, $plain]));

        $this->set($counter, $notListed, 1);
        $this->set($counter, $withoutAction, 1);
        // Ohne passende Darstellung gibt es kein Steuerelement, das Modul schreibt dort nichts
        $this->set($counter, $plain, 1);
        $this->set($counter, 99999, 1);
        $this->set($counter, $listed, 1);

        $this->assertSame(0, GetValueInteger($notListed));
        $this->assertSame(0, GetValueInteger($withoutAction));
        $this->assertSame(0, GetValueInteger($plain));
        $this->assertSame(1, GetValueInteger($listed));
    }

    // --- Formular ---

    public function testFormShowsOnlyFieldsThatFitTheConfiguration(): void
    {
        $static = json_decode((string) file_get_contents(__DIR__ . '/../Zustandszaehler/form.json'), true);

        // Art Licht ohne eigene Farben: weder die Stufen für Rollos noch die Farben passen
        $light = $this->form($this->counter([]));
        $this->assertFalse($this->formField($light, 'ShutterSteps')['visible']);
        $this->assertFalse($this->formField($light, 'ActiveColor')['visible']);
        $this->assertFalse($this->formField($light, 'ClearColor')['visible']);
        // Alles andere bleibt sichtbar
        foreach (['Kind', 'ActiveText', 'InactiveText', 'GroupVariables', 'FillTile', 'CustomColors', 'Items'] as $name) {
            $this->assertNotFalse($this->formField($light, $name)['visible'] ?? true, $name);
        }

        // Art Rollos mit eigenen Farben: beides ist sichtbar
        $shutter = $this->form($this->counter([], ['Kind' => 3, 'CustomColors' => true]));
        $this->assertTrue($this->formField($shutter, 'ShutterSteps')['visible']);
        $this->assertTrue($this->formField($shutter, 'ActiveColor')['visible']);
        $this->assertTrue($this->formField($shutter, 'ClearColor')['visible']);

        // Fenster und Benutzerdefiniert haben keine Stufen für Rollos
        foreach ([1, 2] as $kind) {
            $this->assertFalse($this->formField($this->form($this->counter([], ['Kind' => $kind])), 'ShutterSteps')['visible']);
        }

        // Das Formular baut auf der statischen Datei auf und verliert nichts
        $this->assertCount(count($static['elements']), $light['elements']);
        $this->assertSame($static['actions'], $light['actions']);
        $this->assertSame($static['status'], $light['status']);
    }

    public function testFormChangesInTheOpenDialogGoThroughNamedActions(): void
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/../Zustandszaehler/form.json'), true);

        $this->assertStringContainsString("IPS_RequestAction(\$id, 'UIKind', \$Kind)", $this->formField($form, 'Kind')['onChange']);
        $this->assertStringContainsString("IPS_RequestAction(\$id, 'UIColors', \$CustomColors)", $this->formField($form, 'CustomColors')['onChange']);

        // Die Aktionen laufen ohne Fehler, auch mit unerwarteten Werten
        $counter = $this->counter([]);
        foreach ([['UIKind', 3], ['UIKind', '1'], ['UIColors', true], ['UIColors', 0], ['UIColors', 'x']] as [$ident, $value]) {
            IPS_RequestAction($counter, $ident, $value);
        }
        $this->assertSame(self::STATUS_INACTIVE, $this->instanceStatus($counter));
    }

    // --- Hilfen ---

    private function form(int $instanceID): array
    {
        return json_decode(IPS_GetConfigurationForm($instanceID), true);
    }

    // Das Element mit dem Namen aus den Elementen eines Formulars
    private function formField(array $form, string $name): array
    {
        foreach ($form['elements'] as $element) {
            if (($element['name'] ?? '') === $name) {
                return $element;
            }
        }
        $this->fail('Feld ' . $name . ' fehlt im Formular');
    }

    private function counter(array $rows, array $configuration = []): int
    {
        $instanceID = IPS_CreateInstance(self::MODULE_ID);
        IPS_SetConfiguration($instanceID, json_encode(array_merge(['Items' => json_encode($rows)], $configuration)));
        IPS_ApplyChanges($instanceID);
        return $instanceID;
    }

    private function row(string $group, int $variableID, string $name = '', bool $invert = false): array
    {
        return ['Group' => $group, 'Name' => $name, 'VariableID' => $variableID, 'Invert' => $invert, 'InstanceID' => 0];
    }

    private function instanceRow(string $group, int $instanceID): array
    {
        return ['Group' => $group, 'Name' => '', 'VariableID' => 0, 'Invert' => false, 'InstanceID' => $instanceID];
    }

    private function boolVar(bool $value): int
    {
        $id = IPS_CreateVariable(0);
        SetValueBoolean($id, $value);
        return $id;
    }

    private function intVar(int $value): int
    {
        $id = IPS_CreateVariable(1);
        SetValueInteger($id, $value);
        return $id;
    }

    private function floatVar(float $value): int
    {
        $id = IPS_CreateVariable(2);
        SetValueFloat($id, $value);
        return $id;
    }

    private function stringVar(string $value): int
    {
        $id = IPS_CreateVariable(3);
        SetValueString($id, $value);
        return $id;
    }

    // Die Aktion setzt den Wert, wie das Gerät es täte
    private function actionScript(): int
    {
        $script = IPS_CreateScript(0);
        IPS_SetScriptContent($script, '<?php SetValue($_IPS[\'VARIABLE\'], $_IPS[\'VALUE\']);');
        return $script;
    }

    // Variable mit der Darstellung und, wenn gewünscht, einer Aktion, die den Wert setzt wie ein Gerät
    private function presentedVar(int $type, mixed $value, array $presentation, bool $withAction = true): int
    {
        $id = IPS_CreateVariable($type);
        SetValue($id, $value);
        IPS_SetVariableCustomPresentation($id, $presentation);
        if ($withAction) {
            IPS_SetVariableCustomAction($id, $this->actionScript());
        }
        return $id;
    }

    // Rollladen als Kommazahl: $open ist der Wert für offen, $close der für geschlossen
    private function shutterVar(float $value, float $open = 1.0, float $close = 0.0): int
    {
        return $this->presentedVar(VARIABLETYPE_FLOAT, $value, [
            'PRESENTATION'       => '{6075FC22-69AF-B110-3749-C24138883082}',
            'USAGE_TYPE'         => 0,
            'OPEN_OUTSIDE_VALUE' => $open,
            'CLOSE_INSIDE_VALUE' => $close
        ]);
    }

    // Darstellung Auswahl aus Paaren [Wert, Beschriftung]
    private function enumPresentation(array $pairs): array
    {
        return [
            'PRESENTATION' => '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}',
            'LAYOUT'       => 1,
            'DISPLAY'      => 0,
            'OPTIONS'      => json_encode(array_map(fn (array $pair) => ['Value' => $pair[0], 'Caption' => $pair[1], 'Color' => -1, 'IconActive' => false, 'IconValue' => ''], $pairs))
        ];
    }

    // Wert aus der Kachel: Die Kachel schickt die Aktion Set mit einem JSON-Text
    private function set(int $counter, int $variableID, mixed $value): void
    {
        IPS_RequestAction($counter, 'Set', json_encode(['id' => $variableID, 'value' => $value]));
    }

    private function switchableBool(bool $value): int
    {
        $id = $this->boolVar($value);
        IPS_SetVariableCustomAction($id, $this->actionScript());
        return $id;
    }

    private function ident(int $instanceID, string $ident): int
    {
        return IPS_GetObjectIDByIdent($ident, $instanceID);
    }

    private function total(int $instanceID): int
    {
        return GetValueInteger($this->ident($instanceID, 'Total'));
    }

    private function instanceStatus(int $instanceID): int
    {
        return IPS_GetInstance($instanceID)['InstanceStatus'];
    }

    private function childIdents(int $instanceID): array
    {
        $idents = [];
        foreach (IPS_GetChildrenIDs($instanceID) as $childID) {
            $idents[] = IPS_GetObject($childID)['ObjectIdent'];
        }
        sort($idents);
        return $idents;
    }

    private function groupIdents(int $instanceID): array
    {
        return array_values(array_filter($this->childIdents($instanceID), fn (string $ident) => str_starts_with($ident, 'Group_')));
    }

    // Liest die Startdaten, die die Kachel hinter dem HTML bekommt
    private function tileData(int $instanceID): array
    {
        $html = IPS\InstanceManager::getInstanceInterface($instanceID)->GetVisualizationTile();
        $this->assertSame(1, preg_match('/handleMessage\(("(?:[^"\\\\]|\\\\.)*")\);<\/script>\s*$/s', $html, $matches));
        return json_decode(json_decode($matches[1]), true);
    }
}
