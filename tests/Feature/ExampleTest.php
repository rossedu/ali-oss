<?php

namespace Ross\AliOSS\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Ross\AliOSS\Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testBasicTest()
    {
        $contents = Storage::get('123.jpg');
        $base64   = base64_encode($contents);
        dd($base64,1);
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
