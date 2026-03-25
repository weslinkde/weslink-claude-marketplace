<?php

/**
 * Find uncategorized Klassifikationsnummern and attempt automatic matching.
 *
 * Usage: php artisan tinker --execute="$(cat .claude/edifact-plugin/skills/klassifikationspflege/scripts/find-uncategorized.php)"
 */

$uncategorized = \App\Models\Klassifikationsnummer::whereNull('category_id')->get();
$total = $uncategorized->count();

if ($total === 0) {
    echo "Keine unkategorisierten Eintraege gefunden.\n";
    return;
}

echo "=== {$total} unkategorisierte Klassifikationsnummern ===\n\n";

$elmo = [];
$matched = [];
$unmatched = [];
$empty = [];

foreach ($uncategorized as $k) {
    if (empty($k->ELMOTEXT)) {
        $empty[] = $k;
        continue;
    }

    // ELMO format (12-digit numeric)
    if (preg_match('/^\d{12}$/', $k->ELMONUMBER)) {
        $prefix = substr($k->ELMONUMBER, 0, 3);
        $ref = \App\Models\Klassifikationsnummer::with('category')
            ->whereNotNull('category_id')
            ->whereRaw("\"ELMONUMBER\" LIKE ?", [$prefix . '%'])
            ->where('ELMOTEXT', 'like', '%' . substr($k->ELMOTEXT, 0, 20) . '%')
            ->first();

        $elmo[] = [
            'entry' => $k,
            'ref' => $ref,
        ];
        continue;
    }

    // Text-based matching
    $clean = $k->ELMOTEXT;
    $clean = preg_replace('/\s*-\s*\d{2}\.\d{2}\.\d{4}$/', '', $clean);
    $clean = preg_replace('/\s*\(?\d{2}\.\d{2}\.\d{4}\s*-\s*\d{2}\.\d{2}\.\d{4}\)?/', '', $clean);
    $clean = preg_replace('/\s*\(anteilig \d+ Tage\)/', '', $clean);
    $clean = preg_replace('/\s*\d+\s*Lizenz(en)?/', '', $clean);
    $clean = preg_replace('/\s*\d+\s*Minute\(n\)/', '', $clean);
    $clean = preg_replace('/\s*\d+\s*(MB|GB)/', '', $clean);
    $short = substr(trim($clean), 0, 25);

    $ref = null;
    if (strlen($short) >= 5) {
        $ref = \App\Models\Klassifikationsnummer::with('category')
            ->whereNotNull('category_id')
            ->where('ELMOTEXT', 'like', $short . '%')
            ->first();
    }

    if ($ref) {
        $matched[] = ['entry' => $k, 'ref' => $ref];
    } else {
        $unmatched[] = $k;
    }
}

// Output
if (! empty($elmo)) {
    echo "--- ELMO-FORMAT (Telekom, 12-stellig): " . count($elmo) . " ---\n";
    foreach ($elmo as $e) {
        $k = $e['entry'];
        $ref = $e['ref'];
        $refInfo = $ref
            ? "-> cat={$ref->category_id} ({$ref->category->name}) via {$ref->ELMONUMBER}"
            : '-> KEIN MATCH';
        echo "  {$k->id} | {$k->ELMONUMBER} | {$k->ELMOTEXT} {$refInfo}\n";
    }
    echo "\n";
}

if (! empty($matched)) {
    echo "--- AUTO-MATCHED (Text): " . count($matched) . " ---\n";
    foreach ($matched as $m) {
        $k = $m['entry'];
        $ref = $m['ref'];
        echo "  {$k->id} | {$k->ELMONUMBER} -> cat={$ref->category_id} ({$ref->category->name})\n";
    }
    echo "\n";
}

if (! empty($unmatched)) {
    echo "--- KEIN MATCH: " . count($unmatched) . " ---\n";
    foreach ($unmatched as $k) {
        echo "  {$k->id} | {$k->ELMONUMBER} | {$k->ELMOTEXT} | provider={$k->provider_id}\n";
    }
    echo "\n";
}

if (! empty($empty)) {
    echo "--- LEER (irrelevant): " . count($empty) . " ---\n";
    foreach ($empty as $k) {
        echo "  {$k->id} | {$k->ELMONUMBER}\n";
    }
    echo "\n";
}

$matchable = count($elmo) + count($matched);
echo "=== Zusammenfassung ===\n";
echo "Zuordenbar: {$matchable} | Kein Match: " . count($unmatched) . " | Leer: " . count($empty) . "\n";
