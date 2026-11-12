<?php

it('returns ok on the health endpoint without revealing versions', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()->assertExactJson(['status' => 'ok']);
    expect($response->getContent())->not->toContain('Laravel')->not->toContain('PHP');
});

it('runs against PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});
