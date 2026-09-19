<?php

declare(strict_types=1);

use Elcreator\aPhalcon\Http\ResponseConverter;
use Illuminate\Http\JsonResponse;
use Phalcon\Http\Response;

test('a returned Phalcon response carries its status, headers and content over', function (): void {
    $phalcon = new Response();
    $phalcon->setStatusCode(201)->setHeader('X-Made-By', 'phalcon')->setContentType('text/plain')->setContent('made');

    $response = (new ResponseConverter())->convert($phalcon, 'made', null);

    expect($response->getStatusCode())->toBe(201);
    expect($response->getContent())->toBe('made');
    expect($response->headers->get('X-Made-By'))->toBe('phalcon');
    expect($response->headers->get('Content-Type'))->toBe('text/plain');
    // Phalcon's status line and 'Status' pseudo-headers are not headers.
    expect($response->headers->has('Status'))->toBeFalse();
    foreach (array_keys($response->headers->all()) as $name) {
        expect($name)->not->toStartWith('http/');
    }
});

test('an array becomes JSON', function (): void {
    $response = (new ResponseConverter())->convert(['a' => 1], '', null);

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getContent())->toBe('{"a":1}');
});

test('a string Micro has already printed is the body once', function (): void {
    $response = (new ResponseConverter())->convert('hello', 'hello', null);

    expect($response->getContent())->toBe('hello');
    expect($response->getStatusCode())->toBe(200);
});

test('a string Application did not print comes after what was echoed', function (): void {
    $response = (new ResponseConverter())->convert('body', 'pre:', null);

    expect($response->getContent())->toBe('pre:body');
});

test('nothing returned means what was echoed is the body', function (): void {
    expect((new ResponseConverter())->convert(null, 'echoed', null)->getContent())->toBe('echoed');
});

test('status and headers set on the shared response apply when nothing was returned', function (): void {
    $shared = new Response();
    $shared->setStatusCode(404)->setHeader('X-Shared', 'yes');

    $response = (new ResponseConverter())->convert('missing', 'missing', $shared);
    expect($response->getStatusCode())->toBe(404);
    expect($response->headers->get('X-Shared'))->toBe('yes');

    $json = (new ResponseConverter())->convert([], '', $shared);
    expect($json->getStatusCode())->toBe(404);
});

test('an untouched shared response changes nothing', function (): void {
    $response = (new ResponseConverter())->convert('ok', 'ok', new Response());

    expect($response->getStatusCode())->toBe(200);
});
