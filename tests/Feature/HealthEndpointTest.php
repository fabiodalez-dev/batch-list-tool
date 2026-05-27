<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| /health endpoint smoke test
|--------------------------------------------------------------------------
|
| The route registered in routes/web.php must be PUBLIC (no auth, no
| session) and must return JSON — monitoring tools / NAF firewall probes
| hit it without a session and parse the body as JSON. We assert the
| status, the Content-Type, and that the response shape exposes the
| `checkResults` key produced by HealthCheckJsonResultsController. RFQ
| §3.4.1 (non-functional observability).
|
*/

uses(RefreshDatabase::class);

test('health endpoint returns JSON and includes the database check', function () {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');

    expect($response->json())->toHaveKey('checkResults');
});
