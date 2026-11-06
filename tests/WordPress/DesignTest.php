<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Design\Design;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Forms\FormSettings;
use SWForms\WordPress\Frontend\DesignGallery;
use SWForms\WordPress\Frontend\FormView;
use SWForms\WordPress\Frontend\Shortcode;

final class DesignTest extends TestCase
{
    private int $user;
    /** @var list<int> */
    private array $forms = [];

    protected function setUp(): void
    {
        $this->user = get_current_user_id();
        wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->forms as $id) {
            (new FormRepository())->delete($id);
        }
        remove_all_filters('swf_form_design');
        wp_set_current_user($this->user);
    }

    private function form(array $design, array $titleField = []): int
    {
        $service = FormService::create_default();
        $id = $service->create('Design test');
        $this->forms[] = $id;
        $input = static fn (string $id, bool $required, array $settings = []): array => ['id' => $id, 'type' => 'input', 'key' => $id, 'label' => ucfirst($id), 'required' => $required, 'settings' => $settings];
        $document = [
            'fields' => [
                ['id' => 'salutation', 'type' => 'select', 'key' => 'salutation', 'label' => 'Anrede', 'settings' => ['options' => [['value' => 'ms', 'label' => 'Frau']]]],
                $input('first', true, ['placeholder' => 'Erika']),
                $input('title', false, $titleField),
                ['id' => 'note', 'type' => 'textarea', 'key' => 'note', 'label' => 'Note'],
                ['id' => 'topics', 'type' => 'checkbox', 'key' => 'topics', 'label' => 'Topics', 'settings' => ['options' => [['value' => 'a', 'label' => 'A']]]],
                ['id' => 'multi', 'type' => 'select', 'key' => 'multi', 'label' => 'Multi', 'settings' => ['multiple' => true, 'options' => [['value' => 'a', 'label' => 'A']]]],
                $input('phone', false, ['inputType' => 'tel', 'countryCode' => true]),
                $input('secret', false, ['inputType' => 'hidden', 'default' => 'x']),
            ],
            'settings' => ['notifications' => [], 'design' => $design],
        ];
        self::assertSame([], $service->save($id, 'Design test', $document)['errors']);
        return $id;
    }

    /** @return array<string, string> class attribute by data-swf-id */
    private function fieldClasses(string $html): array
    {
        $classes = [];
        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_tag()) {
            $id = $processor->get_attribute('data-swf-id');
            if (is_string($id)) {
                $classes[$id] = (string) $processor->get_attribute('class');
            }
        }
        return $classes;
    }

    public function testFormSettingsStoreANormalisedDesign(): void
    {
        self::assertSame(['variant' => 'classic', 'scheme' => 'light', 'tokens' => []], FormSettings::normalize([])['design']);
        $id = $this->form(['variant' => 'guided-card', 'scheme' => 'dark', 'tokens' => ['accent' => '#0F766E', 'text' => 'javascript:x']]);
        self::assertSame(
            ['variant' => 'guided-card', 'scheme' => 'dark', 'tokens' => ['accent' => '#0f766e']],
            (new FormRepository())->published($id)['settings']['design']
        );
    }

    public function testClassicMarkupIsUnchangedByTheDesignSystem(): void
    {
        $html = FormView::render($this->form([]));
        self::assertStringContainsString('class="swf swf-form-wrap swf--classic swf-scheme--light"', $html);
        self::assertStringContainsString('data-swf-variant="classic"', $html);
        self::assertStringNotContainsString('swf-field--floatable', $html);
        self::assertStringNotContainsString('placeholder=" "', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testFloatingVariantsMarkSingleControlsAndPrefilledValues(): void
    {
        $html = FormView::render($this->form(['variant' => 'animated-float', 'tokens' => ['accent' => '#fde047', 'radius' => 12]], ['default' => 'Dr.']));
        $classes = $this->fieldClasses($html);
        foreach (['salutation', 'first', 'title', 'note'] as $floating) {
            self::assertStringContainsString('swf-field--floatable', $classes[$floating], $floating);
        }
        // Several controls, choice lists, multi selects and hidden inputs keep their label above.
        foreach (['topics', 'multi', 'phone', 'secret'] as $stacked) {
            self::assertStringNotContainsString('swf-field--floatable', $classes[$stacked], $stacked);
        }
        self::assertStringContainsString('swf-field--filled', $classes['title']);
        self::assertStringNotContainsString('swf-field--filled', $classes['first']);
        // Blank placeholder only where none was configured; data attributes stay untouched.
        self::assertMatchesRegularExpression('/<input placeholder=" "[^>]+name="swf\[title\]"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]+placeholder="Erika"/', $html);
        self::assertMatchesRegularExpression('/<textarea[^>]+placeholder=" "/', $html);
        self::assertStringContainsString('aria-describedby=', $html);
        self::assertStringContainsString('style="--swf-accent:#fde047;--swf-button-bg:#fde047;--swf-accent-contrast:#111827;--swf-radius:12px"', $html);
        self::assertStringContainsString('name="swf[first]"', $html);
    }

    public function testEmbedVariantAndFilterOverrideTheStoredDesign(): void
    {
        $id = $this->form(['variant' => 'minimal']);
        $shortcode = (new Shortcode())->render(['id' => (string) $id, 'variant' => 'Surprise-Motion']);
        self::assertStringContainsString('data-swf-variant="surprise-motion"', $shortcode);
        self::assertStringContainsString('data-swf-variant="minimal"', (new Shortcode())->render(['id' => (string) $id, 'variant' => 'nope']));
        self::assertStringContainsString('data-swf-variant="floating"', swf_render_form($id, false, 'floating'));

        add_filter('swf_form_design', static fn (array $design, int $form_id): array => $form_id === $id ? ['variant' => 'dark-focus', 'scheme' => 'bogus', 'tokens' => ['surface' => '#000']] + $design : $design, 10, 2);
        $html = FormView::render($id, ['variant' => 'floating']);
        self::assertStringContainsString('swf--dark-focus swf-scheme--light swf--has-surface', $html);
        self::assertSame('dark-focus', FormView::design($id, [])['variant']);
    }

    public function testDemoFormsCannotReachPostbackOrRest(): void
    {
        $html = FormView::render_demo(3, (new \SWForms\WordPress\Forms\DocumentNormalizer(\SWForms\WordPress\Fields\FieldRegistry::instance()))->normalize(DesignGallery::document('animated-float')), 'Personal details');
        self::assertStringNotContainsString('swf_form_id', $html);
        self::assertStringNotContainsString('swf_token', $html);
        self::assertStringNotContainsString(' action=', $html);
        self::assertStringContainsString('<h3 class="swf-title">Personal details</h3>', $html);
        preg_match('/<script type="application\/json" class="swf-config">(.+?)<\/script>/', $html, $match);
        $config = json_decode($match[1], true);
        self::assertTrue($config['demo']);
        self::assertTrue($config['preview']);
        self::assertSame('', $config['rest']);
        self::assertNotSame('', $config['i18n']['required']);
    }

    public function testGalleryShowsEveryVariantWithTheSameFields(): void
    {
        self::assertSame(Design::VARIANTS, array_keys(DesignGallery::variants()));
        $html = DesignGallery::markup();
        foreach (Design::VARIANTS as $variant) {
            self::assertStringContainsString('data-swf-variant="' . $variant . '"', $html);
            self::assertStringContainsString('data-swfg-variant="' . $variant . '"', $html);
        }
        self::assertSame(7, substr_count($html, 'name="swf[first_name]"'));
        self::assertSame(7, substr_count($html, 'name="swf[salutation]"'));
        self::assertStringNotContainsString('swf_form_id', $html);
        $url = DesignGallery::url();
        self::assertStringContainsString('swf_design_gallery=1', $url);
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(1, wp_verify_nonce($query['_wpnonce'], DesignGallery::NONCE_ACTION));
        // Without the query parameter the handler does nothing.
        unset($_GET['swf_design_gallery']);
        (new DesignGallery())->handle();
        self::assertTrue(true);
    }

    public function testGalleryRejectsVisitorsWithoutTheCapability(): void
    {
        wp_set_current_user(0);
        $_GET['swf_design_gallery'] = '1';
        $_GET['_wpnonce'] = 'x';
        add_filter('wp_die_handler', static fn () => static function (string $message, string $title, array $args): void {
            throw new \RuntimeException((string) ($args['response'] ?? 0));
        });
        try {
            (new DesignGallery())->handle();
            self::fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            self::assertSame('403', $e->getMessage());
        } finally {
            unset($_GET['swf_design_gallery'], $_GET['_wpnonce']);
            remove_all_filters('wp_die_handler');
        }
    }

    public function testAdminConfigOffersTheGalleryOnlyToFormManagers(): void
    {
        $page = new \SWForms\WordPress\Admin\AdminPage(\SWForms\WordPress\Fields\FieldRegistry::instance());
        self::assertStringContainsString('swf_design_gallery=1', $page->config()['galleryUrl']);
        wp_set_current_user(0);
        self::assertSame('', $page->config()['galleryUrl']);
    }
}
