---
name: klassifikationspflege
description: >
  This skill should be used when the user says "/klassifikationspflege", "klassifikationsnummern pflegen",
  "unkategorisierte klassifikationsnummern", "fehlende kategorien fixen", "klassifikationsnummern prüfen",
  "category_id NULL", or wants to find and fix uncategorized Klassifikationsnummern entries in the
  Contradoo EDI system. Covers the full workflow: find uncategorized entries, auto-match against
  existing references, review with user, and generate a production migration.
---

# Klassifikationsnummern-Pflege

Wartungs-Workflow für die `klassifikationsnummern`-Tabelle in Contradoo. Neue Klassifikationsnummern
werden beim EDI-Import automatisch angelegt, oft ohne `category_id`. Fehlende Kategorien führen dazu,
dass `CostOverviewService::shouldShowInCostTable()` diese ignoriert und Kostenpositionen in der
PDF-Kostenübersicht fehlen.

## Workflow

### Phase 1: Unkategorisierte finden

Tinker-Abfrage ausführen, um alle Einträge ohne `category_id` zu identifizieren:

```php
$uncategorized = \App\Models\Klassifikationsnummer::whereNull('category_id')->count();
```

Falls 0: Dem User mitteilen, dass keine offenen Einträge existieren. Workflow beenden.

### Phase 2: Einträge gruppieren

Drei Gruppen unterscheiden:

**ELMO-Format (Telekom)** — 12-stellige numerische ELMONUMBER (z.B. `010000046989`):
```php
->whereRaw("\"ELMONUMBER\" ~ '^[0-9]{12}$'")
```

**Text-basiert (O2/Vodafone)** — ELMOTEXT-basierte Nummern mit Datums-/Lizenz-Suffixen.

**Leer** — Leerer ELMOTEXT (Kundennummern etc.), in der Regel irrelevant.

### Phase 3: Automatisches Matching

#### ELMO-Format Matching

Referenz-Einträge anhand von ELMONUMBER-Prefix und Namensmustern finden:

| Prefix | Typische Kategorie | type-Flag |
|--------|-------------------|-----------|
| `010` | 2 (Basispreise) oder 10 (Optionspreise) | `tariff=true` oder `costs=true` |
| `020` | 12 (Gebuchte Datenpässe) oder 31 (Datenpässe Roaming TZ) | `costs=true` |
| `080` | 26 (Einmalige Gebühren) | `costs=true` |
| `090` | 1 (Rabatte auf Basispreise) oder 8 (Rabatte auf Optionen) | `tariffrebate=true` oder `costsrebate=true` |

**Wichtig:** Prefix allein reicht nicht! Immer einen kategorisierten Referenz-Eintrag mit ähnlichem
ELMOTEXT suchen und die Zuordnung bestätigen:

```php
$ref = \App\Models\Klassifikationsnummer::with('category')
    ->whereNotNull('category_id')
    ->where('ELMONUMBER', 'like', $prefix . '%')
    ->where('ELMOTEXT', 'like', '%' . substr($elmotext, 0, 20) . '%')
    ->first();
```

#### Text-basiertes Matching

ELMOTEXT bereinigen (Datums-Suffixe, Lizenzanzahlen, anteilige Tage entfernen) und dann
die ersten 25 Zeichen gegen bestehende kategorisierte Einträge vergleichen:

```php
$clean = preg_replace('/\s*-\s*\d{2}\.\d{2}\.\d{4}$/', '', $text);
$clean = preg_replace('/\s*\(?\d{2}\.\d{2}\.\d{4}\s*-\s*\d{2}\.\d{2}\.\d{4}\)?/', '', $clean);
$clean = preg_replace('/\s*\(anteilig \d+ Tage\)/', '', $clean);
$clean = preg_replace('/\s*\d+\s*Lizenz(en)?/', '', $clean);
$clean = preg_replace('/\s*\d+\s*Minute\(n\)/', '', $clean);
$clean = preg_replace('/\s*\d+\s*(MB|GB)/', '', $clean);
$short = substr(trim($clean), 0, 25);

$match = \App\Models\Klassifikationsnummer::with('category')
    ->whereNotNull('category_id')
    ->where('ELMOTEXT', 'like', $short . '%')
    ->first();
```

### Phase 4: Ergebnisse dem User präsentieren

