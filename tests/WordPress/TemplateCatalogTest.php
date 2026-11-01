<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\DocumentStats;
use SWForms\Core\Form\SchemaValidator;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Frontend\FormView;
use SWForms\WordPress\Frontend\TemplatePreview;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Forms\Templates;

/**
 * Quality gate for the starter template catalog (docs/rest-api.md): metadata contracts, schema
 * validity, condition references, engine limits, the preview route and the REST shape.
 */
final class TemplateCatalogTest extends TestCase
{
    /** Ids are stable identifiers of the catalog; renaming or dropping one is a breaking change. */
    private const EXPECTED_IDS = [
        'contact',
        'callback',
        'feedback',
        'newsletter',
        'appointment',
        'registration',
        'workshop',
        'volunteer',
        'camp',
        'job-application',
        'quote-request',
        'support-ticket',
        'lead',
        'survey',
        'club-membership',
        'donation',
    ];

    /** Extension allowlist of the file type (docs/schema.md "File uploads"). */
    private const ALLOWED_EXTENSIONS = ['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip'];

    private int $previousUser;

    protected function setUp(): void
    {
        $this->previousUser = get_current_user_id();
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        wp_set_current_user((int) $admins[0]);
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->previousUser);
    }

    public function testCatalogIdsStayStableAndCategoriesAreComplete(): void
    {
        self::assertSame(self::EXPECTED_IDS, Templates::ids());
        self::assertSame(
            [Templates::CATEGORY_GENERAL, Templates::CATEGORY_EVENTS, Templates::CATEGORY_BUSINESS, Templates::CATEGORY_COMMUNITY],
            array_column(Templates::categories(), 'slug')
        );
        self::assertSame(16, array_sum(array_column(Templates::categories(), 'count')));
        self::assertCount(16, Templates::ids());
    }

    public function testMetadataFollowsTheDocumentedContract(): void
    {
        $known = array_column(Templates::categories(), 'slug');
        foreach (Templates::all() as $item) {
            $id = (string) $item['id'];
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{0,63}$/', $id);
            self::assertNotSame('', $item['title'], $id);
            self::assertNotSame('', $item['description'], $id);
            self::assertStringStartsWith('r_', $item['icon'], $id);
            self::assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $item['version'], $id);
            self::assertContains($item['category'], $known, $id);
            self::assertNotCount(0, $item['tags'], $id);
            self::assertSame(count($item['tags']), count(array_unique($item['tags'])), $id);

            $stats = DocumentStats::measure($item['document'], FieldRegistry::instance());
            self::assertSame($stats['fields'], $item['fields'], $id);
            self::assertSame($stats['steps'], $item['steps'], $id);
            self::assertSame($stats['conditions'], $item['conditions'], $id);
            self::assertSame($item['steps'] > 1, $item['isMultiStep'], $id);
            self::assertGreaterThan(3, $item['fields'], $id);
        }

        // The gallery list stays small: documents only come through the detail route.
        foreach (Templates::summaries() as $summary) {
            self::assertArrayNotHasKey('document', $summary);
        }
    }

    /**
     * The catalog is written with English source strings, so the German names come from the shipped
     * `languages/sw-forms-de_DE.mo`. They are part of the product, therefore they are pinned here.
     */
    public function testTheShippedGermanTranslationNamesEveryTemplate(): void
    {
        $path = dirname(__DIR__, 2) . '/languages/sw-forms-de_DE.mo';
        if (!file_exists($path)) {
            self::markTestSkipped('The compiled German catalog is missing; run bin/i18n.sh compile.');
        }

        // Loaded under its own domain: the assertion is about the catalog file, not the test locale.
        if (!load_textdomain('swf-catalog-de', $path)) {
            self::markTestSkipped('The compiled German catalog cannot be loaded.');
        }

        try {
            $t = static fn (string $source): string => __($source, 'swf-catalog-de');

            self::assertSame(
                ['Allgemein', 'Veranstaltungen', 'Unternehmen', 'Gemeinschaft und Vereine'],
                array_map(static fn (array $category): string => $t($category['title']), Templates::categories())
            );

            $german = array_combine(self::EXPECTED_IDS, [
                'Kontaktformular',
                'Rückruf anfordern',
                'Feedback und Bewertung',
                'Newsletter-Anmeldung',
                'Terminanfrage',
                'Veranstaltungsanmeldung',
                'Workshop-Buchung',
                'Helferanmeldung',
                'Freizeit- und Rüstzeitenanmeldung',
                'Bewerbung',
                'Angebot anfragen',
                'Support-Ticket',
                'Lead-Qualifizierung',
                'Umfrage',
                'Vereinsmitgliedschaft',
                'Spendenzusage',
            ]);

            foreach ($german as $id => $name) {
                $item = Templates::get($id);
                self::assertSame($name, $t($item['title']), $id);
                $description = $t($item['description']);
                self::assertNotSame('', $description, $id);
                self::assertNotSame($item['description'], $description, $id);
            }
        } finally {
            unload_textdomain('swf-catalog-de');
        }
    }

    public function testEveryDocumentPassesNormalizationAndSchemaValidation(): void
    {
        $service = FormService::create_default();
        foreach (Templates::all() as $item) {
            $id = (string) $item['id'];
            $normalized = $service->normalize($item['document']);
            self::assertSame([], $service->validate($normalized), $id);
            // Duplicate ids/keys, unknown condition references and logic cycles are core errors.
            self::assertSame([], (new SchemaValidator(FieldRegistry::instance()))->validate($normalized), $id);
            self::assertCount(count($normalized['fields']), $item['document']['fields'], $id);
        }
    }

    public function testElementSettingsOnlyUseNamesTheEngineKnows(): void
    {
        foreach (Templates::all() as $item) {
            $ids = $this->documentIds($item['document']['fields']);
            $this->checkSettings($item['document']['fields'], (string) $item['id'], $ids);
        }
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param list<string> $allIds every element id of the template, used by condition rules
     */
    private function checkSettings(array $elements, string $template, array $allIds): void
    {
        foreach ($elements as $element) {
            $type = FieldRegistry::instance()->get((string) $element['type']);
            self::assertNotNull($type, $template . ': unregistered type ' . $element['type']);
            $known = array_column($type->settings_schema(), 'name');
            foreach (array_keys((array) ($element['settings'] ?? [])) as $name) {
                // An unknown setting is dropped silently, so a typo must fail the build.
                self::assertContains((string) $name, $known, $template . ' / ' . $element['id'] . ': unknown setting ' . $name);
            }
            foreach ((array) ($element['logic']['rules'] ?? []) as $rule) {
                self::assertContains((string) $rule['op'], Evaluator::OPERATORS, $template . ' / ' . $element['id']);
                self::assertContains((string) $rule['field'], $allIds, $template . ' / ' . $element['id'] . ' references ' . $rule['field']);
            }
            $this->checkSettings((array) ($element['children'] ?? []), $template, $allIds);
            foreach ((array) ($element['columns'] ?? []) as $column) {
                $this->checkSettings((array) ($column['children'] ?? []), $template, $allIds);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @return list<string>
     */
    private function documentIds(array $elements): array
    {
        $ids = [];
        $walk = static function (array $list) use (&$walk, &$ids): void {
            foreach ($list as $element) {
                if (! is_array($element)) {
                    continue;
                }
                $ids[] = (string) ($element['id'] ?? '');
                $walk((array) ($element['children'] ?? []));
                foreach ((array) ($element['columns'] ?? []) as $column) {
                    $ids[] = (string) ($column['id'] ?? '');
                    $walk((array) ($column['children'] ?? []));
                }
            }
        };
        $walk($elements);
        return $ids;
    }

    public function testUploadsStayInsideTheEngineLimitsAndTheCatalogUsesItsFeatures(): void
    {
        $types = [];
        foreach (Templates::all() as $item) {
            $this->collectTypes($item['document']['fields'], $types);
        }
        foreach (array_keys($types) as $type) {
            self::assertNotNull(FieldRegistry::instance()->get($type), 'unregistered type ' . $type);
        }
        // The catalog also demonstrates what the engine can do.
        foreach (['input', 'textarea', 'select', 'radio', 'checkbox', 'rating', 'scale', 'file', 'birthdate', 'html', 'page-break', 'columns', 'group'] as $type) {
            self::assertArrayHasKey($type, $types, 'catalog never uses ' . $type);
        }
        self::assertGreaterThan(10, $types['page-break'] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param array<string, int> $usedTypes
     */
    private function collectTypes(array $elements, array &$usedTypes): void
    {
        foreach ($elements as $element) {
            $type = (string) $element['type'];
            $usedTypes[$type] = ($usedTypes[$type] ?? 0) + 1;
            if ($type === 'file' || $type === 'image') {
                foreach (array_filter(explode(',', (string) ($element['settings']['extensions'] ?? ''))) as $extension) {
                    self::assertContains(trim($extension), self::ALLOWED_EXTENSIONS, 'element ' . $element['id']);
                }
                $maxSize = (int) ($element['settings']['maxSize'] ?? 10);
                self::assertGreaterThanOrEqual(1, $maxSize);
                self::assertLessThanOrEqual(100, $maxSize);
            }
            $this->collectTypes((array) ($element['children'] ?? []), $usedTypes);
            foreach ((array) ($element['columns'] ?? []) as $column) {
                $this->collectTypes((array) ($column['children'] ?? []), $usedTypes);
            }
        }
    }

    public function testMultiStepTemplatesMatchTheirPageBreaks(): void
    {
        foreach (Templates::all() as $item) {
            $id = (string) $item['id'];
            $fields = $item['document']['fields'];
            $breaks = array_values(array_filter($fields, static fn (array $element): bool => $element['type'] === 'page-break'));
            if (! $item['isMultiStep']) {
                self::assertSame([], $breaks, $id);
                continue;
            }
            self::assertCount($item['steps'] - 1, $breaks, $id . ': step count vs breaks');
            self::assertTrue($item['document']['settings']['steps']['enabled'], $id);
            self::assertNotSame('', $item['document']['settings']['steps']['firstLabel'], $id);
            self::assertNotSame('page-break', $fields[0]['type'], $id);
            self::assertNotSame('page-break', $fields[count($fields) - 1]['type'], $id);
        }
    }

    public function testConditionalRequirementsUseTheSupportedPatterns(): void
    {
        $hiddenUntilNeeded = 0;
        $requiredOnRequest = 0;
        $operators = [];
        foreach ($this->allElements(Templates::all()) as [$element, $id]) {
            $logic = (array) ($element['logic'] ?? []);
            $action = (string) ($logic['action'] ?? '');
            foreach ((array) ($logic['rules'] ?? []) as $rule) {
                $operators[(string) $rule['op']] = true;
            }
            if ($action === 'show' && ! empty($element['required'])) {
                ++$hiddenUntilNeeded;
            }
            if ($action === 'require') {
                // `require` keeps the field visible; it must therefore never be combined with
                // hiding, because one element carries exactly one logic action.
                self::assertSame('', (string) ($element['required'] ?? ''), $id . ' / ' . $element['id']);
                ++$requiredOnRequest;
            }
        }
        self::assertGreaterThan(6, $hiddenUntilNeeded, 'hide-until-needed required fields are a catalog feature');
        self::assertGreaterThan(0, $requiredOnRequest, 'at least one template demonstrates the require action');
        // Documented operators the catalog actually exercises (docs/schema.md "Logic").
        foreach (['is', 'contains', 'notempty', 'gt', 'lt'] as $operator) {
            self::assertArrayHasKey($operator, $operators, 'catalog never uses ' . $operator);
        }
    }

    public function testEveryTemplateHasRequiredFieldsAndAPrivacyOrConfirmNote(): void
    {
        foreach (Templates::all() as $item) {
            $id = (string) $item['id'];
            $elements = $this->allElements([$item]);
            $required = array_filter($elements, static fn (array $pair): bool => ! empty($pair[0]['required']));
            self::assertNotCount(0, $required, $id . ': no required field');
            $notes = array_filter(
                $elements,
                static fn (array $pair): bool => $pair[0]['type'] === 'html' && ! empty($pair[0]['settings']['content'])
            );
            self::assertNotCount(0, $notes, $id . ': no information text');
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{0: array<string, mixed>, 1: string}>
     */
    private function allElements(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $walk = static function (array $elements) use (&$walk, &$out, $item): void {
                foreach ($elements as $element) {
                    $out[] = [$element, (string) $item['id']];
                    $walk((array) ($element['children'] ?? []));
                    foreach ((array) ($element['columns'] ?? []) as $column) {
                        $walk((array) ($column['children'] ?? []));
                    }
                }
            };
            $walk((array) $item['document']['fields']);
        }
        return $out;
    }

    public function testInformationalTextsSurviveTheHtmlAllowlist(): void
    {
        $service = FormService::create_default();
        $seen = 0;
        foreach ($this->allElements(Templates::all()) as [$element, $id]) {
            if ((string) $element['type'] !== 'html') {
                continue;
            }
            ++$seen;
            $content = (string) ($element['settings']['content'] ?? '');
            self::assertNotSame('', $content, $id . ': empty note');
            $normalized = $service->normalize(['fields' => [$element], 'settings' => ['notifications' => []]]);
            self::assertSame($content, $normalized['fields'][0]['settings']['content'], $id . ' / ' . $element['id']);
        }
        self::assertGreaterThan(10, $seen);
    }

    public function testPreviewRouteBuildsADemoFormWithoutAFormId(): void
    {
        $url = TemplatePreview::url('contact');
        self::assertStringContainsString('swf_template_preview=contact', $url);
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(1, wp_verify_nonce($query['_wpnonce'], TemplatePreview::NONCE_ACTION));

        unset($_GET['swf_template_preview']);
        (new TemplatePreview())->handle();
        self::assertTrue(true, 'the handler must stay inactive without its query parameter');

        $document = Templates::document('support-ticket');
        self::assertIsArray($document);
        $html = FormView::render_demo(7, FormService::create_default()->normalize($document), 'Support ticket');
        self::assertStringContainsString('name="swf[email]"', $html);
        self::assertStringContainsString('"demo":true', $html);
        self::assertStringNotContainsString('swf_form_id', $html);
        self::assertStringNotContainsString('swf_token', $html);
    }

    public function testPreviewRouteRejectsUnknownTemplatesAndVisitors(): void
    {
        $_GET['swf_template_preview'] = 'does-not-exist';
        $_GET['_wpnonce'] = wp_create_nonce(TemplatePreview::NONCE_ACTION);
        self::assertSame('404', $this->dieCode(static fn () => (new TemplatePreview())->handle()));

        wp_set_current_user(0);
        $_GET['_wpnonce'] = 'x';
        self::assertSame('403', $this->dieCode(static fn () => (new TemplatePreview())->handle()));

        unset($_GET['swf_template_preview'], $_GET['_wpnonce']);
    }

    /**
     * Runs a callable that must end in wp_die and returns its response code.
     *
     * @param callable $callback
     */
    private function dieCode(callable $callback): string
    {
        add_filter(
            'wp_die_handler',
            static fn () => static function (string $message, string $title, array $args): void {
                throw new \RuntimeException((string) ($args['response'] ?? 0));
            }
        );
        try {
            $callback();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        } finally {
            remove_all_filters('wp_die_handler');
        }
        self::fail('Expected wp_die');
    }

    public function testGalleryRoutesListMetadataAndDetailReturnsTheDocument(): void
    {
        $list = rest_do_request(new \WP_REST_Request('GET', '/swforms/v1/form-templates'))->get_data();
        self::assertSame(['categories', 'items'], array_keys($list));
        self::assertCount(16, $list['items']);
        self::assertArrayNotHasKey('document', $list['items'][0]);
        self::assertSame(['slug', 'title', 'icon', 'count'], array_keys($list['categories'][0]));

        $detail = rest_do_request(new \WP_REST_Request('GET', '/swforms/v1/form-templates/club-membership'))->get_data();
        self::assertSame('club-membership', $detail['id']);
        self::assertStringContainsString('swf_template_preview=club-membership', $detail['previewUrl']);
        self::assertContains('page-break', array_column($detail['document']['fields'], 'type'));

        self::assertSame(404, rest_do_request(new \WP_REST_Request('GET', '/swforms/v1/form-templates/nope'))->get_status());
    }

    public function testCreatingFromATemplateYieldsAnIndependentForm(): void
    {
        $repository = new FormRepository();
        $created = [];
        foreach ([0, 1] as $_) {
            $request = new \WP_REST_Request('POST', '/swforms/v1/forms/import');
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(
                (string) wp_json_encode(
                    [
                        'format' => 'swforms',
                        'formatVersion' => 1,
                        'title' => 'From template',
                        'document' => Templates::document('contact'),
                    ]
                )
            );
            $response = rest_do_request($request);
            self::assertSame(201, $response->get_status());
            $created[] = (int) $response->get_data()['id'];
        }
        self::assertNotSame($created[0], $created[1]);

        foreach ($created as $id) {
            self::assertSame('inactive', $repository->find($id)['status']);
        }
        // Later template versions never touch stored forms: the copy carries no template reference.
        $stored = $repository->published($created[0]);
        self::assertIsArray($stored);
        $json = strtolower((string) wp_json_encode($stored));
        self::assertStringContainsString('salutation', $json);
        self::assertStringNotContainsString('template', $json);
        self::assertSame((string) wp_json_encode($repository->published($created[1])), (string) wp_json_encode($stored));

        foreach ($created as $id) {
            $repository->delete($id);
        }
    }

    public function testAddonsCanExtendTheCatalogThroughTheFilters(): void
    {
        $add = 'acme-intake';
        $remove = 'survey';
        $templates = static function (array $items) use ($add, $remove): array {
            unset($items[$remove]);
            $items[$add] = [
                'id' => $add,
                'title' => 'Acme intake',
                'category' => 'acme',
                'document' => ['fields' => [['id' => 'note', 'type' => 'input', 'key' => 'note', 'label' => 'Note']]],
            ];
            $items['broken'] = ['title' => '', 'document' => ['fields' => []]];
            return $items;
        };
        $categories = static fn (array $categories): array => $categories + ['acme' => ['slug' => 'acme', 'title' => 'Acme', 'icon' => 'r_extension', 'count' => 1]];

        add_filter('swf_form_templates', $templates);
        add_filter('swf_form_template_categories', $categories);
        try {
            $ids = Templates::ids();
            self::assertContains($add, $ids);
            self::assertNotContains($remove, $ids);
            self::assertNotContains('', $ids, 'an incomplete item must not reach the gallery');

            $item = Templates::get($add);
            self::assertSame('acme', $item['category']);
            self::assertSame(1, $item['fields']);
            self::assertSame(1, $item['steps']);
            self::assertFalse($item['isMultiStep']);
            self::assertSame('r_description', $item['icon']);
            self::assertSame('1.0.0', $item['version']);
            self::assertContains('acme', array_column(Templates::categories(), 'slug'));
            self::assertIsArray(Templates::document($add));
            self::assertTrue(Templates::exists($add));
            self::assertFalse(Templates::exists($remove));
        } finally {
            remove_filter('swf_form_templates', $templates);
            remove_filter('swf_form_template_categories', $categories);
        }
    }
}
