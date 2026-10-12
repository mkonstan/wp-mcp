<?php
/** Internal subprocess entry for AcfWriteTest; no shared WordPress or ACF stub additions. */
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
\WpMcp\Tests\Support\WordPressStubs::loadPlugin();
\WpMcp\Tests\Support\WordPressRuntime::install();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['wpmcp_test_wp'] = $input['wp'];
$GLOBALS['acf_write_test'] = $input['acf'];
require __DIR__ . '/acf-write-double.inc';
$answer = wpmcp_acf_tools()['set-acf-values']['run']($input['args']);
echo 'ACF_RESULT:', base64_encode(serialize(['answer' => $answer, 'state' => $GLOBALS['acf_write_test']])), ':END_RESULT';
