<?php

declare(strict_types=1);

namespace Relia\Tests;

use PHPUnit\Framework\TestCase;
use Relia\Config;
use Relia\Exporter;
use Relia\Mask;
use Relia\Relia;
use Relia\Release;
use Relia\Span;
use Relia\Tracer;

/** Records what would be sent instead of sending it. */
final class MemoryExporter extends Exporter
{
    /** @var list<array{string, array}> */
    public array $sent = [];

    protected function post(string $url, array $payload): void
    {
        $this->sent[] = [$url, $payload];
    }
}

final class CoreTest extends TestCase
{
    private function tracer(array $o = []): array
    {
        $config = Config::fromEnv(['projectId' => '6a744ab9c3b399376c2a55d9', 'key' => 'relia_live_x', 'serviceName' => 'shop'] + $o);
        $exp = new MemoryExporter($config, sys_get_temp_dir());
        return [new Tracer($config, $exp), $exp];
    }

    public function testQueryValuesAreMaskedAndPlaceholdersKept(): void
    {
        $this->assertSame("select * from users where email = ? and id = ? and x = ?",
            Mask::statement("select * from users where email = 'a@b.co' and id = 42 and x = ?"));
        $this->assertSame('{"find":?,"filter":{"total":?}}', Mask::statement('{"find":"orders","filter":{"total":12}}'));
        $this->assertSame('select * from t2 where c = ?', Mask::statement('select * from t2 where c = 3'));
    }

    public function testLogTextLosesPersonalDataAndSecrets(): void
    {
        $out = Mask::text('user ann@shop.io paid with 4242 4242 4242 4242 password=hunter2 token: abc sk_live_1234567890ab');
        $this->assertStringNotContainsString('ann@shop.io', $out);
        $this->assertStringNotContainsString('4242 4242', $out);
        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('sk_live_1234567890ab', $out);
        $this->assertSame('https://api.stripe.com/v1/charges', Mask::url('https://user:pw@api.stripe.com/v1/charges?key=secret'));
    }

    public function testReleaseComesFromTheEnvironmentFirst(): void
    {
        Release::reset();
        putenv('RELIA_RELEASE=2.4.1');
        $this->assertSame('2.4.1', Release::version(sys_get_temp_dir()));
        putenv('RELIA_RELEASE');
        Release::reset();
        putenv('GITHUB_SHA=0123456789abcdef0123456789abcdef01234567');
        $this->assertSame('0123456789ab', Release::version(sys_get_temp_dir()));
        putenv('GITHUB_SHA');
        Release::reset();
    }

    public function testARequestIsSentAsOneTraceWithTheProjectAndKey(): void
    {
        [$t, $exp] = $this->tracer();
        $root = $t->begin('GET /orders/{id}', Span::SERVER);
        $root->set(['http.request.method' => 'GET', 'http.route' => '/orders/{id}', 'http.response.status_code' => 200]);
        $q = $t->child('SELECT', Span::CLIENT);
        $q->set(['db.system' => 'mysql', 'db.statement' => 'select * from orders where id = ?']);
        $q->end();
        $t->log('warning', 'stock low for ann@shop.io');
        $t->log('info', 'not stored');
        $t->finish();

        $this->assertCount(2, $exp->sent);
        [$url, $traces] = $exp->sent[0];
        $this->assertSame(Config::DEFAULT_TRACES, $url);
        $rs = $traces['resourceSpans'][0];
        $res = array_column(array_map(static fn ($a) => [$a['key'], $a['value']['stringValue'] ?? null], $rs['resource']['attributes']), 1, 0);
        $this->assertSame('6a744ab9c3b399376c2a55d9', $res['relia.project_id']);
        $this->assertSame('shop', $res['service.name']);
        $this->assertSame('relia', $res['telemetry.sdk.name']);
        $spans = $rs['scopeSpans'][0]['spans'];
        $this->assertCount(2, $spans);
        $this->assertSame(Span::SERVER, $spans[0]['kind']);
        $this->assertSame($spans[0]['spanId'], $spans[1]['parentSpanId']);
        $this->assertSame($spans[0]['traceId'], $spans[1]['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $spans[0]['traceId']);

        [$logUrl, $logs] = $exp->sent[1];
        $this->assertStringEndsWith('/v1/logs', $logUrl);
        $records = $logs['resourceLogs'][0]['scopeLogs'][0]['logRecords'];
        $this->assertCount(1, $records);  // INFO is not stored
        $this->assertStringNotContainsString('ann@shop.io', $records[0]['body']['stringValue']);
        $this->assertSame($spans[0]['traceId'], $records[0]['traceId']);
    }

    public function testACallersTraceparentIsJoined(): void
    {
        [$t] = $this->tracer();
        $root = $t->begin('POST /api/pay', Span::SERVER, '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $root->traceId);
        $this->assertSame('b7ad6b7169203331', $root->parentSpanId);
        $bad = $t->begin('GET /', Span::SERVER, 'nonsense');
        $this->assertSame('', $bad->parentSpanId);
    }

    public function testAnExceptionIsRecordedWithItsStack(): void
    {
        [$t, $exp] = $this->tracer();
        $root = $t->begin('POST /checkout', Span::SERVER);
        try {
            throw new \RuntimeException('card declined for ann@shop.io');
        } catch (\RuntimeException $e) {
            $root->recordException($e);
        }
        $t->finish();
        $span = $exp->sent[0][1]['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $this->assertSame(2, $span['status']['code']);
        $ev = $span['events'][0];
        $this->assertSame('exception', $ev['name']);
        $attrs = array_column(array_map(static fn ($a) => [$a['key'], $a['value']['stringValue']], $ev['attributes']), 1, 0);
        $this->assertSame('RuntimeException', $attrs['exception.type']);
        $this->assertStringContainsString('CoreTest.php', $attrs['exception.stacktrace']);
        $this->assertStringNotContainsString('ann@shop.io', $attrs['exception.message']);
    }

    public function testNothingIsRecordedOutsideARequestOrWithoutAProject(): void
    {
        [$t, $exp] = $this->tracer();
        $this->assertNull($t->child('SELECT', Span::CLIENT));
        $t->finish();
        $this->assertSame([], $exp->sent);
        $this->assertFalse(Config::fromEnv(['projectId' => ''])->enabled);
    }

    public function testMeasureAndTraceparentHelpers(): void
    {
        $config = Config::fromEnv(['projectId' => '6a744ab9c3b399376c2a55d9']);
        $exp = new MemoryExporter($config, sys_get_temp_dir());
        $t = Relia::init(['projectId' => '6a744ab9c3b399376c2a55d9'], sys_get_temp_dir(), $exp);
        $this->assertNull(Relia::traceparent());
        $t->begin('GET /', Span::SERVER);
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', (string) Relia::traceparent());
        $this->assertSame(3, Relia::measure('add', static fn () => 1 + 2));
        Relia::setUser(42, 'pro');
        $t->finish();
        $spans = $exp->sent[0][1]['resourceSpans'][0]['scopeSpans'][0]['spans'];
        $this->assertSame('add', $spans[1]['name']);
        $root = array_column(array_map(static fn ($a) => [$a['key'], $a['value']['stringValue'] ?? null], $spans[0]['attributes']), 1, 0);
        $this->assertSame('42', $root['relia.user_id']);
        $this->assertSame('pro', $root['relia.user_plan']);
        Relia::reset();
    }
}
