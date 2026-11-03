<?php

declare(strict_types=1);

namespace Chicken\Tests\Api;

use Chicken\Api\ApiConfig;
use Chicken\Api\ApiController;
use Chicken\Api\Request;
use Chicken\Api\Response;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/** Scénarios complets de l'API (statuts HTTP, corps JSON, fichiers), avec une horloge contrôlée. */
final class ApiControllerTest extends TestCase
{
    private const string TOKEN = 'abababababababababababababababab';
    private const string OTHER = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    private string $dir;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/chicken-api-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
    }

    private function controller(?ApiConfig $config = null): ApiController
    {
        $clock = new class ($this) implements ClockInterface {
            public function __construct(private readonly ApiControllerTest $test)
            {
            }

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@' . $this->test->now());
            }
        };

        return new ApiController($this->dir, $config ?? new ApiConfig(secret: str_repeat('s', 32)), $clock);
    }

    public function now(): int
    {
        return $this->now;
    }

    /** @param array<string, mixed> $body */
    private function post(array $body, ?ApiController $api = null, array $server = ['REMOTE_ADDR' => '203.0.113.5']): Response
    {
        return ($api ?? $this->controller())->handle(new Request('POST', [], json_encode($body), $server));
    }

    private function ticket(string $token = self::TOKEN, ?ApiController $api = null): string
    {
        $r = $this->post(['action' => 'start', 'token' => $token, 'level' => 1], $api);
        $this->assertSame(200, $r->status);

        return $r->body['ticket'];
    }

    private function config(float $perPoint = 0.0, int $records = 8): ApiConfig
    {
        return new ApiConfig(ticketsPerWindow: 40, recordsPerWindow: $records, secondsPerPoint: $perPoint, secret: str_repeat('s', 32));
    }

    // ---- lecture ------------------------------------------------------------------------------------------------

    public function testEmptyBoardsAndForbiddenMethods(): void
    {
        $api = $this->controller();
        $this->assertEquals(new Response(200, []), $api->handle(new Request('GET')));
        $this->assertEquals(new Response(200, []), $api->handle(new Request('GET', ['board' => 'multi'])));
        $this->assertEquals(new Response(405, ['error' => 'method']), $api->handle(new Request('DELETE')));
        $this->assertEquals(new Response(405, ['error' => 'method']), $api->handle(new Request('PUT')));
    }

    public function testReadingNeverCreatesTheSecret(): void
    {
        $this->controller()->handle(new Request('GET'));
        $this->assertFileDoesNotExist("{$this->dir}/secret.key");
        $this->assertFileDoesNotExist("{$this->dir}/api.lock");
    }

    // ---- ticket puis score --------------------------------------------------------------------------------------

    public function testFullGameFlow(): void
    {
        $api = $this->controller($this->config());
        $ticket = $this->ticket(api: $api);
        $this->assertSame(200, $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 1], $api)->status);
        $board = $api->handle(new Request('GET'))->body;
        $this->assertSame([['name' => 'Alice', 'played' => 1, 'wins' => 1, 'gf' => 4, 'ga' => 1]], $board);
        $this->assertStringNotContainsString('"th"', json_encode($board));
        $this->assertStringContainsString('"th"', (string) file_get_contents("{$this->dir}/scores.json"), 'le hash du jeton est stocké, pas publié');
    }

    public function testATicketWorksOnlyOnce(): void
    {
        $api = $this->controller($this->config());
        $ticket = $this->ticket(api: $api);
        $this->assertSame(422, $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 3], $api)->status, 'score invalide');
        $this->assertSame(200, $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 1], $api)->status, 'l\'échec n\'a pas consommé le ticket');
        $this->assertEquals(new Response(403, ['error' => 'ticket']), $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 1], $api));
    }

    public function testMissingOrForgedTicketsAreRefused(): void
    {
        $api = $this->controller($this->config());
        $this->assertEquals(new Response(403, ['error' => 'ticket']), $this->post(['name' => 'Alice', 'me' => 12, 'opp' => 0], $api));
        $this->assertEquals(new Response(403, ['error' => 'ticket']), $this->post(['ticket' => 'forged.ticket', 'name' => 'Alice', 'me' => 12, 'opp' => 0], $api));
    }

    public function testBadRequestsAreRefused(): void
    {
        $api = $this->controller($this->config());
        $this->assertEquals(new Response(400, ['error' => 'bad json']), $api->handle(new Request('POST', [], 'not json')));
        $this->assertEquals(new Response(400, ['error' => 'bad json']), $api->handle(new Request('POST', [], '5')));
        $this->assertEquals(new Response(400, ['error' => 'token']), $this->post(['action' => 'start', 'token' => 'short'], $api));
        $this->assertEquals(new Response(400, ['error' => 'level']), $this->post(['action' => 'start', 'token' => self::TOKEN, 'level' => 3], $api));
        $this->assertEquals(new Response(400, ['error' => 'level']), $this->post(['action' => 'start', 'token' => self::TOKEN, 'level' => -1], $api));
        $ticket = $this->ticket(api: $api);
        $this->assertEquals(new Response(422, ['error' => 'invalid']), $this->post(['ticket' => $ticket, 'name' => '<>', 'me' => 4, 'opp' => 1], $api));
    }

    public function testAGameCannotEndFasterThanTheGameAllows(): void
    {
        $api = $this->controller($this->config(perPoint: 2.0));
        $ticket = $this->ticket(api: $api);
        $this->assertEquals(new Response(422, ['error' => 'too_fast']), $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 0], $api));
        $this->now += 8;                                                           // 4 points x 2 s
        $this->assertSame(200, $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 0], $api)->status);
    }

    public function testATicketExpires(): void
    {
        $api = $this->controller($this->config());
        $ticket = $this->ticket(api: $api);
        $this->now += 10800 + 1;
        $this->assertEquals(new Response(403, ['error' => 'ticket']), $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 0], $api));
    }

    public function testANameIsReservedByTheFirstToken(): void
    {
        $api = $this->controller($this->config());
        $this->assertSame(200, $this->post(['ticket' => $this->ticket(api: $api), 'name' => 'Reine', 'me' => 4, 'opp' => 0], $api)->status);
        $theft = $this->ticket(self::OTHER, $api);
        $this->assertEquals(new Response(403, ['error' => 'name_taken']), $this->post(['ticket' => $theft, 'name' => 'REINE', 'me' => 4, 'opp' => 0], $api));
        $this->assertSame(200, $this->post(['ticket' => $this->ticket(api: $api), 'name' => 'Reine', 'me' => 4, 'opp' => 0], $api)->status, 'le propriétaire rejoue');
        $this->assertSame(2, $api->handle(new Request('GET'))->body[0]['played']);
    }

    public function testRateLimitsApplyPerIpAndPerToken(): void
    {
        $api = $this->controller(new ApiConfig(ticketsPerWindow: 2, recordsPerWindow: 8, secondsPerPoint: 0.0, secret: str_repeat('s', 32)));
        $this->ticket(api: $api);
        $this->ticket(api: $api);
        $limited = $this->post(['action' => 'start', 'token' => self::TOKEN, 'level' => 1], $api);
        $this->assertSame(429, $limited->status);
        $this->assertSame('rate_limited', $limited->body['error']);
        $this->assertGreaterThan(0, $limited->body['retry_after']);
        // un autre jeton, depuis une autre adresse : pas concerné
        $this->assertSame(200, $this->post(['action' => 'start', 'token' => self::OTHER, 'level' => 1], $api, ['REMOTE_ADDR' => '203.0.113.99'])->status);
        $this->now += 3601;
        $this->assertSame(200, $this->post(['action' => 'start', 'token' => self::TOKEN, 'level' => 1], $api)->status, 'la fenêtre a glissé');
    }

    public function testTheForwardedAddressIsUsedBehindAPrivateProxy(): void
    {
        $api = $this->controller(new ApiConfig(ticketsPerWindow: 1, secret: str_repeat('s', 32)));
        $proxy = static fn (string $client): array => ['REMOTE_ADDR' => '192.168.1.46', 'HTTP_X_FORWARDED_FOR' => $client];
        $this->assertSame(200, $this->post(['action' => 'start', 'token' => self::TOKEN, 'level' => 1], $api, $proxy('198.51.100.1'))->status);
        $this->assertSame(200, $this->post(['action' => 'start', 'token' => self::OTHER, 'level' => 1], $api, $proxy('198.51.100.2'))->status, 'chaque visiteur a son quota');
        $again = $this->post(['action' => 'start', 'token' => str_repeat('ef', 16), 'level' => 1], $api, $proxy('198.51.100.1'));
        $this->assertSame(429, $again->status, 'même visiteur, autre jeton');
    }

    public function testStorageFailuresAnswer500WithoutLeakingPaths(): void
    {
        $api = new ApiController($this->dir . '/absent', $this->config(), $this->clockAt($this->now));
        $r = $this->post(['action' => 'start', 'token' => self::TOKEN, 'level' => 1], $api);
        $this->assertEquals(new Response(500, ['error' => 'storage']), $r);

        // scores.json non inscriptible (un dossier à sa place) : 500, et le ticket n'est pas brûlé pour autant
        $api = $this->controller($this->config());
        $ticket = $this->ticket(api: $api);
        mkdir("{$this->dir}/scores.json");
        $r = @$this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 0], $api);
        rmdir("{$this->dir}/scores.json");
        $this->assertEquals(new Response(500, ['error' => 'storage']), $r);
        $this->assertSame(200, $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 4, 'opp' => 0], $api)->status);
    }

    public function testTheSecretIsCreatedOnceAndKept(): void
    {
        $api = $this->controller(new ApiConfig());
        $ticket = $this->ticket(api: $api);
        $secret = (string) file_get_contents("{$this->dir}/secret.key");
        $this->assertSame(64, strlen($secret));
        $otherProcess = $this->controller(new ApiConfig(secondsPerPoint: 0.0));
        $this->assertSame(200, $this->post(['ticket' => $ticket, 'name' => 'Alice', 'me' => 12, 'opp' => 0], $otherProcess)->status, 'un autre processus relit le même secret');
        $this->assertSame($secret, (string) file_get_contents("{$this->dir}/secret.key"));
    }

    public function testASecretFromTheEnvironmentWinsWhenLongEnough(): void
    {
        $api = $this->controller(new ApiConfig(secret: 'un-secret-de-plus-de-16'));
        $this->ticket(api: $api);
        $this->assertFileDoesNotExist("{$this->dir}/secret.key");
        $short = $this->controller(new ApiConfig(secret: 'trop-court'));
        $this->ticket(api: $short);
        $this->assertFileExists("{$this->dir}/secret.key", 'trop court : ignoré');
    }

    // ---- classement multijoueur ---------------------------------------------------------------------------------

    public function testTheMultiplayerBoardNeverExposesTokenHashes(): void
    {
        file_put_contents("{$this->dir}/multi_scores.json", json_encode([
            'version' => 1,
            'players' => ['alice' => ['name' => 'Alice', 'th' => 'secrethash', 'rating' => 1020, 'played' => 1, 'wins' => 1, 'losses' => 0, 'gf' => 4, 'ga' => 0]],
            'pairs' => ['a|b' => [1]],
        ]));
        $r = $this->controller()->handle(new Request('GET', ['board' => 'multi']));
        $this->assertSame([['name' => 'Alice', 'rating' => 1020, 'played' => 1, 'wins' => 1, 'losses' => 0, 'gf' => 4, 'ga' => 0]], $r->body);
        $this->assertStringNotContainsString('secrethash', json_encode($r->body));
    }

    private function clockAt(int $time): ClockInterface
    {
        return new class ($time) implements ClockInterface {
            public function __construct(private readonly int $time)
            {
            }

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@' . $this->time);
            }
        };
    }
}
