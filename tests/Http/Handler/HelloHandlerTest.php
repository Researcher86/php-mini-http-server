<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Http\Handler;

use PhpMiniHttpServer\Http\Handler\HelloHandler;
use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Request\HttpRequest;
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
