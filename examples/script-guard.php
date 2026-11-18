<?php

/**
 * Runs the script guard and the segmenter without WordPress.
 *
 * Usage: php examples/script-guard.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Guard\Guards;
use IndianOpinion\TranslateSidecar\Guard\Thresholds;
use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Script\ScriptCounter;
use IndianOpinion\TranslateSidecar\Segment\Segmenter;
use IndianOpinion\TranslateSidecar\Segment\SourceFields;

$counter = ScriptCounter::withDefaults();
$guards = new Guards($counter);
$greek = Language::fromArray(['prefix' => 'el', 'hreflang' => 'el']);

$samples = [
    'good' => 'Το δημοτικό συμβούλιο ενέκρινε τον νέο προϋπολογισμό τη Δευτέρα.',
    'left in English' => 'The council approved the new budget on Monday.',
    'Cyrillic bleeding in' => 'Το δημοτικό συμβούλιο ενέκρινε τον νέο бюджет в понедельник.',
];
foreach ($samples as $name => $text) {
    $report = new GuardReport();
    $guards->checkScript($text, 'body', $greek, new Thresholds(), $report);
    printf("%-22s %s %s\n", $name, $report->passed() ? 'pass' : 'FAIL', json_encode($counter->shares($text)));
}

$post = (new Segmenter())->segment(new SourceFields(
    'A title',
    '<!-- wp:paragraph --><p>Read <a href="https://example.org/">the report</a> at https://example.org/r.</p><!-- /wp:paragraph -->'
));
foreach ($post->segments as $id => $segment) {
    printf("%-6s %s\n", $id, $segment->text);
}
