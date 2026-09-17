<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SeoAndMonetizationReadinessTest extends TestCase
{
    public function test_robots_txt_returns_ok_with_secure_disallow_rules_and_canonical_sitemap(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Disallow: /download/', $content);
        $this->assertStringContainsString('Disallow: /s/', $content);
        $this->assertStringContainsString('Disallow: /job/', $content);
        $this->assertStringContainsString('Disallow: /share/', $content);
        $this->assertStringContainsString('Disallow: /livewire/', $content);
        $this->assertStringContainsString('Sitemap: https://ka7lootpdf.me/sitemap.xml', $content);
    }

    public function test_sitemap_xml_returns_valid_xml_with_all_22_canonical_production_urls(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $content);

        // Verify XML is well-formed
        $xml = simplexml_load_string($content);
        $this->assertNotFalse($xml, 'Sitemap is not valid XML');

        $urls = [];
        foreach ($xml->url as $urlElement) {
            $urls[] = (string) $urlElement->loc;
        }

        $this->assertCount(22, $urls, 'Sitemap should have exactly 22 public URLs');

        // Verify all URLs use production domain https://ka7lootpdf.me
        foreach ($urls as $loc) {
            $this->assertStringStartsWith('https://ka7lootpdf.me', $loc);
            $this->assertStringNotContainsString('localhost', $loc);
            $this->assertStringNotContainsString('127.0.0.1', $loc);
            $this->assertStringNotContainsString('/download/', $loc);
            $this->assertStringNotContainsString('/s/', $loc);
            $this->assertStringNotContainsString('/job/', $loc);
        }

        // Verify crucial routes exist in sitemap
        $this->assertContains('https://ka7lootpdf.me', $urls);
        $this->assertContains('https://ka7lootpdf.me/edit-pdf', $urls);
        $this->assertContains('https://ka7lootpdf.me/remove-background', $urls);
        $this->assertContains('https://ka7lootpdf.me/merge-pdf', $urls);
        $this->assertContains('https://ka7lootpdf.me/compress-pdf', $urls);
        $this->assertContains('https://ka7lootpdf.me/sign-pdf', $urls);
        $this->assertContains('https://ka7lootpdf.me/privacy-policy', $urls);
        $this->assertContains('https://ka7lootpdf.me/terms-of-service', $urls);
    }

    public function test_home_page_renders_seo_and_social_metadata_correctly(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical" href="https://ka7lootpdf.me">', false);
        $response->assertSee('<meta name="robots" content="index, follow">', false);
        $response->assertSee('property="og:url" content="https://ka7lootpdf.me"', false);
        $response->assertSee('property="og:site_name" content="Ka7loot PDF"', false);
        $response->assertSee('name="twitter:card" content="summary_large_image"', false);
        $response->assertSee('"@type": "WebSite"', false);
        $response->assertSee('"@type": "Organization"', false);
    }

    public function test_tool_pages_render_unique_meta_canonical_and_json_ld(): void
    {
        $tools = [
            '/edit-pdf'          => 'https://ka7lootpdf.me/edit-pdf',
            '/merge-pdf'         => 'https://ka7lootpdf.me/merge-pdf',
            '/compress-pdf'      => 'https://ka7lootpdf.me/compress-pdf',
            '/remove-background' => 'https://ka7lootpdf.me/remove-background',
            '/change-background' => 'https://ka7lootpdf.me/change-background',
            '/sign-pdf'          => 'https://ka7lootpdf.me/sign-pdf',
        ];

        foreach ($tools as $uri => $expectedCanonical) {
            $response = $this->get($uri);
            $response->assertStatus(200);
            $response->assertSee('<link rel="canonical" href="' . $expectedCanonical . '">', false);
            $response->assertSee('<meta name="robots" content="index, follow">', false);
            $response->assertSee('"@type": "WebApplication"', false);
            $response->assertSee('property="og:url" content="' . $expectedCanonical . '"', false);
        }
    }

    public function test_error_pages_render_noindex_nofollow(): void
    {
        $response = $this->get('/non-existent-page-for-testing-404');

        $response->assertStatus(404);
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_analytics_and_adsense_are_not_rendered_when_unconfigured(): void
    {
        Config::set('services.google.analytics.measurement_id', null);
        Config::set('services.google.adsense.publisher_id', null);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('googletagmanager.com/gtag/js');
        $response->assertDontSee('pagead2.googlesyndication.com/pagead/js/adsbygoogle.js');
    }

    public function test_analytics_and_adsense_render_cleanly_when_configured(): void
    {
        Config::set('services.google.analytics.measurement_id', 'G-TEST123456');
        Config::set('services.google.adsense.publisher_id', 'ca-pub-1234567890123456');

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('googletagmanager.com/gtag/js?id=G-TEST123456', false);
        $response->assertSee("gtag('config', 'G-TEST123456'", false);
        $response->assertSee('pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456', false);
    }
}
