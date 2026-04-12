<?php
// Just a scratch file to mentally structure the implementation
function calculateSecretaryValidatedTotal($corner) {
    $totalValidated = 0;
    // Assuming active match logic uses all records in DB? 
    // Yes, FightDetailJuryPointYellow only holds points for the active match currently!
    
    for ($round = 1; $round <= 3; $round++) {
        // Collect sequences
        $juryInputs = [1 => [], 2 => [], 3 => [], 4 => []];
        // Fetch inputs ordered by ID explicitly to preserve history order:
        // $details = FightDetailJuryPointYellow::where('round_number', $round)->orderBy('id', 'asc')->with(['score', 'punishment'])->get();
        // foreach ($details as $d) {
        // ... array_push($juryInputs[$d->jury_number], ...) 
        // ...
        // }
        
        $maxLen = max(count($juryInputs[1]), count($juryInputs[2]), count($juryInputs[3]), count($juryInputs[4]));
        for ($i = 0; $i < $maxLen; $i++) {
            $freq = [];
            $valueMap = [];
            for ($j = 1; $j <= 4; $j++) {
                if (isset($juryInputs[$j][$i])) {
                    $item = $juryInputs[$j][$i];
                    $id = $item['id']; // "s:1"
                    if (!isset($freq[$id])) {
                        $freq[$id] = 0;
                        $valueMap[$id] = $item['val'];
                    }
                    $freq[$id]++;
                }
            }
            
            // Check if any id has freq >= 3
            foreach ($freq as $id => $count) {
                if ($count >= 3) {
                    $totalValidated += $valueMap[$id];
                    break; // Only one item can have majority >= 3 out of 4
                }
            }
        }
    }
    return $totalValidated;
}