Übersichtliche Tabelle mit allen gefundenen Einträgen zeigen, gruppiert nach:
1. **Sicher zuordenbar** (ELMO mit Referenz-Match oder Text-Match) — mit vorgeschlagener Kategorie
2. **Nicht zuordenbar** (kein Referenz-Match) — manuell zu prüfen
3. **Irrelevant** (leerer ELMOTEXT)

Dem User die Zahlen und vorgeschlagenen Zuordnungen zeigen. Erst nach User-Bestätigung weiter.

### Phase 5: Migration erstellen

Nach Bestätigung eine Laravel-Migration generieren mit folgenden Anforderungen:

- **Branch**: `hotfix/fix-missing-klassifikationsnummer-categories` (oder ähnlich)
- **Idempotent**: Nur updaten wenn `category_id IS NULL`
- **Abgesichert gegen frische DBs**: Prüfen ob Tabelle und IDs existieren
- **Reversibel**: `down()` setzt `category_id` auf NULL zurück
- **Dokumentiert**: Docblock mit Root Cause, betroffenen Einträgen, und Referenz-Quellen

Migrations-Template:

```php
public function up(): void
{
    if (! DB::getSchemaBuilder()->hasTable('klassifikationsnummern')) {
        return;
    }

    $mappings = $this->getMappings();
    $existingIds = DB::table('klassifikationsnummern')
        ->whereIn('id', array_keys($mappings))
        ->whereNull('category_id')
        ->pluck('id')
        ->all();

    if (empty($existingIds)) {
        Log::info('Migration: No matching Klassifikationsnummern to fix');
        return;
    }

    $updated = 0;
    foreach ($existingIds as $id) {
        [$categoryId, $type] = $mappings[$id];
        DB::table('klassifikationsnummern')
            ->where('id', $id)
            ->update([
                'category_id' => $categoryId,
                'type' => json_encode($type),
                'updated_at' => now(),
            ]);
        $updated++;
    }

    Log::info("Migration: Fixed {$updated} Klassifikationsnummern");
}
```

### Phase 6: Nach Deployment

Den User darauf hinweisen, dass nach dem Deployment die PDF-Kostenübersichten für
betroffene Kunden neu generiert werden müssen. Betroffene EdiCostOverviews identifizieren
durch Verknüpfung der gefixten Klassifikationsnummern mit EdiFactDocuments.

## Kategorie-Referenz

| ID | Name | Beschreibung |
|----|------|-------------|
| 1 | Rabatte auf Basispreise | Rabatte auf Tarif-Grundpreise |
| 2 | Basispreise | Tarif-Grundpreise (Mobilfunk, Festnetz) |
| 8 | Rabatte auf Optionen | Rabatte auf gebuchte Optionen |
| 10 | Optionspreise | Gebuchte Optionen/Zusatzpakete |
| 12 | Gebuchte Datenpässe | Datenpässe national |
| 18 | # INFOTEXT | Info-Texte, keine Kosten |
| 21 | # Undefiniert | Undefiniert, wird ausgeblendet |
| 26 | Einmalige Gebühren | Bereitstellung, Anschlusspreise |
| 27 | Drittanbieterkosten (brutto) | Kosten von Drittanbietern |
| 31 | Datenpässe Roaming (TZ) | Roaming-Datenpässe |

## Type-Flags

Jede Klassifikationsnummer hat ein `type` JSON-Feld mit 5 Flags:

| Flag | Bedeutung | Wann true |
|------|-----------|-----------|
| `tariff` | Ist ein Tarif | Basispreise (010-Prefix) |
| `costs` | Kostenposition | Optionen, Einmalige, Datenpässe |
| `costsrebate` | Rabatt auf Kosten | Rabatte auf Optionen (090 + Option) |
| `tariffrebate` | Rabatt auf Tarif | Rabatte auf Basispreise (090 + Basis) |
| `info` | Nur Info | INFOTEXT-Kategorie |

## Wichtige Hinweise

- `shouldShowInCostTable()` prüft zuerst `category_id != NULL`, dann ob Kategorie in
  `COST_CATEGORIES` ist oder `type.costs`/`type.costsrebate` true ist
- Einträge mit `category_id = NULL` werden **immer** ignoriert — sie tauchen nie in Kostenübersichten auf
- Die `klassifikationsnummern`-Tabelle hat Soft Deletes (`deleted_at`)
- Alle Kategorien abrufbar via: `\App\Models\KlassifikationsnummerCategory::all(['id', 'name'])`
