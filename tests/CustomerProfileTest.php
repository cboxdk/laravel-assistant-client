<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Tests;

use Cbox\AiAssistant\Connector\Events\SupportAccessUsed;
use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Profile\Lifecycle;
use Cbox\AiAssistant\Connector\Profile\ResourceItem;
use Cbox\AiAssistant\Connector\Profile\ResourceStatus;
use Cbox\AiAssistant\Connector\Profile\SignalFlag;
use Cbox\AiAssistant\Connector\Profile\Signals;
use Cbox\AiAssistant\Connector\ResourceBuilder;
use Cbox\AiAssistant\Connector\Support\SupportAccess;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class CustomerProfileTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $customer = ['sub' => 'user_7', 'account_id' => 'acme', 'verified' => true];

    public function test_resource_keys_follow_the_documented_pattern(): void
    {
        self::assertSame('sites_domains', AiAssistant::resource('sites_domains')->key);

        foreach (['', 'Sites', '1sites', 'sites-domains', str_repeat('a', 42)] as $invalid) {
            try {
                new ResourceBuilder($invalid);
                self::fail("Key [{$invalid}] was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_array_items_are_normalised_and_validated(): void
    {
        $item = ResourceItem::fromArray([
            'id' => 42,
            'label' => str_repeat('x', 250),
            'status' => 'warning',
            'status_label' => 'Certificate expires soon',
            'url' => 'http://insecure.example.com/domains/42',
            'parent' => 'sites_sites:7',
            'attributes' => [
                'registrar' => str_repeat('r', 300), 'nested' => ['no'], 'none' => null,
                'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6, 'g' => 7, 'h' => 8,
            ],
            'metrics' => ['visits' => 120, 'ratio' => 0.5, 'text' => '12', 'nan' => NAN],
            'updated_at' => '2026-11-01T10:00:00+00:00',
            'unknown' => 'ignored',
        ]);

        $wire = $item->toArray();
        self::assertSame('42', $wire['id']);
        self::assertSame(200, mb_strlen($wire['label']));
        self::assertSame('warning', $wire['status']);
        self::assertNull($wire['url'], 'Non-HTTPS links are dropped.');
        self::assertSame('sites_sites:7', $wire['parent']);
        self::assertCount(8, $wire['attributes']);
        self::assertSame(200, mb_strlen((string) $wire['attributes']['registrar']));
        self::assertArrayNotHasKey('nested', $wire['attributes']);
        self::assertArrayHasKey('none', $wire['attributes']);
        self::assertSame(['visits' => 120, 'ratio' => 0.5], $wire['metrics']);
        self::assertSame('2026-11-01T10:00:00+00:00', $wire['updated_at']);
        self::assertArrayNotHasKey('unknown', $wire);

        $other = ResourceItem::fromArray(['id' => 'a', 'label' => 'A', 'status' => 'purple', 'parent' => 'not a ref', 'url' => 'https://sites.cbox.dk/a']);
        self::assertNull($other->status);
        self::assertNull($other->parent);
        self::assertSame('https://sites.cbox.dk/a', $other->url);
        self::assertNull((new ResourceItem('a', 'A', url: 'javascript:alert(1)'))->url);
        self::assertSame(ResourceStatus::Ok, (new ResourceItem('a', 'A', ResourceStatus::Ok))->status);
    }

    public function test_items_without_id_or_label_are_refused(): void
    {
        foreach ([['label' => 'No id'], ['id' => 'x'], ['id' => ' ', 'label' => 'Blank id'], ['id' => 'x', 'label' => '']] as $invalid) {
            try {
                ResourceItem::fromArray($invalid);
                self::fail('An item without id or label was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_resource_serves_its_items_to_the_principal(): void
    {
        AiAssistant::resource('sites_domains')->label('Domains')->singular('Domain')->pickable()
            ->guard(fn (ToolContext $ctx): bool => $ctx->accountId() === 'acme')
            ->list(fn (ToolContext $ctx, array $filters): array => [
                new ResourceItem('d1', 'shop.acme.dk', ResourceStatus::Ok, url: 'https://sites.cbox.dk/domains/d1'),
                ['id' => 'd2', 'label' => 'acme.dk', 'attributes' => ['owner' => $ctx->userId(), 'q' => $filters['q'] ?? null]],
            ]);

        $this->getAssistantProfile($this->customer, ['filter' => ['q' => 'shop']])
            ->assertOk()
            ->assertJsonPath('resources.sites_domains.label', 'Domains')
            ->assertJsonPath('resources.sites_domains.truncated', false)
            ->assertJsonPath('resources.sites_domains.items.0.id', 'd1')
            ->assertJsonPath('resources.sites_domains.items.0.status', 'ok')
            ->assertJsonPath('resources.sites_domains.items.0.url', 'https://sites.cbox.dk/domains/d1')
            ->assertJsonPath('resources.sites_domains.items.1.attributes.owner', 'user_7')
            ->assertJsonPath('resources.sites_domains.items.1.attributes.q', 'shop')
            ->assertJsonPath('signals', null);
    }

    public function test_items_are_capped_at_fifty_without_draining_the_source(): void
    {
        $read = 0;
        AiAssistant::resource('billing_invoices')->guard(fn (): bool => true)
            ->list(function () use (&$read): Generator {
                for ($i = 1; $i <= 500; $i++) {
                    $read++;
                    yield ['id' => "inv_{$i}", 'label' => "Invoice {$i}"];
                }
            });
        AiAssistant::resource('billing_small')->guard(fn (): bool => true)
            ->list(fn (): array => array_map(fn (int $i): array => ['id' => (string) $i, 'label' => "#{$i}"], range(1, 50)));

        $this->getAssistantProfile($this->customer)
            ->assertOk()
            ->assertJsonCount(50, 'resources.billing_invoices.items')
            ->assertJsonPath('resources.billing_invoices.truncated', true)
            ->assertJsonCount(50, 'resources.billing_small.items')
            ->assertJsonPath('resources.billing_small.truncated', false);

        self::assertSame(51, $read);
    }

    public function test_a_denying_or_missing_guard_omits_the_resource(): void
    {
        AiAssistant::resource('sites_sites')->guard(fn (): bool => true)->list(fn (): array => [['id' => 's1', 'label' => 'Shop']]);
        AiAssistant::resource('sites_domains')->guard(fn (): bool => false)->list(fn (): array => $this->fail('A denied list ran.'));
        AiAssistant::resource('sites_secrets')->list(fn (): array => $this->fail('An unguarded list ran.'));
        AiAssistant::resource('sites_truthy')->guard(fn (): int => 1)->list(fn (): array => $this->fail('A non-true guard passed.'));

        $response = $this->getAssistantProfile($this->customer)->assertOk();

        self::assertSame(['sites_sites'], array_keys((array) $response->json('resources')));
    }

    public function test_a_failing_resource_does_not_break_the_others(): void
    {
        Exceptions::fake();

        AiAssistant::resource('sites_sites')->label('Sites')->guard(fn (): bool => true)->list(fn (): array => [['id' => 's1', 'label' => 'Shop']]);
        AiAssistant::resource('sites_domains')->label('Domains')->guard(fn (): bool => true)
            ->list(fn (): array => throw new RuntimeException('Database is down'));
        AiAssistant::resource('sites_broken')->guard(fn (): bool => true)->list(fn (): array => [['label' => 'no id']]);
        AiAssistant::resource('sites_guard')->guard(fn (): bool => throw new RuntimeException('Guard failed'))->list(fn (): array => []);

        $this->getAssistantProfile($this->customer)
            ->assertOk()
            ->assertJsonPath('resources.sites_sites.items.0.id', 's1')
            ->assertJsonPath('resources.sites_domains', ['label' => 'Domains', 'error' => 'unavailable'])
            ->assertJsonPath('resources.sites_broken.error', 'unavailable')
            ->assertJsonMissingPath('resources.sites_guard');

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Database is down');
        Exceptions::assertReported(InvalidArgumentException::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Guard failed');
    }

    public function test_the_resources_query_narrows_the_profile(): void
    {
        AiAssistant::resource('sites_sites')->guard(fn (): bool => true)->list(fn (): array => [['id' => 's1', 'label' => 'Shop']]);
        AiAssistant::resource('sites_domains')->guard(fn (): bool => true)->list(fn (): array => $this->fail('An unrequested list ran.'));

        $response = $this->getAssistantProfile($this->customer, ['resources' => 'sites_sites, unknown_key'])->assertOk();

        self::assertSame(['sites_sites'], array_keys((array) $response->json('resources')));
    }

    public function test_an_empty_profile_is_a_json_object(): void
    {
        $response = $this->getAssistantProfile($this->customer)->assertOk();

        self::assertSame('{"resources":{},"signals":null}', $response->getContent());
    }

    public function test_signals_keep_the_vocabulary_and_drop_the_rest(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');

        $signals = Signals::fromArray([
            'lifecycle' => 'past_due',
            'plan' => 'Pro',
            'mrr' => ['amount_minor' => 4900, 'currency' => 'eur'],
            'customer_since' => '2024-03-01T08:00:00+00:00',
            'seats' => 5,
            'usage' => [
                ['meter' => 'sites', 'used' => 3, 'limit' => 5, 'unit' => 'sites'],
                ['meter' => '', 'used' => 1],
                ['meter' => 'storage', 'used' => -1],
                ...array_map(fn (int $i): array => ['meter' => "m{$i}", 'used' => $i], range(1, 12)),
            ],
            'flags' => ['payment_failed', 'made_up', 'site_down', 'payment_failed'],
            'recent_changes' => [
                ['what' => 'Upgraded to Pro', 'at' => '2026-11-08T10:00:00+00:00', 'url' => 'https://billing.cbox.dk/subscription'],
                ['what' => 'Deployed main', 'at' => '2026-11-09T09:00:00+00:00', 'url' => 'http://insecure.example.com'],
                ['what' => 'Too old', 'at' => '2026-10-20T10:00:00+00:00'],
                ['what' => str_repeat('w', 200), 'at' => '2026-11-07T10:00:00+00:00'],
                ['what' => 'No time'],
            ],
            'facts' => ['region' => 'eu', 'nested' => ['x'], 'empty' => null, ...array_combine(array_map(fn (int $i): string => "f{$i}", range(1, 12)), range(1, 12))],
            'secret_internal_score' => 99,
        ]);

        $wire = $signals->toArray();

        self::assertSame('past_due', $wire['lifecycle']);
        self::assertSame(['amount_minor' => 4900, 'currency' => 'EUR'], $wire['mrr']);
        self::assertSame('2024-03-01', $wire['customer_since']);
        self::assertSame(5, $wire['seats']);
        self::assertIsArray($wire['usage']);
        self::assertCount(10, $wire['usage']);
        self::assertSame(['meter' => 'sites', 'used' => 3, 'limit' => 5, 'unit' => 'sites'], $wire['usage'][0]);
        self::assertSame(['payment_failed', 'site_down'], $wire['flags']);
        self::assertIsArray($wire['recent_changes']);
        self::assertCount(3, $wire['recent_changes']);
        self::assertSame(['what' => 'Deployed main', 'at' => '2026-11-09T09:00:00+00:00', 'url' => null], $wire['recent_changes'][0]);
        self::assertSame(120, mb_strlen($wire['recent_changes'][2]['what']));
        self::assertIsArray($wire['facts']);
        self::assertCount(10, $wire['facts']);
        self::assertSame('eu', $wire['facts']['region']);
        self::assertArrayNotHasKey('secret_internal_score', $wire);

        $invalid = Signals::fromArray(['lifecycle' => 'zombie', 'mrr' => ['amount_minor' => -1, 'currency' => 'EUR'], 'seats' => -2, 'flags' => 'site_down']);
        self::assertTrue($invalid->isEmpty());
        self::assertNull(Signals::make()->mrr(100, 'EURO')->mrr);
    }

    public function test_signals_closures_are_merged_and_a_failing_one_is_skipped(): void
    {
        Exceptions::fake();
        Carbon::setTestNow('2026-11-09 12:00:00');

        AiAssistant::signals(fn (ToolContext $ctx): Signals => Signals::make()
            ->lifecycle(Lifecycle::Trial)->plan('Starter')->mrr(0, 'DKK')
            ->flag(SignalFlag::TrialEnding)->usage('sites', 1, 3)->fact('owner', (string) $ctx->userId()));
        AiAssistant::signals(fn (): array => ['lifecycle' => 'active', 'plan' => 'Pro', 'flags' => ['site_down', 'trial_ending'], 'facts' => ['region' => 'eu']]);
        AiAssistant::signals(fn (): array => throw new RuntimeException('Billing is down'));
        AiAssistant::signals(fn (): string => 'not signals');

        $this->getAssistantProfile($this->customer)
            ->assertOk()
            ->assertJsonPath('signals.lifecycle', 'active')
            ->assertJsonPath('signals.plan', 'Pro')
            ->assertJsonPath('signals.mrr', ['amount_minor' => 0, 'currency' => 'DKK'])
            ->assertJsonPath('signals.flags', ['trial_ending', 'site_down'])
            ->assertJsonPath('signals.usage.0.meter', 'sites')
            ->assertJsonPath('signals.facts', ['owner' => 'user_7', 'region' => 'eu']);

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Billing is down');
        Exceptions::assertReported(UnexpectedValueException::class);
    }

    public function test_registered_signals_that_say_nothing_are_an_empty_object(): void
    {
        AiAssistant::signals(fn (): Signals => Signals::make());

        self::assertSame('{"resources":{},"signals":{}}', $this->getAssistantProfile($this->customer)->getContent());
    }

    public function test_the_manifest_declares_resources_and_signals(): void
    {
        $jwt = $this->sign(['tool' => 'discovery']);
        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('resources', [])
            ->assertJsonPath('signals', false)
            ->assertJsonPath('version', 1);

        AiAssistant::resource('sites_domains')->label('Domains')->singular('Domain')->pickable();
        AiAssistant::resource('sites_sites');
        AiAssistant::signals(fn (): array => []);

        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$this->sign(['tool' => 'discovery'])])
            ->assertOk()
            ->assertJsonPath('resources', [
                ['key' => 'sites_domains', 'label' => 'Domains', 'singular' => 'Domain', 'pickable' => true],
                ['key' => 'sites_sites', 'label' => 'sites_sites', 'singular' => 'sites_sites', 'pickable' => false],
            ])
            ->assertJsonPath('signals', true)
            ->assertJsonPath('tools', [])
            ->assertJsonPath('inbox_cards', []);
    }

    public function test_the_profile_requires_a_valid_platform_signature(): void
    {
        AiAssistant::resource('sites_sites')->guard(fn (): bool => true)->list(fn (): array => $this->fail('An unverified request listed data.'));
        $principal = ['principal' => $this->customer];

        $this->getJson('/ai-assistant/profile')->assertUnauthorized();

        $forged = JWT::encode(
            ['aud' => 'localhost', 'iat' => time(), 'exp' => time() + 120, 'cnt' => 'test-connector-secret-at-least-32-bytes', ...$principal],
            base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), 'EdDSA', $this->kid,
        );
        $this->getJson('/ai-assistant/profile', ['Authorization' => 'Bearer '.$forged])->assertUnauthorized();

        foreach ([
            ['aud' => 'someone-else.example'],
            ['iat' => time() - 600, 'exp' => time() - 400],
            ['exp' => time() + 900],
            ['cnt' => 'another-connector-secret-of-32-bytes!'],
        ] as $invalid) {
            $this->getJson('/ai-assistant/profile', ['Authorization' => 'Bearer '.$this->sign([...$principal, ...$invalid])])
                ->assertUnauthorized();
        }
    }

    public function test_the_profile_is_read_only_so_jti_is_optional_but_single_use(): void
    {
        $this->getJson('/ai-assistant/profile', ['Authorization' => 'Bearer '.$this->sign(['jti' => null, 'principal' => $this->customer])])
            ->assertOk();

        $once = $this->sign(['jti' => 'profile-once', 'principal' => $this->customer]);
        $this->getJson('/ai-assistant/profile', ['Authorization' => 'Bearer '.$once])->assertOk();
        $this->getJson('/ai-assistant/profile', ['Authorization' => 'Bearer '.$once])->assertUnauthorized();
    }

    public function test_a_valid_elevation_is_exposed_and_announced(): void
    {
        $this->freezeTime();
        Event::fake([SupportAccessUsed::class]);

        $seen = null;
        AiAssistant::resource('sites_domains')
            ->guard(function (ToolContext $ctx) use (&$seen): bool {
                $seen = $ctx->supportAccess();

                return true;
            })
            ->list(fn (): array => []);
        AiAssistant::resource('sites_denied')->guard(fn (): bool => false)->list(fn (): array => []);

        $this->getAssistantProfile($this->customer, [], $this->assistantSupportAccess(minutes: 60))->assertOk();

        self::assertInstanceOf(SupportAccess::class, $seen);
        self::assertSame('staff_1', $seen->staffId);
        self::assertSame('Support Agent', $seen->staffName);
        self::assertSame('Customer asked us to check why their domain is not resolving', $seen->reason);
        self::assertSame(Carbon::now()->addHour()->getTimestamp(), $seen->until->getTimestamp());

        Event::assertDispatched(
            SupportAccessUsed::class,
            fn (SupportAccessUsed $event): bool => $event->surface === 'profile'
                && $event->keys === ['sites_domains']
                && $event->subject === 'user_7'
                && $event->accountId === 'acme'
                && $event->access->staffId === 'staff_1',
        );
    }

    public function test_an_invalid_elevation_is_ignored(): void
    {
        $this->freezeTime();
        Event::fake([SupportAccessUsed::class]);

        $seen = [];
        AiAssistant::resource('sites_domains')
            ->guard(function (ToolContext $ctx) use (&$seen): bool {
                $seen[] = $ctx->supportAccess();

                return true;
            })
            ->list(fn (): array => []);

        $claims = [
            'empty reason' => $this->assistantSupportAccess(reason: ''),
            'short reason' => $this->assistantSupportAccess(reason: '  too short '),
            'expired' => $this->assistantSupportAccess(minutes: -1),
            'now' => $this->assistantSupportAccess(minutes: 0),
            'too far ahead' => $this->assistantSupportAccess(minutes: 61),
            'no staff' => [...$this->assistantSupportAccess(), 'staff_id' => ''],
            'no name' => [...$this->assistantSupportAccess(), 'staff_name' => null],
            'bad time' => [...$this->assistantSupportAccess(), 'until' => 'soon'],
        ];

        foreach ($claims as $claim) {
            $this->getAssistantProfile($this->customer, [], $claim)->assertOk();
        }
        $this->getAssistantProfile($this->customer)->assertOk();
        $this->getAssistantProfile([...$this->customer, 'support_access' => 'yes'])->assertOk();

        self::assertSame(array_fill(0, count($claims) + 2, null), $seen);
        Event::assertNotDispatched(SupportAccessUsed::class);
    }

    public function test_elevation_accepts_a_unix_timestamp(): void
    {
        $this->freezeTime();
        $ctx = new ToolContext(['support_access' => [
            'staff_id' => 9, 'staff_name' => 'Ada', 'reason' => 'Investigating a failed payment', 'until' => time() + 600,
        ]]);

        self::assertSame('9', $ctx->supportAccess()?->staffId);
        self::assertEquals(new DateTimeImmutable('@'.(time() + 600)), $ctx->supportAccess()->until);
    }

    public function test_tools_and_inbox_components_announce_elevated_use(): void
    {
        Event::fake([SupportAccessUsed::class]);
        AiAssistant::tool('sites_lookup')->guard(fn (): bool => true)->handle(fn (): string => 'ok');
        AiAssistant::tool('sites_denied')->guard(fn (): bool => false)->handle(fn (): string => 'no');
        AiAssistant::inboxCard('sites_card')->guard(fn (): bool => true)->handle(fn (): array => []);
        $elevated = [...$this->customer, 'support_access' => $this->assistantSupportAccess()];

        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$this->sign(['tool' => 'sites_lookup', 'principal' => $elevated])])->assertOk();
        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$this->sign(['tool' => 'sites_denied', 'principal' => $elevated])])->assertForbidden();
        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$this->sign(['tool' => 'sites_lookup', 'principal' => $this->customer])])->assertOk();
        $this->getJson('/ai-assistant/inbox/sites_card', ['Authorization' => 'Bearer '.$this->sign(['principal' => $elevated])])->assertOk();

        Event::assertDispatchedTimes(SupportAccessUsed::class, 2);
        Event::assertDispatched(
            SupportAccessUsed::class,
            fn (SupportAccessUsed $e): bool => $e->surface === 'tool' && $e->keys === ['sites_lookup'],
        );
        Event::assertDispatched(
            SupportAccessUsed::class,
            fn (SupportAccessUsed $e): bool => $e->surface === 'inbox' && $e->keys === ['sites_card'],
        );
    }
}
