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
        $this->assertSame('Windows open', $this->tileData($this->counter($rows, ['Kind' => 1]))['texts']['headline']);
        $custom = $this->tileData($this->counter($rows, ['Kind' => 2, 'ActiveText' => 'Geräte aktiv', 'InactiveText' => 'Ruhe']));
        $this->assertSame('Geräte aktiv', $custom['texts']['headline']);
        $this->assertSame('Ruhe', $custom['texts']['clear']);
    }

    public function testTileHtmlIsDeliveredWithStartData(): void
    {
        $counter = $this->counter([$this->row('EG', $this->boolVar(true))]);

        $html = IPS\InstanceManager::getInstanceInterface($counter)->GetVisualizationTile();

        $this->assertStringContainsString('window.handleMessage', $html);
        $this->assertMatchesRegularExpression('/<script>handleMessage\(".*"\);<\/script>\s*$/s', $html);
    }

    // --- Hilfen ---

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
