<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The application boots and serves a request.
     *
     * This used to assert 200 on "/", which has no route: the only public
     * entry points are the SSO callback, the WhatsApp webhook and the magic
     * link. It checks the health endpoint registered in bootstrap/app.php
     * instead, so a red suite means something is actually broken.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/up')->assertStatus(200);
    }
}
