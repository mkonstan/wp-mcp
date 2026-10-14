<?php
/** Real ACF round trips. Fixture-only writes; run by the orchestrator, never by the builder.
 * @group acf-data
 */
declare(strict_types=1);
namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class AcfValueWriteTest extends FixtureIntegrationTestCase
{
    private static int $admin = 0;
    private static int $post = 0;
    private static int $fresh = 0;
    private static int $term = 0;
    private static int $user = 0;
    private static string $token = '';
    private static string $readToken = '';
    private static bool $dropped = false;

    private static function prefix(): string { return 'wpmcp_test_' . Fixtures::runId() . '_write_'; }
    private static function key(string $suffix): string { return 'field_' . self::prefix() . $suffix; }
    private static function fieldName(string $suffix): string { return self::prefix() . $suffix; }
    private static function label(): string { return Fixtures::name('acf-write-admin'); }

    /**
     * Why this class cannot run here, or ''. Set once, read per test.
     *
     * WHY THE SKIP IS NOT IN setUpBeforeClass, which is where it started and where it broke CI.
     * A skip raised from setUpBeforeClass aborts the class before PHPUnit emits ANY testcase, so the
     * class is simply absent from the JUnit file - and `bin/ci-merge-junit.php` reports it as
     * "never ran", which is a CI failure rather than a skip. That is exactly what happened on the
     * first push of this class: 1030 testcases, 0 failures, 0 errors, 19 skipped, and a red run.
     * Both legs of the integration tier failed on an absence, not on a defect.
     *
     * Skipping PER TEST instead emits one skipped testcase per method, so a bare host records
     * "this ran and declined" rather than "this is missing". AcfValueReadTest already does exactly
     * this; it is the established pattern here and this class now follows it.
     */
    private static string $skip = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
        $ready = trim(WpCli::evaluate(
            'echo (int) (function_exists("acf_add_local_field_group") && function_exists("acf_add_options_page")'
            . ' && function_exists("acf_get_field_type") && acf_get_field_type("repeater"));'));

        if ($ready !== '1') {
            self::$skip = 'ACF Pro with options pages and repeater is required for acf-data write tests.';

            return;
        }

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skip !== '') {
            self::markTestSkipped(self::$skip);
        }
    }

    private static function build(): void
    {
        self::$admin = Fixtures::createUser(Fixtures::name('acf-write-admin'), 'administrator');
        self::$post = Fixtures::createPost(Fixtures::name('acf-write-post'), 'publish', self::$admin, 'fixture');
        self::$fresh = Fixtures::createPost(Fixtures::name('acf-write-fresh'), 'publish', self::$admin, 'fixture');
        self::$term = Fixtures::createTerm('category', Fixtures::name('acf-write-category'));
        self::$user = Fixtures::createUser(Fixtures::name('acf-write-target'), 'subscriber');
        self::$token = Fixtures::mintToken('admin', self::label(), self::$admin);
        self::$readToken = Fixtures::mintToken('read', Fixtures::name('acf-write-read'), self::$admin);
        $fields = [
            ['key' => self::key('text'), 'name' => self::fieldName('text'), 'label' => 'Text', 'type' => 'text'],
            ['key' => self::key('rows'), 'name' => self::fieldName('rows'), 'label' => 'Rows', 'type' => 'repeater',
                'sub_fields' => [['key' => self::key('caption'), 'name' => 'caption', 'label' => 'Caption', 'type' => 'text']]],
        ];
        // [unknown] Display-group clone with prefixed storage names, source fields on
        // an inapplicable fixture screen. ACF expands clone sub_fields at load time.
        $fields[] = ['key' => self::key('clone'), 'name' => self::fieldName('clone'),
            'label' => 'Clone group', 'type' => 'clone', 'display' => 'group',
            'prefix_name' => 1, 'prefix_label' => 0,
            'clone' => [self::key('clone_caption'), self::key('clone_url')]];
        // [unknown] Ordinary group: the same isset(null) skip as a display-group clone.
        $fields[] = ['key' => self::key('group'), 'name' => self::fieldName('group'),
            'label' => 'Details group', 'type' => 'group', 'sub_fields' => [
                ['key' => self::key('group_caption'), 'name' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                ['key' => self::key('group_url'), 'name' => 'url', 'label' => 'URL', 'type' => 'url']]];
        $slug = Fixtures::name('acf-write-options');
        $source = 'add_action("acf/init", static function () {'
            . 'acf_add_options_page(' . var_export(['page_title' => $slug, 'menu_title' => $slug, 'menu_slug' => $slug,
                'post_id' => 'options', 'capability' => 'manage_options', 'redirect' => false], true) . ');'
            . 'acf_add_local_field_group(' . var_export(['key' => 'group_' . self::prefix() . 'clone_source',
                'title' => Fixtures::name('acf-write-clone-source'), 'show_in_rest' => 0,
                'fields' => [
                    ['key' => self::key('clone_caption'), 'name' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                    ['key' => self::key('clone_url'), 'name' => 'url', 'label' => 'URL', 'type' => 'url']],
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => self::prefix() . 'source']]]], true) . ');'
            . 'acf_add_local_field_group(' . var_export(['key' => 'group_' . self::prefix() . 'post',
                'title' => Fixtures::name('acf-write-group'), 'fields' => $fields, 'show_in_rest' => 0,
                'location' => [[['param' => 'post', 'operator' => '==', 'value' => (string) self::$post]],
                    [['param' => 'post', 'operator' => '==', 'value' => (string) self::$fresh]]]], true) . ');'
            . 'acf_add_local_field_group(' . var_export(['key' => 'group_' . self::prefix() . 'options',
                'title' => $slug, 'show_in_rest' => 0,
                'fields' => [['key' => self::key('date'), 'name' => self::fieldName('date'), 'label' => 'Date',
                    'type' => 'date_picker', 'return_format' => 'Ymd']],
                'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => $slug]]]], true) . ');'
            . 'acf_add_local_field_group(' . var_export(['key' => 'group_' . self::prefix() . 'term',
                'title' => Fixtures::name('acf-write-term-group'), 'show_in_rest' => 0,
                'fields' => [['key' => self::key('term_text'), 'name' => self::fieldName('term_text'),
                    'label' => 'Term text', 'type' => 'text']],
                'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]]], true) . ');'
            . 'acf_add_local_field_group(' . var_export(['key' => 'group_' . self::prefix() . 'user',
                'title' => Fixtures::name('acf-write-user-group'), 'show_in_rest' => 0,
                'fields' => [['key' => self::key('user_text'), 'name' => self::fieldName('user_text'),
                    'label' => 'User text', 'type' => 'text']],
                'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'edit']]]], true) . ');'
            . '});';
        MuPlugin::drop('acf-write-group', $source);
        self::$dropped = true;
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();
        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        if (self::$admin === 0) { return; }
        // This field name contains the run id. No pre-existing options value is touched.
        if (self::$dropped) {
            WpCli::tryEvaluate('delete_field(' . var_export(self::key('date'), true) . ', "options"); echo "ok";');
            MuPlugin::remove('acf-write-group');
            self::$dropped = false;
        }
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteTokensLabelled(Fixtures::name('acf-write-read'));
        Fixtures::deleteTerm('category', self::$term);
        Fixtures::deleteUser(self::$user);
        Fixtures::deletePost(self::$fresh);
        Fixtures::deletePost(self::$post);
        Fixtures::deleteUser(self::$admin);
        self::$term = self::$user = self::$fresh = self::$post = self::$admin = 0;
    }

    private function write(string $suffix, mixed $value, bool $dry = false, ?int $post = null): array
    {
        $type = match ($suffix) { 'date' => 'options', 'term_text' => 'term', 'user_text' => 'user', default => 'post' };
        $args = ['object_type' => $type,
            'fields' => [self::fieldName($suffix) => $value], 'dry_run' => $dry];
        if ($type !== 'options') {
            $args['id'] = match ($type) { 'term' => self::$term, 'user' => self::$user, default => $post ?? self::$post };
        }
        $result = $this->mcp(self::$token)->callTool('set-acf-values', $args);
        self::assertFalse($result->isError, $result->text);
        return $result->data()['fields'][0];
    }

    private function read(string $suffix, ?int $post = null): mixed
    {
        $type = match ($suffix) { 'date' => 'options', 'term_text' => 'term', 'user_text' => 'user', default => 'post' };
        $args = ['object_type' => $type, 'fields' => [self::fieldName($suffix)]];
        if ($type !== 'options') {
            $args['id'] = match ($type) { 'term' => self::$term, 'user' => self::$user, default => $post ?? self::$post };
        }
        $result = $this->mcp(self::$token)->callTool('get-acf-values', $args);
        self::assertFalse($result->isError, $result->text);
        self::assertCount(1, $result->data()['fields']);
        return $result->data()['fields'][0]['value'];
    }

    public function testI1SimpleTextRoundTrip(): void
    {
        self::assertTrue($this->write('text', 'First text')['saved']);
        self::assertSame('First text', $this->read('text'));
        $second = $this->write('text', 'Second text');
        self::assertSame('First text', $second['old_value']);
        self::assertTrue($second['saved']);
        self::assertSame('Second text', $this->read('text'));
    }

    public function testI2FullRepeaterRoundTrip(): void
    {
        $rows = [['caption' => 'One'], ['caption' => 'Two']];
        self::assertTrue($this->write('rows', $rows)['saved']);
        self::assertSame($rows, $this->read('rows'));
        // RAW old rows are keyed by ACF field key and can be written back, losslessly.
        $changed = $this->write('rows', [['caption' => 'Replacement']]);
        self::assertTrue($changed['saved']);
        self::assertTrue($this->write('rows', $changed['old_value'])['saved']);
        self::assertSame($rows, $this->read('rows'));
    }

    public function testI3DateOnGenericOptionsStore(): void
    {
        self::assertTrue($this->write('date', '20261012')['saved']);
        self::assertSame('20261012', $this->read('date'));
    }

    public function testI4NeverSavedTargetResolvesName(): void
    {
        $hasMeta = trim(WpCli::evaluate('echo (int) metadata_exists("post", ' . self::$fresh . ', '
            . var_export('_' . self::fieldName('text'), true) . ');'));
        self::assertSame('0', $hasMeta, 'Fresh fixture unexpectedly has an ACF reference.');
        self::assertTrue($this->write('text', 'First ever', post: self::$fresh)['saved']);
        self::assertSame('First ever', $this->read('text', self::$fresh));
        // Positive control proves metadata_exists addresses this fixture and reference key.
        self::assertSame('1', trim(WpCli::evaluate('echo (int) metadata_exists("post", ' . self::$fresh . ', '
            . var_export('_' . self::fieldName('text'), true) . ');')));
    }

    public function testI5DryRunLeavesStoredValueUntouched(): void
    {
        self::assertTrue($this->write('text', 'Keep this')['saved']);
        $preview = $this->write('text', 'Do not save', true);
        self::assertFalse($preview['saved']);
        self::assertSame('Keep this', $preview['old_value']);
        self::assertSame('Do not save', $preview['new_value']);
        self::assertSame('Keep this', $this->read('text'));
    }

    /** [unknown] Real ACF taxonomy screen regression; written for the orchestrator. */
    public function testI6CategoryTermRoundTrip(): void
    {
        self::assertTrue($this->write('term_text', 'First category value')['saved']);
        self::assertSame('First category value', $this->read('term_text'));
        $changed = $this->write('term_text', 'Second category value');
        self::assertSame('First category value', $changed['old_value']);
        self::assertTrue($changed['saved']);
        self::assertSame('Second category value', $this->read('term_text'));
    }

    /** [unknown] Real ACF user_form==edit regression; written for the orchestrator. */
    public function testI7UserEditFormRoundTrip(): void
    {
        self::assertTrue($this->write('user_text', 'First user value')['saved']);
        self::assertSame('First user value', $this->read('user_text'));
        $changed = $this->write('user_text', 'Second user value');
        self::assertSame('First user value', $changed['old_value']);
        self::assertTrue($changed['saved']);
        self::assertSame('Second user value', $this->read('user_text'));
    }

    /** [unknown] Written only: real clone must refuse both omissions and null before any write. */
    public function testI8CloneGroupCompleteWriteAndPartialRefusals(): void
    {
        self::assertTrue($this->write('text', 'Keep sibling')['saved']);
        self::assertTrue($this->write('clone', ['caption' => 'Keep caption', 'url' => 'https://example.test/'])['saved']);
        $rawCode = 'echo json_encode(get_field(' . var_export(self::key('clone'), true)
            . ', ' . self::$post . ', false));';
        $before = WpCli::evaluate($rawCode);
        foreach ([false, true] as $dry) {
            foreach ([['caption' => 'Changed'], ['caption' => 'Changed', 'url' => null]] as $value) {
                $result = $this->mcp(self::$token)->callTool('set-acf-values',
                    ['object_type' => 'post', 'id' => self::$post, 'dry_run' => $dry,
                        'fields' => [self::fieldName('text') => 'Changed sibling', self::fieldName('clone') => $value]]);
                self::assertTrue($result->isError);
                self::assertStringContainsString('url', $result->text);
                if (array_key_exists('url', $value)) {
                    self::assertStringContainsString('ACF cannot clear', $result->text);
                }
                self::assertSame('Keep sibling', $this->read('text'));
                self::assertSame($before, WpCli::evaluate($rawCode));
            }
        }
        // Empty text is a supplied value to clone's isset check; raw-key undo also works.
        $changed = $this->write('clone', ['caption' => 'Changed', 'url' => '']);
        self::assertTrue($changed['saved']);
        self::assertTrue($this->write('clone', $changed['old_value'])['saved']);
        self::assertSame($before, WpCli::evaluate($rawCode));
    }

    /** [unknown] Written only: real group must refuse both omissions and null before any write. */
    public function testI9GroupNullRefusesBeforeAnyWrite(): void
    {
        self::assertTrue($this->write('text', 'Keep sibling')['saved']);
        self::assertTrue($this->write('group', ['caption' => 'Keep caption', 'url' => 'https://example.test/'])['saved']);
        $rawCode = 'echo json_encode(get_field(' . var_export(self::key('group'), true)
            . ', ' . self::$post . ', false));';
        $before = WpCli::evaluate($rawCode);
        foreach ([false, true] as $dry) {
            foreach ([['caption' => 'Changed'], ['caption' => 'Changed', 'url' => null]] as $value) {
                $result = $this->mcp(self::$token)->callTool('set-acf-values',
                    ['object_type' => 'post', 'id' => self::$post, 'dry_run' => $dry,
                        'fields' => [self::fieldName('text') => 'Changed sibling', self::fieldName('group') => $value]]);
                self::assertTrue($result->isError);
                self::assertStringContainsString('url', $result->text);
                if (array_key_exists('url', $value)) {
                    self::assertStringContainsString('ACF cannot clear', $result->text);
                }
                self::assertSame('Keep sibling', $this->read('text'));
                self::assertSame($before, WpCli::evaluate($rawCode));
            }
        }
        // Empty text is a supplied value to group's isset check; raw-key undo also works.
        $changed = $this->write('group', ['caption' => 'Changed', 'url' => '']);
        self::assertTrue($changed['saved']);
        self::assertTrue($this->write('group', $changed['old_value'])['saved']);
        self::assertSame($before, WpCli::evaluate($rawCode));
    }

    public function testReadTokenCannotWriteOrDryRun(): void
    {
        foreach ([false, true] as $dry) {
            $result = $this->mcp(self::$readToken)->callTool('set-acf-values',
                ['object_type' => 'post', 'id' => self::$post, 'fields' => [self::fieldName('text') => 'Forbidden'], 'dry_run' => $dry]);
            self::assertTrue($result->isError);
            self::assertStringContainsString('admin-scope token', $result->text);
        }
    }
}
