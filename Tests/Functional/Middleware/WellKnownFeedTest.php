<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Tests\Functional\Middleware;

use LetsEmploy\OJobPub\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

final class WellKnownFeedTest extends AbstractFunctionalTestCase
{
    private const URL = 'http://localhost/.well-known/ojobpub.json';

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method = 'GET', array $headers = []): ResponseInterface
    {
        $request = (new InternalRequest(self::URL))->withMethod($method);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->executeFrontendSubRequest($request);
    }

    #[Test]
    public function getReturnsTheFeed(): void
    {
        $response = $this->request();
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame((string)strlen($body), $response->getHeaderLine('Content-Length'));
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $response->getHeaderLine('ETag'));
        self::assertMatchesRegularExpression('/^\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $response->getHeaderLine('Last-Modified'));
        self::assertSame('public, max-age=900', $response->getHeaderLine('Cache-Control'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        $feed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $feed['version']);
        self::assertSame('Example Ltd', $feed['employer']['name']);
        // en: 1, 3, 4, 5 (All languages); de: 2. Broken link (12) and expired (13) are left out.
        self::assertCount(5, $feed['jobs']);
        $this->assertSchemaValid($body);
    }

    #[Test]
    public function headReturnsHeadersWithoutBody(): void
    {
        $get = $this->request();
        $head = $this->request('HEAD');

        self::assertSame(200, $head->getStatusCode());
        self::assertSame('', (string)$head->getBody());
        self::assertSame($get->getHeaderLine('Content-Length'), $head->getHeaderLine('Content-Length'));
        self::assertSame($get->getHeaderLine('ETag'), $head->getHeaderLine('ETag'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function matchingEtags(): iterable
    {
        yield 'strong' => ['%s'];
        yield 'weak' => ['W/%s'];
        yield 'in a list' => ['"other", %s'];
        yield 'any' => ['*'];
    }

    #[Test]
    #[DataProvider('matchingEtags')]
    public function matchingIfNoneMatchReturnsNotModified(string $header): void
    {
        $etag = $this->request()->getHeaderLine('ETag');
        $response = $this->request('GET', ['If-None-Match' => sprintf($header, $etag)]);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string)$response->getBody());
        self::assertSame($etag, $response->getHeaderLine('ETag'));
    }

    #[Test]
    public function otherIfNoneMatchReturnsTheFeed(): void
    {
        $response = $this->request('GET', ['If-None-Match' => '"other"']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', (string)$response->getBody());
    }

    #[Test]
    public function ifModifiedSinceIsUsedWithoutIfNoneMatch(): void
    {
        $lastModified = $this->request()->getHeaderLine('Last-Modified');

        self::assertSame(304, $this->request('GET', ['If-Modified-Since' => $lastModified])->getStatusCode());
        self::assertSame(200, $this->request('GET', ['If-Modified-Since' => 'Sat, 01 Jan 2000 00:00:00 GMT'])->getStatusCode());
        // If-None-Match takes precedence (RFC 9110).
        self::assertSame(200, $this->request('GET', ['If-Modified-Since' => $lastModified, 'If-None-Match' => '"other"'])->getStatusCode());
    }

    #[Test]
    public function optionsAnswersThePreflight(): void
    {
        $response = $this->request('OPTIONS');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function otherMethodsAreNotAllowed(): void
    {
        $response = $this->request('POST');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Allow'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unservedFeeds(): iterable
    {
        yield 'disabled' => [['enabled' => false]];
        yield 'employer without name' => [['employer.name' => '']];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('unservedFeeds')]
    public function unservedFeedFallsThroughToNotFound(array $settings): void
    {
        $this->writeSite($settings);

        $response = $this->request();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('"version"', (string)$response->getBody());
    }
}
