<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Http\Request;

use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PHPUnit\Framework\TestCase;

final class HttpRequestTest extends TestCase
{
    public function testPathStripsQueryString(): void
    {
        $request = $this->requestWithTarget('/users/42?page=2');

        $this->assertSame('/users/42', $request->path());
        $this->assertSame(['page' => '2'], $request->query());
    }

    public function testPathWithoutQueryIsTheWholeTarget(): void
    {
        $request = $this->requestWithTarget('/hello');

        $this->assertSame('/hello', $request->path());
        $this->assertSame([], $request->query());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $headers = new Headers();
        $headers->set('Content-Type', 'text/plain');

        $request = new HttpRequest(
            method: HttpMethod::GET,
            target: '/',
            version: HttpVersion::HTTP_1_1,
            headers: $headers,
            body: '',
        );

        $this->assertSame('text/plain', $request->header('content-type'));
        $this->assertSame('text/plain', $request->header('Content-Type'));
        $this->assertNull($request->header('Missing'));
    }

    public function testHttp11KeepsAliveByDefault(): void
    {
        $request = $this->requestWithTarget('/');

        $this->assertTrue($request->wantsKeepAlive());
    }

    public function testHttp11ClosesOnExplicitConnectionClose(): void
    {
        $headers = new Headers();
        $headers->set('Connection', 'close');

        $request = new HttpRequest(
            method: HttpMethod::GET,
            target: '/',
            version: HttpVersion::HTTP_1_1,
            headers: $headers,
            body: '',
        );

        $this->assertFalse($request->wantsKeepAlive());
    }

    public function testHttp10ClosesByDefault(): void
    {
        $request = new HttpRequest(
            method: HttpMethod::GET,
            target: '/',
            version: HttpVersion::HTTP_1_0,
            headers: new Headers(),
            body: '',
        );

        $this->assertFalse($request->wantsKeepAlive());
    }

    public function testHttp10KeepsAliveOnExplicitConnectionKeepAlive(): void
    {
        $headers = new Headers();
        $headers->set('Connection', 'keep-alive');

        $request = new HttpRequest(
            method: HttpMethod::GET,
            target: '/',
            version: HttpVersion::HTTP_1_0,
            headers: $headers,
            body: '',
        );

        $this->assertTrue($request->wantsKeepAlive());
    }

    public function testHttp11ClosesWhenCloseIsOneOfSeveralConnectionTokens(): void
    {
        $headers = new Headers();
        $headers->set('Connection', 'keep-alive, close');

        $request = new HttpRequest(HttpMethod::GET, '/', HttpVersion::HTTP_1_1, $headers, '');

        $this->assertFalse($request->wantsKeepAlive());
    }

    public function testHttp10KeepsAliveWhenKeepAliveIsOneOfSeveralConnectionTokens(): void
    {
        $headers = new Headers();
        $headers->set('Connection', 'upgrade, keep-alive');

        $request = new HttpRequest(HttpMethod::GET, '/', HttpVersion::HTTP_1_0, $headers, '');

        $this->assertTrue($request->wantsKeepAlive());
    }

    private function requestWithTarget(string $target): HttpRequest
    {
        return new HttpRequest(
            method: HttpMethod::GET,
            target: $target,
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );
    }
}
