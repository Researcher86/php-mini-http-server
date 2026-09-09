<?php

declare(strict_types=1);

namespace App\Tests\Http\Handler;

use App\Http\Handler\HelloHandler;
use App\Http\Headers\Headers;
use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpVersion;
use App\Http\Request\HttpRequest;
use PHPUnit\Framework\TestCase;

final class HelloHandlerTest extends TestCase
{
    public function testHandlerAnswersWithPlainTextHello(): void
    {
        $handler = new HelloHandler();
        $request = new HttpRequest(
            method: HttpMethod::GET,
            target: '/hello',
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );

        $response = $handler->handle($request);

        $this->assertSame(200, $response->statusCode());
        $this->assertSame('Hello', $response->body);
        $this->assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
    }

    public function testHandlerDoesNotDependOnRequestDetails(): void
    {
        $handler = new HelloHandler();
        $request = new HttpRequest(
            method: HttpMethod::POST,
            target: '/anything',
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: 'ignored',
        );

        $this->assertSame('Hello', $handler->handle($request)->body);
    }
}