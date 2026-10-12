<?php
/** Write-path doubles live ONLY in isolated processes; the bare-host suite must still see no ACF. */
declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;
use WpMcp\SchemaValidator;

final class AcfWriteTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressStubs::loadPlugin();
        WordPressRuntime::install();
        $GLOBALS['acf_write_test'] = [
            'fields' => ['field_title' => ['key' => 'field_title', 'name' => 'title', 'type' => 'text']],
            'values' => [], 'writes' => [], 'reads' => [], 'groups' => [],
            'locations' => [['param' => 'post', 'value' => 123]], 'user_roles' => [125 => ['editor']],
            'pro' => true, 'return' => true, 'persist' => true, 'pages' => [
                ['menu_slug' => 'theme-options', 'post_id' => 'options'],
                ['menu_slug' => 'private-options', 'post_id' => 'private_store'],
            ],
        ];
        WordPressRuntime::addPost(123);
        WordPressRuntime::addTerm(124);
        WordPressRuntime::addUser(125, 'target');
        foreach (['read_post:123', 'edit_post:123', 'edit_term:124', 'edit_user:125', 'manage_options'] as $cap) {
            WordPressRuntime::allowCap($cap);
        }
    }

    private function call(array $fields = ['title' => 'new'], array $extra = []): mixed
    {
        // As in BareCoreTest: noisy startup stderr is not a failure. The child exit code and
        // fenced result decide success. A fresh process prevents ACF doubles contaminating
        // any existing no-ACF test, without PHPUnit's stderr-sensitive isolation transport.
        if (($extra['object_type'] ?? 'post') === 'options') {
            $GLOBALS['acf_write_test']['locations'] = [['param' => 'options_page', 'value' => 'theme-options']];
        }
        $process = proc_open([PHP_BINARY, '-d', 'display_startup_errors=0',
            '-d', 'display_errors=stderr', __DIR__ . '/AcfWriteProbe.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode(['acf' => $GLOBALS['acf_write_test'],
            'wp' => $GLOBALS['wpmcp_test_wp'], 'args' => array_merge(
                ['object_type' => 'post', 'id' => 123, 'fields' => $fields], $extra)], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame(0, $status, $stdout . $stderr);
        self::assertSame(1, preg_match('/ACF_RESULT:([A-Za-z0-9+\/=]+):END_RESULT/', $stdout, $match), $stdout . $stderr);
        $result = unserialize(base64_decode($match[1]), ['allowed_classes' => ['WP_Error']]);
        self::assertIsArray($result);
        $GLOBALS['acf_write_test'] = $result['state'];
        return $result['answer'];
    }

    private function cloneFixture(): array
    {
        $GLOBALS['acf_write_test']['fields']['field_clone'] = ['key' => 'field_clone',
            'name' => 'bundle', 'type' => 'clone', 'sub_fields' => [
                ['key' => 'field_caption', 'name' => 'bundle_caption', '_name' => 'caption', 'type' => 'text'],
                ['key' => 'field_url', 'name' => 'bundle_url', '_name' => 'url', 'type' => 'url']]];
        $old = ['field_caption' => 'Old caption', 'field_url' => 'https://old.test/'];
        $GLOBALS['acf_write_test']['values'] = ['123:field_title' => 'Keep title', '123:field_clone' => $old];
        return $GLOBALS['acf_write_test']['values'];
    }

    public function testOmittedCloneChildRefusesBeforeAnyWrite(): void
    {
        $before = $this->cloneFixture();
        foreach ([false, true] as $dry) {
            $error = $this->call(['title' => 'Changed title', 'bundle' => ['caption' => 'New caption']], ['dry_run' => $dry]);
            self::assertInstanceOf(\WP_Error::class, $error,
                'Partial clone wrote: ' . json_encode($GLOBALS['acf_write_test']['writes'])
                . '; storage: ' . json_encode($GLOBALS['acf_write_test']['values']));
            self::assertStringContainsString('bundle', $error->get_error_message());
            self::assertStringContainsString('url', $error->get_error_message());
            self::assertSame([], $GLOBALS['acf_write_test']['writes']);
            self::assertSame($before, $GLOBALS['acf_write_test']['values']);
        }
        // Positive control: complete clone writes both children and the preceding field.
        $answer = $this->call(['title' => 'Changed title', 'bundle' => ['caption' => 'New caption', 'url' => '']]);
        self::assertTrue($answer['fields'][1]['saved']);
        self::assertCount(2, $GLOBALS['acf_write_test']['writes']);
        self::assertSame(['field_caption' => 'New caption', 'field_url' => ''],
            $GLOBALS['acf_write_test']['values']['123:field_clone']);
    }

    public function testNullCloneAndGroupChildrenRefuseWhileRepeaterWritesNull(): void
    {
        $before = $this->cloneFixture();
        // The actual double, not a PHP-language tautology, demonstrates array_key_exists.
        $GLOBALS['acf_write_test']['fields']['field_rows'] = ['key' => 'field_rows', 'name' => 'rows',
            'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_caption', 'name' => 'caption', 'type' => 'text'],
                ['key' => 'field_url', 'name' => 'url', 'type' => 'url']]];
        $GLOBALS['acf_write_test']['model_rows'] = true;
        $GLOBALS['acf_write_test']['values']['123:field_rows'] = [$before['123:field_clone']];
        $rows = $this->call(['rows' => [['caption' => 'New caption', 'url' => null]]]);
        self::assertTrue($rows['fields'][0]['saved']);
        self::assertSame([['field_caption' => 'New caption', 'field_url' => null]],
            $GLOBALS['acf_write_test']['values']['123:field_rows']);
        self::assertCount(1, $GLOBALS['acf_write_test']['writes']);
        $GLOBALS['acf_write_test']['writes'] = [];
        // Exercise all three container types through the same public write entry.
        foreach (['clone', 'group'] as $container) {
            $GLOBALS['acf_write_test']['fields']['field_clone']['type'] = $container;
            if ($container === 'group') {
                foreach ($GLOBALS['acf_write_test']['fields']['field_clone']['sub_fields'] as &$child) {
                    $child['name'] = $child['_name'];
                }
                unset($child);
            }
            $before = $GLOBALS['acf_write_test']['values'];
            foreach ([false, true] as $dry) {
                foreach (['url', 'field_url'] as $selector) {
                    $error = $this->call(['title' => 'Changed title', 'bundle' => ['caption' => 'New caption', $selector => null]],
                        ['dry_run' => $dry]);
                    self::assertInstanceOf(\WP_Error::class, $error,
                        $container . ' skipped null but wrote siblings: ' . json_encode($GLOBALS['acf_write_test']['writes'])
                        . '; storage: ' . json_encode($GLOBALS['acf_write_test']['values']));
                    self::assertStringContainsString('bundle', $error->get_error_message());
                    self::assertStringContainsString('url', $error->get_error_message());
                    self::assertStringContainsString('ACF cannot clear', $error->get_error_message());
                    self::assertStringContainsString($container, $error->get_error_message());
                    self::assertSame([], $GLOBALS['acf_write_test']['writes']);
                    self::assertSame($before, $GLOBALS['acf_write_test']['values']);
                }
            }
        }
    }

    public function testNullGroupChildRefusesBeforeAnyWrite(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_group'] = ['key' => 'field_group',
            'name' => 'details', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_caption', 'name' => 'caption', 'type' => 'text'],
                ['key' => 'field_url', 'name' => 'url', 'type' => 'url']]];
        $before = ['123:field_title' => 'Keep title', '123:field_group' =>
            ['field_caption' => 'Old caption', 'field_url' => 'https://old.test/']];
        $GLOBALS['acf_write_test']['values'] = $before;
        foreach ([false, true] as $dry) {
            foreach (['url', 'field_url'] as $selector) {
                $error = $this->call(['title' => 'Changed title', 'details' => ['caption' => 'New caption', $selector => null]],
                    ['dry_run' => $dry]);
                self::assertInstanceOf(\WP_Error::class, $error,
                    'Group skipped null but wrote siblings: ' . json_encode($GLOBALS['acf_write_test']['writes'])
                    . '; storage: ' . json_encode($GLOBALS['acf_write_test']['values'])
                    . '; response: ' . json_encode($error));
                self::assertSame('wpmcp_bad_request', $error->get_error_code());
                self::assertStringContainsString('details.url', $error->get_error_message());
                self::assertStringContainsString('field_url', $error->get_error_message());
                self::assertStringContainsString('group', $error->get_error_message());
                self::assertStringContainsString('ACF cannot clear', $error->get_error_message());
                self::assertStringContainsString('"url":""', $error->get_error_message());
                self::assertSame([], $GLOBALS['acf_write_test']['writes']);
                self::assertSame($before, $GLOBALS['acf_write_test']['values']);
            }
        }
        // A real write is the positive control for the zero-write assertion and row model.
        $answer = $this->call(['title' => 'Changed title', 'details' => ['caption' => 'New caption', 'url' => '']]);
        self::assertTrue($answer['fields'][1]['saved']);
        self::assertCount(2, $GLOBALS['acf_write_test']['writes']);
        self::assertSame(['field_caption' => 'New caption', 'field_url' => ''],
            $GLOBALS['acf_write_test']['values']['123:field_group']);
        // A group nested in a repeater retains its own isset behavior.
        $GLOBALS['acf_write_test']['fields']['field_rows'] = ['key' => 'field_rows', 'name' => 'rows',
            'type' => 'repeater', 'sub_fields' => [$GLOBALS['acf_write_test']['fields']['field_group']]];
        $GLOBALS['acf_write_test']['writes'] = [];
        $before = $GLOBALS['acf_write_test']['values'];
        $error = $this->call(['title' => 'Again', 'rows' => [['details' => ['caption' => 'Nested', 'url' => null]]]]);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('rows[0].details.url', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        self::assertSame($before, $GLOBALS['acf_write_test']['values']);
    }

    public function testCompleteCloneRawKeysAndEmptyValuesAreSaved(): void
    {
        $this->cloneFixture();
        $answer = $this->call(['bundle' => ['field_caption' => '', 'field_url' => 'https://new.test/']]);
        self::assertTrue($answer['fields'][0]['saved']);
        self::assertTrue($this->call(['bundle' => $answer['fields'][0]['old_value']])['fields'][0]['saved']);
        self::assertSame($answer['fields'][0]['old_value'], $GLOBALS['acf_write_test']['values']['123:field_clone']);
    }

    public function testNestedCloneNullRefusesBeforeAnyWrite(): void
    {
        $this->cloneFixture();
        $GLOBALS['acf_write_test']['fields']['field_rows'] = ['key' => 'field_rows', 'name' => 'rows',
            'type' => 'repeater', 'sub_fields' => [$GLOBALS['acf_write_test']['fields']['field_clone']]];
        $error = $this->call(['title' => 'Changed title', 'rows' => [['bundle' => ['caption' => 'New caption', 'url' => null]]]]);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('rows[0].bundle.url', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testNamesAreResolvedToKeysBeforeWriting(): void
    {
        $answer = $this->call();
        self::assertSame([['field_title', 'new', 123]], $GLOBALS['acf_write_test']['writes']);
        self::assertTrue($answer['fields'][0]['saved']);
    }

    public function testNeverSavedFieldWritesWithoutReferenceMeta(): void
    {
        self::assertSame([], $GLOBALS['acf_write_test']['values']);
        $answer = $this->call();
        self::assertNull($answer['fields'][0]['old_value']);
        self::assertSame('new', $GLOBALS['acf_write_test']['values']['123:field_title']);
        self::assertTrue($answer['fields'][0]['saved']);
    }

    public function testAmbiguousNameListsKeysAndWritesNothing(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_other'] = ['key' => 'field_other', 'name' => 'title', 'type' => 'text'];
        $error = $this->call();
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('field_title, field_other', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testKeyWinsOverAmbiguousName(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_other'] = ['key' => 'field_other', 'name' => 'title', 'type' => 'text'];
        self::assertTrue($this->call(['field_title' => 'new'])['fields'][0]['saved']);
    }

    public function testUnknownLaterFieldPreventsEveryWrite(): void
    {
        $error = $this->call(['title' => 'new', 'typo' => 'bad']);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('Unknown field typo', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testFalseUpdateReturnWithPersistedValueIsSaved(): void
    {
        $GLOBALS['acf_write_test']['return'] = false;
        $answer = $this->call();
        self::assertTrue($answer['fields'][0]['saved'], 'Rule 4: saved must reflect the raw reread, never update_field false.');
        self::assertSame('new', $answer['fields'][0]['new_value']);
        self::assertCount(2, $GLOBALS['acf_write_test']['reads']);
    }

    public function testTrueUpdateReturnWithoutPersistenceIsNotSaved(): void
    {
        $GLOBALS['acf_write_test']['persist'] = false;
        self::assertFalse($this->call()['fields'][0]['saved']);
    }

    public function testUnchangedValueIsSavedEvenWhenUpdateReturnsFalse(): void
    {
        $GLOBALS['acf_write_test']['return'] = false;
        $GLOBALS['acf_write_test']['values']['123:field_title'] = 'new';
        $answer = $this->call()['fields'][0];
        self::assertTrue($answer['saved']);
        self::assertFalse($answer['would_change']);
    }

    public function testFlexibleContentRefusalNamesFieldAndType(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title']['type'] = 'flexible_content';
        $error = $this->call();
        self::assertStringContainsString('title', $error->get_error_message());
        self::assertStringContainsString('flexible_content', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public static function proTypes(): array
    {
        return [['repeater'], ['gallery'], ['flexible_content']];
    }

    #[DataProvider('proTypes')]
    public function testMissingProNamesFeatureAndField(string $type): void
    {
        $GLOBALS['acf_write_test']['pro'] = false;
        $GLOBALS['acf_write_test']['fields']['field_title']['type'] = $type;
        $error = $this->call();
        self::assertStringContainsString('ACF Pro ' . $type, $error->get_error_message());
        self::assertStringContainsString('title', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $answer = $this->call(extra: ['dry_run' => true])['fields'][0];
        self::assertSame('new', $answer['new_value']);
        self::assertTrue($answer['would_change']);
        self::assertFalse($answer['saved']);
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testOldValueIsRaw(): void
    {
        $GLOBALS['acf_write_test']['values']['123:field_title'] = '<b>raw</b>';
        $answer = $this->call()['fields'][0];
        self::assertSame('<b>raw</b>', $answer['old_value']);
        foreach ($GLOBALS['acf_write_test']['reads'] as $read) { self::assertFalse($read[2]); }
    }

    public static function targets(): array
    {
        return [['post', 123, 'edit_post:123'], ['term', 124, 'edit_term:124'],
            ['user', 125, 'edit_user:125'], ['options', 0, 'manage_options']];
    }

    #[DataProvider('targets')]
    public function testEveryTargetRequiresItsScreenCapability(string $type, int $id, string $cap): void
    {
        $GLOBALS['acf_write_test']['locations'] = [match ($type) {
            'post' => ['param' => 'post', 'value' => $id],
            'term' => ['param' => 'taxonomy', 'value' => 'category'],
            'user' => ['param' => 'user_form', 'value' => 'edit'],
            'options' => ['param' => 'options_page', 'value' => 'theme-options'],
        }];
        $GLOBALS['wpmcp_test_wp']['caps'] = ['read_post:123'];
        foreach ([false, true] as $dry) {
            $error = $this->call(extra: ['object_type' => $type, 'id' => $id, 'dry_run' => $dry]);
            self::assertInstanceOf(\WP_Error::class, $error);
            self::assertSame([], $GLOBALS['acf_write_test']['writes']);
            self::assertSame([], $GLOBALS['acf_write_test']['groups']);
            self::assertSame([], $GLOBALS['acf_write_test']['reads']);
        }
        WordPressRuntime::allowCap($cap);
        $answer = $this->call(extra: ['object_type' => $type, 'id' => $id]);
        self::assertIsArray($answer, $answer instanceof \WP_Error ? $answer->get_error_message() : 'Expected write response');
        self::assertTrue($answer['fields'][0]['saved']);
    }

    public function testOptionsUsePageLocationAndExcludeCustomStore(): void
    {
        $GLOBALS['acf_write_test']['locations'] = [['param' => 'options_page', 'value' => 'theme-options']];
        $answer = $this->call(extra: ['object_type' => 'options']);
        self::assertTrue($answer['fields'][0]['saved']);
        self::assertSame([['options_page' => 'theme-options']], $GLOBALS['acf_write_test']['groups']);
        self::assertSame('options_fr', $GLOBALS['acf_write_test']['writes'][0][2]);
    }

    public function testProviderEntryStillRequiresCapability(): void
    {
        $GLOBALS['wpmcp_test_wp']['caps'] = [];
        self::assertInstanceOf(\WP_Error::class, $this->call());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testToolDeclaresAdminScopeAndDestructiveHints(): void
    {
        $tool = \wpmcp_acf_tools()['set-acf-values'];
        self::assertTrue($tool['write']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true,
            'idempotentHint' => true, 'openWorldHint' => false], $tool['annotations']);
        self::assertLessThanOrEqual(1000, strlen($tool['description']));
        self::assertSame(\wpmcp_acf_object_types(), $tool['inputSchema']['properties']['object_type']['enum']);
    }

    public function testRepeaterComparisonUsesKeysAndStorageScalarTypes(): void
    {
        $field = ['type' => 'repeater', 'sub_fields' => [['key' => 'field_image', 'name' => 'image', 'type' => 'image']]];
        self::assertSame(\wpmcp_acf_write_comparable([['image' => 7]], $field),
            \wpmcp_acf_write_comparable([['field_image' => '7']], $field));
        self::assertNotSame(\wpmcp_acf_write_comparable([['image' => 7]], $field),
            \wpmcp_acf_write_comparable([['field_image' => '8']], $field));
    }

    public function testRepeaterRereadBySubfieldKeyCountsAsSaved(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title'] = ['key' => 'field_title', 'name' => 'title',
            'type' => 'repeater', 'sub_fields' => [['key' => 'field_image', 'name' => 'image', 'type' => 'image']]];
        $GLOBALS['acf_write_test']['stored_answer'] = [['field_image' => '7']];
        $GLOBALS['acf_write_test']['return'] = false;
        self::assertTrue($this->call(['title' => [['image' => 7]]])['fields'][0]['saved']);
    }

    public function testUnknownRepeaterSubfieldRefusesBeforeWriting(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title'] = ['key' => 'field_title', 'name' => 'title',
            'type' => 'repeater', 'sub_fields' => [['key' => 'field_image', 'name' => 'image', 'type' => 'image']]];
        $GLOBALS['acf_write_test']['stored_answer'] = [['field_image' => '7']];
        $error = $this->call(['title' => [['image' => 7, 'typo' => 'lost']]]);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('typo', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testMissingRepeaterSubfieldRefusesBeforeAnyWrite(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title'] = ['key' => 'field_title', 'name' => 'title',
            'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_caption', 'name' => 'caption', 'type' => 'text'],
                ['key' => 'field_url', 'name' => 'url', 'type' => 'url']]];
        $old = [['field_caption' => 'Old', 'field_url' => 'https://old.test/']];
        $GLOBALS['acf_write_test']['values']['123:field_title'] = $old;
        // Reproduce ACF's hybrid reread: omitted URL retains the previous row's value.
        $GLOBALS['acf_write_test']['stored_answer'] = [['field_caption' => 'New', 'field_url' => 'https://old.test/']];
        $error = $this->call(['title' => [['caption' => 'New']]]);
        self::assertInstanceOf(\WP_Error::class, $error,
            'Partial repeater row must refuse; writes=' . json_encode($GLOBALS['acf_write_test']['writes'])
            . '; stored=' . json_encode($GLOBALS['acf_write_test']['values']));
        self::assertStringContainsString('url', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        self::assertSame($old, $GLOBALS['acf_write_test']['values']['123:field_title']);
    }

    public static function locationScreens(): array
    {
        return [
            'taxonomy' => ['term', 124, 'taxonomy', 'category', 'post_tag',
                ['post_id' => 'term_124', 'taxonomy' => 'category']],
            'user form' => ['user', 125, 'user_form', 'edit', 'register',
                ['post_id' => 'user_125', 'user_id' => 125, 'user_form' => 'edit']],
            'user role' => ['user', 125, 'user_role', 'editor', 'subscriber',
                ['post_id' => 'user_125', 'user_id' => 125, 'user_form' => 'edit']],
        ];
    }

    #[DataProvider('locationScreens')]
    public function testLocationScreensSelectOnlyMatchingGroups(string $type, int $id, string $param,
        string $match, string $mismatch, array $screen): void
    {
        $GLOBALS['acf_write_test']['locations'] = [['param' => $param, 'value' => $mismatch]];
        $error = $this->call(extra: ['object_type' => $type, 'id' => $id]);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('Unknown field title', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        $GLOBALS['acf_write_test']['locations'] = [['param' => $param, 'value' => $match]];
        $GLOBALS['acf_write_test']['groups'] = [];
        $preview = $this->call(extra: ['object_type' => $type, 'id' => $id, 'dry_run' => true]);
        self::assertFalse($preview['fields'][0]['saved']);
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        self::assertSame([$screen], $GLOBALS['acf_write_test']['groups']);
        self::assertTrue($this->call(extra: ['object_type' => $type, 'id' => $id])['fields'][0]['saved']);
        self::assertSame([['field_title', 'new', $screen['post_id']]], $GLOBALS['acf_write_test']['writes']);
    }

    public function testLaterPartialRowStopsEarlierValidField(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_rows'] = ['key' => 'field_rows', 'name' => 'rows',
            'type' => 'repeater', 'sub_fields' => [['key' => 'field_caption', 'name' => 'caption',
                'type' => 'text', 'default_value' => 'Default']]];
        $error = $this->call(['title' => 'new', 'rows' => [[]]]);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('caption', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        self::assertTrue($this->call(['title' => 'new', 'rows' => [['caption' => '']]])['fields'][1]['saved']);
        self::assertCount(2, $GLOBALS['acf_write_test']['writes']);
    }

    public static function badRows(): array
    {
        return [
            'missing first child' => [[['url' => 'new']], 'caption'],
            'scalar row' => [['not an object'], 'object'],
            'duplicate name and key' => [[['caption' => 'A', 'field_caption' => 'B', 'url' => '']], 'twice'],
            'non-list rows' => [['row-1' => ['caption' => '', 'url' => '']], 'full array'],
        ];
    }

    #[DataProvider('badRows')]
    public function testMalformedRowsRefuseInWriteAndPreview(array $rows, string $message): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title'] = ['key' => 'field_title', 'name' => 'title',
            'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_caption', 'name' => 'caption', 'type' => 'text'],
                ['key' => 'field_url', 'name' => 'url', 'type' => 'url']]];
        foreach ([false, true] as $dry) {
            $error = $this->call(['title' => $rows], ['dry_run' => $dry]);
            self::assertInstanceOf(\WP_Error::class, $error);
            self::assertStringContainsString($message, $error->get_error_message());
            self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        }
        self::assertTrue($this->call(['title' => [['field_caption' => '', 'field_url' => null]]])['fields'][0]['saved']);
        self::assertCount(1, $GLOBALS['acf_write_test']['writes']);
    }

    public static function nestedRowTypes(): array { return [['group'], ['repeater']]; }

    #[DataProvider('nestedRowTypes')]
    public function testNestedPartialRowRefuses(string $nestedType): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title'] = ['key' => 'field_title', 'name' => 'title',
            'type' => 'repeater', 'sub_fields' => [['key' => 'field_nested', 'name' => 'nested',
                'type' => $nestedType, 'sub_fields' => [['key' => 'field_caption', 'name' => 'caption', 'type' => 'text']]]]];
        $error = $this->call(['title' => [['nested' => $nestedType === 'group' ? [] : [[]]]]]);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('nested', $error->get_error_message());
        self::assertStringContainsString('caption', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        $nested = ['field_caption' => 'Full'];
        self::assertTrue($this->call(['title' => [['field_nested' => $nestedType === 'group' ? $nested : [$nested]]]])['fields'][0]['saved']);
    }

    public static function emptyRepeaters(): array { return [[[]], [false], [null], ['']]; }

    #[DataProvider('emptyRepeaters')]
    public function testRawEmptyRepeaterUndoStillWorks(mixed $value): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title'] = ['key' => 'field_title', 'name' => 'title',
            'type' => 'repeater', 'sub_fields' => [['key' => 'field_caption', 'name' => 'caption', 'type' => 'text']]];
        $GLOBALS['acf_write_test']['stored_answer'] = false;
        self::assertTrue($this->call(['title' => $value])['fields'][0]['saved']);
        self::assertCount(1, $GLOBALS['acf_write_test']['writes']);
    }

    public function testOptionsPageCapabilityIsAdditionalToManageOptions(): void
    {
        $GLOBALS['acf_write_test']['pages'][0]['capability'] = 'update_core';
        foreach ([false, true] as $dry) {
            $error = $this->call(extra: ['object_type' => 'options', 'dry_run' => $dry]);
            self::assertInstanceOf(\WP_Error::class, $error);
            self::assertStringContainsString('theme-options', $error->get_error_message());
            self::assertStringContainsString('update_core', $error->get_error_message());
            self::assertSame([], $GLOBALS['acf_write_test']['writes']);
            self::assertSame([], $GLOBALS['acf_write_test']['reads']);
        }
        WordPressRuntime::allowCap('update_core');
        self::assertTrue($this->call(extra: ['object_type' => 'options'])['fields'][0]['saved']);
    }

    public function testSharedOptionsPageCannotBypassStricterPage(): void
    {
        $GLOBALS['acf_write_test']['pages'][] = ['menu_slug' => 'restricted-shared',
            'post_id' => 'options', 'capability' => 'update_core'];
        $error = $this->call(extra: ['object_type' => 'options']);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString('restricted-shared', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
        self::assertSame([], $GLOBALS['acf_write_test']['reads']);
        WordPressRuntime::allowCap('update_core');
        self::assertTrue($this->call(extra: ['object_type' => 'options'])['fields'][0]['saved']);
    }

    public function testCustomOptionsStoreCapabilityDoesNotGateGenericStore(): void
    {
        $GLOBALS['acf_write_test']['pages'][1]['capability'] = 'update_core';
        self::assertTrue($this->call(extra: ['object_type' => 'options'])['fields'][0]['saved']);
        self::assertSame([['options_page' => 'theme-options']], $GLOBALS['acf_write_test']['groups']);
    }

    public function testMissingOptionsPagesNamesProFeature(): void
    {
        $GLOBALS['acf_write_test']['omit_options_pages'] = true;
        $error = $this->call(extra: ['object_type' => 'options']);
        self::assertStringContainsString('ACF Pro options pages', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testMissingWriteApiNamesExactlyMissingFunction(): void
    {
        $GLOBALS['acf_write_test']['omit_update'] = true;
        $error = $this->call();
        self::assertStringContainsString('update_field()', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['reads']);
    }

    public function testNestedFlexibleContentRefusesBeforeWriting(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title']['type'] = 'repeater';
        $GLOBALS['acf_write_test']['fields']['field_title']['sub_fields'] = [
            ['key' => 'field_nested', 'name' => 'nested', 'type' => 'flexible_content']];
        $error = $this->call(['title' => []]);
        self::assertStringContainsString('nested', $error->get_error_message());
        self::assertStringContainsString('flexible_content', $error->get_error_message());
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testWriteFlagUsesExistingDispatcherScopeGate(): void
    {
        // The existing stubs do not define WP_REST_Response; do not widen them for this tool.
        // Hold the flag and the real core source; integration verifies the HTTP refusal.
        self::assertTrue(\wpmcp_acf_tools()['set-acf-values']['write']);
        $source = file_get_contents(WPMCP_PLUGIN_DIR . '/endpoint.php');
        self::assertStringContainsString("!empty(\$tools[\$name]['write'])", $source);
        self::assertStringContainsString("\$session->scope !== 'admin'", $source);
        self::assertLessThan(strpos($source, "call_user_func(\$tools[\$name]['run']"),
            strpos($source, "\$session->scope !== 'admin'"));
    }

    public function testFalseBooleanHasStorageComparison(): void
    {
        $GLOBALS['acf_write_test']['fields']['field_title']['type'] = 'true_false';
        $GLOBALS['acf_write_test']['stored_answer'] = '0';
        self::assertTrue($this->call(['title' => false])['fields'][0]['saved']);
    }

    public function testDuplicateKeyAndNameAreRejected(): void
    {
        self::assertInstanceOf(\WP_Error::class, $this->call(['title' => 'new', 'field_title' => 'other']));
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public static function invalidArgs(): array
    {
        return [[['object_type' => 'other']], [['id' => '123']], [['dry_run' => 1]], [['fields' => []]]];
    }

    #[DataProvider('invalidArgs')]
    public function testBadArgumentsDoNotWrite(array $extra): void
    {
        self::assertInstanceOf(\WP_Error::class, $this->call(extra: $extra));
        self::assertSame([], $GLOBALS['acf_write_test']['writes']);
    }

    public function testReaderRetainsItsExistingCapabilityOutput(): void
    {
        // The read closure also needs remove_filter(), absent from the shared runtime stubs.
        // Hold the only edited read expression here; existing real-ACF tests cover its values.
        $source = file_get_contents(WPMCP_PLUGIN_DIR . '/modules/acf.php');
        self::assertStringContainsString("array_intersect_key(wpmcp_module_face_capabilities('acf'),", $source);
        self::assertStringContainsString("array('layout_metadata' => true, 'layout_title' => true)", $source);
    }

    public function testWriteSchemaAndDescriptionMeetExistingContract(): void
    {
        $tool = \wpmcp_acf_tools()['set-acf-values'];
        [$published, $removed] = SchemaValidator::publishable($tool['inputSchema']);
        self::assertSame([], $removed);
        self::assertSame($tool['inputSchema'], $published);
        self::assertNull(SchemaValidator::unreadableConstraint($tool['inputSchema']));
        self::assertSame(1, preg_match('/\.(\s|$)/', $tool['description'], $m, PREG_OFFSET_CAPTURE));
        self::assertLessThan(50, $m[0][1]);
        self::assertStringContainsString('Returns', $tool['description']);
    }
}
