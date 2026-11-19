<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Segment\Reassembler;
use IndianOpinion\TranslateSidecar\Segment\Segmenter;
use IndianOpinion\TranslateSidecar\Segment\SourceFields;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 16 to 20 and 22: segmenting, placeholders, verbatim
 * parts, images, fields, grouping and sanitising.
 */
final class SegmenterTest extends TestCase
{
    private function segmenter(bool $alt = true, array $shortcodes = []): Segmenter
    {
        return new Segmenter(['core/code', 'core/preformatted', 'core/shortcode', 'core/embed'], $shortcodes, $alt);
    }

    /** Test 16 (cases 18, 19). */
    public function testBlockDocumentGetsStableIdsAndPlaceholders(): void
    {
        $html = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">A heading</h2><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Read <a href="https://example.test/report/" class="x">the report</a> and <strong>act</strong>.</p><!-- /wp:paragraph -->';
        $post = $this->segmenter()->segment(new SourceFields('Title here', $html));
        self::assertSame(['title', 's1', 's2'], array_keys($post->segments));
        self::assertSame('A heading', $post->segments['s1']->text);
        self::assertSame('Read {1}the report{/1} and {2}act{/2}.', $post->segments['s2']->text);
        $again = $this->segmenter()->segment(new SourceFields('Title here', $html));
        self::assertSame($post->hashes(), $again->hashes());
        foreach ($post->segments as $seg) {
            self::assertStringNotContainsString('https://', $seg->text);
            self::assertStringNotContainsString('class=', $seg->text);
            self::assertStringNotContainsString('wp:', $seg->text);
        }
    }

    /** Test 16 (cases 18, 19): classic HTML without blocks. */
    public function testClassicDocument(): void
    {
        $html = "First paragraph with <em>emphasis</em>.\n\nSecond paragraph, see https://example.test/x and mail me@example.test.";
        $post = $this->segmenter()->segment(new SourceFields('T', $html));
        self::assertSame('First paragraph with {1}emphasis{/1}.', $post->segments['s1']->text);
        self::assertSame('Second paragraph, see {1/} and mail {2/}.', $post->segments['s2']->text);
        self::assertSame('https://example.test/x', $post->segments['s2']->placeholders[1]['open']);
    }

    /** Test 17 (cases 20, 96). */
    public function testVerbatimPartsAreCopied(): void
    {
        $html = '<!-- wp:code --><pre class="wp-block-code"><code>echo "hello world";</code></pre><!-- /wp:code -->'
            . '<!-- wp:paragraph --><p>Call <code>run()</code> now [gallery ids="1,2"] please.</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph {"translate":false} --><p>Keep this sentence exactly.</p><!-- /wp:paragraph -->'
            . '<!-- wp:embed {"url":"https://video.example/x"} --><figure class="wp-block-embed">https://video.example/x</figure><!-- /wp:embed -->'
            . '<p translate="no">Brand Name Ltd</p><p>12345</p><p>https://example.test/only-a-url</p><p>&#x1F600;</p>';
        $s = $this->segmenter(true, ['gallery']);
        $post = $s->segment(new SourceFields('', $html));
        $texts = array_map(static fn ($x) => $x->text, $post->segments);
        self::assertSame(['s1' => 'Call {1/} now {2/} please.'], $texts);
        $out = (new Reassembler())->assemble($post, ['s1' => 'Appelez {1/} maintenant {2/} svp.']);
        self::assertStringContainsString('<code>echo "hello world";</code>', $out->content);
        self::assertStringContainsString('<code>run()</code>', $out->content);
        self::assertStringContainsString('[gallery ids="1,2"]', $out->content);
        self::assertStringContainsString('Keep this sentence exactly.', $out->content);
        self::assertStringContainsString('Brand Name Ltd', $out->content);
        self::assertStringContainsString('<p>12345</p>', $out->content);
        self::assertStringContainsString('Appelez <code>run()</code> maintenant [gallery ids="1,2"] svp.', $out->content);
    }

    /** Test 18 (case 21). */
    public function testImagesKeepSourcesAndAltIsTranslatedWhenOn(): void
    {
        $html = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="https://example.test/a.jpg" srcset="https://example.test/a-300.jpg 300w" alt="A red bus" class="wp-image-42"/><figcaption>The bus at noon</figcaption></figure><!-- /wp:image -->';
        $post = $this->segmenter(true)->segment(new SourceFields('', $html));
        self::assertSame('A red bus', $post->segments['alt1']->text);
        self::assertSame(42, $post->segments['alt1']->attachmentId);
        self::assertSame('The bus at noon', $post->segments['s1']->text);
        $out = (new Reassembler())->assemble($post, ['alt1' => 'Un bus rouge', 's1' => 'Le bus a midi']);
        self::assertStringContainsString('src="https://example.test/a.jpg"', $out->content);
        self::assertStringContainsString('srcset="https://example.test/a-300.jpg 300w"', $out->content);
        self::assertStringContainsString('alt="Un bus rouge"', $out->content);
        self::assertSame([42 => 'Un bus rouge'], $out->altMap);

        $off = $this->segmenter(false)->segment(new SourceFields('', $html));
        self::assertArrayNotHasKey('alt1', $off->segments);
        $out = (new Reassembler())->assemble($off, ['s1' => 'Le bus a midi']);
        self::assertStringContainsString('alt="A red bus"', $out->content);
    }

    /** Test 19 (cases 22, 30). */
    public function testFieldsAreSeparateSegmentsAndExcerptIsDerived(): void
    {
        $post = $this->segmenter()->segment(new SourceFields('The title', '<p>Body text that is long enough.</p>', '', 'SEO title', 'Meta description here'));
        self::assertSame('title', $post->segments['title']->field);
        self::assertSame('seo_title', $post->segments['seo_title']->field);
        self::assertSame('meta_description', $post->segments['meta_description']->field);
        self::assertArrayNotHasKey('excerpt', $post->segments);
        $out = (new Reassembler())->assemble($post, ['title' => 'Le titre', 's1' => 'Texte du corps assez long.', 'seo_title' => 'Titre SEO', 'meta_description' => 'Description']);
        self::assertSame('Texte du corps assez long.', $out->excerpt);
        self::assertSame('Titre SEO', $out->seoTitle);

        $manual = $this->segmenter()->segment(new SourceFields('T', '<p>Body text.</p>', 'Hand written excerpt'));
        self::assertSame('Hand written excerpt', $manual->segments['excerpt']->text);
    }

    /** Test 22 (case 25). */
    public function testScriptInModelOutputIsRemoved(): void
    {
        $post = $this->segmenter()->segment(new SourceFields('', '<p>Hello there friend.</p>'));
        $out = (new Reassembler())->assemble($post, ['s1' => 'Bonjour <script>alert(1)</script><img src=x onerror=alert(2)> ami.']);
        self::assertStringNotContainsString('<script', $out->content);
        self::assertStringNotContainsString('onerror', $out->content);
        self::assertStringNotContainsString('<img', $out->content);
        self::assertStringContainsString('Bonjour', $out->content);
    }
}
