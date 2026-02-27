<?php

declare(strict_types=1);

namespace Convoy\Tests\Unit\Concurrency;

use Convoy\Concurrency\Settlement;
use Convoy\Concurrency\SettlementBag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class SettlementTest extends TestCase
{
    #[Test]
    public function ok_settlement_has_correct_properties(): void
    {
        $settlement = Settlement::ok('value');

        $this->assertTrue($settlement->isOk);
        $this->assertSame('value', $settlement->value);
        $this->assertNull($settlement->error);
    }

    #[Test]
    public function err_settlement_has_correct_properties(): void
    {
        $error = new RuntimeException('test error');
        $settlement = Settlement::err($error);

        $this->assertFalse($settlement->isOk);
        $this->assertNull($settlement->value);
        $this->assertSame($error, $settlement->error);
    }

    #[Test]
    public function ok_settlement_unwrap_returns_value(): void
    {
        $settlement = Settlement::ok('value');

        $this->assertSame('value', $settlement->unwrap());
    }

    #[Test]
    public function err_settlement_unwrap_throws(): void
    {
        $error = new RuntimeException('test error');
        $settlement = Settlement::err($error);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $settlement->unwrap();
    }

    #[Test]
    public function ok_settlement_unwrap_or_returns_value(): void
    {
        $settlement = Settlement::ok('value');

        $this->assertSame('value', $settlement->unwrapOr('default'));
    }

    #[Test]
    public function err_settlement_unwrap_or_returns_default(): void
    {
        $settlement = Settlement::err(new RuntimeException('error'));

        $this->assertSame('default', $settlement->unwrapOr('default'));
    }

    #[Test]
    public function ok_settlement_error_returns_null(): void
    {
        $settlement = Settlement::ok('value');

        $this->assertNull($settlement->error());
    }

    #[Test]
    public function err_settlement_error_returns_throwable(): void
    {
        $error = new RuntimeException('test');
        $settlement = Settlement::err($error);

        $this->assertSame($error, $settlement->error());
    }

    #[Test]
    public function ok_settlement_with_null_value(): void
    {
        $settlement = Settlement::ok(null);

        $this->assertTrue($settlement->isOk);
        $this->assertNull($settlement->value);
        $this->assertNull($settlement->error);
        $this->assertNull($settlement->unwrap());
    }

    #[Test]
    public function ok_settlement_with_complex_value(): void
    {
        $data = ['key' => 'value', 'nested' => ['a' => 1]];
        $settlement = Settlement::ok($data);

        $this->assertSame($data, $settlement->unwrap());
    }

    #[Test]
    public function bag_get_returns_value_or_default(): void
    {
        $bag = new SettlementBag([
            'success' => Settlement::ok('value'),
            'failure' => Settlement::err(new RuntimeException('error')),
        ]);

        $this->assertSame('value', $bag->get('success', 'default'));
        $this->assertSame('default', $bag->get('failure', 'default'));
        $this->assertSame('default', $bag->get('missing', 'default'));
        $this->assertNull($bag->get('missing'));
    }

    #[Test]
    public function bag_extract_returns_array_with_defaults(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok('alpha'),
            'b' => Settlement::err(new RuntimeException('error')),
            'c' => Settlement::ok('charlie'),
        ]);

        $result = $bag->extract([
            'a' => 'default_a',
            'b' => 'default_b',
            'c' => 'default_c',
            'd' => 'default_d',
        ]);

        $this->assertSame([
            'a' => 'alpha',
            'b' => 'default_b',
            'c' => 'charlie',
            'd' => 'default_d',
        ], $result);
    }

    #[Test]
    public function bag_values_property_returns_only_successes(): void
    {
        $bag = new SettlementBag([
            'ok1' => Settlement::ok('one'),
            'err' => Settlement::err(new RuntimeException('error')),
            'ok2' => Settlement::ok('two'),
        ]);

        $this->assertSame(['ok1' => 'one', 'ok2' => 'two'], $bag->values);
    }

    #[Test]
    public function bag_errors_property_returns_only_failures(): void
    {
        $error1 = new RuntimeException('error1');
        $error2 = new InvalidArgumentException('error2');

        $bag = new SettlementBag([
            'ok' => Settlement::ok('value'),
            'err1' => Settlement::err($error1),
            'err2' => Settlement::err($error2),
        ]);

        $this->assertSame(['err1' => $error1, 'err2' => $error2], $bag->errors);
    }

    #[Test]
    public function bag_allOk_property(): void
    {
        $allOk = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::ok('two'),
        ]);
        $this->assertTrue($allOk->allOk);

        $mixed = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::err(new RuntimeException('error')),
        ]);
        $this->assertFalse($mixed->allOk);
    }

    #[Test]
    public function bag_anyOk_property(): void
    {
        $allErr = new SettlementBag([
            'a' => Settlement::err(new RuntimeException('e1')),
            'b' => Settlement::err(new RuntimeException('e2')),
        ]);
        $this->assertFalse($allErr->anyOk);

        $mixed = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::err(new RuntimeException('error')),
        ]);
        $this->assertTrue($mixed->anyOk);
    }

    #[Test]
    public function bag_allErr_property(): void
    {
        $allErr = new SettlementBag([
            'a' => Settlement::err(new RuntimeException('e1')),
            'b' => Settlement::err(new RuntimeException('e2')),
        ]);
        $this->assertTrue($allErr->allErr);

        $mixed = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::err(new RuntimeException('error')),
        ]);
        $this->assertFalse($mixed->allErr);
    }

    #[Test]
    public function bag_anyErr_property(): void
    {
        $allOk = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::ok('two'),
        ]);
        $this->assertFalse($allOk->anyErr);

        $mixed = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::err(new RuntimeException('error')),
        ]);
        $this->assertTrue($mixed->anyErr);
    }

    #[Test]
    public function bag_okKeys_and_errKeys_properties(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::err(new RuntimeException('error')),
            'c' => Settlement::ok('two'),
        ]);

        $this->assertSame(['a', 'c'], $bag->okKeys);
        $this->assertSame(['b'], $bag->errKeys);
    }

    #[Test]
    public function bag_partition_returns_both_buckets(): void
    {
        $error = new RuntimeException('error');
        $bag = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::err($error),
            'c' => Settlement::ok('two'),
        ]);

        [$values, $errors] = $bag->partition();

        $this->assertSame(['a' => 'one', 'c' => 'two'], $values);
        $this->assertSame(['b' => $error], $errors);
    }

    #[Test]
    public function bag_unwrapAll_throws_on_any_error(): void
    {
        $error = new RuntimeException('first error');
        $bag = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::err($error),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('first error');

        $bag->unwrapAll();
    }

    #[Test]
    public function bag_unwrapAll_returns_values_when_all_ok(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::ok('two'),
        ]);

        $this->assertSame(['a' => 'one', 'b' => 'two'], $bag->unwrapAll());
    }

    #[Test]
    public function bag_mapOk_transforms_values(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok(1),
            'b' => Settlement::err(new RuntimeException('error')),
            'c' => Settlement::ok(2),
        ]);

        $result = $bag->mapOk(fn($v, $k) => "{$k}:{$v}");

        $this->assertSame(['a' => 'a:1', 'c' => 'c:2'], $result);
    }

    #[Test]
    public function bag_settlement_returns_raw_settlement(): void
    {
        $ok = Settlement::ok('value');
        $err = Settlement::err(new RuntimeException('error'));

        $bag = new SettlementBag([
            'ok' => $ok,
            'err' => $err,
        ]);

        $this->assertSame($ok, $bag->settlement('ok'));
        $this->assertSame($err, $bag->settlement('err'));
        $this->assertNull($bag->settlement('missing'));
    }

    #[Test]
    public function bag_isOk_and_isErr_check_specific_keys(): void
    {
        $bag = new SettlementBag([
            'ok' => Settlement::ok('value'),
            'err' => Settlement::err(new RuntimeException('error')),
        ]);

        $this->assertTrue($bag->isOk('ok'));
        $this->assertFalse($bag->isOk('err'));
        $this->assertFalse($bag->isOk('missing'));

        $this->assertFalse($bag->isErr('ok'));
        $this->assertTrue($bag->isErr('err'));
        $this->assertFalse($bag->isErr('missing'));
    }

    #[Test]
    public function bag_implements_array_access(): void
    {
        $settlement = Settlement::ok('value');
        $bag = new SettlementBag(['key' => $settlement]);

        $this->assertTrue(isset($bag['key']));
        $this->assertFalse(isset($bag['missing']));
        $this->assertSame($settlement, $bag['key']);
        $this->assertNull($bag['missing']);
    }

    #[Test]
    public function bag_array_access_set_throws(): void
    {
        $bag = new SettlementBag([]);

        $this->expectException(LogicException::class);

        $bag['key'] = Settlement::ok('value');
    }

    #[Test]
    public function bag_array_access_unset_throws(): void
    {
        $bag = new SettlementBag(['key' => Settlement::ok('value')]);

        $this->expectException(LogicException::class);

        unset($bag['key']);
    }

    #[Test]
    public function bag_implements_countable(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok('one'),
            'b' => Settlement::ok('two'),
            'c' => Settlement::err(new RuntimeException('error')),
        ]);

        $this->assertCount(3, $bag);
    }

    #[Test]
    public function bag_implements_iterator(): void
    {
        $settlements = [
            'a' => Settlement::ok('one'),
            'b' => Settlement::ok('two'),
        ];
        $bag = new SettlementBag($settlements);

        $iterated = [];
        foreach ($bag as $key => $settlement) {
            $iterated[$key] = $settlement;
        }

        $this->assertSame($settlements, $iterated);
    }

    #[Test]
    public function bag_with_integer_keys(): void
    {
        $bag = new SettlementBag([
            0 => Settlement::ok('zero'),
            1 => Settlement::err(new RuntimeException('error')),
            2 => Settlement::ok('two'),
        ]);

        $this->assertSame('zero', $bag->get(0, 'default'));
        $this->assertSame('default', $bag->get(1, 'default'));
        $this->assertSame([0, 2], $bag->okKeys);
        $this->assertSame([1], $bag->errKeys);
    }
}
