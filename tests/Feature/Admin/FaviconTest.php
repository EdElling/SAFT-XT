<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\TestCase;

/** Symbol im Browser-Tab: Safttropfen als SVG, dazu favicon.ico und apple-touch-icon.png. */
class FaviconTest extends TestCase
{
    public function test_pages_link_the_saft_icons_and_the_files_exist(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('favicon.svg', false)
            ->assertSee('favicon.ico', false)
            ->assertSee('apple-touch-icon.png', false);

        $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('favicon.svg')));
        $this->assertSame("\x00\x00\x01\x00", substr((string) file_get_contents(public_path('favicon.ico')), 0, 4), 'Gültige ICO-Datei.');
        $this->assertStringStartsWith("\x89PNG", (string) file_get_contents(public_path('apple-touch-icon.png')));
    }
}
