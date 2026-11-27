<?php

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;

it('reports ok when MongoDB and the queue answer', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'db' => 'ok', 'queue' => 'ok']);
});

it('reports the queue as failing with HTTP 503 when the queue store is down', function () {
    $this->app->instance(QueueFactory::class, new class implements QueueFactory
    {
        public function connection($name = null): Queue
        {
            throw new RuntimeException('Connection refused');
        }
    });

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'fail', 'db' => 'ok', 'queue' => 'fail']);
});

it('reports the database as failing with HTTP 503 when MongoDB is unreachable', function () {
    config([
        'database.connections.mongodb.dsn' => 'mongodb://127.0.0.1:1/?serverSelectionTimeoutMS=200',
    ]);
    $this->app['db']->purge('mongodb');

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJson(['status' => 'fail', 'db' => 'fail']);
});

it('does not leak error details from the public health endpoint', function () {
    config(['database.connections.mongodb.dsn' => 'mongodb://127.0.0.1:1/?serverSelectionTimeoutMS=200']);
    $this->app['db']->purge('mongodb');

    $body = $this->getJson('/api/v1/health')->getContent();

    expect($body)->not->toContain('127.0.0.1')->not->toContain('Exception');
});
